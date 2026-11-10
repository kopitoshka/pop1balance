<?php

declare(strict_types=1);

namespace P1Bot\App;

use DateInterval;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Handlers\Context;
use P1Bot\Repository\PendingActionRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\DeepLink;
use P1Bot\View\Html;

/**
	Pending fill of missing players during free-text team registration:
	suggestion buttons, Mini App multi-select, Telegram request_users, or @username text.
*/
class RegistrationFillFlow {
	public const OP = 'reg_fill';
	public const REQUEST_USERS_ID = 4;
	public const TTL_SECONDS = 600;
	public const BUTTON_TEXT = '🔎 Подобрать игроков';

	public function __construct(
		protected TelegramApi $api,
		protected TournamentService $tournaments,
		protected TeamService $teams,
		protected PlayerRepository $players,
		protected PlayerResolver $resolver,
		protected PendingActionRepository $pending,
		protected Clock $clock,
		protected Announcer $announcer,
		protected TeamCards $cards,
		protected string $bot_username = '',
		protected ?WebAppPicker $picker = null,
	) {
	}

	public function setPicker(?WebAppPicker $picker): void {
		$this->picker = $picker;
	}

	public function hasPending(int $actor_id, int $chat_id): bool {
		return $this->pending->peekLatest($actor_id, $chat_id, $this->clock->now(), self::OP) !== null;
	}

	/**
		Store the unfinished registration and return a controlled reply (no extra model round).

		@param array{
			tournament_id: int,
			team_name: string,
			organizer_mode: bool,
			captain_id: ?int,
			resolved_ids: list<int>,
			missing: list<array{label: string, role: string, suggestion_ids: list<int>}>
		} $state
		@return array<string, mixed>
	*/
	public function start(Context $ctx, Player $actor, array $state): array {
		$chat_id = $ctx->chatId();
		$pending_id = $this->store($actor->id, $chat_id, $state);
		$private = $ctx->isPrivate();
		return [
			'say' => $this->promptText($state, $private),
			'suggest_markup' => $this->markup($actor, $chat_id, $pending_id, $state, $private),
			'followup' => $private ? $this->telegramPickerFollowup($this->slotsLeft($state)) : null,
			'note' => 'Кнопки выбора игроков уже уйдут в чат. Не проси ники отдельно и не выдумывай ответ.',
		];
	}

	/**
		Continue a group fill in private after t.me deep link start=rf_<id>.
	*/
	public function continueFromStart(int $chat_id, Player $actor, string $payload): bool {
		if (preg_match('/^rf_(\d+)$/', trim($payload), $m) !== 1) {
			return false;
		}
		$row = $this->pending->take((int) $m[1], $this->clock->now());
		if ($row === null || $row['actor_player_id'] !== $actor->id) {
			return false;
		}
		$action = $row['action'];
		if (($action['op'] ?? '') !== self::OP) {
			return false;
		}
		$state = $this->normalize($action);
		if ($state === null) {
			return false;
		}
		$pending_id = $this->store($actor->id, $chat_id, $state);
		$this->sendOffer($chat_id, $actor, $pending_id, $state, true, null);
		return true;
	}

	/**
		Text with @usernames (or exact nicks) while a fill is pending.
	*/
	public function handleSearchText(int $chat_id, Player $actor, string $text, ?int $reply_to = null): bool {
		$row = $this->pending->takeLatest($actor->id, $chat_id, $this->clock->now(), self::OP);
		if ($row === null) {
			return false;
		}
		$state = $this->normalize($row['action']);
		if ($state === null) {
			$this->api->sendMessage($chat_id, 'Регистрация уже недоступна.', $reply_to);
			return true;
		}
		$found = $this->resolveFromText($text, $actor, $this->takenIds($state));
		if ($found === []) {
			$pending_id = $this->store($actor->id, $chat_id, $state);
			$this->api->sendMessage(
				$chat_id,
				'Не поняла ники. Выбери кнопками ниже или пришли @username.',
				$reply_to,
				$this->markup($actor, $chat_id, $pending_id, $state, $chat_id > 0),
			);
			if ($chat_id > 0) {
				$this->sendTelegramPicker($chat_id, $this->slotsLeft($state));
			}
			return true;
		}
		$this->accept($chat_id, $actor, $state, $found, $reply_to, $chat_id > 0);
		return true;
	}

	/**
		@param list<array{id: int, username: ?string, first_name: string, last_name: string}> $users
	*/
	public function handleUsersShared(int $chat_id, Player $actor, array $users, ?int $reply_to = null): bool {
		$row = $this->pending->takeLatest($actor->id, $chat_id, $this->clock->now(), self::OP);
		if ($row === null) {
			return false;
		}
		$state = $this->normalize($row['action']);
		$remove = ['reply_markup' => ['remove_keyboard' => true]];
		if ($state === null) {
			$this->api->sendMessage($chat_id, 'Регистрация уже недоступна.', $reply_to, $remove);
			return true;
		}
		$picked = [];
		foreach ($users as $u) {
			$tg_id = (int) ($u['id'] ?? 0);
			if ($tg_id <= 0) {
				continue;
			}
			$username = isset($u['username']) && is_string($u['username']) && $u['username'] !== ''
				? $u['username']
				: null;
			$display = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
			if ($display === '') {
				$display = $username ?? ('user' . $tg_id);
			}
			$picked[] = $this->players->ensureByTelegram($tg_id, $username, $display, $this->clock->now());
		}
		if ($picked === []) {
			$pending_id = $this->store($actor->id, $chat_id, $state);
			$this->api->sendMessage($chat_id, 'Никого не выбрали.', $reply_to, $remove);
			$this->sendOffer($chat_id, $actor, $pending_id, $state, $chat_id > 0, $reply_to);
			return true;
		}
		$this->accept($chat_id, $actor, $state, $picked, $reply_to, true);
		return true;
	}

	public function handlePick(
		int $pending_id,
		int $player_id,
		Player $actor,
		int $chat_id,
		string $callback_id,
		?int $message_id,
	): void {
		$row = $this->pending->take($pending_id, $this->clock->now());
		if ($row === null || ($row['action']['op'] ?? '') !== self::OP) {
			$this->api->answerCallbackQuery($callback_id, 'Подсказка устарела — напиши ещё раз.', true);
			return;
		}
		if ($row['actor_player_id'] !== $actor->id) {
			$this->api->answerCallbackQuery($callback_id, 'Это не твоя подсказка.', true);
			return;
		}
		$state = $this->normalize($row['action']);
		$chosen = $this->players->find($player_id);
		if ($state === null || $chosen === null || $chosen->tg_user_id === null) {
			$this->api->answerCallbackQuery($callback_id, 'Игрок уже недоступен.', true);
			return;
		}
		$this->api->answerCallbackQuery($callback_id, 'Ок!');
		$this->accept($chat_id, $actor, $state, [$chosen], $message_id, $chat_id > 0);
	}

	/**
		Mini App submit: add selected players to the pending registration.
		@param list<int> $player_ids
		@return bool true when the team was created
	*/
	public function applyFromWebApp(int $chat_id, Player $actor, array $player_ids): bool {
		$row = $this->pending->takeLatest($actor->id, $chat_id, $this->clock->now(), self::OP);
		if ($row === null) {
			throw new DomainError('Запись команды уже не ждёт подбора — напиши ещё раз.');
		}
		$state = $this->normalize($row['action']);
		if ($state === null) {
			throw new DomainError('Регистрация уже недоступна.');
		}
		$picked = [];
		foreach ($player_ids as $id) {
			$p = $this->players->find((int) $id);
			if ($p !== null && $p->tg_user_id !== null) {
				$picked[] = $p;
			}
		}
		if ($picked === []) {
			$this->store($actor->id, $chat_id, $state);
			throw new DomainError('Никого не выбрали.');
		}
		return $this->accept($chat_id, $actor, $state, $picked, null, true);
	}

	/**
		@param array<string, mixed> $state
		@param list<Player> $picked
	*/
	protected function accept(
		int $chat_id,
		Player $actor,
		array $state,
		array $picked,
		?int $reply_to,
		bool $private,
	): bool {
		$errors = [];
		$taken = $this->takenIds($state);
		foreach ($picked as $player) {
			if ($this->slotsLeft($state) <= 0) {
				break;
			}
			if (isset($taken[$player->id])) {
				continue;
			}
			$err = $this->precheck($state, $player);
			if ($err !== null) {
				$errors[] = $err;
				continue;
			}
			$state = $this->addPlayer($state, $player);
			$taken[$player->id] = true;
		}
		foreach ($errors as $err) {
			$this->api->sendMessage($chat_id, Html::e($err), $reply_to);
		}
		if ($this->slotsLeft($state) <= 0) {
			try {
				$this->complete($chat_id, $actor, $state, $reply_to, $private);
				return true;
			} catch (DomainError $e) {
				$pending_id = $this->store($actor->id, $chat_id, $state);
				$this->api->sendMessage($chat_id, Html::e($e->getMessage()), $reply_to);
				$this->sendOffer($chat_id, $actor, $pending_id, $state, $private, $reply_to);
				return false;
			}
		}
		$pending_id = $this->store($actor->id, $chat_id, $state);
		$this->sendOffer($chat_id, $actor, $pending_id, $state, $private, $reply_to);
		return false;
	}

	/**
		@param array<string, mixed> $state
	*/
	protected function complete(int $chat_id, Player $actor, array $state, ?int $reply_to, bool $private): void {
		$t = $this->tournaments->get((int) $state['tournament_id']);
		$name = (string) $state['team_name'];
		$ids = array_values(array_map('intval', $state['resolved_ids']));
		$captain_id = (int) ($state['captain_id'] ?? 0);
		if (!empty($state['organizer_mode'])) {
			if ($captain_id <= 0) {
				throw new DomainError('Сначала выбери капитана.');
			}
			$entry = $this->teams->createTeamAsOrganizer($actor->id, $t->id, $captain_id, $name, $ids);
		} else {
			if ($captain_id <= 0) {
				$captain_id = $actor->id;
			}
			$entry = $this->teams->createTeam($t->id, $captain_id, $name, $ids);
		}
		$this->pending->clearChat($actor->id, $chat_id);
		$this->announcer->refresh($t->id);
		$labels = [];
		foreach ($entry->members as $member) {
			$p = $this->players->find($member->player_id);
			$labels[] = $p !== null ? $p->mention() : ('#' . $member->player_id);
		}
		$this->api->sendMessage(
			$chat_id,
			'Записала команду «' . Html::e($entry->team->name) . '»: ' . implode(', ', $labels) . '.',
			$reply_to,
			['reply_markup' => ['remove_keyboard' => true]],
		);
		if ($private) {
			$this->cards->send($chat_id, $t, $entry, $actor);
		}
	}

	/**
		@param array<string, mixed> $state
	*/
	protected function precheck(array $state, Player $player): ?string {
		try {
			$t = $this->tournaments->get((int) $state['tournament_id']);
			$on = $this->players->playersOnTeamsOfTournament($t->id);
			if (in_array($player->id, $on, true)) {
				return $player->plainLabel() . ' уже в другой команде этого турнира.';
			}
		} catch (DomainError $e) {
			return $e->getMessage();
		}
		return null;
	}

	/**
		@param array<string, mixed> $state
		@return array<string, mixed>
	*/
	protected function addPlayer(array $state, Player $player): array {
		$cap = $state['captain_missing'] ?? null;
		$cap_ids = is_array($cap) ? array_map('intval', $cap['suggestion_ids'] ?? []) : [];
		if (is_array($cap) && (int) ($state['captain_id'] ?? 0) <= 0
			&& ($cap_ids === [] || in_array($player->id, $cap_ids, true) || !$this->inAnySlot($state, $player->id))
		) {
			$state['captain_id'] = $player->id;
			$state['captain_missing'] = null;
			return $state;
		}
		$ids = array_values(array_map('intval', $state['resolved_ids']));
		$ids[] = $player->id;
		$state['resolved_ids'] = array_values(array_unique($ids));
		$missing = is_array($state['missing'] ?? null) ? $state['missing'] : [];
		$matched = false;
		foreach ($missing as $i => $slot) {
			$sids = array_map('intval', is_array($slot) ? ($slot['suggestion_ids'] ?? []) : []);
			if (in_array($player->id, $sids, true)) {
				unset($missing[$i]);
				$matched = true;
				break;
			}
		}
		if (!$matched && $missing !== []) {
			array_shift($missing);
		}
		$state['missing'] = array_values($missing);
		return $state;
	}

	/**
		@param array<string, mixed> $state
	*/
	protected function inAnySlot(array $state, int $player_id): bool {
		$cap = $state['captain_missing'] ?? null;
		if (is_array($cap) && in_array($player_id, array_map('intval', $cap['suggestion_ids'] ?? []), true)) {
			return true;
		}
		foreach ($state['missing'] ?? [] as $slot) {
			$ids = array_map('intval', is_array($slot) ? ($slot['suggestion_ids'] ?? []) : []);
			if (in_array($player_id, $ids, true)) {
				return true;
			}
		}
		return false;
	}


	/**
		@param array<string, mixed> $state
		@return array<int, true>
	*/
	protected function takenIds(array $state): array {
		$taken = [];
		foreach (array_map('intval', $state['resolved_ids'] ?? []) as $id) {
			if ($id > 0) {
				$taken[$id] = true;
			}
		}
		$cap = (int) ($state['captain_id'] ?? 0);
		if ($cap > 0) {
			$taken[$cap] = true;
		}
		return $taken;
	}

	/**
		@param array<string, mixed> $state
	*/
	protected function slotsLeft(array $state): int {
		$n = count(is_array($state['missing'] ?? null) ? $state['missing'] : []);
		if (is_array($state['captain_missing'] ?? null) && (int) ($state['captain_id'] ?? 0) <= 0) {
			$n++;
		}
		return $n;
	}

	/**
		@param array<string, mixed> $state
	*/
	protected function store(int $actor_id, int $chat_id, array $state): int {
		$now = $this->clock->now();
		$this->pending->clearChat($actor_id, $chat_id);
		$state['op'] = self::OP;
		return $this->pending->create(
			$actor_id,
			$chat_id,
			$state,
			$now->add(new DateInterval('PT' . self::TTL_SECONDS . 'S')),
			$now,
		);
	}

	/**
		@param array<string, mixed> $action
		@return array<string, mixed>|null
	*/
	protected function normalize(array $action): ?array {
		if ((int) ($action['tournament_id'] ?? 0) <= 0 || trim((string) ($action['team_name'] ?? '')) === '') {
			return null;
		}
		$action['resolved_ids'] = array_values(array_map('intval', $action['resolved_ids'] ?? []));
		$action['organizer_mode'] = !empty($action['organizer_mode']);
		$action['captain_id'] = isset($action['captain_id']) && (int) $action['captain_id'] > 0
			? (int) $action['captain_id']
			: null;
		$action['missing'] = is_array($action['missing'] ?? null) ? array_values($action['missing']) : [];
		if (!is_array($action['captain_missing'] ?? null)) {
			$action['captain_missing'] = null;
		}
		return $action;
	}

	/**
		@param array<string, mixed> $state
		@param list<int> $exclude
		@return list<Player>
	*/
	protected function resolveFromText(string $text, Player $actor, array $exclude): array {
		$found = [];
		$seen = $exclude;
		if (preg_match_all('/@([A-Za-z][A-Za-z0-9_]{2,31})/', $text, $m) === false) {
			$m = [1 => []];
		}
		$refs = [];
		foreach ($m[1] as $username) {
			$refs[] = '@' . $username;
		}
		$rest = trim((string) preg_replace('/@[A-Za-z][A-Za-z0-9_]{2,31}/', ' ', $text));
		if ($rest !== '') {
			foreach (preg_split('/[\s,;]+/u', $rest) ?: [] as $token) {
				$token = trim($token);
				if ($token !== '') {
					$refs[] = $token;
				}
			}
		}
		foreach ($refs as $ref) {
			$probe = $this->resolver->tryResolve($ref, $actor, array_keys($seen));
			$player = $probe['player'];
			if ($player === null || isset($seen[$player->id])) {
				continue;
			}
			$found[] = $player;
			$seen[$player->id] = true;
		}
		return $found;
	}

	/**
		@param array<string, mixed> $state
	*/
	protected function sendOffer(
		int $chat_id,
		Player $actor,
		int $pending_id,
		array $state,
		bool $private,
		?int $reply_to,
	): void {
		$this->api->sendMessage(
			$chat_id,
			Html::e($this->promptText($state, $private)),
			$reply_to,
			$this->markup($actor, $chat_id, $pending_id, $state, $private),
		);
		if ($private) {
			$this->sendTelegramPicker($chat_id, $this->slotsLeft($state));
		}
	}

	/**
		@param array<string, mixed> $state
	*/
	protected function promptText(array $state, bool $private): string {
		$name = (string) $state['team_name'];
		$labels = [];
		$cap = $state['captain_missing'] ?? null;
		if (is_array($cap) && trim((string) ($cap['label'] ?? '')) !== '') {
			$labels[] = (string) $cap['label'];
		}
		foreach ($state['missing'] ?? [] as $slot) {
			$label = is_array($slot) ? trim((string) ($slot['label'] ?? '')) : '';
			if ($label !== '') {
				$labels[] = $label;
			}
		}
		$who = $labels === [] ? 'этих игроков' : self::joinNames($labels);
		$base = 'Не нашла ' . $who . '.';
		if ($private) {
			return $base . ' Выбери их кнопками ниже или пришли @username — и я запишу команду «' . $name . '».';
		}
		return $base . ' Если это кто-то из этих — нажми кнопку. Подбор игроков и выбор из Telegram — в личке. Или пришли @username сюда.';
	}

	/**
		@param array<string, mixed> $state
		@return array<string, mixed>
	*/
	protected function markup(Player $actor, int $chat_id, int $pending_id, array $state, bool $private): array {
		$rows = $this->suggestionRows($pending_id, $state);
		if ($private && $this->picker !== null) {
			$btn = $this->picker->regFillButton($actor, $chat_id, $this->slotsLeft($state), (int) $state['tournament_id']);
			if ($btn !== null) {
				$btn['text'] = self::BUTTON_TEXT;
				$rows[] = [$btn];
			}
		}
		if (!$private && $this->bot_username !== '') {
			$rows[] = [[
				'text' => '✉️ Продолжить в личке',
				'url' => DeepLink::url($this->bot_username, 'rf_' . $pending_id),
			]];
		}
		return ['reply_markup' => ['inline_keyboard' => PendingTextGuard::withCancel($rows)]];
	}

	/**
		@param array<string, mixed> $state
		@return list<list<array<string, mixed>>>
	*/
	protected function suggestionRows(int $pending_id, array $state): array {
		$rows = [];
		$seen = [];
		$slots = [];
		$cap = $state['captain_missing'] ?? null;
		if (is_array($cap)) {
			$slots[] = $cap + ['role' => 'captain'];
		}
		foreach ($state['missing'] ?? [] as $slot) {
			if (is_array($slot)) {
				$slots[] = $slot;
			}
		}
		$multi = count($slots) > 1;
		foreach ($slots as $slot) {
			$label = trim((string) ($slot['label'] ?? ''));
			$ids = array_map('intval', $slot['suggestion_ids'] ?? []);
			$role = (string) ($slot['role'] ?? 'player');
			foreach ($ids as $id) {
				if ($id <= 0 || isset($seen[$id])) {
					continue;
				}
				$player = $this->players->find($id);
				if ($player === null || $player->tg_user_id === null) {
					continue;
				}
				$seen[$id] = true;
				$data = 'rfill:' . $pending_id . ':' . $player->id;
				if (strlen($data) > 64) {
					continue;
				}
				$who = $player->username !== null && $player->username !== ''
					? '@' . $player->username
					: $player->display_name;
				if ($player->hasIgn()) {
					$who .= ' · ' . $player->ign;
				}
				if ($role === 'captain') {
					$text = 'Капитан: ' . $who . '?';
				} elseif ($multi && $label !== '') {
					$text = $label . ': ' . $who . '?';
				} else {
					$text = 'Может, ты про ' . $who . '?';
				}
				if (mb_strlen($text) > 64) {
					$text = mb_substr($text, 0, 61) . '…';
				}
				$rows[] = [['text' => $text, 'callback_data' => $data]];
			}
		}
		return $rows;
	}

	/**
		@return array{text: string, extra: array<string, mixed>}
	*/
	public function telegramPickerFollowup(int $max): array {
		return [
			'text' => 'Можно выбрать прямо из Telegram:',
			'extra' => $this->telegramPickerExtra($max),
		];
	}

	protected function sendTelegramPicker(int $chat_id, int $max): void {
		$follow = $this->telegramPickerFollowup($max);
		$this->api->sendMessage($chat_id, $follow['text'], null, $follow['extra']);
	}

	/**
		@return array<string, mixed>
	*/
	protected function telegramPickerExtra(int $max): array {
		$qty = max(1, min(10, $max));
		return [
			'reply_markup' => [
				'keyboard' => [[
					[
						'text' => '👤 Выбрать из Telegram',
						'request_users' => [
							'request_id' => self::REQUEST_USERS_ID,
							'user_is_bot' => false,
							'max_quantity' => $qty,
							'request_name' => true,
							'request_username' => true,
						],
					],
				]],
				'resize_keyboard' => true,
				'one_time_keyboard' => true,
			],
		];
	}

	/**
		@param list<string> $names
	*/
	public static function joinNames(array $names): string {
		$names = array_values($names);
		if ($names === []) {
			return '';
		}
		if (count($names) === 1) {
			return $names[0];
		}
		$last = array_pop($names);
		return implode(', ', $names) . ' и ' . $last;
	}
}
