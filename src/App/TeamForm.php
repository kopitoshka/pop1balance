<?php

declare(strict_types=1);

namespace P1Bot\App;

use DateInterval;
use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\BalanceService;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Exception\LevelLimitExceeded;
use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Levels;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\PendingActionRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\TeamRepository;
use P1Bot\Repository\WebAppContextRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\DialogLog;
use P1Bot\Support\RuPlural;
use P1Bot\Telegram\TelegramApi;
use P1Bot\Telegram\TgUser;
use P1Bot\Telegram\WebAppInitData;
use P1Bot\View\DeepLink;
use P1Bot\View\Html;
use P1Bot\View\WebAppUrl;

/**
	Team Mini App (public/webapp/team.html): the only way to build or change a roster.

	New mode: the opener is captain. Edit mode: captain, tournament organizer and owner save; other members see a
	read-only form with «❌ Отменить регистрацию». Only organizer/owner pick the team letter.

	A context token keeps the tournament, the team (edit) and an optional unsaved draft: the AI
	prefill (resolved players + unresolved names to look up) or the state saved before the
	Telegram contacts picker. Group chats get a deep link start=tf_<token> into the private chat.
	Adding or removing members sends them nothing; leaving notifies the captain, disbanding
	notifies the team as before.
*/
class TeamForm {
	public const ACTION = 'team_form';
	public const TTL_SECONDS = 21600;
	public const DEEP_LINK_PREFIX = 'tf_';
	public const CONTACTS_OP = 'team_form_contacts';
	public const CONTACTS_REQUEST_ID = 5;
	public const CONTACTS_TTL_SECONDS = 1800;
	public const SEARCH_LIMIT = 20;
	public const MAX_PENDING = 12;

	public const NEW_BUTTON = '🛡 Собрать команду';
	public const EDIT_BUTTON = '✏️ Редактировать команду';
	public const REOPEN_BUTTON = '✏️ Вернуться к форме';
	public const CONTACTS_BUTTON = '👤 Выбрать из Telegram';

	public const NAME_HINT = 'Подставила последнее название твоей команды, можно поменять.';
	public const LETTER_HINT = 'Зачёркнутые уже у других команд. Назначает только организатор.';
	public const EXPIRED_TEXT = 'Форма устарела. Закрой её и открой заново с карточки турнира или команды.';
	public const UNAVAILABLE_TEXT = 'Форма команды сейчас недоступна. Попробуй чуть позже.';
	public const STRANGER_TEXT = 'Эту команду правят капитан и организатор турнира, а ты в ней не играешь.';
	public const CONTACTS_TEXT = 'Выбери игроков кнопкой «👤 Выбрать из Telegram» внизу. Добавлю их в форму '
		. 'и пришлю кнопку, чтобы вернуться к ней.';

	protected ?PrivateViews $views = null;

	public function __construct(
		protected WebAppContextRepository $contexts,
		protected PlayerRepository $players,
		protected TeamService $teams,
		protected TeamRepository $team_repo,
		protected TournamentService $tournaments,
		protected AccessPolicy $access,
		protected BalanceService $balances,
		protected Announcer $announcer,
		protected TeamCards $cards,
		protected TeamDisbandNotifier $notifier,
		protected PendingActionRepository $pending,
		protected TelegramApi $api,
		protected Clock $clock,
		protected string $bot_token,
		protected string $webhook_url,
		protected string $bot_username,
		protected ?DialogLog $dialogs = null,
	) {
	}

	public function setViews(PrivateViews $views): void {
		$this->views = $views;
	}

	public function isAvailable(): bool {
		return WebAppUrl::page($this->webhook_url, WebAppUrl::TEAM_FORM) !== null;
	}

	/**
		Button for a new team (team_id null) or an existing one. Private chat: web_app;
		group: t.me deep link that reopens the same context in the private chat.

		@param array<string, mixed>|null $draft unsaved state (name, player_ids, captain_id, pending)
		@return array<string, mixed>|null
	*/
	public function button(
		Player $actor,
		int $chat_id,
		int $tournament_id,
		?int $team_id,
		?array $draft = null,
		?string $text = null,
	): ?array {
		if (!$this->isAvailable() || $actor->tg_user_id === null) {
			return null;
		}
		$text ??= $team_id === null ? self::NEW_BUTTON : self::EDIT_BUTTON;
		$payload = ['tournament_id' => $tournament_id, 'team_id' => $team_id];
		if ($draft !== null) {
			$payload['draft'] = $draft;
		}
		$token = $this->issue($actor, $chat_id < 0 ? $actor->tg_user_id : $chat_id, $payload);
		if ($chat_id < 0) {
			return ['text' => $text, 'url' => DeepLink::url($this->bot_username, self::DEEP_LINK_PREFIX . $token)];
		}
		$url = WebAppUrl::teamForm($this->webhook_url, $token);
		return $url === null ? null : ['text' => $text, 'web_app' => ['url' => $url]];
	}

	/**
		/start tf_<token> from a group: send the web_app button for that context.
	*/
	public function continueFromStart(int $chat_id, Player $actor, string $payload, ?int $reply_to = null): bool {
		if (!str_starts_with($payload, self::DEEP_LINK_PREFIX)) {
			return false;
		}
		$token = substr($payload, strlen(self::DEEP_LINK_PREFIX));
		$ctx = preg_match('/^[a-f0-9]{32}$/D', $token) === 1 ? $this->contexts->find($token, $this->clock->now()) : null;
		if ($ctx === null || $ctx['action'] !== self::ACTION || $ctx['actor_player_id'] !== $actor->id) {
			$this->api->sendMessage($chat_id, self::EXPIRED_TEXT, $reply_to);
			return true;
		}
		$this->sendReopen($chat_id, $token, 'Открой форму команды:', $reply_to, $ctx['payload']);
		return true;
	}

	/**
		@return array<string, mixed>
	*/
	public function bootstrap(string $init_data, string $token): array {
		[$actor, $ctx, , $t, $entry] = $this->authorize($init_data, $token);
		if ($entry === null) {
			$entry = $this->ownedTeamIfRegular($actor, $t);
		}
		$role = $this->role($actor, $t, $entry);
		if ($entry !== null && $role === 'stranger') {
			throw new WebAppFormError(self::STRANGER_TEXT, '', WebAppFormError::FORBIDDEN);
		}
		$draft = is_array($ctx['payload']['draft'] ?? null) ? $ctx['payload']['draft'] : [];
		$taken_names = $this->team_repo->memberTeamNames($t->id);
		$name_hint = false;
		$can_manage = $this->access->canManageTournament($t, $actor->id);
		// An organizer who already plays here builds a team of other people: the roster starts empty.
		$start_empty = $entry === null && $can_manage && isset($taken_names[$actor->id]);
		if ($entry === null) {
			$name = isset($draft['name']) ? (string) $draft['name'] : '';
			if ($name === '' && !$start_empty) {
				$last = $this->team_repo->preferredTeamName($actor->id);
				if ($last !== null && $this->team_repo->findByName($t->id, $last) === null) {
					$name = $last;
					$name_hint = true;
				}
			}
			$ids = $start_empty ? [] : [$actor->id];
			$captain_id = $start_empty ? 0 : $actor->id;
			$letter = null;
		} else {
			$name = isset($draft['name']) ? (string) $draft['name'] : $entry->team->name;
			$ids = array_map(static fn ($m): int => $m->player_id, $entry->members);
			$captain_id = $entry->team->captain_id;
			$letter = $entry->team->letter;
		}
		if (isset($draft['player_ids']) && is_array($draft['player_ids'])) {
			$ids = self::ids($draft['player_ids']);
		}
		if (isset($draft['captain_id']) && in_array((int) $draft['captain_id'], $ids, true)) {
			$captain_id = (int) $draft['captain_id'];
		}
		if (!in_array($captain_id, $ids, true)) {
			if ($can_manage) {
				$captain_id = 0; // organizer: the first player with Telegram, set below
			} else {
				array_unshift($ids, $captain_id);
			}
		}
		$own_team = $entry?->team->name;
		$members = [];
		foreach ($ids as $id) {
			$player = $this->players->find($id);
			if ($player === null) {
				continue;
			}
			$card = $this->card($player, $taken_names, $own_team, $can_manage);
			if ($card['in_team'] !== null && $entry === null && $id !== $actor->id) {
				continue; // took a place elsewhere since the draft was saved
			}
			$members[] = $card;
		}
		if ($can_manage && !in_array($captain_id, array_column($members, 'id'), true)) {
			$captain_id = 0;
			foreach ($members as $card) {
				if ($this->players->find($card['id'])?->hasTelegram()) {
					$captain_id = $card['id'];
					break;
				}
			}
			if ($captain_id === 0 && $members !== []) {
				$captain_id = $members[0]['id'];
			}
		}
		$pending = [];
		foreach (is_array($draft['pending'] ?? null) ? $draft['pending'] : [] as $item) {
			if (!is_array($item) || trim((string) ($item['label'] ?? '')) === '') {
				continue;
			}
			$suggestions = [];
			foreach (self::ids($item['suggestion_ids'] ?? []) as $sid) {
				$player = $this->players->find($sid);
				if ($player !== null && !in_array($sid, $ids, true)) {
					$suggestions[] = $this->card($player, $taken_names, $own_team, $can_manage);
				}
			}
			$pending[] = ['label' => trim((string) $item['label']), 'suggestions' => $suggestions];
		}
		$letters = [];
		$taken_letters = $this->team_repo->takenLetters($t->id);
		foreach (range('A', 'Z') as $l) {
			$owner_id = $taken_letters[$l] ?? null;
			$letters[] = [
				'letter' => $l,
				'taken' => $owner_id !== null && $owner_id !== $entry?->team->id,
			];
		}
		if (!empty($draft['letter_touched']) && $can_manage) {
			$letter = $draft['letter'] ?? null;
		}
		$readonly = $role === 'member';
		return [
			'ok' => true,
			'action' => self::ACTION,
			'mode' => $entry === null ? 'new' : 'edit',
			'team_name_saved' => $entry?->team->name,
			'role' => $role,
			'readonly' => $readonly,
			'actor_id' => $actor->id,
			'can_letter' => $can_manage,
			'can_transfer' => !$readonly && ($entry !== null || $can_manage),
			// Organizer/owner may remove anyone, themselves and the captain included.
			'can_remove_any' => !$readonly && $can_manage,
			'can_disband' => $entry !== null && !$readonly,
			'can_leave' => $readonly,
			'can_contacts' => !$readonly,
			'tournament' => [
				'id' => $t->id,
				'title' => $t->title,
				'subtitle' => $this->subtitle($t),
				'team_size' => $t->team_size,
				'max_points' => $t->hasLevelLimit() ? (int) $t->max_points : null,
			],
			'team' => [
				'id' => $entry?->team->id,
				'name' => $name,
				'letter' => $letter,
				'letter_touched' => !empty($draft['letter_touched']),
				'captain_id' => $captain_id,
				'members' => $members,
				'saved_points' => $entry !== null ? $this->teams->pointsOf($entry) : null,
			],
			'name_hint' => $name_hint ? self::NAME_HINT : null,
			'name_max' => TeamService::MAX_NAME_LENGTH,
			'letters' => $letters,
			'letter_hint' => self::LETTER_HINT,
			'pending' => $pending,
			'default_level' => Levels::DEFAULT,
		];
	}

	/**
		@return array<string, mixed>
	*/
	public function search(string $init_data, string $token, string $query): array {
		[$actor, , , $t, $entry] = $this->authorize($init_data, $token);
		$entry ??= $this->ownedTeamIfRegular($actor, $t);
		$taken_names = $this->team_repo->memberTeamNames($t->id);
		$query = trim($query);
		if ($query === '') {
			$found = $this->players->teamAddCandidates($actor->id, $t->id, self::SEARCH_LIMIT);
		} else {
			$found = $this->players->searchPartial($query, self::SEARCH_LIMIT);
			$seen = array_fill_keys(array_map(static fn (Player $p): int => $p->id, $found), true);
			if (count($found) < self::SEARCH_LIMIT && mb_strlen($query) >= 2) {
				foreach ($this->players->fuzzySuggest($query, self::SEARCH_LIMIT) as $p) {
					if (!isset($seen[$p->id])) {
						$seen[$p->id] = true;
						$found[] = $p;
					}
				}
			}
		}
		$can_manage = $this->access->canManageTournament($t, $actor->id);
		$out = [];
		foreach (array_slice($found, 0, self::SEARCH_LIMIT) as $player) {
			$out[] = $this->card($player, $taken_names, $entry?->team->name, $can_manage);
		}
		return ['ok' => true, 'players' => $out];
	}

	/**
		@param array<string, mixed> $input name, player_ids, captain_id, letter, letter_touched
		@return array<string, mixed>
	*/
	public function submit(string $init_data, string $token, array $input): array {
		[$actor, $ctx, $user, $t, $entry] = $this->authorize($init_data, $token);
		$ids = self::ids($input['player_ids'] ?? []);
		$captain_id = (int) ($input['captain_id'] ?? 0);
		$name = trim((string) ($input['name'] ?? ''));
		$letter_touched = !empty($input['letter_touched']);
		$letter = isset($input['letter']) && is_string($input['letter']) && $input['letter'] !== ''
			? strtoupper($input['letter'])
			: null;
		if ($name === '') {
			throw new WebAppFormError('Напиши название команды.', 'name');
		}
		$new_team_token = ($ctx['payload']['team_id'] ?? null) === null;
		$opening = WebAppContextRepository::openingId($input['opening_id'] ?? null);
		if ($new_team_token) {
			$replay_id = $this->createdOpening($ctx, $opening);
			if ($replay_id !== null) {
				return ['ok' => true, 'close' => true, 'team_id' => $replay_id];
			}
			if ($entry === null) {
				$entry = $this->ownedTeamIfRegular($actor, $t);
			}
		}
		$creating = $entry === null;
		if ($this->dialogs !== null) {
			$this->dialogs->begin($user, $ctx['chat_id'], 'private', '');
			$this->dialogs->user(sprintf(
				'[форма] %s «%s»: %s',
				$creating ? 'Новая команда' : 'Сохранить команду',
				$name,
				implode(', ', array_map(fn (int $id): string => $this->players->find($id)?->plainLabel() ?? ('#' . $id), $ids)),
			));
		}
		try {
			try {
				$saved = $this->contexts->transactional(function () use (
					$token, $actor, $t, $entry, $creating, $new_team_token, $name, $ids,
					$captain_id, $letter_touched, $letter, $opening,
				): TeamEntry {
					$locked = $this->contexts->lock($token, $this->clock->now());
					if ($locked === null) {
						throw new WebAppFormError(self::EXPIRED_TEXT, '', WebAppFormError::EXPIRED);
					}
					if ($creating) {
						$replay_id = $this->createdOpening($locked, $opening);
						if ($replay_id !== null) {
							return $this->teams->get($replay_id);
						}
					}
					$saved = $this->teams->saveForm(
						$actor->id,
						$t->id,
						$entry?->team->id,
						$name,
						$ids,
						$captain_id,
						$letter_touched,
						$letter,
					);
					$payload = $locked['payload'];
					if ($new_team_token) {
						// Keep team_id null so the same «➕ Добавить команду» button stays reusable.
						if ($creating && $opening !== null) {
							$payload['openings'] = is_array($payload['openings'] ?? null) ? $payload['openings'] : [];
							$payload['openings'][$opening] = $saved->team->id;
						}
						unset($payload['draft']);
						$this->contexts->updatePayload($token, $payload);
					} else {
						$this->contexts->updatePayload($token, [
							'tournament_id' => $t->id,
							'team_id' => $saved->team->id,
						]);
					}
					return $saved;
				});
			} catch (LevelLimitExceeded $e) {
				throw new WebAppFormError($e->getMessage(), 'roster');
			} catch (PermissionDenied $e) {
				throw new WebAppFormError($e->getMessage(), '', WebAppFormError::FORBIDDEN);
			} catch (WebAppFormError $e) {
				throw $e;
			} catch (DomainError $e) {
				throw new WebAppFormError($e->getMessage(), self::fieldFor($e->getMessage()));
			}
			$this->announcer->refresh($t->id);
			$t = $this->tournaments->get($t->id);
			$this->cards->send($ctx['chat_id'], $t, $saved, $actor);
		} finally {
			if ($this->dialogs !== null) {
				$this->dialogs->end();
			}
		}
		return ['ok' => true, 'close' => true, 'team_id' => $saved->team->id];
	}

	/**
		Save unsaved state, then offer the Telegram contacts picker in the private chat.

		@param array<string, mixed> $input same as submit
		@return array<string, mixed>
	*/
	public function contacts(string $init_data, string $token, array $input): array {
		[$actor, $ctx, , $t, $entry] = $this->authorize($init_data, $token);
		$entry ??= $this->ownedTeamIfRegular($actor, $t);
		if ($entry !== null && $this->role($actor, $t, $entry) === 'member') {
			throw new WebAppFormError(self::STRANGER_TEXT, '', WebAppFormError::FORBIDDEN);
		}
		$payload = $ctx['payload'];
		$ids = self::ids($input['player_ids'] ?? []);
		$payload['draft'] = [
			'name' => trim((string) ($input['name'] ?? '')),
			'player_ids' => $ids,
			'captain_id' => (int) ($input['captain_id'] ?? $actor->id),
			'letter' => $input['letter'] ?? null,
			'letter_touched' => !empty($input['letter_touched']),
			'pending' => is_array($payload['draft']['pending'] ?? null) ? $payload['draft']['pending'] : [],
		];
		if (isset($input['pending']) && is_array($input['pending'])) {
			$payload['draft']['pending'] = array_map(
				static fn ($label): array => ['label' => (string) $label, 'suggestion_ids' => []],
				array_slice(array_values(array_filter($input['pending'], 'is_string')), 0, self::MAX_PENDING),
			);
		}
		$this->contexts->updatePayload($token, $payload);
		$this->pending->clearChat($actor->id, $ctx['chat_id']);
		$this->pending->create(
			$actor->id,
			$ctx['chat_id'],
			['op' => self::CONTACTS_OP, 'token' => $token],
			$this->clock->now()->add(new DateInterval('PT' . self::CONTACTS_TTL_SECONDS . 'S')),
			$this->clock->now(),
		);
		$free = max(1, min(10, $t->team_size - count($ids)));
		$this->api->sendMessage($ctx['chat_id'], self::CONTACTS_TEXT, null, [
			'reply_markup' => [
				'keyboard' => [[[
					'text' => self::CONTACTS_BUTTON,
					'request_users' => [
						'request_id' => self::CONTACTS_REQUEST_ID,
						'user_is_bot' => false,
						'max_quantity' => $free,
						'request_name' => true,
						'request_username' => true,
					],
				]]],
				'resize_keyboard' => true,
				'one_time_keyboard' => true,
			],
		]);
		return ['ok' => true, 'close' => true];
	}

	/**
		users_shared with CONTACTS_REQUEST_ID: add the people to the saved form state and send
		a button back to the form. Nothing is saved to the team until the form is submitted.

		@param list<array<string, mixed>> $users
	*/
	public function handleUsersShared(int $chat_id, Player $actor, int $request_id, array $users, ?int $reply_to = null): bool {
		if ($request_id !== self::CONTACTS_REQUEST_ID) {
			return false;
		}
		$row = $this->pending->takeLatest($actor->id, $chat_id, $this->clock->now(), self::CONTACTS_OP);
		$token = is_array($row) ? (string) ($row['action']['token'] ?? '') : '';
		$ctx = $token !== '' ? $this->contexts->find($token, $this->clock->now()) : null;
		$remove = ['reply_markup' => ['remove_keyboard' => true]];
		if ($ctx === null || $ctx['action'] !== self::ACTION || $ctx['actor_player_id'] !== $actor->id) {
			$this->api->sendMessage($chat_id, self::EXPIRED_TEXT, $reply_to, $remove);
			return true;
		}
		$payload = $ctx['payload'];
		$draft = is_array($payload['draft'] ?? null) ? $payload['draft'] : ['player_ids' => [$actor->id]];
		$ids = self::ids($draft['player_ids'] ?? []);
		$names = [];
		foreach ($users as $u) {
			$tg_id = (int) ($u['id'] ?? 0);
			if ($tg_id <= 0) {
				continue;
			}
			$username = isset($u['username']) && is_string($u['username']) && $u['username'] !== '' ? $u['username'] : null;
			$display = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
			if ($display === '') {
				$display = $username ?? ('user' . $tg_id);
			}
			$player = $this->players->ensureByTelegram($tg_id, $username, $display, $this->clock->now());
			if (!in_array($player->id, $ids, true)) {
				$ids[] = $player->id;
				$names[] = $player->plainLabel();
			}
		}
		$draft['player_ids'] = $ids;
		$payload['draft'] = $draft;
		$this->contexts->updatePayload($token, $payload);
		$text = $names === []
			? 'Никого нового не выбрали.'
			: 'Добавила в форму: ' . implode(', ', $names) . '. Сохранится, когда нажмёшь кнопку в форме.';
		$this->api->sendMessage($chat_id, Html::e($text), $reply_to, $remove);
		$this->sendReopen($chat_id, $token, 'Вернись в форму и проверь состав:', null, $payload);
		return true;
	}

	/**
		@return array<string, mixed>
	*/
	public function leave(string $init_data, string $token): array {
		[$actor, $ctx, , $t, $entry] = $this->authorize($init_data, $token);
		$entry ??= $this->ownedTeamIfRegular($actor, $t);
		if ($entry === null) {
			throw new WebAppFormError(self::EXPIRED_TEXT, '', WebAppFormError::EXPIRED);
		}
		$captain_id = $entry->team->captain_id;
		try {
			$this->teams->leaveTeam($actor->id, $entry->team->id);
		} catch (DomainError $e) {
			throw new WebAppFormError($e->getMessage());
		}
		$this->contexts->delete($token);
		$this->announcer->refresh($t->id);
		$this->notifier->notifyMemberLeft($actor, $captain_id, $entry->team->name, $t->id);
		$this->after($ctx['chat_id'], $t, $actor, sprintf('Ты вышел из команды «%s».', $entry->team->name));
		return ['ok' => true, 'close' => true];
	}

	/**
		@return array<string, mixed>
	*/
	public function disband(string $init_data, string $token): array {
		[$actor, $ctx, , $t, $entry] = $this->authorize($init_data, $token);
		$entry ??= $this->ownedTeamIfRegular($actor, $t);
		if ($entry === null) {
			throw new WebAppFormError(self::EXPIRED_TEXT, '', WebAppFormError::EXPIRED);
		}
		try {
			$disband = $this->teams->withdrawTeam($actor->id, $entry->team->id);
		} catch (PermissionDenied $e) {
			throw new WebAppFormError($e->getMessage(), '', WebAppFormError::FORBIDDEN);
		} catch (DomainError $e) {
			throw new WebAppFormError($e->getMessage());
		}
		$this->contexts->delete($token);
		$this->notifier->notify($actor->id, $disband);
		$this->announcer->refresh($t->id);
		$this->after($ctx['chat_id'], $t, $actor, sprintf('Команда «%s» распущена и снята с турнира.', $entry->team->name));
		return ['ok' => true, 'close' => true];
	}

	/**
		captain | organizer | member | stranger. New team: organizer (if may manage) or captain.
	*/
	public function role(Player $actor, Tournament $t, ?TeamEntry $entry): string {
		if ($this->access->canManageTournament($t, $actor->id)) {
			return 'organizer';
		}
		if ($entry === null || $entry->team->captain_id === $actor->id) {
			return 'captain';
		}
		foreach ($entry->members as $m) {
			if ($m->player_id === $actor->id) {
				return 'member';
			}
		}
		return 'stranger';
	}

	/**
		@param array<string, mixed> $payload
	*/
	protected function sendReopen(int $chat_id, string $token, string $text, ?int $reply_to, array $payload): void {
		$url = WebAppUrl::teamForm($this->webhook_url, $token);
		if ($url === null) {
			$this->api->sendMessage($chat_id, self::UNAVAILABLE_TEXT, $reply_to);
			return;
		}
		$label = ($payload['team_id'] ?? null) === null ? self::NEW_BUTTON : self::REOPEN_BUTTON;
		$this->api->sendMessage($chat_id, $text, $reply_to, [
			'reply_markup' => ['inline_keyboard' => [[['text' => $label, 'web_app' => ['url' => $url]]]]],
		]);
	}

	protected function after(int $chat_id, Tournament $t, Player $actor, string $text): void {
		$this->api->sendMessage($chat_id, Html::e($text));
		if ($this->views !== null) {
			$this->views->tournament($chat_id, $this->tournaments->get($t->id), $actor);
		}
	}

	/**
		@param array<int, string> $taken_names player id => team name in this tournament
		@return array<string, mixed>
	*/
	protected function card(Player $player, array $taken_names, ?string $own_team, bool $can_manage = false): array {
		$scale_id = $this->balances->viewScaleId();
		$explicit = $this->balances->explicitLevel($scale_id, $player->id);
		$level = $explicit ?? Levels::DEFAULT;
		$team = $taken_names[$player->id] ?? null;
		if ($team !== null && $own_team !== null && $team === $own_team) {
			$team = null;
		}
		$name = $player->hasIgn() ? (string) $player->ign : ($player->display_name !== '' ? $player->display_name : ($player->username ?? ('#' . $player->id)));
		return [
			'id' => $player->id,
			'name' => $name,
			'username' => $player->username,
			'display_name' => $player->display_name,
			'level' => $level,
			'level_name' => Levels::name($level),
			'level_explicit' => $explicit !== null,
			'in_team' => $team,
			// Offline players: «сделать капитаном» only for the organizer/owner.
			'can_captain' => $player->hasTelegram() || $can_manage,
		];
	}

	/**
		«Кубок · Сб 23.11 20:00 МСК · по 3 игрока · уровень 11»
	*/
	public function subtitle(Tournament $t): string {
		$days = ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'];
		$msk = $t->starts_at->setTimezone(MoscowTime::zone());
		$parts = [
			$t->title,
			$days[(int) $msk->format('w')] . ' ' . $msk->format('d.m H:i') . ' МСК',
			'по ' . $t->team_size . ' ' . RuPlural::choose($t->team_size, ['игроку', 'игрока', 'игроков']),
		];
		if ($t->team_size === 1) {
			$parts[2] = 'по 1 игроку';
		}
		$parts[] = $t->hasLevelLimit() ? 'уровень ' . (int) $t->max_points : 'без лимита уровня';
		return implode(' · ', $parts);
	}

	/**
		A regular player who already has a team, tapping a leftover new-team button, gets that team
		(edit / leave), not a second create. Organizers keep a blank new-team form.
	*/
	protected function ownedTeamIfRegular(Player $actor, Tournament $t): ?TeamEntry {
		if ($this->access->canManageTournament($t, $actor->id)) {
			return null;
		}
		return $this->teams->teamOf($t->id, $actor->id);
	}

	/**
		Team already created by this form opening, if it is still there.
		@param array<string, mixed> $ctx
	*/
	protected function createdOpening(array $ctx, ?string $opening): ?int {
		if ($opening === null) {
			return null;
		}
		$openings = $ctx['payload']['openings'] ?? null;
		if (!is_array($openings) || !isset($openings[$opening])) {
			return null;
		}
		$id = (int) $openings[$opening];
		if ($id <= 0) {
			return null;
		}
		try {
			$this->teams->get($id);
			return $id;
		} catch (NotFound $e) {
			return null;
		}
	}

	/**
		@return array{0: Player, 1: array<string, mixed>, 2: TgUser, 3: Tournament, 4: ?TeamEntry}
	*/
	protected function authorize(string $init_data, string $token): array {
		$now = $this->clock->now();
		$this->contexts->purgeExpired($now);
		try {
			$parsed = WebAppInitData::parse($init_data, $this->bot_token, $now->getTimestamp());
		} catch (DomainError $e) {
			throw new WebAppFormError(self::EXPIRED_TEXT, '', WebAppFormError::EXPIRED);
		}
		$token = trim($token);
		if (preg_match('/^[a-f0-9]{32}$/D', $token) !== 1) {
			throw new WebAppFormError(self::EXPIRED_TEXT, '', WebAppFormError::EXPIRED);
		}
		$ctx = $this->contexts->find($token, $now);
		if ($ctx === null || $ctx['action'] !== self::ACTION) {
			throw new WebAppFormError(self::EXPIRED_TEXT, '', WebAppFormError::EXPIRED);
		}
		$actor = $this->players->find($ctx['actor_player_id']);
		if ($actor === null || $actor->tg_user_id === null || $actor->tg_user_id !== $parsed['user_id']) {
			throw new WebAppFormError('Эта форма открыта для другого человека.', '', WebAppFormError::FORBIDDEN);
		}
		$user = new TgUser(
			$parsed['user_id'],
			false,
			$parsed['username'] ?? $actor->username,
			$parsed['first_name'] !== '' ? $parsed['first_name'] : $actor->display_name,
		);
		try {
			$t = $this->tournaments->get((int) ($ctx['payload']['tournament_id'] ?? 0));
			$team_id = $ctx['payload']['team_id'] ?? null;
			$entry = $team_id === null ? null : $this->teams->get((int) $team_id);
		} catch (DomainError $e) {
			throw new WebAppFormError('Этой команды уже нет. Открой карточку турнира заново.', '', WebAppFormError::EXPIRED);
		}
		if (($entry !== null && $entry->team->tournament_id !== $t->id)
			|| $this->role($actor, $t, $entry) === 'stranger') {
			throw new WebAppFormError(self::STRANGER_TEXT, '', WebAppFormError::FORBIDDEN);
		}
		if (!in_array($t->status, [TournamentStatus::REGISTRATION, TournamentStatus::CLOSED], true)) {
			throw new WebAppFormError('Регистрация на этот турнир уже не меняется.', '', WebAppFormError::EXPIRED);
		}
		return [$actor, $ctx, $user, $t, $entry];
	}

	/**
		@param array<string, mixed> $payload
	*/
	protected function issue(Player $actor, int $chat_id, array $payload): string {
		$now = $this->clock->now();
		$this->contexts->purgeExpired($now);
		$token = bin2hex(random_bytes(16));
		$this->contexts->create(
			$token,
			$actor->id,
			$chat_id,
			self::ACTION,
			$payload,
			$now->add(new DateInterval('PT' . self::TTL_SECONDS . 'S')),
			$now,
		);
		return $token;
	}

	/**
		Which form field a domain error belongs to, so it shows next to it.
	*/
	protected static function fieldFor(string $message): string {
		$m = mb_strtolower($message);
		if (str_contains($m, 'назван')) {
			return 'name';
		}
		if (str_contains($m, 'букв')) {
			return 'letter';
		}
		return 'roster';
	}

	/**
		@return list<int>
	*/
	public static function ids(mixed $value): array {
		if (!is_array($value)) {
			return [];
		}
		$out = [];
		foreach ($value as $v) {
			if ((is_int($v) || (is_string($v) && ctype_digit($v))) && (int) $v > 0 && !in_array((int) $v, $out, true)) {
				$out[] = (int) $v;
			}
		}
		return $out;
	}
}
