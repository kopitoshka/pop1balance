<?php

declare(strict_types=1);

namespace P1Bot\Domain;

/**
	Partial update for TournamentService::edit(); null means "leave as is".
*/
class EditTournament {

	/**
		@param bool $remove_level_limit drop balance and reference team (takes precedence over the two fields)
	*/
	public function __construct(
		public ?string $title = null,
		public ?string $starts_at_msk = null,
		public ?int $team_size = null,
		public ?string $reference_team = null,
		public ?int $balance_id = null,
		public ?int $max_players = null,
		public bool $remove_level_limit = false,
	) {
	}
}
