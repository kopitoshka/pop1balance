<?php

declare(strict_types=1);

namespace P1Bot\Support;

use PDO;
use PDOStatement;
use Throwable;

/**
	Thin PDO wrapper: prepared queries plus nestable transactions.
	Repositories use only portable SQL so tests can run on SQLite.
*/
class Database {
	protected int $depth = 0;

	public function __construct(protected PDO $pdo) {
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
		$this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
		$this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
		if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
			$this->pdo->exec('PRAGMA foreign_keys = ON');
		}
	}

	/**
		@param array{dsn: string, user: string, password: string} $config
	*/
	public static function fromConfig(array $config): self {
		return new self(new PDO($config['dsn'], $config['user'], $config['password']));
	}

	public function pdo(): PDO {
		return $this->pdo;
	}

	/**
		@param array<string, scalar|null> $params
	*/
	public function run(string $sql, array $params = []): PDOStatement {
		$stmt = $this->pdo->prepare($sql);
		$stmt->execute($params);
		return $stmt;
	}

	/**
		@param array<string, scalar|null> $params
		@return array<string, mixed>|null
	*/
	public function fetchOne(string $sql, array $params = []): ?array {
		$row = $this->run($sql, $params)->fetch();
		return $row === false ? null : $row;
	}

	/**
		@param array<string, scalar|null> $params
		@return list<array<string, mixed>>
	*/
	public function fetchAll(string $sql, array $params = []): array {
		return $this->run($sql, $params)->fetchAll();
	}

	/**
		@param array<string, scalar|null> $params
	*/
	public function fetchValue(string $sql, array $params = []): mixed {
		$value = $this->run($sql, $params)->fetchColumn();
		return $value === false ? null : $value;
	}

	/**
		@param array<string, scalar|null> $params
	*/
	public function insert(string $sql, array $params = []): int {
		$this->run($sql, $params);
		return (int) $this->pdo->lastInsertId();
	}

	/**
		Runs $fn inside a transaction. Nested calls join the outer transaction.

		@template T
		@param callable(): T $fn
		@return T
	*/
	public function transactional(callable $fn): mixed {
		if ($this->depth === 0) {
			$this->pdo->beginTransaction();
		}
		$this->depth++;
		try {
			$result = $fn();
			$this->depth--;
			if ($this->depth === 0) {
				$this->pdo->commit();
			}
			return $result;
		} catch (Throwable $e) {
			$this->depth--;
			if ($this->depth === 0 && $this->pdo->inTransaction()) {
				$this->pdo->rollBack();
			}
			throw $e;
		}
	}
}
