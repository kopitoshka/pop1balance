<?php

declare(strict_types=1);

namespace P1Bot\Domain\Model;

use DateTimeImmutable;
use P1Bot\Domain\TeamStatus;
use P1Bot\Support\DateFormat;

class Team {

	public function __construct(
		public int $id,
		public int $tournament_id,
		public int $captain_id,
		public string $name,
		public string $status,
		public ?DateTimeImmutable $registered_at,
		public DateTimeImmutable $created_at,
	) {
	}

	/**
		@param array<string, mixed> $row
	*/
	public static function fromRow(array $row): self {
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

	public function isRegistered(): bool {
		return $this->status === TeamStatus::REGISTERED;
	}
}
