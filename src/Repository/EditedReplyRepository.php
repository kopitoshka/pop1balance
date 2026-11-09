<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;
use PDOException;

/** Remembers edited messages we already answered, so each gets at most one reply. */
final class EditedReplyRepository
{
    private const KEEP_DAYS = 30;

    public function __construct(private Database $db)
    {
    }

    /** True the first time for (chat, message), false afterwards. */
    public function claim(int $chatId, int $messageId, DateTimeImmutable $now): bool
    {
        $this->db->run('DELETE FROM edited_replies WHERE created_at < :old', [
            'old' => DateFormat::toDb($now->modify('-' . self::KEEP_DAYS . ' days')),
        ]);
        try {
            $this->db->run(
                'INSERT INTO edited_replies (chat_id, message_id, created_at) VALUES (:c, :m, :t)',
                ['c' => $chatId, 'm' => $messageId, 't' => DateFormat::toDb($now)],
            );
            return true;
        } catch (PDOException $e) {
            return false; // primary key: already answered
        }
    }
}
