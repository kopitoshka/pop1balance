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
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\WebAppContextRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\TelegramApi;
use P1Bot\Telegram\WebAppInitData;
use P1Bot\View\Html;
use P1Bot\View\WebAppUrl;

/**
	Telegram Mini App player picker for /level: issues short-lived context tokens, searches
	known players and shows (owner: sets) the picked player's level in the private chat.
	Team rosters are built in the team form (TeamForm), not here; old team picker
	contexts are rejected as unknown.
*/
class WebAppPicker {
	public const ACTION_SHOW_LEVEL = 'show_level';
	public const TTL_SECONDS = 600;
	public const SEARCH_LIMIT = 20;
	public const BUTTON_TEXT = '🔎 Подобрать игрока';

	public function __construct(
		protected WebAppContextRepository $contexts,
		protected PlayerRepository $players,
		protected BalanceService $balances,
		protected AccessPolicy $access,
		protected TelegramApi $api,
		protected Clock $clock,
		protected string $bot_token,
		protected string $webhook_url,
	) {
	}

	public function isAvailable(): bool {
		return WebAppUrl::pickerPage($this->webhook_url) !== null;
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
		[, $ctx] = $this->authorize($init_data, $token);
		return [
			'ok' => true,
			'action' => $ctx['action'],
			'max_select' => 1,
			'can_set_level' => (bool) ($ctx['payload']['can_set_level'] ?? false),
			'levels' => $this->levelOptions(),
		];
	}

	/**
		@return array{ok: true, players: list<array<string, mixed>>}
	*/
	public function search(string $init_data, string $token, string $query): array {
		[, $ctx] = $this->authorize($init_data, $token);
		unset($ctx);
		$found = $this->lookupPlayers($query);
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
		$this->submitLevel($actor, $ctx['chat_id'], $ids[0], $level, (bool) ($ctx['payload']['can_set_level'] ?? false));
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
		if ($ctx === null || $ctx['action'] !== self::ACTION_SHOW_LEVEL) {
			throw new ValidationError('Сессия подбора истекла. Закрой окно и открой снова.');
		}
		$actor = $this->players->find($ctx['actor_player_id']);
		if ($actor === null || $actor->tg_user_id === null || $actor->tg_user_id !== $parsed['user_id']) {
			throw new ValidationError('Это приглашение выдано другому человеку.');
		}
		return [$actor, $ctx];
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
		@return list<Player>
	*/
	protected function lookupPlayers(string $query): array {
		$query = trim($query);
		$limit = self::SEARCH_LIMIT;
		$exclude = [];
		if ($query === '') {
			return $this->players->listKnown($limit);
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
