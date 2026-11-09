<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use DateTimeImmutable;
use P1Bot\Domain\Exception\InvalidLevel;
use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Repository\BalanceRepository;
use P1Bot\Repository\SoloPlayerRepository;
use P1Bot\Repository\TeamRepository;
use P1Bot\Repository\TournamentRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Database;

class TournamentService {
	public const MIN_TEAM_SIZE = 2;
	public const MAX_TEAM_SIZE = 4;

	public const REFERENCE_REQUIRED = 'Нужно указать уровень турнира: одним числом — сумму баллов на команду, '
		. 'или уровнями всех игроков команды, например «средний, средний, средний+».';

	public const LEVEL_LOCKED = 'Уровень турнира и размер команды уже не поменять: на турнир есть заявки, '
		. 'и их проверяли по текущим правилам. Название, время и лимит участников менять можно. '
		. 'Если нужен другой уровень — отмени этот турнир (/cancel с его номером) и создай новый (/new).';

	public function __construct(
		protected TournamentRepository $tournaments,
		protected TeamRepository $teams,
		protected SoloPlayerRepository $solos,
		protected BalanceRepository $balances,
		protected AccessPolicy $access,
		protected Database $db,
		protected Clock $clock,
		protected int $default_max_players = 24,
		protected int $registration_close_minutes = 20,
	) {
	}

	public function get(int $tournament_id): Tournament {
		return $this->tournaments->find($tournament_id) ?? throw new NotFound('Такого турнира нет.');
	}

	/**
		Creates a draft tournament; the author becomes the organizer.
	*/
	public function create(CreateTournament $input): Tournament {
		// Create permission is enforced by CreateAccess in handlers (shared group with owner).
		if ($input->balance_id !== null) {
			// Picking a scale is owner only for now; others always get the default scale.
			$this->access->assertOwner($input->organizer_id);
		}

		$title = $this->validTitle($input->title);
		$starts_at = $this->validStart($input->starts_at_msk);
		$team_size = $this->validTeamSize($input->team_size);
		[$balance_id, $reference, $max_points] = $this->levelLimit(
			$input->reference_team,
			$input->balance_id,
			$team_size,
		);
		$max_players = $this->validMaxPlayers($input->max_players ?? $this->default_max_players, $team_size);

		return $this->tournaments->create([
			'chat_id' => $input->chat_id,
			'organizer_id' => $input->organizer_id,
			'title' => $title,
			'starts_at' => $starts_at,
			'team_size' => $team_size,
			'balance_id' => $balance_id,
			'reference_team' => $reference,
			'max_points' => $max_points,
			'max_players' => $max_players,
			'status' => TournamentStatus::DRAFT,
		], $this->clock->now());
	}

	public function edit(int $actor_id, int $tournament_id, EditTournament $edit): Tournament {
		return $this->db->transactional(function () use ($actor_id, $tournament_id, $edit): Tournament {
			$t = $this->get($tournament_id);
			$this->access->assertCanManageTournament($t, $actor_id);
			$this->assertEditable($t);
			if ($edit->balance_id !== null) {
				// Changing the scale is owner only for now; other edits keep the tournament's scale.
				$this->access->assertOwner($actor_id);
			}

			$fields = [];
			if ($edit->title !== null) {
				$fields['title'] = $this->validTitle($edit->title);
			}
			if ($edit->starts_at_msk !== null) {
				$fields['starts_at'] = $this->validStart($edit->starts_at_msk);
				$fields['reminded_at'] = null;
			}

			$limit_touched = $edit->team_size !== null || $edit->reference_team !== null
				|| $edit->balance_id !== null || $edit->remove_level_limit;
			if ($limit_touched) {
				if ($this->hasEntries($t->id)) {
					throw new ValidationError(self::LEVEL_LOCKED);
				}
				$team_size = $this->validTeamSize($edit->team_size ?? $t->team_size);
				if ($edit->remove_level_limit) {
					throw new ValidationError('Турнир без уровня сделать нельзя. ' . self::REFERENCE_REQUIRED);
				}
				$level = $edit->reference_team;
				if ($level === null) {
					if ($team_size !== $t->team_size) {
						throw new InvalidLevel(sprintf(
							'Размер команды меняется на %d — укажи и уровень турнира под новый размер.',
							$team_size,
						));
					}
					// Only the scale changes: keep the current level.
					$level = $t->reference_team ?? ($t->max_points !== null ? (string) $t->max_points : null);
				}
				[$balance_id, $reference, $max_points] = $this->levelLimit(
					$level,
					$edit->balance_id ?? $t->balance_id,
					$team_size,
				);
				$fields += [
					'team_size' => $team_size,
					'balance_id' => $balance_id,
					'reference_team' => $reference,
					'max_points' => $max_points,
				];
			}

			if ($edit->max_players !== null || isset($fields['team_size'])) {
				$fields['max_players'] = $this->validMaxPlayers(
					$edit->max_players ?? $t->max_players,
					$fields['team_size'] ?? $t->team_size,
				);
			}

			if ($fields !== [] && $t->status === TournamentStatus::DRAFT) {
				// The changed draft must be shown again before it can be published.
				$fields['card_msg_id'] = null;
				$fields['card_shown_to'] = null;
			}
			$this->tournaments->update($t->id, $fields);
			return $this->get($t->id);
		});
	}

	/**
		Lowering the limit is allowed: whoever no longer fits simply becomes reserve.
	*/
	public function setMaxPlayers(int $actor_id, int $tournament_id, int $max_players): Tournament {
		return $this->edit($actor_id, $tournament_id, new EditTournament(max_players: $max_players));
	}

	public function openRegistration(int $actor_id, int $tournament_id): Tournament {
		return $this->transition(
			$actor_id,
			$tournament_id,
			[TournamentStatus::DRAFT, TournamentStatus::CLOSED],
			TournamentStatus::REGISTRATION,
		);
	}

	public function closeRegistration(int $actor_id, int $tournament_id): Tournament {
		return $this->transition($actor_id, $tournament_id, [TournamentStatus::REGISTRATION], TournamentStatus::CLOSED);
	}

	public function start(int $actor_id, int $tournament_id): Tournament {
		return $this->transition(
			$actor_id,
			$tournament_id,
			[TournamentStatus::REGISTRATION, TournamentStatus::CLOSED],
			TournamentStatus::RUNNING,
		);
	}

	public function finish(int $actor_id, int $tournament_id): Tournament {
		return $this->transition($actor_id, $tournament_id, [TournamentStatus::RUNNING], TournamentStatus::FINISHED);
	}

	public function cancel(int $actor_id, int $tournament_id): Tournament {
		return $this->transition(
			$actor_id,
			$tournament_id,
			[
				TournamentStatus::DRAFT,
				TournamentStatus::REGISTRATION,
				TournamentStatus::CLOSED,
				TournamentStatus::RUNNING,
			],
			TournamentStatus::CANCELLED,
		);
	}

	/**
		Automatic status changes made by cron (no actor, no permission check).
		Closes registration REGISTRATION_CLOSE_MINUTES before start, then registration/closed → running
		at starts_at, then running → finished after $finish_after_hours.

		@return list<Tournament> tournaments whose status changed
	*/
	public function autoAdvance(int $finish_after_hours, ?int $registration_close_minutes = null): array {
		$now = $this->clock->now();
		$changed = [];
		$past = new DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('UTC'));
		$close_minutes = $registration_close_minutes ?? $this->registration_close_minutes;
		if ($close_minutes > 0) {
			$close_until = $now->modify("+{$close_minutes} minutes");
			foreach ($this->tournaments->listStartingBetween(
				[TournamentStatus::REGISTRATION],
				$past,
				$close_until,
			) as $t) {
				// Past the start → RUNNING below; only lock the ones still upcoming.
				if ($t->starts_at > $now) {
					$this->tournaments->update($t->id, ['status' => TournamentStatus::CLOSED]);
					$changed[] = $this->get($t->id);
				}
			}
		}
		foreach ($this->tournaments->listStartingBetween(
			[TournamentStatus::REGISTRATION, TournamentStatus::CLOSED],
			$past,
			$now,
		) as $t) {
			$this->tournaments->update($t->id, ['status' => TournamentStatus::RUNNING]);
			$changed[] = $this->get($t->id);
		}
		$finish_before = $now->modify("-{$finish_after_hours} hours");
		foreach ($this->tournaments->listStartingBetween([TournamentStatus::RUNNING], $past, $finish_before) as $t) {
			$this->tournaments->update($t->id, ['status' => TournamentStatus::FINISHED]);
			$changed[] = $this->get($t->id);
		}
		return $changed;
	}

	/**
		True when now is within REGISTRATION_CLOSE_MINUTES of starts_at (players may not change lineups).
	*/
	public function isRegistrationTimeLocked(Tournament $t): bool {
		if ($this->registration_close_minutes <= 0) {
			return false;
		}
		$closes_at = $t->starts_at->modify("-{$this->registration_close_minutes} minutes");
		return $this->clock->now() >= $closes_at;
	}

	/**
		Registration is open for regular players (status + time lock).
	*/
	public function isUserRegistrationOpen(Tournament $t): bool {
		return $t->status === TournamentStatus::REGISTRATION && !$this->isRegistrationTimeLocked($t);
	}

	public function registrationCloseMinutes(): int {
		return $this->registration_close_minutes;
	}

	/**
		Upcoming tournaments that are due for a reminder: for the smallest offset whose moment
		has passed and that was not reminded at or after that moment. Returns [tournament, minutes].

		@param list<int> $offsets_minutes e.g. [60, 15]
		@return list<array{0: Tournament, 1: int}>
	*/
	public function dueReminders(array $offsets_minutes): array {
		if ($offsets_minutes === []) {
			return [];
		}
		rsort($offsets_minutes);
		$now = $this->clock->now();
		$result = [];
		$upcoming = $this->tournaments->listStartingBetween(
			[TournamentStatus::REGISTRATION, TournamentStatus::CLOSED],
			$now,
			$now->modify('+' . $offsets_minutes[0] . ' minutes'),
		);
		foreach ($upcoming as $t) {
			$due = null;
			foreach ($offsets_minutes as $minutes) {
				$moment = $t->starts_at->modify("-{$minutes} minutes");
				if ($now >= $moment && ($t->reminded_at === null || $t->reminded_at < $moment)) {
					$due = $minutes;
				}
			}
			if ($due !== null) {
				$result[] = [$t, $due];
			}
		}
		return $result;
	}

	/**
		Remembers the confirmation card shown for the current version of a draft.
	*/
	public function assertCanManage(int $actor_id, int $tournament_id): void {
		$this->access->assertCanManageTournament($this->get($tournament_id), $actor_id);
	}

	/**
		Remembers the shown draft card and who it was shown to (they may confirm it).
	*/
	public function setCardMessage(int $tournament_id, ?int $message_id, ?int $shown_to = null): void {
		$this->tournaments->update(
			$tournament_id,
			['card_msg_id' => $message_id, 'card_shown_to' => $message_id !== null ? $shown_to : null],
		);
	}

	public function findByCardMessage(int $chat_id, int $message_id): ?Tournament {
		return $this->tournaments->findByCardMessage($chat_id, $message_id);
	}

	public function findByPrivateCard(int $message_id, int $player_id): ?Tournament {
		return $this->tournaments->findByPrivateCard($message_id, $player_id);
	}

	public function findByAnnounceMessage(int $chat_id, int $message_id): ?Tournament {
		return $this->tournaments->findByAnnounceMessage($chat_id, $message_id);
	}

	public function setAnnounceMessage(int $tournament_id, int $message_id): void {
		$this->tournaments->update($tournament_id, ['announce_msg_id' => $message_id]);
	}

	public function markReminded(int $tournament_id): void {
		$this->tournaments->update($tournament_id, ['reminded_at' => $this->clock->now()]);
	}

	/**
		@return list<Tournament> registration / closed / running, soonest first (global)
	*/
	public function listAllActive(): array {
		return $this->tournaments->listByStatuses(TournamentStatus::active());
	}

	/**
		@param list<string> $statuses
		@return list<Tournament>
	*/
	public function listAllByStatus(array $statuses): array {
		return $this->tournaments->listByStatuses($statuses);
	}

	/**
		Kept for callers that still pass a chat; tournaments are global so this equals listAllActive.
		@return list<Tournament>
	*/
	public function listActive(int $chat_id): array {
		unset($chat_id);
		return $this->listAllActive();
	}

	/**
		@param list<string> $statuses
		@return list<Tournament>
	*/
	public function listByStatus(int $chat_id, array $statuses): array {
		unset($chat_id);
		return $this->listAllByStatus($statuses);
	}

	public function lineup(int $tournament_id): Lineup {
		$t = $this->get($tournament_id);
		$registered = array_map(
			fn ($team): TeamEntry => new TeamEntry($team, $this->teams->members($team->id)),
			$this->teams->listRegistered($t->id),
		);
		$forming = [];
		foreach ($this->teams->listByTournament($t->id) as $team) {
			if (!$team->isRegistered()) {
				$forming[] = new TeamEntry($team, $this->teams->members($team->id));
			}
		}
		return Lineup::compute($t->max_players, $registered, $this->solos->listByTournament($t->id), $forming);
	}

	/**
		@param list<string> $from
	*/
	protected function transition(int $actor_id, int $tournament_id, array $from, string $to): Tournament {
		$t = $this->get($tournament_id);
		$this->access->assertCanManageTournament($t, $actor_id);
		if (!in_array($t->status, $from, true)) {
			throw new ValidationError(sprintf(
				'Турнир «%s» сейчас в статусе «%s», это действие недоступно.',
				$t->title,
				TournamentStatus::label($t->status),
			));
		}
		$this->tournaments->update($t->id, ['status' => $to]);
		return $this->get($t->id);
	}

	protected function assertEditable(Tournament $t): void {
		if (in_array($t->status, [TournamentStatus::FINISHED, TournamentStatus::CANCELLED], true)) {
			throw new ValidationError(sprintf(
				'Турнир «%s» %s, менять его нельзя.',
				$t->title,
				TournamentStatus::label($t->status),
			));
		}
	}

	protected function hasEntries(int $tournament_id): bool {
		return $this->teams->listByTournament($tournament_id) !== [] || $this->solos->listByTournament($tournament_id) !== [];
	}

	protected function validTitle(string $title): string {
		$title = trim($title);
		if ($title === '' || mb_strlen($title) > 255) {
			throw new ValidationError('Название турнира должно быть от 1 до 255 символов.');
		}
		return $title;
	}

	protected function validStart(string $starts_at_msk): DateTimeImmutable {
		$starts_at = MoscowTime::parse($starts_at_msk);
		if ($starts_at <= $this->clock->now()) {
			throw new ValidationError('Время турнира уже прошло. Укажи будущее время по МСК.');
		}
		return $starts_at;
	}

	protected function validTeamSize(int $team_size): int {
		if ($team_size < self::MIN_TEAM_SIZE || $team_size > self::MAX_TEAM_SIZE) {
			throw new ValidationError(sprintf(
				'В команде может быть от %d до %d игроков.',
				self::MIN_TEAM_SIZE,
				self::MAX_TEAM_SIZE,
			));
		}
		return $team_size;
	}

	protected function validMaxPlayers(int $max_players, int $team_size): int {
		if ($max_players < $team_size || $max_players > 1000) {
			throw new ValidationError(sprintf('Лимит участников должен быть от %d до 1000.', $team_size));
		}
		return $max_players;
	}

	/**
		Tournament level => [balance_id, canonical levels list or null for a plain total, max_points].
		The level is required: tournaments without it can no longer be created (old rows may have NULL).
		Without an explicit scale the default scale is used.

		@return array{0: ?int, 1: ?string, 2: ?int}
	*/
	protected function levelLimit(?string $level, ?int $balance_id, int $team_size): array {
		if ($level === null || trim($level) === '') {
			throw new InvalidLevel(self::REFERENCE_REQUIRED);
		}
		[$levels, $max_points] = Levels::parseTournamentLevel($level, $team_size);
		$balance = $balance_id !== null ? $this->balances->find($balance_id) : $this->balances->findDefault();
		if ($balance === null) {
			throw new NotFound($balance_id !== null ? 'Такой шкалы баланса нет.' : 'Уровни игроков ещё не настроены, поэтому турнир пока не создать. Напиши главному админу.');
		}
		return [$balance->id, $levels !== null ? Levels::formatReferenceTeam($levels) : null, $max_points];
	}
}
