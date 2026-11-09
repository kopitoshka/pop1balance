<?php

declare(strict_types=1);

namespace P1Bot\Domain\Model;

use DateTimeImmutable;
use P1Bot\Support\DateFormat;

class SoloPlayer {

	public function __construct(
		public int $tournament_id,
		public int $player_id,
		public int $level_snapshot,
		public DateTimeImmutable $created_at,
	) {
	}

	/**
		@param array<string, mixed> $row
	*/
	public static function fromRow(array $row): self {
		return new self(
			(int) $row['tournament_id'],
			(int) $row['player_id'],
			(int) $row['level_snapshot'],
			DateFormat::fromDb((string) $row['created_at']),
		);
	}
}
