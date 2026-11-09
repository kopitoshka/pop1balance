<?php

declare(strict_types=1);

namespace P1Bot\Domain\Exception;

use P1Bot\Support\RuPlural;

/** Team level sum is above the tournament max_points. */
final class LevelLimitExceeded extends DomainError
{
    public function __construct(
        public int $teamPoints,
        public int $maxPoints,
    ) {
        $excess = $teamPoints - $maxPoints;
        parent::__construct(sprintf(
            'Сумма уровней команды %d, а лимит турнира %d: перебор на %d %s.',
            $teamPoints,
            $maxPoints,
            $excess,
            RuPlural::points($excess),
        ));
    }

    public function excess(): int
    {
        return $this->teamPoints - $this->maxPoints;
    }
}
