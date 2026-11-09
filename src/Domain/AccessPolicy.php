<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Model\Team;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Repository\AdminRepository;
use P1Bot\Repository\PlayerRepository;

/** All permission rules in one place. The AI layer never decides permissions. */
final class AccessPolicy
{
    public function __construct(
        private AdminRepository $admins,
        private PlayerRepository $players,
        private int $ownerTgId,
        private bool $anyoneCanCreateTournament = false,
    ) {
    }

    /** Bot author (BOT_OWNER_TG_ID). */
    public function isOwner(int $playerId): bool
    {
        if ($this->ownerTgId === 0) {
            return false;
        }
        return $this->players->find($playerId)?->tgUserId === $this->ownerTgId;
    }

    public function isChatAdmin(int $chatId, int $playerId): bool
    {
        return $this->isOwner($playerId) || $this->admins->isAdmin($chatId, $playerId);
    }

    public function assertChatAdmin(int $chatId, int $playerId): void
    {
        if (!$this->isChatAdmin($chatId, $playerId)) {
            throw new PermissionDenied('Это могут делать только админы чата.');
        }
    }

    public function assertCanCreateTournament(int $chatId, int $playerId): void
    {
        if (!$this->anyoneCanCreateTournament) {
            $this->assertChatAdmin($chatId, $playerId);
        }
    }

    public function canManageTournament(Tournament $tournament, int $playerId): bool
    {
        return $tournament->organizerId === $playerId || $this->isChatAdmin($tournament->chatId, $playerId);
    }

    public function assertCanManageTournament(Tournament $tournament, int $playerId): void
    {
        if (!$this->canManageTournament($tournament, $playerId)) {
            throw new PermissionDenied('Менять турнир может только его организатор или админ чата.');
        }
    }

    public function assertCaptain(Team $team, int $playerId): void
    {
        if ($team->captainId !== $playerId) {
            throw new PermissionDenied(sprintf('Состав команды «%s» меняет только капитан.', $team->name));
        }
    }
}
