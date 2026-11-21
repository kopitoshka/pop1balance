<?php

declare(strict_types=1);

namespace P1Bot\Repository;

use DateTimeImmutable;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\TournamentStatus;
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
		Player who wrote a message. If someone already added them by @username
		(/addplayer: a row without tg_user_id), that row is claimed instead of creating a duplicate;
		its nick, levels and rosters stay, the display name is refreshed from Telegram.
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
	*/
	public function findKnownByUsername(string $username): ?Player {
		$player = $this->findByUsername($username);
		if ($player === null || $player->tg_user_id === null) {
			return null;
		}
		return $player;
	}

	/**
		@return list<Player> players without Telegram (allowed via /addplayer)
	*/
	public function listPlaceholders(): array {
		$rows = $this->db->fetchAll(
			'SELECT * FROM players WHERE tg_user_id IS NULL AND deleted_at IS NULL ORDER BY id',
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
		Players (with or without Telegram) the actor played with/against or who share a group
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
			if ($player === null || $player->isDeleted()) {
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
		Partial match on username / display_name / IGN, players without Telegram included.
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
			'SELECT * FROM players WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 3000',
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
		Recent players with a name, nick or @username, for an empty Mini App search.
		@param list<int> $exclude_ids
		@return list<Player>
	*/
	public function listKnown(int $limit = 20, array $exclude_ids = []): array {
		$limit = max(1, min(40, $limit));
		$rows = $this->db->fetchAll(
			'SELECT * FROM players
				WHERE deleted_at IS NULL AND (username IS NOT NULL OR display_name <> \'\' OR ign_norm IS NOT NULL)
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

	/**
		Every player the bot knows (/players): a Telegram id, an @username or an in-game nick.
		Rows stripped by a merge (none of the three) and deleted players are left out.
		@return list<Player>
	*/
	public function listAllKnown(): array {
		return array_map(
			[Player::class, 'fromRow'],
			$this->db->fetchAll(
				'SELECT * FROM players
					WHERE deleted_at IS NULL
						AND (tg_user_id IS NOT NULL OR username IS NOT NULL OR ign_norm IS NOT NULL)
					ORDER BY id',
			),
		);
	}

	/**
		Player without Telegram (/addplayer @username [nick] or /addplayer nick): no tg id, so no
		messages or forms. The organizer or owner may still make them captain. A @username row is
		claimed by ensureByTelegram on the first visit; a nick-only row stays offline. Display
		name is the nick ('' without one).
	*/
	public function createOffline(?string $username, ?string $ign, ?string $ign_norm, DateTimeImmutable $now): Player {
		$id = $this->db->insert(
			'INSERT INTO players (tg_user_id, username, display_name, ign, ign_norm, created_at)
				VALUES (NULL, :u, :d, :i, :n, :c)',
			[
				'u' => $username === null ? null : self::normalizeUsername($username),
				'd' => $ign ?? '',
				'i' => $ign,
				'n' => $ign_norm,
				'c' => DateFormat::toDb($now),
			],
		);
		return $this->find($id) ?? throw new \LogicException('Player insert failed');
	}

	public function fuzzySuggest(string $needle, int $limit = 5): array {

		$needle = mb_strtolower(ltrim(trim($needle), '@'));
		$limit = max(1, min(10, $limit));
		if ($needle === '' || mb_strlen($needle) < 2) {
			return [];
		}
		$rows = $this->db->fetchAll(
			'SELECT * FROM players
				WHERE deleted_at IS NULL AND (username IS NOT NULL OR display_name <> \'\' OR ign_norm IS NOT NULL)
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
		Legacy bulk purge (not called by any command or job). Offline players are now allowed;
		do not run this as maintenance. Deletes every player with tg_user_id IS NULL, roster links and
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

	public function setUsername(int $player_id, ?string $username): void {
		$this->db->run(
			'UPDATE players SET username = :u WHERE id = :id',
			['u' => $username === null ? null : self::normalizeUsername($username), 'id' => $player_id],
		);
	}

	/**
		The card stops being tied to a Telegram account (the owner re-pointed its @username):
		no tg id and no private chat from now on. The next person who writes with that @username
		claims the row (ensureByTelegram).
	*/
	public function unbindTelegram(int $player_id): void {
		$this->db->run(
			'UPDATE players SET tg_user_id = NULL, dm_ok = 0, dm_started_at = NULL WHERE id = :id',
			['id' => $player_id],
		);
	}

	public function preferredTeamName(int $player_id): ?string {
		$value = $this->db->fetchValue('SELECT preferred_team_name FROM players WHERE id = :id', ['id' => $player_id]);
		return $value === null || $value === '' ? null : (string) $value;
	}

	public function setPreferredTeamName(int $player_id, ?string $name): void {
		$this->db->run(
			'UPDATE players SET preferred_team_name = :n WHERE id = :id',
			['n' => $name, 'id' => $player_id],
		);
	}

	/**
		Soft delete: the row stays (past teams, tournaments and ratings point at it) but loses the tg id,
		@username, nick and name, so they are free again and the player vanishes from lists, pickers
		and matching. Rows that only mattered while the player was active (private chat state, form
		contexts, admin flags) are removed.
	*/
	public function softDelete(int $player_id, DateTimeImmutable $now): void {
		$this->db->run(
			'UPDATE players SET deleted_at = :t, tg_user_id = NULL, username = NULL, display_name = \'\',
					ign = NULL, ign_norm = NULL, preferred_team_name = NULL,
					dm_ok = 0, dm_started_at = NULL, dm_group_id = NULL
				WHERE id = :id',
			['t' => DateFormat::toDb($now), 'id' => $player_id],
		);
		$this->db->run('DELETE FROM conversations WHERE player_id = :id', ['id' => $player_id]);
		$this->db->run('DELETE FROM webapp_contexts WHERE actor_player_id = :id', ['id' => $player_id]);
		$this->db->run('DELETE FROM pending_player_actions WHERE actor_player_id = :id', ['id' => $player_id]);
		$this->db->run('DELETE FROM admins WHERE player_id = :id', ['id' => $player_id]);
	}

	/**
		Where the player is still in play: tournaments that are not finished or cancelled.
		Each list item is a tournament (id, title), plus the team name where it applies.

		@return array{
			teams: list<array{tournament_id: int, title: string, team: string}>,
			captain: list<array{tournament_id: int, title: string, team: string}>,
			solo: list<array{tournament_id: int, title: string}>,
			organizer: list<array{tournament_id: int, title: string}>,
			balances: int
		}
	*/
	public function activeUses(int $player_id): array {
		$statuses = TournamentStatus::numbered();
		$in = static function (string $prefix) use ($statuses): array {
			$keys = [];
			$params = [];
			foreach ($statuses as $i => $status) {
				$keys[] = ':' . $prefix . $i;
				$params[$prefix . $i] = $status;
			}
			return [implode(', ', $keys), $params];
		};
		$team_row = static fn (array $r): array => [
			'tournament_id' => (int) $r['tid'],
			'title' => (string) $r['title'],
			'team' => (string) $r['team'],
		];
		$tournament_row = static fn (array $r): array => [
			'tournament_id' => (int) $r['tid'],
			'title' => (string) $r['title'],
		];

		[$list, $params] = $in('s');
		$teams = $this->db->fetchAll(
			'SELECT tr.id AS tid, tr.title AS title, t.name AS team
				FROM team_members tm
				INNER JOIN teams t ON t.id = tm.team_id
				INNER JOIN tournaments tr ON tr.id = tm.tournament_id
				WHERE tm.player_id = :p AND tr.status IN (' . $list . ')
				ORDER BY tr.id',
			['p' => $player_id] + $params,
		);
		[$list, $params] = $in('c');
		$captain = $this->db->fetchAll(
			'SELECT tr.id AS tid, tr.title AS title, t.name AS team
				FROM teams t
				INNER JOIN tournaments tr ON tr.id = t.tournament_id
				WHERE t.captain_id = :p AND tr.status IN (' . $list . ')
				ORDER BY tr.id',
			['p' => $player_id] + $params,
		);
		[$list, $params] = $in('o');
		$solo = $this->db->fetchAll(
			'SELECT tr.id AS tid, tr.title AS title
				FROM solo_players sp
				INNER JOIN tournaments tr ON tr.id = sp.tournament_id
				WHERE sp.player_id = :p AND tr.status IN (' . $list . ')
				ORDER BY tr.id',
			['p' => $player_id] + $params,
		);
		[$list, $params] = $in('g');
		$organizer = $this->db->fetchAll(
			'SELECT tr.id AS tid, tr.title AS title
				FROM tournaments tr
				WHERE tr.organizer_id = :p AND tr.status IN (' . $list . ')
				ORDER BY tr.id',
			['p' => $player_id] + $params,
		);
		$balances = (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM balances WHERE owner_id = :p',
			['p' => $player_id],
		);
		return [
			'teams' => array_map($team_row, $teams),
			'captain' => array_map($team_row, $captain),
			'solo' => array_map($tournament_row, $solo),
			'organizer' => array_map($tournament_row, $organizer),
			'balances' => $balances,
		];
	}

	/**
		Tournaments (any status) where both players appear, in a team or as a solo sign-up.
		A player cannot be in one tournament twice, so a merge is refused for these.

		@return list<array{tournament_id: int, title: string}>
	*/
	public function sharedTournaments(int $first_id, int $second_id): array {
		$rows = $this->db->fetchAll(
			'SELECT tr.id AS tid, tr.title AS title FROM tournaments tr
				WHERE tr.id IN (
					SELECT tournament_id FROM team_members WHERE player_id = :a1
					UNION
					SELECT tournament_id FROM solo_players WHERE player_id = :a2
				) AND tr.id IN (
					SELECT tournament_id FROM team_members WHERE player_id = :b1
					UNION
					SELECT tournament_id FROM solo_players WHERE player_id = :b2
				)
				ORDER BY tr.id',
			['a1' => $first_id, 'a2' => $first_id, 'b1' => $second_id, 'b2' => $second_id],
		);
		return array_map(static fn (array $r): array => [
			'tournament_id' => (int) $r['tid'],
			'title' => (string) $r['title'],
		], $rows);
	}

	/**
		Full merge: $from_id is folded into the survivor $into_id and soft-deleted.
		Moves everything that points at the duplicate (team places, captaincy, solo sign-ups,
		organized tournaments, owned scales, rating authorship, nick history) and the ratings the
		survivor lacks. Identity fields the survivor does not have yet (nick, @username, tg id, name,
		preferred team name) move over; the duplicate's nick goes to the survivor's history when
		both had one. The caller checks permissions and conflicts (sharedTournaments, two tg ids).
	*/
	public function absorb(int $into_id, int $from_id, DateTimeImmutable $now): void {
		if ($into_id === $from_id) {
			return;
		}
		$into = $this->find($into_id) ?? throw new \LogicException('Merge target vanished');
		$from = $this->find($from_id) ?? throw new \LogicException('Merge source vanished');
		$dm = $this->db->fetchOne(
			'SELECT dm_ok, dm_started_at, dm_group_id FROM players WHERE id = :id',
			['id' => $from_id],
		) ?? [];
		$from_preferred = $this->preferredTeamName($from_id);
		$ids = ['into' => $into_id, 'from' => $from_id];

		$this->db->run('UPDATE balance_ratings SET updated_by = :into WHERE updated_by = :from', $ids);
		// Ratings the survivor does not have yet; the survivor's own level wins.
		$this->db->run(
			'INSERT INTO balance_ratings (balance_id, player_id, level, updated_by, updated_at)
				SELECT br.balance_id, :into, br.level, br.updated_by, br.updated_at
				FROM balance_ratings br
				WHERE br.player_id = :from
					AND NOT EXISTS (
						SELECT 1 FROM balance_ratings x
						WHERE x.balance_id = br.balance_id AND x.player_id = :into2
					)',
			$ids + ['into2' => $into_id],
		);
		$this->db->run('DELETE FROM balance_ratings WHERE player_id = :from', ['from' => $from_id]);
		$this->db->run('UPDATE team_members SET player_id = :into WHERE player_id = :from', $ids);
		$this->db->run('UPDATE solo_players SET player_id = :into WHERE player_id = :from', $ids);
		$this->db->run('UPDATE teams SET captain_id = :into WHERE captain_id = :from', $ids);
		$this->db->run('UPDATE tournaments SET organizer_id = :into WHERE organizer_id = :from', $ids);
		$this->db->run('UPDATE balances SET owner_id = :into WHERE owner_id = :from', $ids);
		$this->db->run('UPDATE player_ign_history SET player_id = :into WHERE player_id = :from', $ids);
		$this->db->run('UPDATE player_ign_history SET changed_by = :into WHERE changed_by = :from', $ids);

		// Free the duplicate's unique tg id / @username / nick before the survivor takes them.
		$this->softDelete($from_id, $now);

		if ($from->hasIgn()) {
			if (!$into->hasIgn()) {
				$this->updateIgn($into_id, $from->ign, $from->ign_norm);
			} elseif ($from->ign_norm !== $into->ign_norm) {
				$this->recordIgnHistory($into_id, (string) $from->ign, (string) $from->ign_norm, $now, null);
			}
		}
		if (($into->username === null || $into->username === '') && $from->username !== null && $from->username !== '') {
			$this->setUsername($into_id, $from->username);
		}
		if ($into->tg_user_id === null && $from->tg_user_id !== null) {
			$this->db->run(
				'UPDATE players SET tg_user_id = :tg, dm_ok = :ok, dm_started_at = :st, dm_group_id = :g WHERE id = :id',
				[
					'tg' => $from->tg_user_id,
					'ok' => (int) ($dm['dm_ok'] ?? 0),
					'st' => $dm['dm_started_at'] ?? null,
					'g' => $dm['dm_group_id'] ?? null,
					'id' => $into_id,
				],
			);
		}
		if ($into->display_name === '' && $from->display_name !== '') {
			$this->db->run(
				'UPDATE players SET display_name = :d WHERE id = :id',
				['d' => $from->display_name, 'id' => $into_id],
			);
		}
		if ($from_preferred !== null && $this->preferredTeamName($into_id) === null) {
			$this->setPreferredTeamName($into_id, $from_preferred);
		}
	}

	public static function normalizeUsername(string $username): string {

		return mb_strtolower(ltrim(trim($username), '@'));
	}
}
