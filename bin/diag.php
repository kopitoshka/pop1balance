<?php

declare(strict_types=1);

// Health check:     php bin/diag.php
// Player dig:       php bin/diag.php --player @nick   (or IGN / numeric player id)
// Placeholders dig: php bin/diag.php --placeholders

use P1Bot\App\Diagnostics;
use P1Bot\Http\CurlHttpClient;
use P1Bot\Support\Database;
use P1Bot\Telegram\BotApi;

require dirname(__DIR__) . '/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';

$player_arg = null;
$placeholders_mode = in_array('--placeholders', $argv, true);
foreach ($argv as $i => $arg) {
	if ($arg === '--player' && isset($argv[$i + 1])) {
		$player_arg = (string) $argv[$i + 1];
		break;
	}
	if (str_starts_with($arg, '--player=')) {
		$player_arg = substr($arg, strlen('--player='));
		break;
	}
}

$db = Database::fromConfig($config['db']);

if ($placeholders_mode) {
	fwrite(STDOUT, "== Placeholder players (tg_user_id IS NULL)
");
	$rows = $db->fetchAll('SELECT * FROM players WHERE tg_user_id IS NULL ORDER BY id');
	if ($rows === []) {
		fwrite(STDOUT, "None.
");
		exit(0);
	}
	foreach ($rows as $row) {
		$id = (int) $row['id'];
		$refs = [];
		foreach ([
			['team_members', 'player_id'],
			['solo_players', 'player_id'],
			['teams', 'captain_id'],
			['balance_ratings', 'player_id'],
		] as [$table, $col]) {
			$n = (int) $db->fetchValue(
				"SELECT COUNT(*) FROM {$table} WHERE {$col} = :p",
				['p' => $id],
			);
			if ($n > 0) {
				$refs[] = "{$table}×{$n}";
			}
		}
		$status = $refs === [] ? 'UNREFERENCED (cleaned by 011 if applied)' : 'REFERENCED: ' . implode(', ', $refs);
		fwrite(STDOUT, sprintf(
			"#%d @%s ign=%s — %s
",
			$id,
			$row['username'] ?? 'NULL',
			$row['ign'] ?? 'NULL',
			$status,
		));
		if ($refs !== []) {
			$teams = $db->fetchAll(
				'SELECT tm.tournament_id, tm.team_id, t.name AS team_name
					FROM team_members tm JOIN teams t ON t.id = tm.team_id
					WHERE tm.player_id = :p',
				['p' => $id],
			);
			foreach ($teams as $tm) {
				fwrite(STDOUT, sprintf(
					"    in tournament #%d team #%d «%s»
",
					(int) $tm['tournament_id'],
					(int) $tm['team_id'],
					$tm['team_name'],
				));
			}
		}
	}
	exit(0);
}

if ($player_arg !== null) {
	$ref = ltrim(trim($player_arg), '@');
	fwrite(STDOUT, "== Player dig: {$player_arg}\n");

	$rows = [];
	if (ctype_digit($ref)) {
		$row = $db->fetchOne('SELECT * FROM players WHERE id = :id OR tg_user_id = :tg', [
			'id' => (int) $ref,
			'tg' => (int) $ref,
		]);
		if ($row !== null) {
			$rows[] = $row;
		}
	}
	$by_user = $db->fetchAll(
		'SELECT * FROM players WHERE username = :u OR ign_norm = :n OR display_name = :d',
		['u' => mb_strtolower($ref), 'n' => mb_strtolower($ref), 'd' => $ref],
	);
	foreach ($by_user as $row) {
		$rows[$row['id']] = $row;
	}
	// Also fuzzy: username/ign contains
	$like = $db->fetchAll(
		"SELECT * FROM players WHERE username LIKE :q OR ign LIKE :q2 OR display_name LIKE :q3 LIMIT 20",
		['q' => '%' . mb_strtolower($ref) . '%', 'q2' => '%' . $ref . '%', 'q3' => '%' . $ref . '%'],
	);
	foreach ($like as $row) {
		$rows[$row['id']] = $row;
	}
	$rows = array_values($rows);

	if ($rows === []) {
		fwrite(STDOUT, "No player rows matched.\n");
		exit(0);
	}

	$default = $db->fetchOne('SELECT * FROM balances WHERE is_default = 1 ORDER BY id LIMIT 1');
	fwrite(STDOUT, 'Default scale: ' . ($default === null ? '(none)' : "#{$default['id']} «{$default['name']}»") . "\n\n");

	foreach ($rows as $row) {
		fwrite(STDOUT, sprintf(
			"— player #%d  tg=%s  @%s  name=%s  ign=%s\n",
			(int) $row['id'],
			$row['tg_user_id'] ?? 'NULL',
			$row['username'] ?? 'NULL',
			$row['display_name'] ?? '',
			$row['ign'] ?? 'NULL',
		));
		$ratings = $db->fetchAll(
			'SELECT br.balance_id, br.level, b.name, b.is_default, b.parent_id
				FROM balance_ratings br
				JOIN balances b ON b.id = br.balance_id
				WHERE br.player_id = :p
				ORDER BY br.balance_id',
			['p' => (int) $row['id']],
		);
		if ($ratings === []) {
			fwrite(STDOUT, "  ratings: (none — enrollment uses default 3)\n");
		} else {
			foreach ($ratings as $r) {
				fwrite(STDOUT, sprintf(
					"  rating: scale #%d «%s»%s parent=%s → level %d\n",
					(int) $r['balance_id'],
					$r['name'],
					((int) $r['is_default'] === 1) ? ' [DEFAULT]' : '',
					$r['parent_id'] ?? 'NULL',
					(int) $r['level'],
				));
			}
		}
		$teams = $db->fetchAll(
			'SELECT tm.tournament_id, tm.team_id, t.name AS team_name
				FROM team_members tm
				JOIN teams t ON t.id = tm.team_id
				WHERE tm.player_id = :p
				ORDER BY tm.tournament_id DESC
				LIMIT 10',
			['p' => (int) $row['id']],
		);
		foreach ($teams as $tm) {
			fwrite(STDOUT, sprintf(
				"  team_member: tournament #%d team #%d «%s»\n",
				(int) $tm['tournament_id'],
				(int) $tm['team_id'],
				$tm['team_name'],
			));
		}
		fwrite(STDOUT, "\n");
	}

	// Duplicate hint: same username-ish across rows already listed; also tg-less twins
	$placeholders = $db->fetchAll('SELECT id, username, ign FROM players WHERE tg_user_id IS NULL AND username IS NOT NULL');
	if ($placeholders !== []) {
		fwrite(STDOUT, "== Placeholders without tg_user_id (" . count($placeholders) . ")\n");
		foreach (array_slice($placeholders, 0, 30) as $ph) {
			fwrite(STDOUT, sprintf("  #%d @%s ign=%s\n", (int) $ph['id'], $ph['username'], $ph['ign'] ?? 'NULL'));
		}
	}
	exit(0);
}

$token = (string) ($config['telegram']['token'] ?? '');
$diag = new Diagnostics(
	$config,
	$token !== '' ? new BotApi(new CurlHttpClient(), $token) : null,
	static function () use ($db): Database {
		return $db;
	},
	dirname(__DIR__) . '/migrations',
);
fwrite(STDOUT, implode("\n", $diag->lines()) . "\n");
