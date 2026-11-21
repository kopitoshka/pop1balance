<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Exception\LevelLimitExceeded;
use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Team;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\TeamMember;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Repository\SoloPlayerRepository;
use P1Bot\Repository\TeamRepository;
use P1Bot\Repository\TournamentRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Database;
use P1Bot\Support\RuPlural;

/**
	Team and solo registration. A player is in at most one entry per tournament:
	one team OR the solo list. Adding a solo player to a team drops their solo entry.

	A team becomes 'registered' (and gets registered_at = queue position) when its roster
	reaches team_size; dropping below team_size returns it to 'forming' and clears registered_at.
*/
class TeamService {
	public const EMPTY_ROSTER = 'В команде должен быть хотя бы один игрок.';
	public const SELF_REMOVAL = 'Себя из состава не убрать. Чтобы выйти из команды, передай капитана '
		. 'и нажми «❌ Отменить регистрацию».';
	public const NO_TELEGRAM_CAPTAIN = '%s без Telegram — капитаном быть не может: я не смогу ему писать. '
		. 'Выбери капитаном того, кто есть в Telegram.';

	public const MAX_NAME_LENGTH = 64;

	public function __construct(
		protected TeamRepository $teams,
		protected SoloPlayerRepository $solos,
		protected TournamentRepository $tournaments,
		protected PlayerRepository $players,
		protected BalanceService $balance,
		protected AccessPolicy $access,
		protected Database $db,
		protected Clock $clock,
		protected int $registration_close_minutes = 20,
	) {
	}

	public function get(int $team_id): TeamEntry {
		$team = $this->teams->find($team_id) ?? throw new NotFound('Такой команды нет.');
		return new TeamEntry($team, $this->teams->members($team->id));
	}

	public function findByName(int $tournament_id, string $name): ?TeamEntry {
		$team = $this->teams->findByName($tournament_id, $name);
		return $team === null ? null : new TeamEntry($team, $this->teams->members($team->id));
	}

	/**
		Team the player is in, if any.
	*/
	public function teamOf(int $tournament_id, int $player_id): ?TeamEntry {
		$membership = $this->teams->findMembership($tournament_id, $player_id);
		return $membership === null ? null : $this->get($membership->team_id);
	}

	/**
		Creates a team; the author becomes captain and its first member.

		@param list<int> $player_ids other members (captain may be omitted)
	*/
	public function createTeam(int $tournament_id, int $captain_id, string $name, array $player_ids = []): TeamEntry {
		return $this->db->transactional(function () use ($tournament_id, $captain_id, $name, $player_ids): TeamEntry {
			$tournament = $this->openTournament($tournament_id, $captain_id);
			$name = $this->validName($tournament_id, $name, null);
			$this->assertCanCaptain($captain_id, $captain_id, $tournament);

			$roster = array_values(array_unique(array_merge([$captain_id], $player_ids)));
			if (count($roster) > $tournament->team_size) {
				throw new ValidationError(sprintf(
					'В команде этого турнира максимум %d %s, а указано %d.',
					$tournament->team_size,
					RuPlural::choose($tournament->team_size, ['игрок', 'игрока', 'игроков']),
					count($roster),
				));
			}

			$levels = [];
			$breakdown = [];
			foreach ($roster as $player_id) {
				$this->assertFreeForTeam($tournament, $player_id, null);
				[$level, $row] = $this->playerLevelRow($player_id);
				$levels[$player_id] = $level;
				$breakdown[] = $row;
			}
			$this->assertWithinLimit($tournament, Levels::teamPoints(array_values($levels)), $breakdown);

			$this->lockTournament($tournament->id);
			$team = $this->teams->create($tournament->id, $captain_id, $name, $this->clock->now(), $this->firstFreeLetter($tournament->id));
			foreach (array_keys($levels) as $player_id) {
				$this->solos->remove($tournament->id, $player_id);
				$this->teams->addMember($team, $player_id, $this->clock->now());
			}
			$this->syncStatus($team, $tournament);
			return $this->get($team->id);
		});
	}

	/**
		Organizer/owner creates a team with a chosen captain (actor is not auto-added).
		@param list<int> $player_ids other members besides captain
	*/
	public function createTeamAsOrganizer(
		int $actor_id,
		int $tournament_id,
		int $captain_id,
		string $name,
		array $player_ids = [],
	): TeamEntry {
		return $this->db->transactional(function () use ($actor_id, $tournament_id, $captain_id, $name, $player_ids): TeamEntry {
			$tournament = $this->tournaments->find($tournament_id) ?? throw new NotFound('Такого турнира нет.');
			$this->access->assertCanManageTournament($tournament, $actor_id);
			$tournament = $this->openTournament($tournament_id, $actor_id);
			$name = $this->validName($tournament_id, $name, null);
			$this->assertCanCaptain($captain_id, $actor_id, $tournament);
			$roster = array_values(array_unique(array_merge([$captain_id], $player_ids)));
			if (count($roster) > $tournament->team_size) {
				throw new ValidationError(sprintf(
					'В команде этого турнира максимум %d %s, а указано %d.',
					$tournament->team_size,
					RuPlural::choose($tournament->team_size, ['игрок', 'игрока', 'игроков']),
					count($roster),
				));
			}
			$levels = [];
			$breakdown = [];
			foreach ($roster as $player_id) {
				$this->assertFreeForTeam($tournament, $player_id, null);
				[$level, $row] = $this->playerLevelRow($player_id);
				$levels[$player_id] = $level;
				$breakdown[] = $row;
			}
			$this->assertWithinLimit($tournament, Levels::teamPoints(array_values($levels)), $breakdown);
			$this->lockTournament($tournament->id);
			$team = $this->teams->create($tournament->id, $captain_id, $name, $this->clock->now(), $this->firstFreeLetter($tournament->id));
			foreach (array_keys($levels) as $player_id) {
				$this->solos->remove($tournament->id, $player_id);
				$this->teams->addMember($team, $player_id, $this->clock->now());
			}
			$this->syncStatus($team, $tournament);
			return $this->get($team->id);
		});
	}

	public function addPlayer(int $actor_id, int $team_id, int $player_id): TeamEntry {
		return $this->db->transactional(function () use ($actor_id, $team_id, $player_id): TeamEntry {
			[$team, $tournament] = $this->captainContext($actor_id, $team_id, true);
			$members = $this->teams->members($team->id);
			if (count($members) >= $tournament->team_size) {
				throw new ValidationError(sprintf(
					'В команде «%s» уже %d из %d, мест нет.',
					$team->name,
					count($members),
					$tournament->team_size,
				));
			}
			$this->assertFreeForTeam($tournament, $player_id, $team->id);
			[$level, $row] = $this->playerLevelRow($player_id);
			$breakdown = $this->membersBreakdown($members);
			$breakdown[] = $row;
			$levels = [];
			foreach ($members as $member) {
				$levels[] = $this->liveLevel($member->player_id);
			}
			$levels[] = $level;
			$this->assertWithinLimit($tournament, Levels::teamPoints($levels), $breakdown);

			$this->solos->remove($tournament->id, $player_id);
			$this->teams->addMember($team, $player_id, $this->clock->now());
			$this->syncStatus($team, $tournament);
			return $this->get($team->id);
		});
	}

	/**
		Apply a complete form atomically. Validate the final roster, not intermediate additions,
		so a swap at the level limit and an unchanged full team keep their queue position.
		Only the current captain, organizer or owner may save. A new team's opener is captain.

		@param list<int> $player_ids the complete roster, including captain
	*/
	public function saveForm(
		int $actor_id,
		int $tournament_id,
		?int $team_id,
		string $name,
		array $player_ids,
		int $captain_id,
		bool $letter_touched = false,
		?string $letter = null,
	): TeamEntry {
		return $this->db->transactional(function () use (
			$actor_id, $tournament_id, $team_id, $name, $player_ids, $captain_id, $letter_touched, $letter,
		): TeamEntry {
			$this->lockTournament($tournament_id);
			$ids = array_values(array_unique($player_ids));
			if ($ids === []) {
				throw new ValidationError(self::EMPTY_ROSTER);
			}
			if ($team_id === null) {
				if ($captain_id === $actor_id && in_array($actor_id, $ids, true)) {
					$entry = $this->createTeam($tournament_id, $actor_id, $name, $ids);
				} else {
					// Organizer/owner may build a team for someone else (existing organizer path).
					$t = $this->tournaments->find($tournament_id) ?? throw new NotFound('Такого турнира нет.');
					if (!$this->access->canManageTournament($t, $actor_id)) {
						throw new PermissionDenied('Капитан новой команды — тот, кто открыл форму.');
					}
					if (!in_array($captain_id, $ids, true)) {
						$captain_id = $this->defaultCaptain($ids); // the first player with Telegram
					}
					$entry = $this->createTeamAsOrganizer(
						$actor_id,
						$tournament_id,
						$captain_id,
						$name,
						array_values(array_diff($ids, [$captain_id])),
					);
				}
				if ($letter_touched && $letter !== $entry->team->letter) {
					$t = $this->tournaments->find($tournament_id) ?? throw new NotFound('Такого турнира нет.');
					$this->applyLetter($t, $actor_id, $entry->team->id, $letter);
					$entry = $this->get($entry->team->id);
				}
				return $entry;
			}
			[$team, $t] = $this->captainContext($actor_id, $team_id, false);
			if ($team->tournament_id !== $tournament_id) {
				throw new PermissionDenied('Эта команда с другого турнира.');
			}
			if (!in_array($captain_id, $ids, true)) {
				if (!$this->access->canManageTournament($t, $actor_id)) {
					throw new ValidationError('Капитан должен быть в составе. Сначала выбери капитана.');
				}
				$captain_id = $this->defaultCaptain($ids); // the organizer removed the captain: the first player with Telegram takes over
			}
			if (count($ids) > $t->team_size) {
				throw new ValidationError(sprintf(
					'В команде этого турнира максимум %d %s, а указано %d.',
					$t->team_size,
					RuPlural::choose($t->team_size, ['игрок', 'игрока', 'игроков']),
					count($ids),
				));
			}
			$members = $this->teams->members($team->id);
			$old_ids = array_map(static fn (TeamMember $m): int => $m->player_id, $members);
			if (
				in_array($actor_id, $old_ids, true) && !in_array($actor_id, $ids, true)
				&& !$this->access->canManageTournament($t, $actor_id)
			) {
				// Regular players leave through «❌ Отменить регистрацию», not by editing themselves out.
				throw new ValidationError(self::SELF_REMOVAL);
			}
			$add = array_diff($ids, $old_ids);
			if ($add !== []) {
				$this->openTournament($tournament_id, $actor_id);
			}
			$levels = [];
			$breakdown = [];
			foreach ($ids as $id) {
				if (!in_array($id, $old_ids, true)) {
					$this->assertFreeForTeam($t, $id, $team->id);
				}
				[$level, $row] = $this->playerLevelRow($id);
				$levels[] = $level;
				$breakdown[] = $row;
			}
			$allow_upto = $this->pointsOfMembers($members);
			$this->assertWithinLimit($t, Levels::teamPoints($levels), $breakdown, $allow_upto);
			$name = $this->validName($tournament_id, $name, $team_id);
			if ($letter_touched && $letter !== $team->letter) {
				$this->applyLetter($t, $actor_id, $team_id, $letter);
			}
			$this->teams->rename($team_id, $name);
			foreach ($add as $id) {
				$this->solos->remove($tournament_id, $id);
				$this->teams->addMember($team, $id, $this->clock->now());
			}
			if ($captain_id !== $team->captain_id) {
				// Reuse the same domain access/membership checks as the existing captain flow.
				$this->transferCaptain($actor_id, $team_id, $captain_id);
			}
			foreach (array_diff($old_ids, $ids) as $id) {
				$this->teams->removeMember($team_id, $id);
			}
			$this->syncStatus($team, $t);
			return $this->get($team_id);
		});
	}

	/**
		Organizer/owner only. NULL clears the letter; the unique index guards races.
	*/
	protected function applyLetter(Tournament $t, int $actor_id, int $team_id, ?string $letter): void {
		if (!$this->access->canManageTournament($t, $actor_id)) {
			throw new PermissionDenied('Букву команды назначает только организатор.');
		}
		if ($letter !== null && preg_match('/^[A-Z]$/D', $letter) !== 1) {
			throw new ValidationError('Буква команды — от A до Z или без буквы.');
		}
		$taken = $this->teams->takenLetters($t->id);
		if ($letter !== null && isset($taken[$letter]) && $taken[$letter] !== $team_id) {
			throw new ValidationError('Буква ' . $letter . ' уже у другой команды.');
		}
		$this->teams->setLetter($team_id, $letter);
	}

	/**
		Serialize new-letter allocation and form saves per tournament on MySQL/MariaDB.
		SQLite already serializes writes. The unique index is the final protection.
	*/
	protected function lockTournament(int $tournament_id): void {
		if ($this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
			$this->db->fetchValue('SELECT id FROM tournaments WHERE id = :id FOR UPDATE', ['id' => $tournament_id]);
		}
	}

	protected function firstFreeLetter(int $tournament_id): ?string {
		$taken = $this->teams->takenLetters($tournament_id);
		foreach (range('A', 'Z') as $letter) {
			if (!isset($taken[$letter])) {
				return $letter;
			}
		}
		return null;
	}

	/**
		Captain removes a member. A registered team that is no longer full leaves the queue.
	*/
	public function removePlayer(int $actor_id, int $team_id, int $player_id): TeamEntry {
		return $this->db->transactional(function () use ($actor_id, $team_id, $player_id): TeamEntry {
			[$team, $tournament] = $this->captainContext($actor_id, $team_id, false);
			if ($player_id === $team->captain_id) {
				throw new ValidationError('Капитана нельзя убрать из команды. Передай капитанство или распусти команду.');
			}
			$this->assertMember($team, $player_id);
			$this->teams->removeMember($team->id, $player_id);
			$this->syncStatus($team, $tournament);
			return $this->get($team->id);
		});
	}

	/**
		Swaps one member for another in one step, keeping the team's place in the queue.
	*/
	public function replacePlayer(int $actor_id, int $team_id, int $out_player_id, int $in_player_id): TeamEntry {
		return $this->db->transactional(function () use (
			$actor_id,
			$team_id,
			$out_player_id,
			$in_player_id,
		): TeamEntry {
			[$team, $tournament] = $this->captainContext($actor_id, $team_id, true);
			if ($out_player_id === $team->captain_id) {
				throw new ValidationError('Капитана нельзя заменить. Сначала передай капитанство.');
			}
			$this->assertMember($team, $out_player_id);
			$this->assertFreeForTeam($tournament, $in_player_id, $team->id);

			$members = array_filter(
				$this->teams->members($team->id),
				static fn (TeamMember $m): bool => $m->player_id !== $out_player_id,
			);
			[$level, $row] = $this->playerLevelRow($in_player_id);
			$breakdown = $this->membersBreakdown(array_values($members));
			$breakdown[] = $row;
			$old_sum = $this->pointsOf($this->get($team->id));
			$levels = [];
			foreach ($members as $member) {
				$levels[] = $this->liveLevel($member->player_id);
			}
			$levels[] = $level;
			$this->assertWithinLimit($tournament, Levels::teamPoints($levels), $breakdown, $old_sum);

			$this->teams->removeMember($team->id, $out_player_id);
			$this->solos->remove($tournament->id, $in_player_id);
			$this->teams->addMember($team, $in_player_id, $this->clock->now());
			return $this->get($team->id);
		});
	}

	/**
		A non-captain member leaves the team on their own.
	*/
	public function leaveTeam(int $player_id, int $team_id): TeamEntry {
		return $this->db->transactional(function () use ($player_id, $team_id): TeamEntry {
			$team = $this->teams->find($team_id) ?? throw new NotFound('Такой команды нет.');
			$tournament = $this->changeableTournament($team->tournament_id, $player_id);
			if ($player_id === $team->captain_id) {
				throw new ValidationError('Капитан не может просто выйти: передай капитанство или распусти команду.');
			}
			$this->assertMember($team, $player_id);
			$this->teams->removeMember($team->id, $player_id);
			$this->syncStatus($team, $tournament);
			return $this->get($team->id);
		});
	}

	public function renameTeam(int $actor_id, int $team_id, string $name): TeamEntry {
		return $this->db->transactional(function () use ($actor_id, $team_id, $name): TeamEntry {
			[$team] = $this->captainContext($actor_id, $team_id, false);
			$this->teams->rename($team->id, $this->validName($team->tournament_id, $name, $team->id));
			return $this->get($team->id);
		});
	}

	public function transferCaptain(int $actor_id, int $team_id, int $new_captain_id): TeamEntry {
		return $this->db->transactional(function () use ($actor_id, $team_id, $new_captain_id): TeamEntry {
			[$team, $tournament] = $this->captainContext($actor_id, $team_id, false);
			if ($new_captain_id === $team->captain_id) {
				throw new ValidationError('Этот игрок уже капитан.');
			}
			$this->assertMember($team, $new_captain_id);
			$this->assertCanCaptain($new_captain_id, $actor_id, $tournament);
			$this->teams->setCaptain($team->id, $new_captain_id);
			return $this->get($team->id);
		});
	}

	/**
		Disbands the team (row deleted, members cascade). Captain, organizer or owner.
		@return array{team_name: string, tournament_id: int, tournament_title: string, member_ids: list<int>, by_captain: bool}
	*/
	public function withdrawTeam(int $actor_id, int $team_id): array {
		return $this->db->transactional(function () use ($actor_id, $team_id): array {
			$team = $this->teams->find($team_id) ?? throw new NotFound('Такой команды нет.');
			$tournament = $this->changeableTournament($team->tournament_id, $actor_id);
			if ($team->captain_id !== $actor_id && !$this->access->canManageTournament($tournament, $actor_id)) {
				throw new PermissionDenied(sprintf(
					'Распустить команду «%s» может только её капитан или организатор.',
					$team->name,
				));
			}
			$member_ids = [];
			foreach ($this->teams->members($team->id) as $member) {
				$member_ids[] = $member->player_id;
			}
			$this->teams->delete($team->id);
			return [
				'team_name' => $team->name,
				'tournament_id' => $tournament->id,
				'tournament_title' => $tournament->title,
				'member_ids' => $member_ids,
				'by_captain' => $team->captain_id === $actor_id,
			];
		});
	}

	/**
		"Хочу участвовать" without a team.
	*/
	public function joinSolo(int $tournament_id, int $player_id): void {
		$this->db->transactional(function () use ($tournament_id, $player_id): void {
			$tournament = $this->openTournament($tournament_id, $player_id);
			$team = $this->teamOf($tournament->id, $player_id);
			if ($team !== null) {
				throw new ValidationError(sprintf(
					'Ты уже в команде «%s». Чтобы записаться соло, сначала выйди из неё.',
					$team->team->name,
				));
			}
			if ($this->solos->find($tournament->id, $player_id) !== null) {
				throw new ValidationError('Ты уже записан соло на этот турнир.');
			}
			$this->solos->add($tournament->id, $player_id, $this->clock->now());
		});
	}

	public function leaveSolo(int $tournament_id, int $player_id): void {
		$this->changeableTournament($tournament_id, $player_id);
		if (!$this->solos->remove($tournament_id, $player_id)) {
			throw new NotFound('Ты не записан соло на этот турнир.');
		}
	}

	/**
		@return array{0: Team, 1: Tournament}
	*/
	/**
		Whether the actor may add a player now (captain or organizer/owner, lineup editable).
	*/
	public function canActorAddPlayer(int $actor_id, int $team_id): bool {
		try {
			$this->captainContext($actor_id, $team_id, true);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
		Whether the actor may remove a member now (captain or organizer/owner, lineup editable).
	*/
	public function canActorRemovePlayer(int $actor_id, int $team_id): bool {
		try {
			$this->captainContext($actor_id, $team_id, false);
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	protected function captainContext(int $actor_id, int $team_id, bool $needs_open_registration): array {
		$team = $this->teams->find($team_id) ?? throw new NotFound('Такой команды нет.');
		$tournament = $this->tournaments->find($team->tournament_id) ?? throw new NotFound('Такого турнира нет.');
		if ($team->captain_id !== $actor_id && !$this->access->canManageTournament($tournament, $actor_id)) {
			throw new PermissionDenied(sprintf(
				'Состав команды «%s» меняет только капитан или организатор.',
				$team->name,
			));
		}
		$tournament = $needs_open_registration
			? $this->openTournament($team->tournament_id, $actor_id)
			: $this->changeableTournament($team->tournament_id, $actor_id);
		return [$team, $tournament];
	}

	/**
		Join or grow a roster: registration open for everyone; when CLOSED, only the
		organizer/owner until the tournament starts (RUNNING).
	*/
	protected function openTournament(int $tournament_id, int $actor_id): Tournament {
		$tournament = $this->tournaments->find($tournament_id) ?? throw new NotFound('Такого турнира нет.');
		if ($tournament->isRegistrationOpen()) {
			$this->assertLineupEditable($tournament, $actor_id);
			return $tournament;
		}
		if (
			$tournament->status === TournamentStatus::CLOSED
			&& $this->access->canManageTournament($tournament, $actor_id)
		) {
			return $tournament;
		}
		throw new ValidationError(sprintf(
			'Регистрация на турнир «%s» сейчас не идёт (%s).',
			$tournament->title,
			TournamentStatus::label($tournament->status),
		));
	}

	/**
		Leaving, renaming and withdrawing are allowed until the tournament starts
		(and until the registration time lock for regular players).
	*/
	protected function changeableTournament(int $tournament_id, int $actor_id): Tournament {
		$tournament = $this->tournaments->find($tournament_id) ?? throw new NotFound('Такого турнира нет.');
		if (!in_array($tournament->status, [TournamentStatus::REGISTRATION, TournamentStatus::CLOSED], true)) {
			throw new ValidationError(sprintf(
				'Турнир «%s» %s, заявки уже не меняются.',
				$tournament->title,
				TournamentStatus::label($tournament->status),
			));
		}
		$this->assertLineupEditable($tournament, $actor_id);
		return $tournament;
	}

	/**
		REGISTRATION_CLOSE_MINUTES before start: regular players cannot change lineups.
		Organizer and owner still can (until the tournament is RUNNING).
	*/
	protected function assertLineupEditable(Tournament $tournament, int $actor_id): void {
		if ($this->registration_close_minutes <= 0) {
			return;
		}
		$closes_at = $tournament->starts_at->modify("-{$this->registration_close_minutes} minutes");
		if ($this->clock->now() < $closes_at) {
			return;
		}
		if ($this->access->canManageTournament($tournament, $actor_id)) {
			return;
		}
		throw new ValidationError(sprintf(
			'Регистрация закрылась за %d %s до старта — составы уже не меняются.',
			$this->registration_close_minutes,
			RuPlural::choose($this->registration_close_minutes, ['минуту', 'минуты', 'минут']),
		));
	}

	protected function assertFreeForTeam(Tournament $tournament, int $player_id, ?int $own_team_id): void {
		$player = $this->players->find($player_id) ?? throw new NotFound('Такого игрока я не знаю.');
		$membership = $this->teams->findMembership($tournament->id, $player_id);
		if ($membership === null) {
			return;
		}
		if ($membership->team_id === $own_team_id) {
			throw new ValidationError(sprintf('%s уже в этой команде.', $player->mention()));
		}
		$other = $this->teams->find($membership->team_id);
		throw new ValidationError(sprintf(
			'%s уже играет в команде «%s» на этом турнире.',
			$player->mention(),
			$other !== null ? $other->name : '?',
		));
	}

	/**
		Captains normally need a Telegram id (they get messages and open the form). The tournament
		organizer or the bot owner may still make a player without Telegram the captain.
	*/
	protected function assertCanCaptain(int $player_id, int $actor_id, Tournament $tournament): void {
		$player = $this->players->find($player_id) ?? throw new NotFound('Такого игрока я не знаю.');
		if ($player->hasTelegram()) {
			return;
		}
		if ($this->access->canManageTournament($tournament, $actor_id)) {
			return;
		}
		throw new ValidationError(sprintf(self::NO_TELEGRAM_CAPTAIN, $player->mention()));
	}

	/**
		First roster player with Telegram; the first player when nobody has it (an admin may then
		save that offline captain; a regular captain still gets NO_TELEGRAM_CAPTAIN).
		@param list<int> $ids
	*/
	protected function defaultCaptain(array $ids): int {
		foreach ($ids as $id) {
			if ($this->players->find($id)?->hasTelegram()) {
				return $id;
			}
		}
		return $ids[0];
	}

	protected function assertMember(Team $team, int $player_id): void {
		$membership = $this->teams->findMembership($team->tournament_id, $player_id);
		if ($membership === null || $membership->team_id !== $team->id) {
			throw new NotFound(sprintf('Этого игрока нет в команде «%s».', $team->name));
		}
	}

	/**
		An already over-limit team may keep its current (or lower) sum after the tournament
		level was lowered; making it worse is still refused.
		@param list<array{label: string, level: int, explicit: bool}> $breakdown
	*/
	protected function assertWithinLimit(Tournament $tournament, int $points, array $breakdown = [], ?int $allow_upto = null): void {
		if ($tournament->hasLevelLimit() && $points > (int) $tournament->max_points) {
			if ($allow_upto !== null && $points <= $allow_upto) {
				return;
			}
			throw new LevelLimitExceeded($points, (int) $tournament->max_points, $breakdown);
		}
	}

	/**
		Live profile level (same source as /level): default/view scale, unset = Levels::DEFAULT.

		@return array{0: int, 1: array{label: string, level: int, explicit: bool}}
	*/
	protected function playerLevelRow(int $player_id): array {
		$scale_id = $this->balance->viewScaleId();
		$explicit = $this->balance->explicitLevel($scale_id, $player_id);
		$level = $explicit ?? Levels::DEFAULT;
		$player = $this->players->find($player_id);
		$label = $player !== null ? $player->plainLabel() : ('#' . $player_id);
		return [$level, ['label' => $label, 'level' => $level, 'explicit' => $explicit !== null]];
	}

	public function liveLevel(int $player_id): int {
		return $this->playerLevelRow($player_id)[0];
	}

	/**
		Team points from live profile ratings (not a stored snapshot).
	*/
	public function pointsOf(TeamEntry $entry): int {
		return $this->pointsOfMembers($entry->members);
	}

	/**
		@param list<TeamMember> $members
	*/
	protected function pointsOfMembers(array $members): int {
		$levels = [];
		foreach ($members as $member) {
			$levels[] = $this->liveLevel($member->player_id);
		}
		return Levels::teamPoints($levels);
	}

	/**
		@param list<TeamMember> $members
		@return list<array{label: string, level: int, explicit: bool}>
	*/
	protected function membersBreakdown(array $members): array {
		$rows = [];
		foreach ($members as $member) {
			[, $row] = $this->playerLevelRow($member->player_id);
			$rows[] = $row;
		}
		return $rows;
	}

	protected function validName(int $tournament_id, string $name, ?int $except_team_id): string {
		$name = trim($name);
		if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new ValidationError(sprintf(
				'Название команды должно быть от 1 до %d символов.',
				self::MAX_NAME_LENGTH,
			));
		}
		if ($this->teams->findByName($tournament_id, $name, $except_team_id) !== null) {
			throw new ValidationError(sprintf(
				'Команда «%s» на этом турнире уже есть, придумай другое название.',
				$name,
			));
		}
		return $name;
	}

	/**
		Full roster => registered (keeps an existing registered_at); otherwise forming.
	*/
	protected function syncStatus(Team $team, Tournament $tournament): void {
		$fresh = $this->teams->find($team->id) ?? throw new NotFound('Такой команды нет.');
		$full = $this->teams->countMembers($team->id) >= $tournament->team_size;
		if ($full && !$fresh->isRegistered()) {
			$this->teams->setStatus($team->id, TeamStatus::REGISTERED, $this->clock->now());
		} elseif (!$full && $fresh->isRegistered()) {
			$this->teams->setStatus($team->id, TeamStatus::FORMING, null);
		}
	}

}
