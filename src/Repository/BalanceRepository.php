<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Domain\Model\Balance;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

class BalanceRepository {

	public function __construct(protected Database $db) {
	}

	public function find(int $id): ?Balance {
		$row = $this->db->fetchOne('SELECT * FROM balances WHERE id = :id', ['id' => $id]);
		return $row === null ? null : Balance::fromRow($row);
	}

	public function findDefault(): ?Balance {
		$row = $this->db->fetchOne('SELECT * FROM balances WHERE is_default = 1 ORDER BY id LIMIT 1');
		return $row === null ? null : Balance::fromRow($row);
	}

	/**
		@return list<Balance>
	*/
	public function listByOwner(int $owner_id): array {
		$rows = $this->db->fetchAll('SELECT * FROM balances WHERE owner_id = :o ORDER BY id', ['o' => $owner_id]);
		return array_map([Balance::class, 'fromRow'], $rows);
	}

	public function create(
		int $owner_id,
		string $name,
		?int $parent_id,
		bool $is_default,
		DateTimeImmutable $now,
	): Balance {
		$id = $this->db->insert(
			'INSERT INTO balances (owner_id, parent_id, name, is_default, created_at) VALUES (:o, :p, :n, :d, :c)',
			[
				'o' => $owner_id,
				'p' => $parent_id,
				'n' => $name,
				'd' => (int) $is_default,
				'c' => DateFormat::toDb($now),
			],
		);
		return $this->find($id) ?? throw new \LogicException('Balance insert failed');
	}

	/**
		Rating stored directly in this scale (no inheritance).
	*/
	public function findRating(int $balance_id, int $player_id): ?int {
		$level = $this->db->fetchValue(
			'SELECT level FROM balance_ratings WHERE balance_id = :b AND player_id = :p',
			['b' => $balance_id, 'p' => $player_id],
		);
		return $level === null ? null : (int) $level;
	}

	public function setRating(
		int $balance_id,
		int $player_id,
		int $level,
		int $updated_by,
		DateTimeImmutable $now,
	): void {
		$params = [
			'b' => $balance_id,
			'p' => $player_id,
			'l' => $level,
			'u' => $updated_by,
			't' => DateFormat::toDb($now),
		];
		$this->db->transactional(function () use ($balance_id, $player_id, $params): void {
			if ($this->findRating($balance_id, $player_id) === null) {
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

	public function deleteRating(int $balance_id, int $player_id): void {
		$this->db->run(
			'DELETE FROM balance_ratings WHERE balance_id = :b AND player_id = :p',
			['b' => $balance_id, 'p' => $player_id],
		);
	}

	/**
		@return array<int, int> player_id => level, only ratings stored in this scale
	*/
	public function ratings(int $balance_id): array {
		$rows = $this->db->fetchAll(
			'SELECT player_id, level FROM balance_ratings WHERE balance_id = :b',
			['b' => $balance_id],
		);
		$result = [];
		foreach ($rows as $row) {
			$result[(int) $row['player_id']] = (int) $row['level'];
		}
		return $result;
	}
}
