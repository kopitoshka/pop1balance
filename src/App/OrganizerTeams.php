<?php

declare(strict_types=1);

namespace P1Bot\App;

use DateInterval;
use P1Bot\Domain\AccessPolicy;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TeamService;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Repository\PendingActionRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\TeamRepository;
use P1Bot\Support\Clock;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Html;

/**
	Organizer/owner private UI: list/create/manage teams on a tournament.
*/
class OrganizerTeams {
	public const REQUEST_CAPTAIN_ID = 3;
	public const PENDING_TTL = 300;

	public function __construct(
		protected TelegramApi $api,
		protected TournamentService $tournaments,
		protected TeamService $teams,
		protected TeamRepository $team_repo,
		protected PlayerRepository $players,
		protected AccessPolicy $access,
		protected PendingActionRepository $pending,
		protected Clock $clock,
		protected Announcer $announcer,
		protected TeamCards $cards,
		protected TeamDisbandNotifier $disband_notifier,
	) {
	}

	public function canManage(Tournament $t, Player $actor): bool {
		return in_array($t->status, [TournamentStatus::REGISTRATION, TournamentStatus::CLOSED], true)
			&& $this->access->canManageTournament($t, $actor->id);
	}

	public function sendList(int $chat_id, Tournament $t, Player $actor, ?int $reply_to = null): void {
		if (!$this->canManage($t, $actor)) {
			throw new DomainError('Команды турнира правит только организатор или главный админ.');
		}
		$lineup = $this->tournaments->lineup($t->id);
		$teams = $this->team_repo->listByTournament($t->id);
		$lines = ['👥 <b>Команды</b> · ' . Html::e($t->title) . ' · №' . $t->id];
		$rows = [];
		if ($teams === []) {
			$lines[] = '';
			$lines[] = 'Пока нет ни одной команды.';
		}
		foreach ($teams as $team) {
			$entry = $this->teams->get($team->id);
			$place = $lineup->placementOf($team->captain_id) ?? 'forming';
			$status = match ($place) {
				'main' => 'в сетке',
				'reserve' => 'резерв',
				default => 'собирается',
			};
			$pts = $t->hasLevelLimit()
				? sprintf(', %d/%d б.', $this->teams->pointsOf($entry), (int) $t->max_points)
				: '';
			$lines[] = sprintf(
				'• %s — %d/%d%s · %s',
				Html::e($team->name),
				$entry->size(),
				$t->team_size,
				$pts,
				$status,
			);
			$rows[] = [[
				'text' => mb_substr('🛡 ' . $team->name . ' · ' . $entry->size() . '/' . $t->team_size, 0, 64),
				'callback_data' => 'tcard:' . $team->id,
			]];
		}
		$rows[] = [['text' => '➕ Добавить команду', 'callback_data' => 'otnew:' . $t->id]];
		$this->api->sendMessage(
			$chat_id,
			implode("\n", $lines),
			$reply_to,
			['reply_markup' => ['inline_keyboard' => $rows]],
		);
	}

	public function startCreate(int $chat_id, Player $actor, int $tournament_id, ?int $reply_to = null): void {
		$t = $this->tournaments->get($tournament_id);
		if (!$this->canManage($t, $actor)) {
			throw new DomainError('Команды турнира правит только организатор или главный админ.');
		}
		$this->storePending($actor->id, $chat_id, ['op' => 'org_team_name', 'tournament_id' => $tournament_id]);
		$this->api->sendMessage($chat_id, 'Как назовём новую команду? Напиши название одним сообщением.', $reply_to);
	}

	public function startRename(int $chat_id, Player $actor, int $team_id, ?int $reply_to = null): void {
		$entry = $this->teams->get($team_id);
		$t = $this->tournaments->get($entry->team->tournament_id);
		if (!$this->canManage($t, $actor)) {
			throw new DomainError('Переименовать может организатор или главный админ.');
		}
		$this->storePending($actor->id, $chat_id, ['op' => 'org_rename', 'team_id' => $team_id]);
		$this->api->sendMessage(
			$chat_id,
			'Новое название для «' . Html::e($entry->team->name) . '»? Напиши одним сообщением.',
			$reply_to,
		);
	}

	public function offerCaptainPick(int $chat_id, Player $actor, int $team_id, ?int $reply_to = null): void {
		$entry = $this->teams->get($team_id);
		$t = $this->tournaments->get($entry->team->tournament_id);
		if (!$this->canManage($t, $actor)) {
			throw new DomainError('Сменить капитана может организатор или главный админ.');
		}
		$rows = [];
		foreach ($entry->members as $member) {
			if ($member->player_id === $entry->team->captain_id) {
				continue;
			}
			$p = $this->players->find($member->player_id);
			$label = $p !== null ? $p->plainLabel() : ('#' . $member->player_id);
			$rows[] = [[
				'text' => '👑 ' . mb_substr($label, 0, 60),
				'callback_data' => 'otsetc:' . $team_id . ':' . $member->player_id,
			]];
		}
		if ($rows === []) {
			throw new DomainError('Кроме капитана в команде никого нет — сначала добавь игроков.');
		}
		$this->api->sendMessage(
			$chat_id,
			'Кто будет новым капитаном «' . Html::e($entry->team->name) . '»?',
			$reply_to,
			['reply_markup' => ['inline_keyboard' => $rows]],
		);
	}

	public function confirmDelete(int $chat_id, Player $actor, int $team_id, ?int $reply_to = null): void {
		$entry = $this->teams->get($team_id);
		$t = $this->tournaments->get($entry->team->tournament_id);
		if (!$this->canManage($t, $actor)) {
			throw new DomainError('Удалить команду может организатор или главный админ.');
		}
		$this->api->sendMessage(
			$chat_id,
			'Точно удалить команду «' . Html::e($entry->team->name) . '»?',
			$reply_to,
			['reply_markup' => ['inline_keyboard' => [[
				['text' => 'Да, удалить', 'callback_data' => 'otdelo:' . $team_id],
				['text' => 'Нет', 'callback_data' => 'tcard:' . $team_id],
			]]]],
		);
	}

	public function deleteTeam(int $chat_id, Player $actor, int $team_id, ?int $reply_to = null): void {
		$entry = $this->teams->get($team_id);
		$t = $this->tournaments->get($entry->team->tournament_id);
		if (!$this->canManage($t, $actor)) {
			throw new DomainError('Удалить команду может организатор или главный админ.');
		}
		$disband = $this->teams->withdrawTeam($actor->id, $team_id);
		$this->disband_notifier->notify($actor->id, $disband);
		$this->announcer->refresh($t->id);
		$this->api->sendMessage(
			$chat_id,
			'Команда «' . Html::e($disband['team_name']) . '» удалена.',
			$reply_to,
		);
		$this->sendList($chat_id, $t, $actor);
	}

	/**
		Handle pending text for org_team_name / org_team_captain / org_rename.
	*/
	public function handlePendingText(int $chat_id, Player $actor, string $text, ?int $reply_to = null): bool {
		foreach (['org_team_name', 'org_team_captain', 'org_rename'] as $op) {
			$row = $this->pending->takeLatest($actor->id, $chat_id, $this->clock->now(), $op);
			if ($row === null) {
				continue;
			}
			return match ($op) {
				'org_team_name' => $this->onTeamName($chat_id, $actor, $row['action'], $text, $reply_to),
				'org_team_captain' => $this->onCaptainSearch($chat_id, $actor, $row['action'], $text, $reply_to),
				'org_rename' => $this->onRename($chat_id, $actor, $row['action'], $text, $reply_to),
			};
		}
		return false;
	}

	/**
		users_shared for new-team captain picker.
	*/
	public function handleUsersShared(int $chat_id, Player $actor, array $users, ?int $reply_to = null): bool {
		$row = $this->pending->takeLatest($actor->id, $chat_id, $this->clock->now(), 'org_team_captain');
		if ($row === null || $users === []) {
			return $row !== null;
		}
		$u = $users[0];
		$tg_id = (int) ($u['id'] ?? 0);
		$username = isset($u['username']) && is_string($u['username']) && $u['username'] !== ''
			? $u['username'] : null;
		$display = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
		if ($display === '') {
			$display = $username ?? ('user' . $tg_id);
		}
		$captain = $this->players->ensureByTelegram($tg_id, $username, $display, $this->clock->now());
		return $this->finishCreate($chat_id, $actor, $row['action'], $captain, $reply_to);
	}


	/**
		Inline pick of captain while org_team_captain pending holds the team name.
	*/
	public function pickCaptainFromPending(
		int $chat_id,
		Player $actor,
		int $tournament_id,
		int $captain_id,
		?int $reply_to = null,
	): void {
		$row = $this->pending->takeLatest($actor->id, $chat_id, $this->clock->now(), 'org_team_captain');
		if ($row === null) {
			throw new DomainError('Запрос на создание команды устарел — начни снова с «➕ Добавить команду».');
		}
		$action = $row['action'];
		if ((int) ($action['tournament_id'] ?? 0) !== $tournament_id) {
			throw new DomainError('Это предложение уже не актуально — начни создание команды заново.');
		}
		$captain = $this->players->find($captain_id) ?? throw new DomainError('Такого игрока я не знаю.');
		$this->finishCreate($chat_id, $actor, $action, $captain, $reply_to);
	}

	public function pickCaptain(int $chat_id, Player $actor, int $tournament_id, string $name, int $captain_id, ?int $reply_to = null): void {
		$captain = $this->players->find($captain_id) ?? throw new DomainError('Такого игрока я не знаю.');
		$this->finishCreate($chat_id, $actor, [
			'op' => 'org_team_captain',
			'tournament_id' => $tournament_id,
			'name' => $name,
		], $captain, $reply_to);
	}

	/**
		Organizer extras on a team card (rename / captain / delete / back).
		@return list<list<array<string, string>>>
	*/
	public function manageRows(Tournament $t, TeamEntry $entry, Player $actor): array {
		if (!$this->canManage($t, $actor)) {
			return [];
		}
		$rows = [];
		$rows[] = [['text' => '✏️ Переименовать', 'callback_data' => 'otren:' . $entry->team->id]];
		$rows[] = [['text' => '👑 Сменить капитана', 'callback_data' => 'otcap:' . $entry->team->id]];
		if ($entry->team->captain_id !== $actor->id) {
			$rows[] = [['text' => '🗑 Удалить команду', 'callback_data' => 'otdel:' . $entry->team->id]];
		}
		$rows[] = [['text' => '⬅️ К списку команд', 'callback_data' => 'oteams:' . $t->id]];
		return $rows;
	}

	protected function onTeamName(int $chat_id, Player $actor, array $action, string $text, ?int $reply_to): bool {
		$tournament_id = (int) ($action['tournament_id'] ?? 0);
		$t = $this->tournaments->get($tournament_id);
		if (!$this->canManage($t, $actor)) {
			$this->api->sendMessage($chat_id, 'Уже нельзя создавать команды на этот турнир.', $reply_to);
			return true;
		}
		$name = trim($text);
		if ($name === '') {
			$this->storePending($actor->id, $chat_id, $action);
			$this->api->sendMessage($chat_id, 'Название не должно быть пустым — напиши ещё раз.', $reply_to);
			return true;
		}
		$this->storePending($actor->id, $chat_id, [
			'op' => 'org_team_captain',
			'tournament_id' => $tournament_id,
			'name' => $name,
		]);
		$candidates = $this->players->teamAddCandidates($actor->id, $tournament_id, 8);
		$rows = [];
		foreach ($candidates as $player) {
			$rows[] = [[
				'text' => mb_substr($player->plainLabel(), 0, 64),
				'callback_data' => 'otcc:' . $tournament_id . ':' . $player->id,
			]];
		}
		$this->api->sendMessage(
			$chat_id,
			'Кто капитан команды «' . Html::e($name) . '»? Выбери, напиши часть ника или из Telegram:',
			$reply_to,
			$rows === [] ? [] : ['reply_markup' => ['inline_keyboard' => $rows]],
		);
		$this->api->sendMessage($chat_id, 'Можно выбрать капитана в Telegram:', null, [
			'reply_markup' => [
				'keyboard' => [[
					[
						'text' => '👤 Выбрать из Telegram',
						'request_users' => [
							'request_id' => self::REQUEST_CAPTAIN_ID,
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
		return true;
	}

	protected function onCaptainSearch(int $chat_id, Player $actor, array $action, string $text, ?int $reply_to): bool {
		$tournament_id = (int) ($action['tournament_id'] ?? 0);
		$name = (string) ($action['name'] ?? '');
		$this->storePending($actor->id, $chat_id, $action);
		$query = trim($text);
		if ($query === '') {
			$this->api->sendMessage($chat_id, 'Напиши часть ника капитана.', $reply_to);
			return true;
		}
		$taken = $this->players->playersOnTeamsOfTournament($tournament_id);
		$found = $this->players->searchPartial($query, 8, $taken);
		if ($found === []) {
			$this->api->sendMessage($chat_id, 'Никого не нашла по «' . Html::e($query) . '».', $reply_to);
			return true;
		}
		$rows = [];
		foreach ($found as $player) {
			$rows[] = [[
				'text' => mb_substr($player->plainLabel(), 0, 64),
				'callback_data' => 'otcc:' . $tournament_id . ':' . $player->id,
			]];
		}
		$this->api->sendMessage(
			$chat_id,
			'Капитан для «' . Html::e($name) . '» — выбери:',
			$reply_to,
			['reply_markup' => ['inline_keyboard' => $rows]],
		);
		return true;
	}

	protected function onRename(int $chat_id, Player $actor, array $action, string $text, ?int $reply_to): bool {
		$team_id = (int) ($action['team_id'] ?? 0);
		try {
			$entry = $this->teams->renameTeam($actor->id, $team_id, trim($text));
		} catch (DomainError $e) {
			$this->storePending($actor->id, $chat_id, $action);
			$this->api->sendMessage($chat_id, $e->getMessage(), $reply_to);
			return true;
		}
		$t = $this->tournaments->get($entry->team->tournament_id);
		$this->announcer->refresh($t->id);
		$this->api->sendMessage(
			$chat_id,
			'Переименовала в «' . Html::e($entry->team->name) . '».',
			$reply_to,
			['reply_markup' => ['remove_keyboard' => true]],
		);
		$this->cards->send($chat_id, $t, $entry, $actor);
		return true;
	}

	/**
		@param array<string, mixed> $action
	*/
	protected function finishCreate(
		int $chat_id,
		Player $actor,
		array $action,
		Player $captain,
		?int $reply_to,
	): bool {
		$tournament_id = (int) ($action['tournament_id'] ?? 0);
		$name = (string) ($action['name'] ?? '');
		try {
			$entry = $this->teams->createTeamAsOrganizer($actor->id, $tournament_id, $captain->id, $name, []);
		} catch (DomainError $e) {
			$this->api->sendMessage($chat_id, $e->getMessage(), $reply_to, [
				'reply_markup' => ['remove_keyboard' => true],
			]);
			return true;
		}
		$t = $this->tournaments->get($tournament_id);
		$this->announcer->refresh($t->id);
		$this->api->sendMessage(
			$chat_id,
			'Создала «' . Html::e($entry->team->name) . '», капитан ' . Html::e($captain->mention())
				. '. Добавь остальных игроков кнопками ниже.',
			$reply_to,
			['reply_markup' => ['remove_keyboard' => true]],
		);
		$this->cards->send($chat_id, $t, $entry, $actor);
		return true;
	}

	/**
		@param array<string, mixed> $action
	*/
	protected function storePending(int $actor_id, int $chat_id, array $action): void {
		$now = $this->clock->now();
		$this->pending->create(
			$actor_id,
			$chat_id,
			$action,
			$now->add(new DateInterval('PT' . self::PENDING_TTL . 'S')),
			$now,
		);
	}
}
