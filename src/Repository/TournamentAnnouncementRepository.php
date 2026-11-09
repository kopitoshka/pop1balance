<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use P1Bot\Support\Database;

/**
	Announcement message ids per group for a global tournament.
*/
class TournamentAnnouncementRepository {

	public function __construct(protected Database $db) {
	}

	/**
		@param array<int, int> $messages chat_id => message_id
	*/
	public function replaceAll(int $tournament_id, array $messages): void {
		$this->db->run('DELETE FROM tournament_announcements WHERE tournament_id = :t', ['t' => $tournament_id]);
		foreach ($messages as $chat_id => $message_id) {
			$this->db->run(
				'INSERT INTO tournament_announcements (tournament_id, chat_id, message_id) VALUES (:t, :c, :m)',
				['t' => $tournament_id, 'c' => (int) $chat_id, 'm' => (int) $message_id],
			);
		}
	}

	public function upsert(int $tournament_id, int $chat_id, int $message_id): void {
		$this->db->run('DELETE FROM tournament_announcements WHERE tournament_id = :t AND chat_id = :c', [
			't' => $tournament_id,
			'c' => $chat_id,
		]);
		$this->db->run(
			'INSERT INTO tournament_announcements (tournament_id, chat_id, message_id) VALUES (:t, :c, :m)',
			['t' => $tournament_id, 'c' => $chat_id, 'm' => $message_id],
		);
	}

	/**
		@return array<int, int> chat_id => message_id
	*/
	public function listForTournament(int $tournament_id): array {
		$result = [];
		foreach ($this->db->fetchAll(
			'SELECT chat_id, message_id FROM tournament_announcements WHERE tournament_id = :t ORDER BY chat_id',
			['t' => $tournament_id],
		) as $row) {
			$result[(int) $row['chat_id']] = (int) $row['message_id'];
		}
		return $result;
	}

	public function deleteForTournament(int $tournament_id): void {
		$this->db->run('DELETE FROM tournament_announcements WHERE tournament_id = :t', ['t' => $tournament_id]);
	}
}
