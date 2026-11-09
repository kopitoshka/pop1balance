<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Domain\Model\SoloPlayer;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

class SoloPlayerRepository {

	public function __construct(protected Database $db) {
	}

	public function find(int $tournament_id, int $player_id): ?SoloPlayer {
		$row = $this->db->fetchOne(
			'SELECT * FROM solo_players WHERE tournament_id = :t AND player_id = :p',
			['t' => $tournament_id, 'p' => $player_id],
		);
		return $row === null ? null : SoloPlayer::fromRow($row);
	}

	public function add(int $tournament_id, int $player_id, int $level_snapshot, DateTimeImmutable $now): void {
		$this->db->run(
			'INSERT INTO solo_players (tournament_id, player_id, level_snapshot, created_at) VALUES (:t, :p, :l, :c)',
			['t' => $tournament_id, 'p' => $player_id, 'l' => $level_snapshot, 'c' => DateFormat::toDb($now)],
		);
	}

	public function remove(int $tournament_id, int $player_id): bool {
		return $this->db->run(
			'DELETE FROM solo_players WHERE tournament_id = :t AND player_id = :p',
			['t' => $tournament_id, 'p' => $player_id],
		)->rowCount() > 0;
	}

	/**
		@return list<SoloPlayer> in queue order
	*/
	public function listByTournament(int $tournament_id): array {
		$rows = $this->db->fetchAll(
			'SELECT * FROM solo_players WHERE tournament_id = :t ORDER BY created_at, player_id',
			['t' => $tournament_id],
		);
		return array_map([SoloPlayer::class, 'fromRow'], $rows);
	}
}
