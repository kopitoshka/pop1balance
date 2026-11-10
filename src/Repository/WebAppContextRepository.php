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
		$row = $this->db->fetchOne(
			'SELECT * FROM webapp_contexts WHERE token = :t AND expires_at >= :n',
			['t' => $token, 'n' => DateFormat::toDb($now)],
		);
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
