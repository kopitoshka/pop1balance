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

			// Keep a known @username when Telegram omits it (common for users_shared /
			// privacy); only overwrite when a non-empty username arrives. Always refresh
			// display_name so request_name / profile changes stick.
			$keep_username = $username ?? $existing->username;
			if ($keep_username !== $existing->username || $existing->display_name !== $display_name) {
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
					['u' => $keep_username, 'd' => $display_name, 'id' => $existing->id],
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


	/**
		Known players (tg_user_id set) the actor played with/against or who share a group
		via tournament history; excludes anyone already on a team of $tournament_id.

		@return list<Player>
	*/
	public function teamAddCandidates(int $actor_id, int $tournament_id, int $limit = 8): array {
		$limit = max(1, min(20, $limit));
		$taken = $this->playersOnTeamsOfTournament($tournament_id);
		$taken_set = array_fill_keys($taken, true);
		$taken_set[$actor_id] = true;

		$chat_rows = $this->db->fetchAll(
			'SELECT DISTINCT tr.chat_id FROM tournaments tr
				WHERE tr.chat_id IS NOT NULL AND tr.id IN (
					SELECT tournament_id FROM team_members WHERE player_id = :a
					UNION
					SELECT tournament_id FROM solo_players WHERE player_id = :a2
					UNION
					SELECT id FROM tournaments WHERE organizer_id = :a3
				)',
			['a' => $actor_id, 'a2' => $actor_id, 'a3' => $actor_id],
		);
		$chat_ids = [];
		foreach ($chat_rows as $row) {
			$chat_ids[] = (int) $row['chat_id'];
		}

		$scores = [];
		// Same past tournaments as actor.
		foreach ($this->db->fetchAll(
			'SELECT tm.player_id AS pid, MAX(tr.starts_at) AS last_at
				FROM team_members tm
				INNER JOIN tournaments tr ON tr.id = tm.tournament_id
				WHERE tm.tournament_id IN (
					SELECT tournament_id FROM team_members WHERE player_id = :a
					UNION
					SELECT tournament_id FROM solo_players WHERE player_id = :a2
				) AND tm.player_id <> :a3
				GROUP BY tm.player_id',
			['a' => $actor_id, 'a2' => $actor_id, 'a3' => $actor_id],
		) as $row) {
			$pid = (int) $row['pid'];
			$scores[$pid] = max($scores[$pid] ?? 0, 100);
		}
		foreach ($this->db->fetchAll(
			'SELECT sp.player_id AS pid, MAX(tr.starts_at) AS last_at
				FROM solo_players sp
				INNER JOIN tournaments tr ON tr.id = sp.tournament_id
				WHERE sp.tournament_id IN (
					SELECT tournament_id FROM team_members WHERE player_id = :a
					UNION
					SELECT tournament_id FROM solo_players WHERE player_id = :a2
				) AND sp.player_id <> :a3
				GROUP BY sp.player_id',
			['a' => $actor_id, 'a2' => $actor_id, 'a3' => $actor_id],
		) as $row) {
			$pid = (int) $row['pid'];
			$scores[$pid] = max($scores[$pid] ?? 0, 80);
		}
		if ($chat_ids !== []) {
			// Native MySQL prepares forbid reusing the same named placeholder twice in one SQL.
			$params = [];
			$keys_a = [];
			$keys_b = [];
			foreach ($chat_ids as $i => $cid) {
				$ka = 'ca' . $i;
				$kb = 'cb' . $i;
				$keys_a[] = ':' . $ka;
				$keys_b[] = ':' . $kb;
				$params[$ka] = $cid;
				$params[$kb] = $cid;
			}
			$sql = 'SELECT DISTINCT tm.player_id AS pid FROM team_members tm
				INNER JOIN tournaments tr ON tr.id = tm.tournament_id
				WHERE tr.chat_id IN (' . implode(',', $keys_a) . ')
				UNION
				SELECT DISTINCT sp.player_id FROM solo_players sp
				INNER JOIN tournaments tr ON tr.id = sp.tournament_id
				WHERE tr.chat_id IN (' . implode(',', $keys_b) . ')';
			foreach ($this->db->fetchAll($sql, $params) as $row) {
				$pid = (int) $row['pid'];
				$scores[$pid] = max($scores[$pid] ?? 0, 40);
			}
		}
		arsort($scores);
		$out = [];
		foreach ($scores as $pid => $_score) {
			if (isset($taken_set[$pid])) {
				continue;
			}
			$player = $this->find($pid);
			if ($player === null || $player->tg_user_id === null) {
				continue;
			}
			$out[] = $player;
			if (count($out) >= $limit) {
				break;
			}
		}
		return $out;
	}

	/**
		Player ids already on any team of this tournament.
		@return list<int>
	*/
	public function playersOnTeamsOfTournament(int $tournament_id): array {
		$rows = $this->db->fetchAll(
			'SELECT player_id FROM team_members WHERE tournament_id = :t',
			['t' => $tournament_id],
		);
		return array_map(static fn (array $r): int => (int) $r['player_id'], $rows);
	}

	/**
		Partial match on username / display_name / IGN for known players.
		@param list<int> $exclude_ids
		@return list<Player>
	*/
	public function searchPartial(string $query, int $limit = 8, array $exclude_ids = []): array {
		$query = trim($query);
		$limit = max(1, min(20, $limit));
		if ($query === '') {
			return [];
		}
		$q = mb_strtolower(ltrim($query, '@'));
		$q_compact = self::compactKey($query);
		$exclude = array_fill_keys($exclude_ids, true);
		$rows = $this->db->fetchAll(
			'SELECT * FROM players WHERE tg_user_id IS NOT NULL ORDER BY id DESC LIMIT 3000',
		);
		$prefix = [];
		$contains = [];
		foreach ($rows as $row) {
			$pid = (int) $row['id'];
			if (isset($exclude[$pid])) {
				continue;
			}
			$fields = [
				mb_strtolower((string) ($row['username'] ?? '')),
				mb_strtolower((string) ($row['display_name'] ?? '')),
				mb_strtolower((string) ($row['ign_norm'] ?? '')),
				mb_strtolower((string) ($row['ign'] ?? '')),
			];
			$is_prefix = false;
			$is_contains = false;
			foreach ($fields as $field) {
				if ($field === '') {
					continue;
				}
				$compact = self::compactKey($field);
				if (str_starts_with($field, $q) || ($q_compact !== '' && str_starts_with($compact, $q_compact))) {
					$is_prefix = true;
					break;
				}
				if (str_contains($field, $q) || ($q_compact !== '' && str_contains($compact, $q_compact))) {
					$is_contains = true;
				}
			}
			if ($is_prefix) {
				$prefix[] = $row;
			} elseif ($is_contains) {
				$contains[] = $row;
			}
		}
		$sort = static function (array $a, array $b): int {
			return ((string) ($a['username'] ?? 'ÿ')) <=> ((string) ($b['username'] ?? 'ÿ'))
				?: ((string) $a['display_name'] <=> (string) $b['display_name']);
		};
		usort($prefix, $sort);
		usort($contains, $sort);
		$merged = array_slice(array_merge($prefix, $contains), 0, $limit);
		return array_map([Player::class, 'fromRow'], $merged);
	}

	/**
		Recent known players (have a Telegram id), for an empty Mini App search.
		@param list<int> $exclude_ids
		@return list<Player>
	*/
	public function listKnown(int $limit = 20, array $exclude_ids = []): array {
		$limit = max(1, min(40, $limit));
		$rows = $this->db->fetchAll(
			'SELECT * FROM players WHERE tg_user_id IS NOT NULL
				AND (username IS NOT NULL OR display_name <> \'\' OR ign_norm IS NOT NULL)
				ORDER BY id DESC LIMIT 200',
		);
		$skip = array_fill_keys($exclude_ids, true);
		$out = [];
		foreach ($rows as $row) {
			$pid = (int) $row['id'];
			if (isset($skip[$pid])) {
				continue;
			}
			$out[] = Player::fromRow($row);
			if (count($out) >= $limit) {
				break;
			}
		}
		return $out;
	}

	public function fuzzySuggest(string $needle, int $limit = 5): array {

		$needle = mb_strtolower(ltrim(trim($needle), '@'));
		$limit = max(1, min(10, $limit));
		if ($needle === '' || mb_strlen($needle) < 2) {
			return [];
		}
		$rows = $this->db->fetchAll(
			'SELECT * FROM players WHERE tg_user_id IS NOT NULL
				AND (username IS NOT NULL OR display_name <> \'\' OR ign_norm IS NOT NULL)
				ORDER BY id DESC LIMIT 2000',
		);
		$scored = [];
		foreach ($rows as $row) {
			$score = self::similarityScore($needle, $row);
			if ($score < 45) {
				continue;
			}
			$scored[] = ['score' => $score, 'row' => $row];
		}
		usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
		$out = [];
		foreach (array_slice($scored, 0, $limit) as $item) {
			$out[] = Player::fromRow($item['row']);
		}
		return $out;
	}

	/**
		@param array<string, mixed> $row
	*/
	protected static function similarityScore(string $needle, array $row): float {
		$best = 0.0;
		foreach ([
			(string) ($row['username'] ?? ''),
			mb_strtolower((string) ($row['display_name'] ?? '')),
			(string) ($row['ign_norm'] ?? ''),
		] as $candidate) {
			if ($candidate === '') {
				continue;
			}
			$needle_c = self::compactKey($needle);
			$cand_c = self::compactKey($candidate);
			if ($candidate === $needle || ($needle_c !== '' && $cand_c === $needle_c)) {
				return 100.0;
			}
			similar_text($needle, $candidate, $pct);
			$best = max($best, (float) $pct);
			if ($needle_c !== '' && $cand_c !== '') {
				similar_text($needle_c, $cand_c, $pct_c);
				$best = max($best, (float) $pct_c);
				if (str_starts_with($cand_c, $needle_c) || str_contains($cand_c, $needle_c)) {
					$best = max($best, 80.0);
				}
			}
			$max_len = max(mb_strlen($needle), mb_strlen($candidate));
			if ($max_len <= 32) {
				$dist = levenshtein(
					mb_substr($needle, 0, 255),
					mb_substr($candidate, 0, 255),
				);
				if ($dist >= 0) {
					$lev = max(0.0, 100.0 - ($dist * 100.0 / max(1, $max_len)));
					$best = max($best, $lev);
				}
			}
			if (str_starts_with($candidate, $needle) || str_contains($candidate, $needle)) {
				$best = max($best, 70.0);
			}
		}
		return $best;
	}

	/**
		Lowercase key without @, spaces, underscores, dots or hyphens:
		«tired angel» and «just_tired_angel» share the core «tiredangel».
	*/
	public static function compactKey(string $value): string {
		$value = mb_strtolower(ltrim(trim($value), '@'));
		return (string) preg_replace('/[\s_\-\.]+/u', '', $value);
	}

	protected static function likeEscape(string $value): string {
		return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
	}

	/**
		Delete every player with tg_user_id IS NULL together with roster links and
		ratings. Empty teams are removed. Captains that pointed at a placeholder are
		reassigned to the oldest remaining member. Placeholders that still organize a
		tournament or own a balance are left (rare); count them in the return value.
		@return array{deleted: int, teams_removed: int, skipped: int}
	*/
	public function purgePlaceholders(): array {
		return $this->db->transactional(function (): array {
			$ph = $this->db->fetchAll('SELECT id FROM players WHERE tg_user_id IS NULL');
			$ids = array_map(static fn (array $r): int => (int) $r['id'], $ph);
			if ($ids === []) {
				return ['deleted' => 0, 'teams_removed' => 0, 'skipped' => 0];
			}
			$in = [];
			$params = [];
			foreach ($ids as $i => $id) {
				$key = 'p' . $i;
				$in[] = ':' . $key;
				$params[$key] = $id;
			}
			$list = implode(', ', $in);

			$this->db->run("DELETE FROM team_members WHERE player_id IN ({$list})", $params);
			$this->db->run("DELETE FROM solo_players WHERE player_id IN ({$list})", $params);

			// Reassign captains on non-empty teams that still point at a placeholder.
			$broken = $this->db->fetchAll(
				"SELECT t.id AS team_id, t.tournament_id
					FROM teams t
					WHERE t.captain_id IN ({$list})
						AND EXISTS (
							SELECT 1 FROM team_members tm
							WHERE tm.team_id = t.id AND tm.tournament_id = t.tournament_id
						)",
				$params,
			);
			foreach ($broken as $row) {
				$new = $this->db->fetchOne(
					'SELECT player_id FROM team_members
						WHERE team_id = :t AND tournament_id = :tr
						ORDER BY joined_at ASC, player_id ASC LIMIT 1',
					['t' => (int) $row['team_id'], 'tr' => (int) $row['tournament_id']],
				);
				if ($new !== null) {
					$this->db->run(
						'UPDATE teams SET captain_id = :c WHERE id = :id',
						['c' => (int) $new['player_id'], 'id' => (int) $row['team_id']],
					);
				}
			}

			$empty = $this->db->fetchAll(
				'SELECT t.id FROM teams t
					WHERE NOT EXISTS (
						SELECT 1 FROM team_members tm
						WHERE tm.team_id = t.id AND tm.tournament_id = t.tournament_id
					)',
			);
			$teams_removed = 0;
			foreach ($empty as $row) {
				$this->db->run('DELETE FROM teams WHERE id = :id', ['id' => (int) $row['id']]);
				$teams_removed++;
			}

			$this->db->run(
				"UPDATE balance_ratings SET updated_by = player_id WHERE updated_by IN ({$list})",
				$params,
			);
			$this->db->run(
				"UPDATE player_ign_history SET changed_by = NULL WHERE changed_by IN ({$list})",
				$params,
			);

			$protected = $this->db->fetchAll(
				"SELECT id FROM players
					WHERE id IN ({$list})
						AND (
							id IN (SELECT organizer_id FROM tournaments)
							OR id IN (SELECT owner_id FROM balances)
						)",
				$params,
			);
			$skip = array_map(static fn (array $r): int => (int) $r['id'], $protected);
			$to_delete = array_values(array_diff($ids, $skip));
			$deleted = 0;
			foreach ($to_delete as $i => $id) {
				$this->db->run('DELETE FROM players WHERE id = :id', ['id' => $id]);
				$deleted++;
			}
			return [
				'deleted' => $deleted,
				'teams_removed' => $teams_removed,
				'skipped' => count($skip),
			];
		});
	}

	public static function normalizeUsername(string $username): string {

		return mb_strtolower(ltrim(trim($username), '@'));
	}
}
