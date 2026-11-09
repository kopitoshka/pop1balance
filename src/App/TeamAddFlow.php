<?php

declare(strict_types=1);

namespace P1Bot\App;

use DateInterval;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Repository\PendingActionRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Html;

/**
	«Добавить игрока» on a team card: candidate buttons, Telegram picker, short text search.
*/
class TeamAddFlow {
	public const REQUEST_USERS_ID = 2;
	public const SEARCH_TTL_SECONDS = 300;
	public const CANDIDATE_LIMIT = 8;

	public function __construct(
		protected TelegramApi $api,
		protected TournamentService $tournaments,
		protected TeamService $teams,
		protected PlayerRepository $players,
		protected PendingActionRepository $pending,
		protected Clock $clock,
		protected Announcer $announcer,
		protected TeamCards $cards,
	) {
	}

	/**
		Show candidate keyboard after «➕ Добавить игрока» (or AI with no nick).
	*/
	public function offer(int $chat_id, Player $actor, int $team_id, ?int $reply_to = null): void {
		$entry = $this->teams->get($team_id);
		$t = $this->tournaments->get($entry->team->tournament_id);
		if (!$this->teams->canActorAddPlayer($actor->id, $team_id)) {
			throw new DomainError('Сейчас нельзя добавить игрока в эту команду.');
		}
		if ($entry->size() >= $t->team_size) {
			throw new DomainError(sprintf(
				'В команде «%s» уже нет мест (%d/%d).',
				$entry->team->name,
				$entry->size(),
				$t->team_size,
			));
		}
		$now = $this->clock->now();
		$this->pending->clearChat($actor->id, $chat_id);
		$this->pending->create(
			$actor->id,
			$chat_id,
			['op' => 'team_add', 'team_id' => $team_id],
			$now->add(new DateInterval('PT' . self::SEARCH_TTL_SECONDS . 'S')),
			$now,
		);
		$candidates = $this->players->teamAddCandidates($actor->id, $t->id, self::CANDIDATE_LIMIT);
		$rows = [];
		foreach ($candidates as $player) {
			$rows[] = [[
				'text' => $this->buttonLabel($player),
				'callback_data' => 'tadd:' . $team_id . ':' . $player->id,
			]];
		}
		$text = 'Кого добавить в «' . Html::e($entry->team->name) . '»?'
			. ($candidates === [] ? '' : "\nВыбери из тех, с кем уже играли, или из общих групп:")
			. "\n<i>или напиши часть ника</i>";
		$extra = [
			'reply_markup' => [
				'inline_keyboard' => PendingTextGuard::withCancel($rows),
			],
		];
		// Reply keyboard with Telegram user picker under the message (separate send).
		$this->api->sendMessage($chat_id, $text, $reply_to, $extra);
		$this->api->sendMessage($chat_id, 'Можно выбрать прямо из Telegram:', null, [
			'reply_markup' => [
				'keyboard' => [[
					[
						'text' => '👤 Выбрать из Telegram',
						'request_users' => [
							'request_id' => self::REQUEST_USERS_ID,
							'user_is_bot' => false,
							'max_quantity' => 1,
							'request_name' => true,
							'request_username' => true,
						],
					],
				]],
				'resize_keyboard' => true,
				'one_time_keyboard' => true,
			],
		]);
	}

	/** Whether a team_add search pending is waiting (does not consume). */
	public function hasPending(int $actor_id, int $chat_id): bool {
		return $this->pending->peekLatest($actor_id, $chat_id, $this->clock->now(), 'team_add') !== null;
	}

	/**
		Partial-match search after the offer (pending team_add).
		@return bool true if pending was consumed (even on empty results)
	*/
	public function handleSearchText(int $chat_id, Player $actor, string $text, ?int $reply_to = null): bool {
		$row = $this->pending->takeLatest($actor->id, $chat_id, $this->clock->now(), 'team_add');
		if ($row === null) {
			return false;
		}
		$team_id = (int) ($row['action']['team_id'] ?? 0);
		if ($team_id <= 0 || !$this->teams->canActorAddPlayer($actor->id, $team_id)) {
			$this->api->sendMessage($chat_id, 'Добавление в команду уже недоступно.', $reply_to);
			return true;
		}
		$query = trim($text);
		if ($query === '' || mb_strlen($query) > 64) {
			$this->restoreTeamAddPending($actor->id, $chat_id, $team_id);
			$this->api->sendMessage(
				$chat_id,
				'Напиши часть ника (или @username).',
				$reply_to,
				['reply_markup' => ['inline_keyboard' => PendingTextGuard::withCancel()]],
			);
			return true;
		}
		$this->restoreTeamAddPending($actor->id, $chat_id, $team_id);
		$found = $this->players->searchPartial($query, 8, $this->takenPlayerIds($team_id));
		if ($found === []) {
			$this->api->sendMessage(
				$chat_id,
				'Никого не нашла по «' . Html::e($query) . '». Попробуй иначе или выбери из Telegram.',
				$reply_to,
				['reply_markup' => ['inline_keyboard' => PendingTextGuard::withCancel()]],
			);
			return true;
		}
		$rows = [];
		foreach ($found as $player) {
			$rows[] = [[
				'text' => $this->buttonLabel($player),
				'callback_data' => 'tadd:' . $team_id . ':' . $player->id,
			]];
		}
		$this->api->sendMessage(
			$chat_id,
			'Нашла по «' . Html::e($query) . '» — выбери:',
			$reply_to,
			['reply_markup' => ['inline_keyboard' => PendingTextGuard::withCancel($rows)]],
		);
		return true;
	}

	/**
		Add a known player from a candidate button; refresh the team card.
	*/
	public function addKnown(int $chat_id, Player $actor, int $team_id, int $player_id, ?int $reply_to = null): string {
		$this->pending->clearChat($actor->id, $chat_id);
		$entry = $this->teams->addPlayer($actor->id, $team_id, $player_id);
		$t = $this->tournaments->get($entry->team->tournament_id);
		$this->announcer->refresh($t->id);
		$added = $this->players->find($player_id);
		$label = $added !== null ? $added->mention() : ('#' . $player_id);
		$this->api->sendMessage(
			$chat_id,
			'Добавила ' . Html::e($label) . ' в «' . Html::e($entry->team->name) . '».',
			$reply_to,
			['reply_markup' => ['remove_keyboard' => true]],
		);
		$this->cards->send($chat_id, $t, $entry, $actor);
		return 'ok';
	}

	/**
		users_shared after the team-add picker (request_id = REQUEST_USERS_ID).
		@return bool true when this flow handled the update
	*/
	public function handleUsersShared(int $chat_id, Player $actor, array $users, ?int $reply_to = null): bool {
		$row = $this->pending->takeLatest($actor->id, $chat_id, $this->clock->now(), 'team_add');
		if ($row === null) {
			return false;
		}
		$team_id = (int) ($row['action']['team_id'] ?? 0);
		if ($team_id <= 0) {
			return false;
		}
		$remove = ['reply_markup' => ['remove_keyboard' => true]];
		if ($users === []) {
			$this->api->sendMessage($chat_id, 'Никого не выбрали.', $reply_to, $remove);
			return true;
		}
		$u = $users[0];
		$tg_id = (int) ($u['id'] ?? 0);
		$username = isset($u['username']) && is_string($u['username']) && $u['username'] !== ''
			? $u['username']
			: null;
		$display = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
		if ($display === '') {
			$display = $username ?? ('user' . $tg_id);
		}
		$player = $this->players->ensureByTelegram($tg_id, $username, $display, $this->clock->now());
		try {
			$this->addKnown($chat_id, $actor, $team_id, $player->id, $reply_to);
		} catch (DomainError $e) {
			$this->api->sendMessage($chat_id, $e->getMessage(), $reply_to, $remove);
		}
		return true;
	}

	/**
		Markup-only candidate list for AI «добавь в мою команду» without a nick.
		@return array<string, mixed>
	*/
	public function suggestMarkup(Player $actor, int $team_id, int $chat_id): array {
		$this->restoreTeamAddPending($actor->id, $chat_id, $team_id);
		$entry = $this->teams->get($team_id);
		$t = $this->tournaments->get($entry->team->tournament_id);
		$candidates = $this->players->teamAddCandidates($actor->id, $t->id, self::CANDIDATE_LIMIT);
		$rows = [];
		foreach ($candidates as $player) {
			$rows[] = [[
				'text' => $this->buttonLabel($player),
				'callback_data' => 'tadd:' . $team_id . ':' . $player->id,
			]];
		}
		return ['reply_markup' => ['inline_keyboard' => PendingTextGuard::withCancel($rows)]];
	}

	protected function restoreTeamAddPending(int $actor_id, int $chat_id, int $team_id): void {
		$now = $this->clock->now();
		$this->pending->clearChat($actor_id, $chat_id);
		$this->pending->create(
			$actor_id,
			$chat_id,
			['op' => 'team_add', 'team_id' => $team_id],
			$now->add(new DateInterval('PT' . self::SEARCH_TTL_SECONDS . 'S')),
			$now,
		);
	}

	protected function buttonLabel(Player $player): string {
		if ($player->hasIgn()) {
			$base = (string) $player->ign;
			if ($player->username !== null) {
				return mb_substr($base . ' @' . $player->username, 0, 64);
			}
			return mb_substr($base, 0, 64);
		}
		return mb_substr($player->mention(), 0, 64);
	}

	/**
		@param list<int> already excluded inside searchPartial via taken ids
		@return list<int>
	*/
	protected function takenPlayerIds(int $team_id): array {
		$entry = $this->teams->get($team_id);
		$t = $this->tournaments->get($entry->team->tournament_id);
		$ids = [];
		foreach ($this->players->playersOnTeamsOfTournament($t->id) as $id) {
			$ids[] = $id;
		}
		return $ids;
	}
}
