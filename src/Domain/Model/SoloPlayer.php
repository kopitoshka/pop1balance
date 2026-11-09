<?php

declare(strict_types=1);

namespace P1Bot\Domain\Model;

use DateTimeImmutable;
use P1Bot\Support\DateFormat;

final class SoloPlayer
{
    public function __construct(
        public int $tournamentId,
        public int $playerId,
        public int $levelSnapshot,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['tournament_id'],
            (int) $row['player_id'],
            (int) $row['level_snapshot'],
            DateFormat::fromDb((string) $row['created_at']),
        );
    }
}
