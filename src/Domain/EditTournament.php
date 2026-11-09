<?php

declare(strict_types=1);

namespace P1Bot\Domain;

/** Partial update for TournamentService::edit(); null means "leave as is". */
final class EditTournament
{
    /**
     * @param bool $removeLevelLimit drop balance and reference team (takes precedence over the two fields)
     */
    public function __construct(
        public ?string $title = null,
        public ?string $startsAtMsk = null,
        public ?int $teamSize = null,
        public ?string $referenceTeam = null,
        public ?int $balanceId = null,
        public ?int $maxPlayers = null,
        public bool $removeLevelLimit = false,
    ) {
    }
}
