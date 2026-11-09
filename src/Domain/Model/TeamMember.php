<?php

declare(strict_types=1);

namespace P1Bot\Domain\Model;

class TeamMember {

	public function __construct(
		public int $team_id,
		public int $tournament_id,
		public int $player_id,
	) {
	}

	/**
		@param array<string, mixed> $row
	*/
	public static function fromRow(array $row): self {
		return new self(
			(int) $row['team_id'],
			(int) $row['tournament_id'],
			(int) $row['player_id'],
		);
	}
}
