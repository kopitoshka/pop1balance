<?php

declare(strict_types=1);

namespace P1Bot\Domain;

/**
	Input for TournamentService::create(). Times are Moscow time strings, e.g. "2026-11-14 19:00".
	chat_id is optional origin/history; null = CreateAccess picks a shared group (or leaves null).
*/
class CreateTournament {

	/**
		@param string|null $reference_team strongest allowed team, e.g. "3,3,4"; null = no level limit
		@param int|null    $balance_id     scale for levels; null with a reference team = default scale
		@param int|null    $max_players    null = configured default (24)
	*/
	public function __construct(
		public ?int $chat_id,
		public int $organizer_id,
		public string $title,
		public string $starts_at_msk,
		public int $team_size,
		public ?string $reference_team = null,
		public ?int $balance_id = null,
		public ?int $max_players = null,
	) {
	}
}
