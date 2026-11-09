<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

class PendingActionRepository {

	public function __construct(protected Database $db) {
	}

	/**
		@param array<string, mixed> $action
	*/
	public function create(
		int $actor_player_id,
		int $chat_id,
		array $action,
		DateTimeImmutable $expires_at,
		DateTimeImmutable $now,
	): int {
		return $this->db->insert(
			'INSERT INTO pending_player_actions (actor_player_id, chat_id, action_json, expires_at, created_at)
				VALUES (:a, :c, :j, :e, :n)',
			[
				'a' => $actor_player_id,
				'c' => $chat_id,
				'j' => (string) json_encode($action, JSON_UNESCAPED_UNICODE),
				'e' => DateFormat::toDb($expires_at),
				'n' => DateFormat::toDb($now),
			],
		);
	}

	/**
		@return array{id: int, actor_player_id: int, chat_id: int, action: array<string, mixed>}|null
	*/
	public function take(int $id, DateTimeImmutable $now): ?array {
		$row = $this->db->fetchOne(
			'SELECT * FROM pending_player_actions WHERE id = :id AND expires_at >= :n',
			['id' => $id, 'n' => DateFormat::toDb($now)],
		);
		if ($row === null) {
			return null;
		}
		$action = json_decode((string) $row['action_json'], true);
		if (!is_array($action)) {
			return null;
		}
		$this->db->run('DELETE FROM pending_player_actions WHERE id = :id', ['id' => $id]);
		return [
			'id' => (int) $row['id'],
			'actor_player_id' => (int) $row['actor_player_id'],
			'chat_id' => (int) $row['chat_id'],
			'action' => $action,
		];
	}

	public function purgeExpired(DateTimeImmutable $now): void {
		$this->db->run(
			'DELETE FROM pending_player_actions WHERE expires_at < :n',
			['n' => DateFormat::toDb($now)],
		);
	}

	/**
		Latest non-expired pending action for this actor in this chat (deleted on take).
		When $op is set, only rows whose action.op matches.

		@return array{id: int, actor_player_id: int, chat_id: int, action: array<string, mixed>}|null
	*/
	/**
		Look at the latest pending without deleting it.
		@return array{id: int, actor_player_id: int, chat_id: int, action: array<string, mixed>}|null
	*/
	public function peekLatest(
		int $actor_player_id,
		int $chat_id,
		DateTimeImmutable $now,
		?string $op = null,
	): ?array {
		$rows = $this->db->fetchAll(
			'SELECT * FROM pending_player_actions
				WHERE actor_player_id = :a AND chat_id = :c AND expires_at >= :n
				ORDER BY id DESC LIMIT 20',
			['a' => $actor_player_id, 'c' => $chat_id, 'n' => DateFormat::toDb($now)],
		);
		foreach ($rows as $row) {
			$action = json_decode((string) $row['action_json'], true);
			if (!is_array($action)) {
				continue;
			}
			if ($op !== null && ($action['op'] ?? null) !== $op) {
				continue;
			}
			return [
				'id' => (int) $row['id'],
				'actor_player_id' => (int) $row['actor_player_id'],
				'chat_id' => (int) $row['chat_id'],
				'action' => $action,
			];
		}
		return null;
	}

	public function takeLatest(
		int $actor_player_id,
		int $chat_id,
		DateTimeImmutable $now,
		?string $op = null,
	): ?array {
		$rows = $this->db->fetchAll(
			'SELECT * FROM pending_player_actions
				WHERE actor_player_id = :a AND chat_id = :c AND expires_at >= :n
				ORDER BY id DESC LIMIT 20',
			['a' => $actor_player_id, 'c' => $chat_id, 'n' => DateFormat::toDb($now)],
		);
		foreach ($rows as $row) {
			$action = json_decode((string) $row['action_json'], true);
			if (!is_array($action)) {
				continue;
			}
			if ($op !== null && ($action['op'] ?? null) !== $op) {
				continue;
			}
			$this->db->run('DELETE FROM pending_player_actions WHERE id = :id', ['id' => (int) $row['id']]);
			return [
				'id' => (int) $row['id'],
				'actor_player_id' => (int) $row['actor_player_id'],
				'chat_id' => (int) $row['chat_id'],
				'action' => $action,
			];
		}
		return null;
	}
}
