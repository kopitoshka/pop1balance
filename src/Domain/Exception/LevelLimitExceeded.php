<?php

declare(strict_types=1);

namespace P1Bot\Domain\Exception;

use P1Bot\Support\RuPlural;

/**
	Team level sum is above the tournament max_points.
*/
class LevelLimitExceeded extends DomainError {

	public function __construct(
		public int $team_points,
		public int $max_points,
	) {
		$excess = $team_points - $max_points;
		parent::__construct(sprintf(
			'Сумма уровней команды %d, а лимит турнира %d: перебор на %d %s.',
			$team_points,
			$max_points,
			$excess,
			RuPlural::points($excess),
		));
	}

	public function excess(): int {
		return $this->team_points - $this->max_points;
	}
}
