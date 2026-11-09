<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Domain\Model\Balance;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

final class BalanceRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?Balance
    {
        $row = $this->db->fetchOne('SELECT * FROM balances WHERE id = :id', ['id' => $id]);
        return $row === null ? null : Balance::fromRow($row);
    }

    public function findDefault(): ?Balance
    {
        $row = $this->db->fetchOne('SELECT * FROM balances WHERE is_default = 1 ORDER BY id LIMIT 1');
        return $row === null ? null : Balance::fromRow($row);
    }

    /** @return list<Balance> */
    public function listByOwner(int $ownerId): array
    {
        $rows = $this->db->fetchAll('SELECT * FROM balances WHERE owner_id = :o ORDER BY id', ['o' => $ownerId]);
        return array_map([Balance::class, 'fromRow'], $rows);
    }

    public function create(int $ownerId, string $name, ?int $parentId, bool $isDefault, DateTimeImmutable $now): Balance
    {
        $id = $this->db->insert(
            'INSERT INTO balances (owner_id, parent_id, name, is_default, created_at) VALUES (:o, :p, :n, :d, :c)',
            ['o' => $ownerId, 'p' => $parentId, 'n' => $name, 'd' => (int) $isDefault, 'c' => DateFormat::toDb($now)],
        );
        return $this->find($id) ?? throw new \LogicException('Balance insert failed');
    }

    /** Rating stored directly in this scale (no inheritance). */
    public function findRating(int $balanceId, int $playerId): ?int
    {
        $level = $this->db->fetchValue(
            'SELECT level FROM balance_ratings WHERE balance_id = :b AND player_id = :p',
            ['b' => $balanceId, 'p' => $playerId],
        );
        return $level === null ? null : (int) $level;
    }

    public function setRating(int $balanceId, int $playerId, int $level, int $updatedBy, DateTimeImmutable $now): void
    {
        $params = ['b' => $balanceId, 'p' => $playerId, 'l' => $level, 'u' => $updatedBy, 't' => DateFormat::toDb($now)];
        $this->db->transactional(function () use ($balanceId, $playerId, $params): void {
            if ($this->findRating($balanceId, $playerId) === null) {
                $this->db->run(
                    'INSERT INTO balance_ratings (balance_id, player_id, level, updated_by, updated_at) VALUES (:b, :p, :l, :u, :t)',
                    $params,
                );
                return;
            }
            $this->db->run(
                'UPDATE balance_ratings SET level = :l, updated_by = :u, updated_at = :t WHERE balance_id = :b AND player_id = :p',
                $params,
            );
        });
    }

    public function deleteRating(int $balanceId, int $playerId): void
    {
        $this->db->run('DELETE FROM balance_ratings WHERE balance_id = :b AND player_id = :p', ['b' => $balanceId, 'p' => $playerId]);
    }

    /** @return array<int, int> player_id => level, only ratings stored in this scale */
    public function ratings(int $balanceId): array
    {
        $rows = $this->db->fetchAll('SELECT player_id, level FROM balance_ratings WHERE balance_id = :b', ['b' => $balanceId]);
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['player_id']] = (int) $row['level'];
        }
        return $result;
    }
}
