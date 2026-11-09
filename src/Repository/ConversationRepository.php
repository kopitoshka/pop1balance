<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

/**
	Short-lived AI dialog memory: a reply to last_bot_msg_id continues the dialog.
*/
class ConversationRepository {

	public function __construct(protected Database $db) {
	}

	/**
		@return array{id: int, player_id: int, history: list<array<string, mixed>>}|null
	*/
	public function findByBotMessage(int $chat_id, int $bot_message_id, DateTimeImmutable $now): ?array {
		$row = $this->db->fetchOne(
			'SELECT id, player_id, history_json FROM conversations
				WHERE chat_id = :c AND last_bot_msg_id = :m AND expires_at > :now
				ORDER BY id DESC LIMIT 1',
			['c' => $chat_id, 'm' => $bot_message_id, 'now' => DateFormat::toDb($now)],
		);
		if ($row === null) {
			return null;
		}
		return [
			'id' => (int) $row['id'],
			'player_id' => (int) $row['player_id'],
			'history' => json_decode((string) $row['history_json'], true, 512, JSON_THROW_ON_ERROR),
		];
	}

	/**
		@param list<array<string, mixed>> $history
	*/
	public function save(
		?int $id,
		int $chat_id,
		int $player_id,
		?int $last_bot_msg_id,
		array $history,
		DateTimeImmutable $expires_at,
	): int {
		$params = [
			'c' => $chat_id,
			'p' => $player_id,
			'm' => $last_bot_msg_id,
			'h' => json_encode($history, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
			'e' => DateFormat::toDb($expires_at),
		];
		if ($id === null) {
			return $this->db->insert(
				'INSERT INTO conversations (chat_id, player_id, last_bot_msg_id, history_json, expires_at) VALUES (:c, :p, :m, :h, :e)',
				$params,
			);
		}
		$this->db->run(
			'UPDATE conversations SET chat_id = :c, player_id = :p, last_bot_msg_id = :m, history_json = :h, expires_at = :e WHERE id = :id',
			$params + ['id' => $id],
		);
		return $id;
	}

	public function deleteExpired(DateTimeImmutable $now): int {
		return $this->db->run(
			'DELETE FROM conversations WHERE expires_at <= :now',
			['now' => DateFormat::toDb($now)],
		)->rowCount();
	}
}
