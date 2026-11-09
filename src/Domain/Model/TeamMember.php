<?php

declare(strict_types=1);

namespace P1Bot\Domain\Model;

final class TeamMember
{
    public function __construct(
        public int $teamId,
        public int $tournamentId,
        public int $playerId,
        public int $levelSnapshot,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['team_id'],
            (int) $row['tournament_id'],
            (int) $row['player_id'],
            (int) $row['level_snapshot'],
        );
    }
}
