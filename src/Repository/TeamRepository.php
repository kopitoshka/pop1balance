<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Domain\Model\Team;
use P1Bot\Domain\Model\TeamMember;
use P1Bot\Domain\TeamStatus;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

class TeamRepository {

	public function __construct(protected Database $db) {
	}

	public function find(int $id): ?Team {
		$row = $this->db->fetchOne('SELECT * FROM teams WHERE id = :id', ['id' => $id]);
		return $row === null ? null : Team::fromRow($row);
	}

	/**
		Case-insensitive lookup (done in PHP so Cyrillic works on any driver).
	*/
	public function findByName(int $tournament_id, string $name, ?int $except_team_id = null): ?Team {
		$needle = mb_strtolower(trim($name));
		foreach ($this->listByTournament($tournament_id) as $team) {
			if ($team->id !== $except_team_id && mb_strtolower($team->name) === $needle) {
				return $team;
			}
		}
		return null;
	}

	/**
		@return list<Team> all teams, any status, in creation order
	*/
	public function listByTournament(int $tournament_id): array {
		$rows = $this->db->fetchAll(
			'SELECT * FROM teams WHERE tournament_id = :t ORDER BY id',
			['t' => $tournament_id],
		);
		return array_map([Team::class, 'fromRow'], $rows);
	}

	/**
		@return list<Team> registered teams in queue order
	*/
	public function listRegistered(int $tournament_id): array {
		$rows = $this->db->fetchAll(
			"SELECT * FROM teams WHERE tournament_id = :t AND status = 'registered' ORDER BY registered_at, id",
			['t' => $tournament_id],
		);
		return array_map([Team::class, 'fromRow'], $rows);
	}

	public function create(int $tournament_id, int $captain_id, string $name, DateTimeImmutable $now): Team {
		$id = $this->db->insert(
			"INSERT INTO teams (tournament_id, captain_id, name, status, registered_at, created_at)
				VALUES (:t, :c, :n, 'forming', NULL, :now)",
			['t' => $tournament_id, 'c' => $captain_id, 'n' => $name, 'now' => DateFormat::toDb($now)],
		);
		return $this->find($id) ?? throw new \LogicException('Team insert failed');
	}

	public function delete(int $team_id): void {
		// Members are removed explicitly too, in case the driver has FK cascades disabled.
		$this->db->run('DELETE FROM team_members WHERE team_id = :id', ['id' => $team_id]);
		$this->db->run('DELETE FROM teams WHERE id = :id', ['id' => $team_id]);
	}

	public function setStatus(int $team_id, string $status, ?DateTimeImmutable $registered_at): void {
		$this->db->run(
			'UPDATE teams SET status = :s, registered_at = :r WHERE id = :id',
			[
				's' => TeamStatus::assertValid($status),
				'r' => $registered_at === null ? null : DateFormat::toDb($registered_at),
				'id' => $team_id,
			],
		);
	}

	public function rename(int $team_id, string $name): void {
		$this->db->run('UPDATE teams SET name = :n WHERE id = :id', ['n' => $name, 'id' => $team_id]);
	}

	public function setCaptain(int $team_id, int $captain_id): void {
		$this->db->run('UPDATE teams SET captain_id = :c WHERE id = :id', ['c' => $captain_id, 'id' => $team_id]);
	}

	/**
		@return list<TeamMember> in join order
	*/
	public function members(int $team_id): array {
		$rows = $this->db->fetchAll(
			'SELECT * FROM team_members WHERE team_id = :id ORDER BY joined_at, player_id',
			['id' => $team_id],
		);
		return array_map([TeamMember::class, 'fromRow'], $rows);
	}

	public function countMembers(int $team_id): int {
		return (int) $this->db->fetchValue('SELECT COUNT(*) FROM team_members WHERE team_id = :id', ['id' => $team_id]);
	}

	public function addMember(Team $team, int $player_id, int $level_snapshot, DateTimeImmutable $now): void {
		$this->db->run(
			'INSERT INTO team_members (team_id, tournament_id, player_id, level_snapshot, joined_at) VALUES (:team, :t, :p, :l, :j)',
			[
				'team' => $team->id,
				't' => $team->tournament_id,
				'p' => $player_id,
				'l' => $level_snapshot,
				'j' => DateFormat::toDb($now),
			],
		);
	}

	public function removeMember(int $team_id, int $player_id): void {
		$this->db->run(
			'DELETE FROM team_members WHERE team_id = :team AND player_id = :p',
			['team' => $team_id, 'p' => $player_id],
		);
	}

	/**
		Team membership of a player in a tournament, if any.
	*/
	public function findMembership(int $tournament_id, int $player_id): ?TeamMember {
		$row = $this->db->fetchOne(
			'SELECT * FROM team_members WHERE tournament_id = :t AND player_id = :p',
			['t' => $tournament_id, 'p' => $player_id],
		);
		return $row === null ? null : TeamMember::fromRow($row);
	}
}
