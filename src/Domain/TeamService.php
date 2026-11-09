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

			$roster = array_values(array_unique(array_merge([$captain_id], $player_ids)));
			if (count($roster) > $tournament->team_size) {
				throw new ValidationError(sprintf(
					'В команде этого турнира максимум %d игрока(ов), а указано %d.',
					$tournament->team_size,
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
			$this->assertWithinLimit($tournament, array_sum($levels), $breakdown);

			$team = $this->teams->create($tournament->id, $captain_id, $name, $this->clock->now());
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
					'В команде «%s» уже %d из %d игроков, мест нет.',
					$team->name,
					count($members),
					$tournament->team_size,
				));
			}
			$this->assertFreeForTeam($tournament, $player_id, $team->id);
			[$level, $row] = $this->playerLevelRow($player_id);
			$breakdown = $this->membersBreakdown($members);
			$breakdown[] = $row;
			$this->assertWithinLimit($tournament, $this->pointsOfMembers($members) + $level, $breakdown);

			$this->solos->remove($tournament->id, $player_id);
			$this->teams->addMember($team, $player_id, $this->clock->now());
			$this->syncStatus($team, $tournament);
			return $this->get($team->id);
		});
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
			$this->assertWithinLimit($tournament, $this->pointsOfMembers(array_values($members)) + $level, $breakdown);

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
			[$team] = $this->captainContext($actor_id, $team_id, false);
			if ($new_captain_id === $team->captain_id) {
				throw new ValidationError('Этот игрок уже капитан.');
			}
			$this->assertMember($team, $new_captain_id);
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

	protected function assertMember(Team $team, int $player_id): void {
		$membership = $this->teams->findMembership($team->tournament_id, $player_id);
		if ($membership === null || $membership->team_id !== $team->id) {
			throw new NotFound(sprintf('Этого игрока нет в команде «%s».', $team->name));
		}
	}

	/**
		@param list<array{label: string, level: int, explicit: bool}> $breakdown
	*/
	protected function assertWithinLimit(Tournament $tournament, int $points, array $breakdown = []): void {
		if ($tournament->hasLevelLimit() && $points > (int) $tournament->max_points) {
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
		$sum = 0;
		foreach ($members as $member) {
			$sum += $this->liveLevel($member->player_id);
		}
		return $sum;
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
