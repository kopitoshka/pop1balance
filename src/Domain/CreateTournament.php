<?php

declare(strict_types=1);

namespace P1Bot\Domain;

/** Input for TournamentService::create(). Times are Moscow time strings, e.g. "2026-11-14 19:00". */
final class CreateTournament
{
    /**
     * @param string|null $referenceTeam strongest allowed team, e.g. "3,3,4"; null = no level limit
     * @param int|null    $balanceId     scale for levels; null with a reference team = default scale
     * @param int|null    $maxPlayers    null = configured default (24)
     */
    public function __construct(
        public int $chatId,
        public int $organizerId,
        public string $title,
        public string $startsAtMsk,
        public int $teamSize,
        public ?string $referenceTeam = null,
        public ?int $balanceId = null,
        public ?int $maxPlayers = null,
    ) {
    }
}
