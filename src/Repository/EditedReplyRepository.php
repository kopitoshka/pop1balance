<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;
use PDOException;

/**
	Remembers edited messages we already answered, so each gets at most one reply.
*/
class EditedReplyRepository {
	protected const KEEP_DAYS = 30;

	public function __construct(protected Database $db) {
	}

	/**
		True the first time for (chat, message), false afterwards.
	*/
	public function claim(int $chat_id, int $message_id, DateTimeImmutable $now): bool {
		$this->db->run('DELETE FROM edited_replies WHERE created_at < :old', [
			'old' => DateFormat::toDb($now->modify('-' . self::KEEP_DAYS . ' days')),
		]);
		try {
			$this->db->run(
				'INSERT INTO edited_replies (chat_id, message_id, created_at) VALUES (:c, :m, :t)',
				['c' => $chat_id, 'm' => $message_id, 't' => DateFormat::toDb($now)],
			);
			return true;
		} catch (PDOException $e) {
			return false; // primary key: already answered
		}
	}
}
