<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;
use PDOException;

/**
	Which groups already got a reminder for a tournament at a given offset (minutes before start).
*/
class GroupNoticeRepository {

	public function __construct(protected Database $db) {
	}

	/**
		True the first time for (tournament, group, minutes), false afterwards: claim before sending.
	*/
	public function claim(int $tournament_id, int $chat_id, DateTimeImmutable $now, int $minutes = 0): bool {
		try {
			$this->db->run(
				'INSERT INTO group_notices (tournament_id, chat_id, minutes, created_at) VALUES (:t, :c, :m, :n)',
				[
					't' => $tournament_id,
					'c' => $chat_id,
					'm' => $minutes,
					'n' => DateFormat::toDb($now),
				],
			);
			return true;
		} catch (PDOException $e) {
			return false;
		}
	}

	public function setResult(int $tournament_id, int $chat_id, string $result, int $minutes = 0): void {
		$this->db->run(
			'UPDATE group_notices SET result = :r WHERE tournament_id = :t AND chat_id = :c AND minutes = :m',
			[
				'r' => mb_substr($result, 0, 255),
				't' => $tournament_id,
				'c' => $chat_id,
				'm' => $minutes,
			],
		);
	}

	/**
		@return array<int, string> chat_id => result ('' while sending); ignores minutes distinction
	*/
	public function listForTournament(int $tournament_id): array {
		$result = [];
		foreach ($this->db->fetchAll(
			'SELECT chat_id, result FROM group_notices WHERE tournament_id = :t ORDER BY chat_id, minutes',
			['t' => $tournament_id],
		) as $row) {
			$result[(int) $row['chat_id']] = (string) ($row['result'] ?? '');
		}
		return $result;
	}
}
