<?php

declare(strict_types=1);

namespace P1Bot\Domain\Exception;

use P1Bot\Domain\Levels;
use P1Bot\Support\RuPlural;

/**
	Team level sum is above the tournament max_points.
*/
class LevelLimitExceeded extends DomainError {

	/**
		@param list<array{label: string, level: int, explicit: bool}> $breakdown
	*/
	public function __construct(
		public int $team_points,
		public int $max_points,
		public array $breakdown = [],
	) {
		if ($breakdown === []) {
			$excess = $team_points - $max_points;
			parent::__construct(sprintf(
				'Сумма уровней команды %d, а лимит турнира %d: перебор на %d %s.',
				$team_points,
				$max_points,
				$excess,
				RuPlural::points($excess),
			));
			return;
		}
		$parts = [];
		foreach ($breakdown as $row) {
			$bit = sprintf('%s %d', $row['label'], $row['level']);
			if (!$row['explicit']) {
				$bit .= sprintf(' — уровень не задан, считаю %d', Levels::DEFAULT);
			}
			$parts[] = $bit;
		}
		parent::__construct(sprintf(
			'Команда набирает %d %s (%s), а лимит турнира %d.',
			$team_points,
			RuPlural::points($team_points),
			implode(', ', $parts),
			$max_points,
		));
	}

	public function excess(): int {
		return $this->team_points - $this->max_points;
	}
}
