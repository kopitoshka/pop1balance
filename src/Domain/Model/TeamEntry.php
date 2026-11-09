<?php

declare(strict_types=1);

namespace P1Bot\Domain\Model;

/** A registered team together with its roster, as used in the lineup. */
final class TeamEntry
{
    /** @param list<TeamMember> $members */
    public function __construct(
        public Team $team,
        public array $members,
    ) {
    }

    public function size(): int
    {
        return count($this->members);
    }

    public function points(): int
    {
        return array_sum(array_map(static fn (TeamMember $m): int => $m->levelSnapshot, $this->members));
    }
}
