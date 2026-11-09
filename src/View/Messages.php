<?php

declare(strict_types=1);

namespace P1Bot\View;

use P1Bot\Domain\Lineup;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\MoscowTime;

/** User-facing texts. Only the tournament headline for now; announcements come later. */
final class Messages
{
    /** "Субботний кубок — 14.11.2026 19:00 МСК, команды по 3, уровень: средний (10 баллов на троих), занято 18/24, резерв 2" */
    public static function tournamentLine(Tournament $t, Lineup $lineup): string
    {
        return sprintf(
            '%s — %s, команды по %d, уровень: %s, %s',
            $t->title,
            MoscowTime::format($t->startsAt),
            $t->teamSize,
            $t->levelLabel(),
            $lineup->summary(),
        );
    }
}
