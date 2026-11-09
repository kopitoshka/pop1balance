<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Model\SoloPlayer;
use P1Bot\Domain\Model\TeamEntry;

/**
 * Computed main/reserve split of a tournament. Nothing here is stored in the DB.
 *
 * Rules: registered teams go first in registered_at order, each fits wholly into the remaining
 * places or goes to reserve wholly; then solo players in created_at order fill what is left.
 */
final class Lineup
{
    /**
     * @param list<TeamEntry>  $mainTeams
     * @param list<TeamEntry>  $reserveTeams
     * @param list<SoloPlayer> $mainSolo
     * @param list<SoloPlayer> $reserveSolo
     * @param list<TeamEntry>  $formingTeams teams still assembling, not in the queue
     */
    private function __construct(
        public int $maxPlayers,
        public array $mainTeams,
        public array $reserveTeams,
        public array $mainSolo,
        public array $reserveSolo,
        public array $formingTeams,
    ) {
    }

    /**
     * @param list<TeamEntry>  $registeredTeams already in registered_at order
     * @param list<SoloPlayer> $soloPlayers     already in created_at order
     * @param list<TeamEntry>  $formingTeams
     */
    public static function compute(int $maxPlayers, array $registeredTeams, array $soloPlayers, array $formingTeams = []): self
    {
        $free = $maxPlayers;
        $mainTeams = $reserveTeams = $mainSolo = $reserveSolo = [];

        foreach ($registeredTeams as $entry) {
            if ($entry->size() <= $free) {
                $mainTeams[] = $entry;
                $free -= $entry->size();
            } else {
                $reserveTeams[] = $entry;
            }
        }
        foreach ($soloPlayers as $solo) {
            if ($free > 0) {
                $mainSolo[] = $solo;
                $free--;
            } else {
                $reserveSolo[] = $solo;
            }
        }

        return new self($maxPlayers, $mainTeams, $reserveTeams, $mainSolo, $reserveSolo, $formingTeams);
    }

    /** Players in the main lineup. */
    public function occupied(): int
    {
        return self::teamPlayers($this->mainTeams) + count($this->mainSolo);
    }

    /** Players in reserve (teams counted by members). */
    public function reserveCount(): int
    {
        return self::teamPlayers($this->reserveTeams) + count($this->reserveSolo);
    }

    public function freePlaces(): int
    {
        return $this->maxPlayers - $this->occupied();
    }

    /** "занято 18/24, резерв 2" */
    public function summary(): string
    {
        return sprintf('занято %d/%d, резерв %d', $this->occupied(), $this->maxPlayers, $this->reserveCount());
    }

    /** 'main' | 'reserve' | 'forming' | null — where the player is in this tournament. */
    public function placementOf(int $playerId): ?string
    {
        foreach (['main' => $this->mainTeams, 'reserve' => $this->reserveTeams, 'forming' => $this->formingTeams] as $place => $teams) {
            foreach ($teams as $entry) {
                foreach ($entry->members as $member) {
                    if ($member->playerId === $playerId) {
                        return $place;
                    }
                }
            }
        }
        foreach (['main' => $this->mainSolo, 'reserve' => $this->reserveSolo] as $place => $solos) {
            foreach ($solos as $solo) {
                if ($solo->playerId === $playerId) {
                    return $place;
                }
            }
        }
        return null;
    }

    /** @param list<TeamEntry> $teams */
    private static function teamPlayers(array $teams): int
    {
        return array_sum(array_map(static fn (TeamEntry $e): int => $e->size(), $teams));
    }
}
