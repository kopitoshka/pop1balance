<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

final class ChatRepository
{
    public function __construct(private Database $db)
    {
    }

    public function ensure(int $chatId, string $title): void
    {
        $row = $this->db->fetchOne('SELECT title, is_active FROM chats WHERE id = :id', ['id' => $chatId]);
        if ($row === null) {
            $this->db->run('INSERT INTO chats (id, title, is_active) VALUES (:id, :t, 1)', ['id' => $chatId, 't' => $title]);
            return;
        }
        if ((string) $row['title'] !== $title || (int) $row['is_active'] !== 1) {
            $this->db->run('UPDATE chats SET title = :t, is_active = 1 WHERE id = :id', ['id' => $chatId, 't' => $title]);
        }
    }

    public function exists(int $chatId): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM chats WHERE id = :id', ['id' => $chatId]) !== null;
    }

    public function isActive(int $chatId): bool
    {
        return (int) $this->db->fetchValue('SELECT is_active FROM chats WHERE id = :id', ['id' => $chatId]) === 1;
    }

    public function deactivate(int $chatId): void
    {
        $this->db->run('UPDATE chats SET is_active = 0 WHERE id = :id', ['id' => $chatId]);
    }

    public function adminsSyncedAt(int $chatId): ?DateTimeImmutable
    {
        $value = $this->db->fetchValue('SELECT admins_synced_at FROM chats WHERE id = :id', ['id' => $chatId]);
        return $value === null ? null : DateFormat::fromDb((string) $value);
    }

    public function markAdminsSynced(int $chatId, DateTimeImmutable $now): void
    {
        $this->db->run('UPDATE chats SET admins_synced_at = :t WHERE id = :id', ['t' => DateFormat::toDb($now), 'id' => $chatId]);
    }
}
