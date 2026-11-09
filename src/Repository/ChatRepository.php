<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use P1Bot\Support\Database;

final class ChatRepository
{
    public function __construct(private Database $db)
    {
    }

    public function ensure(int $chatId, string $title): void
    {
        $exists = $this->db->fetchValue('SELECT 1 FROM chats WHERE id = :id', ['id' => $chatId]);
        if ($exists === null) {
            $this->db->run('INSERT INTO chats (id, title, is_active) VALUES (:id, :t, 1)', ['id' => $chatId, 't' => $title]);
            return;
        }
        $this->db->run('UPDATE chats SET title = :t, is_active = 1 WHERE id = :id', ['id' => $chatId, 't' => $title]);
    }

    public function exists(int $chatId): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM chats WHERE id = :id', ['id' => $chatId]) !== null;
    }

    public function deactivate(int $chatId): void
    {
        $this->db->run('UPDATE chats SET is_active = 0 WHERE id = :id', ['id' => $chatId]);
    }
}
