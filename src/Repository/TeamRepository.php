<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Domain\Model\Team;
use P1Bot\Domain\Model\TeamMember;
use P1Bot\Domain\TeamStatus;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

final class TeamRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?Team
    {
        $row = $this->db->fetchOne('SELECT * FROM teams WHERE id = :id', ['id' => $id]);
        return $row === null ? null : Team::fromRow($row);
    }

    /** Case-insensitive lookup (done in PHP so Cyrillic works on any driver). */
    public function findByName(int $tournamentId, string $name, ?int $exceptTeamId = null): ?Team
    {
        $needle = mb_strtolower(trim($name));
        foreach ($this->listByTournament($tournamentId) as $team) {
            if ($team->id !== $exceptTeamId && mb_strtolower($team->name) === $needle) {
                return $team;
            }
        }
        return null;
    }

    /** @return list<Team> all teams, any status, in creation order */
    public function listByTournament(int $tournamentId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM teams WHERE tournament_id = :t ORDER BY id', ['t' => $tournamentId]);
        return array_map([Team::class, 'fromRow'], $rows);
    }

    /** @return list<Team> registered teams in queue order */
    public function listRegistered(int $tournamentId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT * FROM teams WHERE tournament_id = :t AND status = 'registered' ORDER BY registered_at, id",
            ['t' => $tournamentId],
        );
        return array_map([Team::class, 'fromRow'], $rows);
    }

    public function create(int $tournamentId, int $captainId, string $name, DateTimeImmutable $now): Team
    {
        $id = $this->db->insert(
            "INSERT INTO teams (tournament_id, captain_id, name, status, registered_at, created_at)
             VALUES (:t, :c, :n, 'forming', NULL, :now)",
            ['t' => $tournamentId, 'c' => $captainId, 'n' => $name, 'now' => DateFormat::toDb($now)],
        );
        return $this->find($id) ?? throw new \LogicException('Team insert failed');
    }

    public function delete(int $teamId): void
    {
        // Members are removed explicitly too, in case the driver has FK cascades disabled.
        $this->db->run('DELETE FROM team_members WHERE team_id = :id', ['id' => $teamId]);
        $this->db->run('DELETE FROM teams WHERE id = :id', ['id' => $teamId]);
    }

    public function setStatus(int $teamId, string $status, ?DateTimeImmutable $registeredAt): void
    {
        $this->db->run(
            'UPDATE teams SET status = :s, registered_at = :r WHERE id = :id',
            ['s' => TeamStatus::assertValid($status), 'r' => $registeredAt === null ? null : DateFormat::toDb($registeredAt), 'id' => $teamId],
        );
    }

    public function rename(int $teamId, string $name): void
    {
        $this->db->run('UPDATE teams SET name = :n WHERE id = :id', ['n' => $name, 'id' => $teamId]);
    }

    public function setCaptain(int $teamId, int $captainId): void
    {
        $this->db->run('UPDATE teams SET captain_id = :c WHERE id = :id', ['c' => $captainId, 'id' => $teamId]);
    }

    /** @return list<TeamMember> in join order */
    public function members(int $teamId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM team_members WHERE team_id = :id ORDER BY joined_at, player_id',
            ['id' => $teamId],
        );
        return array_map([TeamMember::class, 'fromRow'], $rows);
    }

    public function countMembers(int $teamId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM team_members WHERE team_id = :id', ['id' => $teamId]);
    }

    public function addMember(Team $team, int $playerId, int $levelSnapshot, DateTimeImmutable $now): void
    {
        $this->db->run(
            'INSERT INTO team_members (team_id, tournament_id, player_id, level_snapshot, joined_at) VALUES (:team, :t, :p, :l, :j)',
            ['team' => $team->id, 't' => $team->tournamentId, 'p' => $playerId, 'l' => $levelSnapshot, 'j' => DateFormat::toDb($now)],
        );
    }

    public function removeMember(int $teamId, int $playerId): void
    {
        $this->db->run('DELETE FROM team_members WHERE team_id = :team AND player_id = :p', ['team' => $teamId, 'p' => $playerId]);
    }

    /** Team membership of a player in a tournament, if any. */
    public function findMembership(int $tournamentId, int $playerId): ?TeamMember
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM team_members WHERE tournament_id = :t AND player_id = :p',
            ['t' => $tournamentId, 'p' => $playerId],
        );
        return $row === null ? null : TeamMember::fromRow($row);
    }
}
