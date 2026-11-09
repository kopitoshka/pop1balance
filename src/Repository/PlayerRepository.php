<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Domain\Model\Player;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

class PlayerRepository {

	public function __construct(protected Database $db) {
	}

	public function find(int $id): ?Player {
		$row = $this->db->fetchOne('SELECT * FROM players WHERE id = :id', ['id' => $id]);
		return $row === null ? null : Player::fromRow($row);
	}

	public function findByTelegramId(int $tg_user_id): ?Player {
		$row = $this->db->fetchOne('SELECT * FROM players WHERE tg_user_id = :tg', ['tg' => $tg_user_id]);
		return $row === null ? null : Player::fromRow($row);
	}

	public function findByUsername(string $username): ?Player {
		$row = $this->db->fetchOne(
			'SELECT * FROM players WHERE username = :u',
			['u' => self::normalizeUsername($username)],
		);
		return $row === null ? null : Player::fromRow($row);
	}

	public function findByIgnNorm(string $ign_norm): ?Player {
		$row = $this->db->fetchOne('SELECT * FROM players WHERE ign_norm = :n', ['n' => $ign_norm]);
		return $row === null ? null : Player::fromRow($row);
	}

	public function updateIgn(int $player_id, ?string $ign, ?string $ign_norm): void {
		$this->db->run(
			'UPDATE players SET ign = :i, ign_norm = :n WHERE id = :id',
			['i' => $ign, 'n' => $ign_norm, 'id' => $player_id],
		);
	}

	public function recordIgnHistory(
		int $player_id,
		string $ign,
		string $ign_norm,
		DateTimeImmutable $now,
		?int $changed_by,
	): void {
		$this->db->run(
			'INSERT INTO player_ign_history (player_id, ign, ign_norm, changed_at, changed_by)
				VALUES (:p, :i, :n, :c, :b)',
			[
				'p' => $player_id,
				'i' => $ign,
				'n' => $ign_norm,
				'c' => DateFormat::toDb($now),
				'b' => $changed_by,
			],
		);
	}

	/**
		Player who wrote a message. If someone already referenced them by @username
		(placeholder row without tg_user_id), that row is claimed instead of creating a duplicate.
	*/
	public function ensureByTelegram(
		int $tg_user_id,
		?string $username,
		string $display_name,
		DateTimeImmutable $now,
	): Player {
		$username = $username === null || $username === '' ? null : self::normalizeUsername($username);

		return $this->db->transactional(function () use ($tg_user_id, $username, $display_name, $now): Player {
			$existing = $this->findByTelegramId($tg_user_id);
			if ($existing === null && $username !== null) {
				$placeholder = $this->findByUsername($username);
				if ($placeholder !== null && $placeholder->tg_user_id === null) {
					$this->db->run(
						'UPDATE players SET tg_user_id = :tg WHERE id = :id',
						['tg' => $tg_user_id, 'id' => $placeholder->id],
					);
					$existing = $this->find($placeholder->id);
				}
			}

			if ($existing === null) {
				$orphan_ids = $username !== null ? $this->idsWithUsername($username) : [];
				if ($username !== null) {
					// Free the unique username before insert; ratings are merged below.
					$this->db->run('UPDATE players SET username = NULL WHERE username = :u', ['u' => $username]);
				}
				$id = $this->db->insert(
					'INSERT INTO players (tg_user_id, username, display_name, created_at) VALUES (:tg, :u, :d, :c)',
					['tg' => $tg_user_id, 'u' => $username, 'd' => $display_name, 'c' => DateFormat::toDb($now)],
				);
				foreach ($orphan_ids as $orphan_id) {
					$this->mergePlayerInto($id, $orphan_id);
				}
				return $this->find($id) ?? throw new \LogicException('Player insert failed');
			}

			if ($existing->username !== $username || $existing->display_name !== $display_name) {
				if ($username !== null && $existing->username !== $username) {
					$orphan_ids = array_values(array_filter(
						$this->idsWithUsername($username),
						static fn (int $id): bool => $id !== $existing->id,
					));
					$this->db->run(
						'UPDATE players SET username = NULL WHERE username = :u AND id <> :id',
						['u' => $username, 'id' => $existing->id],
					);
					foreach ($orphan_ids as $orphan_id) {
						$this->mergePlayerInto($existing->id, $orphan_id);
					}
				}
				$this->db->run(
					'UPDATE players SET username = :u, display_name = :d WHERE id = :id',
					['u' => $username, 'd' => $display_name, 'id' => $existing->id],
				);
			}
			return $this->find($existing->id) ?? throw new \LogicException('Player vanished');
		});
	}

	/**
		@return list<int>
	*/
	protected function idsWithUsername(string $username): array {
		$rows = $this->db->fetchAll(
			'SELECT id FROM players WHERE username = :u',
			['u' => self::normalizeUsername($username)],
		);
		return array_map(static fn (array $r): int => (int) $r['id'], $rows);
	}

	/**
		Move ratings / IGN from a duplicate placeholder onto the surviving player.
		The orphan row is kept (FKs on past team memberships) but stripped of username/ign.
	*/
	protected function mergePlayerInto(int $into_id, int $from_id): void {
		if ($into_id === $from_id) {
			return;
		}
		// Copy ratings the survivor does not already have.
		$this->db->run(
			'INSERT INTO balance_ratings (balance_id, player_id, level, updated_by, updated_at)
				SELECT br.balance_id, :into, br.level, br.updated_by, br.updated_at
				FROM balance_ratings br
				WHERE br.player_id = :from
					AND NOT EXISTS (
						SELECT 1 FROM balance_ratings x
						WHERE x.balance_id = br.balance_id AND x.player_id = :into2
					)',
			['into' => $into_id, 'from' => $from_id, 'into2' => $into_id],
		);
		$this->db->run('DELETE FROM balance_ratings WHERE player_id = :from', ['from' => $from_id]);

		$into = $this->find($into_id);
		$from = $this->find($from_id);
		if ($into !== null && $from !== null && !$into->hasIgn() && $from->hasIgn()) {
			$this->updateIgn($into_id, $from->ign, $from->ign_norm);
			$this->updateIgn($from_id, null, null);
		}
		$this->db->run(
			'UPDATE players SET username = NULL WHERE id = :id',
			['id' => $from_id],
		);
	}

	/**
		Row for a known Telegram id without touching name fields (used for the bot owner).
	*/
	public function ensureTelegramId(int $tg_user_id, string $display_name, DateTimeImmutable $now): Player {
		$existing = $this->findByTelegramId($tg_user_id);
		if ($existing !== null) {
			return $existing;
		}
		$id = $this->db->insert(
			'INSERT INTO players (tg_user_id, username, display_name, created_at) VALUES (:tg, NULL, :d, :c)',
			['tg' => $tg_user_id, 'd' => $display_name, 'c' => DateFormat::toDb($now)],
		);
		return $this->find($id) ?? throw new \LogicException('Player insert failed');
	}

	/**
		Existing player with this @username and a real Telegram id (already seen by the bot).
		Does not create placeholders.
	*/
	public function findKnownByUsername(string $username): ?Player {
		$player = $this->findByUsername($username);
		if ($player === null || $player->tg_user_id === null) {
			return null;
		}
		return $player;
	}

	/**
		@return list<Player> rows without tg_user_id (legacy placeholders)
	*/
	public function listPlaceholders(): array {
		$rows = $this->db->fetchAll(
			'SELECT * FROM players WHERE tg_user_id IS NULL ORDER BY id',
		);
		return array_map([Player::class, 'fromRow'], $rows);
	}

	/**
		The player wrote to the bot in private: personal messages are possible from now on.
	*/
	public function markDmStarted(int $player_id, DateTimeImmutable $now): void {
		$this->db->run(
			'UPDATE players SET dm_ok = 1, dm_started_at = COALESCE(dm_started_at, :t) WHERE id = :id',
			['t' => DateFormat::toDb($now), 'id' => $player_id],
		);
	}

	public function dmOk(int $player_id): bool {
		return (int) $this->db->fetchValue('SELECT dm_ok FROM players WHERE id = :id', ['id' => $player_id]) === 1;
	}

	/**
		Group the player currently works with in private (null = not chosen yet).
	*/
	public function dmGroup(int $player_id): ?int {
		$value = $this->db->fetchValue('SELECT dm_group_id FROM players WHERE id = :id', ['id' => $player_id]);
		return $value === null ? null : (int) $value;
	}

	public function setDmGroup(int $player_id, ?int $chat_id): void {
		$this->db->run('UPDATE players SET dm_group_id = :g WHERE id = :id', ['g' => $chat_id, 'id' => $player_id]);
	}

	public static function normalizeUsername(string $username): string {
		return mb_strtolower(ltrim(trim($username), '@'));
	}
}
