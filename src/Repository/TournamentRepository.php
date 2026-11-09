<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\Database;
use P1Bot\Support\DateFormat;

class TournamentRepository {
	/**
		Columns that update() may touch.
	*/
	protected const UPDATABLE = [
		'title', 'starts_at', 'team_size', 'balance_id', 'reference_team', 'max_points',
		'max_players', 'status', 'announce_msg_id', 'reminded_at', 'card_msg_id', 'card_shown_to',
	];

	public function __construct(protected Database $db) {
	}

	public function find(int $id): ?Tournament {
		$row = $this->db->fetchOne('SELECT * FROM tournaments WHERE id = :id', ['id' => $id]);
		return $row === null ? null : Tournament::fromRow($row);
	}

	/**
		@param array{chat_id: ?int, organizer_id: int, title: string, starts_at: DateTimeImmutable, team_size: int,
		    balance_id: ?int, reference_team: ?string, max_points: ?int, max_players: int, status: string} $data
	*/
	public function create(array $data, DateTimeImmutable $now): Tournament {
		$id = $this->db->insert(
			'INSERT INTO tournaments
				(chat_id, organizer_id, title, starts_at, team_size, balance_id, reference_team, max_points, max_players, status, created_at)
				VALUES (:chat, :org, :title, :starts, :size, :bal, :ref, :maxp, :maxpl, :status, :created)',
			[
				'chat' => $data['chat_id'],
				'org' => $data['organizer_id'],
				'title' => $data['title'],
				'starts' => DateFormat::toDb($data['starts_at']),
				'size' => $data['team_size'],
				'bal' => $data['balance_id'],
				'ref' => $data['reference_team'],
				'maxp' => $data['max_points'],
				'maxpl' => $data['max_players'],
				'status' => TournamentStatus::assertValid($data['status']),
				'created' => DateFormat::toDb($now),
			],
		);
		return $this->find($id) ?? throw new \LogicException('Tournament insert failed');
	}

	/**
		@param array<string, scalar|null|DateTimeImmutable> $fields
	*/
	public function delete(int $id): void {
		$this->db->run('DELETE FROM tournaments WHERE id = :id', ['id' => $id]);
	}

	public function update(int $id, array $fields): void {
		if ($fields === []) {
			return;
		}
		$sets = [];
		$params = ['id' => $id];
		foreach ($fields as $column => $value) {
			if (!in_array($column, self::UPDATABLE, true)) {
				throw new \InvalidArgumentException("Column $column is not updatable");
			}
			$sets[] = "$column = :$column";
			$params[$column] = match (true) {
				$value instanceof DateTimeImmutable => DateFormat::toDb($value),
				default => $value,
			};
		}
		$this->db->run('UPDATE tournaments SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
	}

	/**
		Draft whose confirmation card is the given message.
	*/
	public function findByCardMessage(int $chat_id, int $message_id): ?Tournament {
		$row = $this->db->fetchOne(
			'SELECT * FROM tournaments WHERE chat_id = :c AND card_msg_id = :m',
			['c' => $chat_id, 'm' => $message_id],
		);
		return $row === null ? null : Tournament::fromRow($row);
	}

	/**
		Draft whose confirmation card was shown to $player_id in private as the given message.
	*/
	public function findByPrivateCard(int $message_id, int $player_id): ?Tournament {
		$row = $this->db->fetchOne(
			'SELECT * FROM tournaments WHERE card_msg_id = :m AND card_shown_to = :p ORDER BY id DESC LIMIT 1',
			['m' => $message_id, 'p' => $player_id],
		);
		return $row === null ? null : Tournament::fromRow($row);
	}

	/**
		Tournament whose group announcement is the given message.
	*/
	public function findByAnnounceMessage(int $chat_id, int $message_id): ?Tournament {
		$row = $this->db->fetchOne(
			'SELECT t.* FROM tournament_announcements a
				INNER JOIN tournaments t ON t.id = a.tournament_id
				WHERE a.chat_id = :c AND a.message_id = :m',
			['c' => $chat_id, 'm' => $message_id],
		);
		if ($row !== null) {
			return Tournament::fromRow($row);
		}
		$row = $this->db->fetchOne(
			'SELECT * FROM tournaments WHERE announce_msg_id = :m AND (chat_id = :c OR chat_id IS NULL)',
			['c' => $chat_id, 'm' => $message_id],
		);
		return $row === null ? null : Tournament::fromRow($row);
	}

	/**
		@param list<string> $statuses
		@return list<Tournament>
	*/
	public function listByChat(int $chat_id, array $statuses): array {
		if ($statuses === []) {
			return [];
		}
		$params = ['chat' => $chat_id];
		$placeholders = [];
		foreach (array_values($statuses) as $i => $status) {
			$placeholders[] = ":s$i";
			$params["s$i"] = TournamentStatus::assertValid($status);
		}
		$rows = $this->db->fetchAll(
			'SELECT * FROM tournaments WHERE chat_id = :chat AND status IN (' . implode(', ', $placeholders) . ')
				ORDER BY starts_at, id',
			$params,
		);
		return array_map([Tournament::class, 'fromRow'], $rows);
	}


	/**
		@param list<string> $statuses
		@return list<Tournament>
	*/
	public function listByStatuses(array $statuses): array {
		if ($statuses === []) {
			return [];
		}
		$params = [];
		$placeholders = [];
		foreach (array_values($statuses) as $i => $status) {
			$placeholders[] = ":s$i";
			$params["s$i"] = TournamentStatus::assertValid($status);
		}
		$rows = $this->db->fetchAll(
			'SELECT * FROM tournaments WHERE status IN (' . implode(', ', $placeholders) . ')
				ORDER BY starts_at, id',
			$params,
		);
		return array_map([Tournament::class, 'fromRow'], $rows);
	}

	/**
		Newest first (by starts_at, then id), capped at $limit — for /history.

		@param list<string> $statuses
		@return list<Tournament>
	*/
	public function listRecentByStatuses(array $statuses, int $limit): array {
		if ($statuses === [] || $limit <= 0) {
			return [];
		}
		$limit = max(1, $limit);
		$params = [];
		$placeholders = [];
		foreach (array_values($statuses) as $i => $status) {
			$placeholders[] = ":s$i";
			$params["s$i"] = TournamentStatus::assertValid($status);
		}
		// LIMIT is inlined: SQLite rejects bound LIMIT with native prepares.
		$rows = $this->db->fetchAll(
			'SELECT * FROM tournaments WHERE status IN (' . implode(', ', $placeholders) . ')
				ORDER BY starts_at DESC, id DESC LIMIT ' . $limit,
			$params,
		);
		return array_map([Tournament::class, 'fromRow'], $rows);
	}

	/**
		Tournaments with one of $statuses whose start is within [$from, $to].

		@param list<string> $statuses
		@return list<Tournament>
	*/
	public function listStartingBetween(array $statuses, DateTimeImmutable $from, DateTimeImmutable $to): array {
		$params = ['from' => DateFormat::toDb($from), 'to' => DateFormat::toDb($to)];
		$placeholders = [];
		foreach (array_values($statuses) as $i => $status) {
			$placeholders[] = ":s$i";
			$params["s$i"] = TournamentStatus::assertValid($status);
		}
		$rows = $this->db->fetchAll(
			'SELECT * FROM tournaments WHERE status IN (' . implode(', ', $placeholders) . ')
				AND starts_at >= :from AND starts_at <= :to ORDER BY starts_at, id',
			$params,
		);
		return array_map([Tournament::class, 'fromRow'], $rows);
	}
}
