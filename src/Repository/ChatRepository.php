<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

class ChatRepository {

	public function __construct(protected Database $db) {
	}

	public function ensure(int $chat_id, string $title): void {
		$row = $this->db->fetchOne('SELECT title, is_active FROM chats WHERE id = :id', ['id' => $chat_id]);
		if ($row === null) {
			$this->db->run(
				'INSERT INTO chats (id, title, is_active) VALUES (:id, :t, 1)',
				['id' => $chat_id, 't' => $title],
			);
			return;
		}
		if ((string) $row['title'] !== $title || (int) $row['is_active'] !== 1) {
			$this->db->run(
				'UPDATE chats SET title = :t, is_active = 1 WHERE id = :id',
				['id' => $chat_id, 't' => $title],
			);
		}
	}

	public function exists(int $chat_id): bool {
		return $this->db->fetchValue('SELECT 1 FROM chats WHERE id = :id', ['id' => $chat_id]) !== null;
	}

	public function isActive(int $chat_id): bool {
		return (int) $this->db->fetchValue('SELECT is_active FROM chats WHERE id = :id', ['id' => $chat_id]) === 1;
	}

	public function deactivate(int $chat_id): void {
		$this->db->run('UPDATE chats SET is_active = 0 WHERE id = :id', ['id' => $chat_id]);
	}

	public function adminsSyncedAt(int $chat_id): ?DateTimeImmutable {
		$value = $this->db->fetchValue('SELECT admins_synced_at FROM chats WHERE id = :id', ['id' => $chat_id]);
		return $value === null ? null : DateFormat::fromDb((string) $value);
	}

	public function markAdminsSynced(int $chat_id, DateTimeImmutable $now): void {
		$this->db->run(
			'UPDATE chats SET admins_synced_at = :t WHERE id = :id',
			['t' => DateFormat::toDb($now), 'id' => $chat_id],
		);
	}
}
