<?php

declare(strict_types=1);

namespace P1Bot\Domain\Model;

use DateTimeImmutable;
use P1Bot\Domain\TeamStatus;
use P1Bot\Support\DateFormat;

final class Team
{
    public function __construct(
        public int $id,
        public int $tournamentId,
        public int $captainId,
        public string $name,
        public string $status,
        public ?DateTimeImmutable $registeredAt,
        public DateTimeImmutable $createdAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tournament_id'],
            (int) $row['captain_id'],
            (string) $row['name'],
            TeamStatus::assertValid((string) $row['status']),
            DateFormat::fromDbNullable($row['registered_at'] === null ? null : (string) $row['registered_at']),
            DateFormat::fromDb((string) $row['created_at']),
        );
    }

    public function isRegistered(): bool
    {
        return $this->status === TeamStatus::REGISTERED;
    }
}
