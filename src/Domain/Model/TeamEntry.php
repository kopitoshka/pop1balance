<?php

declare(strict_types=1);

namespace P1Bot\Domain\Model;

/**
	A registered team together with its roster, as used in the lineup.
	Points are computed live from player profiles via TeamService::pointsOf().
*/
class TeamEntry {

	/**
		@param list<TeamMember> $members
	*/
	public function __construct(
		public Team $team,
		public array $members,
	) {
	}

	public function size(): int {
		return count($this->members);
	}
}
