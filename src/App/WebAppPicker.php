<?php

declare(strict_types=1);

namespace P1Bot\App;

use DateInterval;
use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\BalanceService;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Levels;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Repository\PendingActionRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\WebAppContextRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\TelegramApi;
use P1Bot\Telegram\WebAppInitData;
use P1Bot\View\Html;
use P1Bot\View\WebAppUrl;

/**
	Telegram Mini App player picker: issues short-lived context tokens,
	searches known players, and applies the selection in private chat.
*/
class WebAppPicker {
	public const ACTION_TEAM_ADD = 'team_add';
	public const ACTION_SHOW_LEVEL = 'show_level';
	public const TTL_SECONDS = 600;
	public const SEARCH_LIMIT = 20;
	public const BUTTON_TEXT = '🔎 Подобрать игрока';

	public function __construct(
		protected WebAppContextRepository $contexts,
		protected PlayerRepository $players,
		protected TeamService $teams,
		protected TournamentService $tournaments,
		protected BalanceService $balances,
		protected AccessPolicy $access,
		protected TelegramApi $api,
		protected Announcer $announcer,
		protected TeamCards $cards,
		protected PendingActionRepository $pending,
		protected Clock $clock,
		protected string $bot_token,
		protected string $webhook_url,
	) {
	}

	public function isAvailable(): bool {
		return WebAppUrl::pickerPage($this->webhook_url) !== null;
	}

	/**
		Inline web_app button for the team-add offer, or null when the Mini App URL is unknown.
		@return array<string, mixed>|null
	*/
	public function teamAddButton(Player $actor, int $chat_id, int $team_id): ?array {
		if (!$this->isAvailable() || $actor->tg_user_id === null) {
			return null;
		}
		$entry = $this->teams->get($team_id);
		$t = $this->tournaments->get($entry->team->tournament_id);
		$free = max(1, $t->team_size - $entry->size());
		$token = $this->issue(
			$actor,
			$chat_id,
			self::ACTION_TEAM_ADD,
			[
				'team_id' => $team_id,
				'tournament_id' => $t->id,
				'max_select' => $free,
				'can_set_level' => false,
			],
		);
		$url = WebAppUrl::picker($this->webhook_url, $token);
		if ($url === null) {
			return null;
		}
		return ['text' => self::BUTTON_TEXT, 'web_app' => ['url' => $url]];
	}

	/**
		Inline web_app button for /level (show; owner may set a level in the app).
		@return array<string, mixed>|null
	*/
	public function levelButton(Player $actor, int $chat_id, bool $can_set_level): ?array {
		if (!$this->isAvailable() || $actor->tg_user_id === null) {
			return null;
		}
		$token = $this->issue(
			$actor,
			$chat_id,
			self::ACTION_SHOW_LEVEL,
			[
				'max_select' => 1,
				'can_set_level' => $can_set_level,
			],
		);
		$url = WebAppUrl::picker($this->webhook_url, $token);
		if ($url === null) {
			return null;
		}
		return ['text' => self::BUTTON_TEXT, 'web_app' => ['url' => $url]];
	}

	/**
		Add the level picker button as a new inline row (creates a context token).
		@param array<string, mixed> $extra
		@return array<string, mixed>
	*/
	public function appendLevelButton(array $extra, Player $actor, int $chat_id, bool $can_set_level): array {
		$btn = $this->levelButton($actor, $chat_id, $can_set_level);
		if ($btn === null) {
			return $extra;
		}
		$rows = $extra['reply_markup']['inline_keyboard'] ?? [];
		$rows[] = [$btn];
		$extra['reply_markup']['inline_keyboard'] = $rows;
		return $extra;
	}

	/**
		@param array<string, mixed> $payload
	*/
	protected function issue(Player $actor, int $chat_id, string $action, array $payload): string {
		$this->contexts->purgeExpired($this->clock->now());
		$token = bin2hex(random_bytes(16));
		$now = $this->clock->now();
		$this->contexts->create(
			$token,
			$actor->id,
			$chat_id,
			$action,
			$payload,
			$now->add(new DateInterval('PT' . self::TTL_SECONDS . 'S')),
			$now,
		);
		return $token;
	}

	/**
		Context metadata for the page (after initData + token check).
		@return array<string, mixed>
	*/
	public function bootstrap(string $init_data, string $token): array {
		[$actor, $ctx] = $this->authorize($init_data, $token);
		unset($actor);
		$max = (int) ($ctx['payload']['max_select'] ?? 1);
		if ($ctx['action'] === self::ACTION_TEAM_ADD) {
			$max = $this->freeSlots((int) ($ctx['payload']['team_id'] ?? 0), $max);
		}
		return [
			'ok' => true,
			'action' => $ctx['action'],
			'max_select' => max(1, $max),
			'can_set_level' => (bool) ($ctx['payload']['can_set_level'] ?? false),
			'levels' => $this->levelOptions(),
		];
	}

	/**
		@return array{ok: true, players: list<array<string, mixed>>}
	*/
	public function search(string $init_data, string $token, string $query): array {
		[$actor, $ctx] = $this->authorize($init_data, $token);
		$exclude = [];
		$tournament_id = null;
		if ($ctx['action'] === self::ACTION_TEAM_ADD) {
			$team_id = (int) ($ctx['payload']['team_id'] ?? 0);
			$entry = $this->teams->get($team_id);
			$tournament_id = $entry->team->tournament_id;
			$exclude = $this->players->playersOnTeamsOfTournament($tournament_id);
		}
		$found = $this->lookupPlayers($query, $actor->id, $tournament_id, $exclude);
		$players = [];
		foreach ($found as $player) {
			$players[] = $this->playerCard($player);
		}
		return ['ok' => true, 'players' => $players];
	}

	/**
		Apply the selection and write to the private chat.
		@param list<int> $player_ids
		@return array{ok: true, close: true}
	*/
	public function submit(string $init_data, string $token, array $player_ids, ?int $level): array {
		[$actor, $ctx] = $this->authorize($init_data, $token);
		$ids = [];
		foreach ($player_ids as $id) {
			$id = (int) $id;
			if ($id > 0) {
				$ids[$id] = $id;
			}
		}
		$ids = array_values($ids);
		if ($ids === []) {
			throw new ValidationError('Никого не выбрали.');
		}
		if ($ctx['action'] === self::ACTION_SHOW_LEVEL) {
			$ids = [ $ids[0] ];
			$this->submitLevel($actor, $ctx['chat_id'], $ids[0], $level, (bool) ($ctx['payload']['can_set_level'] ?? false));
		} elseif ($ctx['action'] === self::ACTION_TEAM_ADD) {
			$team_id = (int) ($ctx['payload']['team_id'] ?? 0);
			$max = $this->freeSlots($team_id, (int) ($ctx['payload']['max_select'] ?? 1));
			if (count($ids) > $max) {
				$ids = array_slice($ids, 0, $max);
			}
			$this->submitTeamAdd($actor, $ctx['chat_id'], $team_id, $ids);
		} else {
			throw new ValidationError('Неизвестный сценарий подбора.');
		}
		$this->pending->clearChat($actor->id, $ctx['chat_id']);
		$this->contexts->delete($token);
		return ['ok' => true, 'close' => true];
	}

	/**
		@return array{0: Player, 1: array{token: string, actor_player_id: int, chat_id: int, action: string, payload: array<string, mixed>}}
	*/
	protected function authorize(string $init_data, string $token): array {
		$this->contexts->purgeExpired($this->clock->now());
		$parsed = WebAppInitData::parse($init_data, $this->bot_token, $this->clock->now()->getTimestamp());
		$token = trim($token);
		if ($token === '' || preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
			throw new ValidationError('Сессия подбора недействительна. Закрой окно и открой снова.');
		}
		$ctx = $this->contexts->find($token, $this->clock->now());
		if ($ctx === null) {
			throw new ValidationError('Сессия подбора истекла. Закрой окно и открой снова.');
		}
		$actor = $this->players->find($ctx['actor_player_id']);
		if ($actor === null || $actor->tg_user_id === null || $actor->tg_user_id !== $parsed['user_id']) {
			throw new ValidationError('Это приглашение выдано другому человеку.');
		}
		return [$actor, $ctx];
	}

	/**
		@param list<int> $player_ids
	*/
	protected function submitTeamAdd(Player $actor, int $chat_id, int $team_id, array $player_ids): void {
		if (!$this->teams->canActorAddPlayer($actor->id, $team_id)) {
			throw new DomainError('Сейчас нельзя добавить игрока в эту команду.');
		}
		$added = [];
		$errors = [];
		$entry = null;
		foreach ($player_ids as $player_id) {
			try {
				$entry = $this->teams->addPlayer($actor->id, $team_id, $player_id);
				$p = $this->players->find($player_id);
				$added[] = $p !== null ? $p->plainLabel() : ('#' . $player_id);
			} catch (DomainError $e) {
				$p = $this->players->find($player_id);
				$label = $p !== null ? $p->plainLabel() : ('#' . $player_id);
				$errors[] = $label . ' — ' . $e->getMessage();
			}
		}
		if ($entry === null) {
			$entry = $this->teams->get($team_id);
		}
		$t = $this->tournaments->get($entry->team->tournament_id);
		if ($added !== []) {
			$this->announcer->refresh($t->id);
		}
		$lines = [];
		if ($added !== []) {
			$lines[] = 'Добавила в «' . Html::e($entry->team->name) . '»: ' . Html::e(implode(', ', $added)) . '.';
		}
		foreach ($errors as $err) {
			$lines[] = Html::e($err);
		}
		if ($lines === []) {
			$lines[] = 'Никого не добавила.';
		}
		$this->api->sendMessage($chat_id, implode("\n", $lines), null, ['reply_markup' => ['remove_keyboard' => true]]);
		if ($added !== []) {
			$this->cards->send($chat_id, $t, $entry, $actor);
		}
	}

	protected function submitLevel(Player $actor, int $chat_id, int $player_id, ?int $level, bool $can_set): void {
		$player = $this->players->find($player_id);
		if ($player === null || $player->tg_user_id === null) {
			throw new ValidationError('Такого игрока я не знаю.');
		}
		if ($level !== null) {
			if (!$can_set || !$this->access->isOwner($actor->id)) {
				throw new DomainError(AccessPolicy::LEVELS_OWNER_ONLY);
			}
			$this->balances->setLevel($chat_id, $actor->id, $player->id, $level);
			$text = sprintf(
				'Записала: %s — %s (%d).',
				Html::e($player->plainLabel()),
				Levels::name($level),
				$level,
			);
			$this->api->sendMessage($chat_id, $text);
			return;
		}
		$scale_id = $this->balances->viewScaleId();
		$explicit = $this->balances->explicitLevel($scale_id, $player->id);
		$own = $player->id === $actor->id;
		if ($explicit === null) {
			$text = $own
				? sprintf(
					'Уровень ещё не ставили — пока считаю %s (%d).',
					Levels::name(Levels::DEFAULT),
					Levels::DEFAULT,
				)
				: sprintf(
					'%s — уровень ещё не ставили, считаю %s (%d).',
					Html::e($player->plainLabel()),
					Levels::name(Levels::DEFAULT),
					Levels::DEFAULT,
				);
		} else {
			$text = $own
				? sprintf('Твой уровень: %s (%d)', Levels::name($explicit), $explicit)
				: sprintf('%s — %s (%d)', Html::e($player->plainLabel()), Levels::name($explicit), $explicit);
		}
		if ($can_set && $this->access->isOwner($actor->id)) {
			$text .= "\nПоставить: /level " . Html::e($player->mention()) . ' 4';
		}
		$this->api->sendMessage($chat_id, $text);
	}

	/**
		@param list<int> $exclude
		@return list<Player>
	*/
	protected function lookupPlayers(string $query, int $actor_id, ?int $tournament_id, array $exclude): array {
		$query = trim($query);
		$limit = self::SEARCH_LIMIT;
		if ($query === '') {
			if ($tournament_id !== null) {
				return $this->players->teamAddCandidates($actor_id, $tournament_id, $limit);
			}
			return $this->players->listKnown($limit, $exclude);
		}
		$partial = $this->players->searchPartial($query, $limit, $exclude);
		$seen = [];
		$out = [];
		foreach ($partial as $player) {
			$seen[$player->id] = true;
			$out[] = $player;
		}
		if (count($out) < $limit && mb_strlen($query) >= 2) {
			foreach ($this->players->fuzzySuggest($query, $limit) as $player) {
				if (isset($seen[$player->id]) || in_array($player->id, $exclude, true)) {
					continue;
				}
				$seen[$player->id] = true;
				$out[] = $player;
				if (count($out) >= $limit) {
					break;
				}
			}
		}
		return $out;
	}

	/**
		@return array<string, mixed>
	*/
	protected function playerCard(Player $player): array {
		$scale_id = $this->balances->viewScaleId();
		$explicit = $this->balances->explicitLevel($scale_id, $player->id);
		$level = $explicit ?? Levels::DEFAULT;
		return [
			'id' => $player->id,
			'ign' => $player->hasIgn() ? $player->ign : null,
			'username' => $player->username,
			'display_name' => $player->display_name,
			'label' => $player->plainLabel(),
			'level' => $level,
			'level_name' => Levels::name($level),
			'level_explicit' => $explicit !== null,
		];
	}

	protected function freeSlots(int $team_id, int $fallback): int {
		if ($team_id <= 0) {
			return max(1, $fallback);
		}
		$entry = $this->teams->get($team_id);
		$t = $this->tournaments->get($entry->team->tournament_id);
		return max(0, $t->team_size - $entry->size());
	}

	/**
		@return list<array{level: int, name: string}>
	*/
	protected function levelOptions(): array {
		$out = [];
		foreach (Levels::all() as $level => $name) {
			$out[] = ['level' => $level, 'name' => $name];
		}
		return $out;
	}
}
