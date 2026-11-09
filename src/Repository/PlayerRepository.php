<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Domain\Model\Player;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

final class PlayerRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?Player
    {
        $row = $this->db->fetchOne('SELECT * FROM players WHERE id = :id', ['id' => $id]);
        return $row === null ? null : Player::fromRow($row);
    }

    public function findByTelegramId(int $tgUserId): ?Player
    {
        $row = $this->db->fetchOne('SELECT * FROM players WHERE tg_user_id = :tg', ['tg' => $tgUserId]);
        return $row === null ? null : Player::fromRow($row);
    }

    public function findByUsername(string $username): ?Player
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM players WHERE username = :u',
            ['u' => self::normalizeUsername($username)],
        );
        return $row === null ? null : Player::fromRow($row);
    }

    /**
     * Player who wrote a message. If someone already referenced them by @username
     * (placeholder row without tg_user_id), that row is claimed instead of creating a duplicate.
     */
    public function ensureByTelegram(int $tgUserId, ?string $username, string $displayName, DateTimeImmutable $now): Player
    {
        $username = $username === null || $username === '' ? null : self::normalizeUsername($username);

        return $this->db->transactional(function () use ($tgUserId, $username, $displayName, $now): Player {
            $existing = $this->findByTelegramId($tgUserId);
            if ($existing === null && $username !== null) {
                $placeholder = $this->findByUsername($username);
                if ($placeholder !== null && $placeholder->tgUserId === null) {
                    $this->db->run('UPDATE players SET tg_user_id = :tg WHERE id = :id', ['tg' => $tgUserId, 'id' => $placeholder->id]);
                    $existing = $this->find($placeholder->id);
                }
            }

            if ($existing === null) {
                if ($username !== null) {
                    // Username moved to another account: free it on the old row.
                    $this->db->run('UPDATE players SET username = NULL WHERE username = :u', ['u' => $username]);
                }
                $id = $this->db->insert(
                    'INSERT INTO players (tg_user_id, username, display_name, created_at) VALUES (:tg, :u, :d, :c)',
                    ['tg' => $tgUserId, 'u' => $username, 'd' => $displayName, 'c' => DateFormat::toDb($now)],
                );
                return $this->find($id) ?? throw new \LogicException('Player insert failed');
            }

            if ($existing->username !== $username || $existing->displayName !== $displayName) {
                if ($username !== null) {
                    $this->db->run('UPDATE players SET username = NULL WHERE username = :u AND id <> :id', ['u' => $username, 'id' => $existing->id]);
                }
                $this->db->run(
                    'UPDATE players SET username = :u, display_name = :d WHERE id = :id',
                    ['u' => $username, 'd' => $displayName, 'id' => $existing->id],
                );
            }
            return $this->find($existing->id) ?? throw new \LogicException('Player vanished');
        });
    }

    /** Row for a known Telegram id without touching name fields (used for the bot owner). */
    public function ensureTelegramId(int $tgUserId, string $displayName, DateTimeImmutable $now): Player
    {
        $existing = $this->findByTelegramId($tgUserId);
        if ($existing !== null) {
            return $existing;
        }
        $id = $this->db->insert(
            'INSERT INTO players (tg_user_id, username, display_name, created_at) VALUES (:tg, NULL, :d, :c)',
            ['tg' => $tgUserId, 'd' => $displayName, 'c' => DateFormat::toDb($now)],
        );
        return $this->find($id) ?? throw new \LogicException('Player insert failed');
    }

    /** Player referenced by @username who may not have written to the chat yet. */
    public function ensureByUsername(string $username, DateTimeImmutable $now): Player
    {
        $username = self::normalizeUsername($username);
        $existing = $this->findByUsername($username);
        if ($existing !== null) {
            return $existing;
        }
        $id = $this->db->insert(
            'INSERT INTO players (tg_user_id, username, display_name, created_at) VALUES (NULL, :u, :d, :c)',
            ['u' => $username, 'd' => $username, 'c' => DateFormat::toDb($now)],
        );
        return $this->find($id) ?? throw new \LogicException('Player insert failed');
    }

    public static function normalizeUsername(string $username): string
    {
        return mb_strtolower(ltrim(trim($username), '@'));
    }
}
