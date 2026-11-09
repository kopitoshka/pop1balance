<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use P1Bot\Support\Database;

final class AdminRepository
{
    public function __construct(private Database $db)
    {
    }

    public function isAdmin(int $chatId, int $playerId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM admins WHERE chat_id = :c AND player_id = :p',
            ['c' => $chatId, 'p' => $playerId],
        ) !== null;
    }

    public function add(int $chatId, int $playerId, bool $isOwner = false): void
    {
        $this->db->transactional(function () use ($chatId, $playerId, $isOwner): void {
            if ($this->isAdmin($chatId, $playerId)) {
                $this->db->run(
                    'UPDATE admins SET is_owner = :o WHERE chat_id = :c AND player_id = :p',
                    ['o' => (int) $isOwner, 'c' => $chatId, 'p' => $playerId],
                );
                return;
            }
            $this->db->run(
                'INSERT INTO admins (player_id, chat_id, is_owner) VALUES (:p, :c, :o)',
                ['p' => $playerId, 'c' => $chatId, 'o' => (int) $isOwner],
            );
        });
    }

    public function remove(int $chatId, int $playerId): void
    {
        $this->db->run('DELETE FROM admins WHERE chat_id = :c AND player_id = :p', ['c' => $chatId, 'p' => $playerId]);
    }
}
