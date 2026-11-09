<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\Balance;
use P1Bot\Repository\BalanceRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Clock;
use P1Bot\Support\Database;

/**
 * Rating scales ("балансы"). Each admin owns their scales; a scale may inherit from a parent:
 * a player's level is looked up in the scale, then up the parent chain, otherwise Levels::DEFAULT.
 */
final class BalanceService
{
    public const DEFAULT_NAME = 'Баланс по умолчанию';

    public function __construct(
        private BalanceRepository $balances,
        private PlayerRepository $players,
        private AccessPolicy $access,
        private Database $db,
        private Clock $clock,
    ) {
    }

    public function get(int $balanceId): Balance
    {
        return $this->balances->find($balanceId) ?? throw new NotFound('Такой шкалы баланса нет.');
    }

    public function findDefault(): ?Balance
    {
        return $this->balances->findDefault();
    }

    /** Creates the default scale owned by the bot owner if it does not exist yet. */
    public function ensureDefault(int $ownerPlayerId): Balance
    {
        return $this->db->transactional(function () use ($ownerPlayerId): Balance {
            return $this->balances->findDefault()
                ?? $this->balances->create($ownerPlayerId, self::DEFAULT_NAME, null, true, $this->clock->now());
        });
    }

    /** New scale owned by an admin, optionally inheriting ratings from $basedOnId. */
    public function create(int $chatId, int $actorId, string $name, ?int $basedOnId = null): Balance
    {
        $this->access->assertChatAdmin($chatId, $actorId);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 128) {
            throw new ValidationError('Название шкалы должно быть от 1 до 128 символов.');
        }
        if ($basedOnId !== null) {
            $this->get($basedOnId);
        }
        return $this->balances->create($actorId, $name, $basedOnId, false, $this->clock->now());
    }

    /**
     * Sets a player's level. Without $balanceId the actor's own scale is used; an admin who has
     * no scale yet gets one inheriting from the default scale. Only the owner may edit a scale.
     */
    public function setLevel(int $chatId, int $actorId, int $playerId, int $level, ?int $balanceId = null): Balance
    {
        Levels::assertValid($level);
        $this->access->assertChatAdmin($chatId, $actorId);
        if ($this->players->find($playerId) === null) {
            throw new NotFound('Такого игрока я не знаю.');
        }

        return $this->db->transactional(function () use ($actorId, $playerId, $level, $balanceId): Balance {
            $balance = $balanceId === null ? $this->ownScaleFor($actorId) : $this->get($balanceId);
            if ($balance->ownerId !== $actorId) {
                throw new PermissionDenied(sprintf('Шкала «%s» принадлежит другому админу, её менять нельзя.', $balance->name));
            }
            $this->balances->setRating($balance->id, $playerId, $level, $actorId, $this->clock->now());
            return $balance;
        });
    }

    /** Removes the rating from the actor's scale so the inherited (or default) level applies again. */
    public function resetLevel(int $chatId, int $actorId, int $playerId, int $balanceId): void
    {
        $this->access->assertChatAdmin($chatId, $actorId);
        $balance = $this->get($balanceId);
        if ($balance->ownerId !== $actorId) {
            throw new PermissionDenied(sprintf('Шкала «%s» принадлежит другому админу, её менять нельзя.', $balance->name));
        }
        $this->balances->deleteRating($balanceId, $playerId);
    }

    /** Effective level: scale, then parents, then Levels::DEFAULT. No scale => DEFAULT. */
    public function levelOf(?int $balanceId, int $playerId): int
    {
        foreach ($this->chain($balanceId) as $balance) {
            $level = $this->balances->findRating($balance->id, $playerId);
            if ($level !== null) {
                return $level;
            }
        }
        return Levels::DEFAULT;
    }

    /**
     * All explicitly rated players visible in the scale (own ratings override inherited ones).
     *
     * @return array<int, array{level: int, balance_id: int}> player_id => level and the scale it comes from
     */
    public function effectiveRatings(int $balanceId): array
    {
        $result = [];
        foreach (array_reverse($this->chain($balanceId)) as $balance) {
            foreach ($this->balances->ratings($balance->id) as $playerId => $level) {
                $result[$playerId] = ['level' => $level, 'balance_id' => $balance->id];
            }
        }
        ksort($result);
        return $result;
    }

    /** @return list<Balance> the scale itself first, then its ancestors (cycle-safe). */
    private function chain(?int $balanceId): array
    {
        $chain = [];
        $seen = [];
        while ($balanceId !== null && !isset($seen[$balanceId])) {
            $seen[$balanceId] = true;
            $balance = $this->balances->find($balanceId);
            if ($balance === null) {
                break;
            }
            $chain[] = $balance;
            $balanceId = $balance->parentId;
        }
        return $chain;
    }

    private function ownScaleFor(int $actorId): Balance
    {
        $default = $this->balances->findDefault();
        if ($default !== null && $default->ownerId === $actorId) {
            return $default;
        }
        $own = $this->balances->listByOwner($actorId);
        if ($own !== []) {
            return $own[0];
        }
        $player = $this->players->find($actorId);
        $name = 'Баланс ' . ($player?->mention() ?? '#' . $actorId);
        return $this->balances->create($actorId, $name, $default?->id, false, $this->clock->now());
    }
}
