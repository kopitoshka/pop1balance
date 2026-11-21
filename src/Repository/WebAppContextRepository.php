<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

class WebAppContextRepository {

	public function __construct(protected Database $db) {
	}

	/**
		@param array<string, mixed> $payload
	*/
	public function create(
		string $token,
		int $actor_player_id,
		int $chat_id,
		string $action,
		array $payload,
		DateTimeImmutable $expires_at,
		DateTimeImmutable $now,
	): void {
		$this->db->run(
			'INSERT INTO webapp_contexts (token, actor_player_id, chat_id, action, payload_json, expires_at, created_at)
				VALUES (:t, :a, :c, :act, :p, :e, :n)',
			[
				't' => $token,
				'a' => $actor_player_id,
				'c' => $chat_id,
				'act' => $action,
				'p' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE),
				'e' => DateFormat::toDb($expires_at),
				'n' => DateFormat::toDb($now),
			],
		);
	}

	/**
		@return array{token: string, actor_player_id: int, chat_id: int, action: string, payload: array<string, mixed>}|null
	*/
	public function find(string $token, DateTimeImmutable $now): ?array {
		return $this->load($token, $now, false);
	}

	/**
		Same as find(), but locks the row (SELECT FOR UPDATE). Call inside transactional().
	*/
	public function lock(string $token, DateTimeImmutable $now): ?array {
		return $this->load($token, $now, true);
	}

	/**
		@template T
		@param callable(): T $fn
		@return T
	*/
	public function transactional(callable $fn): mixed {
		return $this->db->transactional($fn);
	}

	/**
		Client-generated id of one form opening, for duplicate-submit protection.
	*/
	public static function openingId(mixed $value): ?string {
		if (!is_string($value)) {
			return null;
		}
		$value = trim($value);
		return preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $value) === 1 ? $value : null;
	}

	protected function load(string $token, DateTimeImmutable $now, bool $for_update): ?array {
		$sql = 'SELECT * FROM webapp_contexts WHERE token = :t AND expires_at >= :n';
		if ($for_update && $this->db->pdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
			$sql .= ' FOR UPDATE';
		}
		$row = $this->db->fetchOne($sql, ['t' => $token, 'n' => DateFormat::toDb($now)]);
		if ($row === null) {
			return null;
		}
		$payload = json_decode((string) $row['payload_json'], true);
		if (!is_array($payload)) {
			$payload = [];
		}
		return [
			'token' => (string) $row['token'],
			'actor_player_id' => (int) $row['actor_player_id'],
			'chat_id' => (int) $row['chat_id'],
			'action' => (string) $row['action'],
			'payload' => $payload,
		];
	}

	/**
		Replace the stored payload (e.g. unsaved team form state before a contacts round trip).

		@param array<string, mixed> $payload
	*/
	public function updatePayload(string $token, array $payload): void {
		$this->db->run(
			'UPDATE webapp_contexts SET payload_json = :p WHERE token = :t',
			['p' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE), 't' => $token],
		);
	}

	public function delete(string $token): void {
		$this->db->run('DELETE FROM webapp_contexts WHERE token = :t', ['t' => $token]);
	}

	public function purgeExpired(DateTimeImmutable $now): int {
		return $this->db->run(
			'DELETE FROM webapp_contexts WHERE expires_at < :n',
			['n' => DateFormat::toDb($now)],
		)->rowCount();
	}
}
