<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use P1Bot\Support\Database;

class AdminRepository {

	public function __construct(protected Database $db) {
	}

	public function isAdmin(int $chat_id, int $player_id): bool {
		return $this->db->fetchValue(
			'SELECT 1 FROM admins WHERE chat_id = :c AND player_id = :p',
			['c' => $chat_id, 'p' => $player_id],
		) !== null;
	}

	public function add(int $chat_id, int $player_id, bool $is_owner = false): void {
		$this->db->transactional(function () use ($chat_id, $player_id, $is_owner): void {
			if ($this->isAdmin($chat_id, $player_id)) {
				$this->db->run(
					'UPDATE admins SET is_owner = :o WHERE chat_id = :c AND player_id = :p',
					['o' => (int) $is_owner, 'c' => $chat_id, 'p' => $player_id],
				);
				return;
			}
			$this->db->run(
				'INSERT INTO admins (player_id, chat_id, is_owner) VALUES (:p, :c, :o)',
				['p' => $player_id, 'c' => $chat_id, 'o' => (int) $is_owner],
			);
		});
	}

	public function remove(int $chat_id, int $player_id): void {
		$this->db->run(
			'DELETE FROM admins WHERE chat_id = :c AND player_id = :p',
			['c' => $chat_id, 'p' => $player_id],
		);
	}

	/**
		Replaces the cached admin list of a chat.

		@param array<int, bool> $admins player_id => is_owner
	*/
	public function replaceForChat(int $chat_id, array $admins): void {
		$this->db->transactional(function () use ($chat_id, $admins): void {
			$this->db->run('DELETE FROM admins WHERE chat_id = :c', ['c' => $chat_id]);
			foreach ($admins as $player_id => $is_owner) {
				$this->db->run(
					'INSERT INTO admins (player_id, chat_id, is_owner) VALUES (:p, :c, :o)',
					['p' => $player_id, 'c' => $chat_id, 'o' => (int) $is_owner],
				);
			}
		});
	}

	/**
		@return list<int> player ids
	*/
	public function listForChat(int $chat_id): array {
		return array_map('intval', array_column(
			$this->db->fetchAll(
				'SELECT player_id FROM admins WHERE chat_id = :c ORDER BY player_id',
				['c' => $chat_id],
			),
			'player_id',
		));
	}
}
