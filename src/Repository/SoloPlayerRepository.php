<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Domain\Model\SoloPlayer;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

final class SoloPlayerRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $tournamentId, int $playerId): ?SoloPlayer
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM solo_players WHERE tournament_id = :t AND player_id = :p',
            ['t' => $tournamentId, 'p' => $playerId],
        );
        return $row === null ? null : SoloPlayer::fromRow($row);
    }

    public function add(int $tournamentId, int $playerId, int $levelSnapshot, DateTimeImmutable $now): void
    {
        $this->db->run(
            'INSERT INTO solo_players (tournament_id, player_id, level_snapshot, created_at) VALUES (:t, :p, :l, :c)',
            ['t' => $tournamentId, 'p' => $playerId, 'l' => $levelSnapshot, 'c' => DateFormat::toDb($now)],
        );
    }

    public function remove(int $tournamentId, int $playerId): bool
    {
        return $this->db->run(
            'DELETE FROM solo_players WHERE tournament_id = :t AND player_id = :p',
            ['t' => $tournamentId, 'p' => $playerId],
        )->rowCount() > 0;
    }

    /** @return list<SoloPlayer> in queue order */
    public function listByTournament(int $tournamentId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM solo_players WHERE tournament_id = :t ORDER BY created_at, player_id',
            ['t' => $tournamentId],
        );
        return array_map([SoloPlayer::class, 'fromRow'], $rows);
    }
}
