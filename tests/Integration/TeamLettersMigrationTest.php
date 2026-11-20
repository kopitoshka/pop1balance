<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
	Migration 017 applied as a file to a pre-017 SQLite teams table (the MariaDB run builds the
	whole test database from migrations/*.sql, 017 included).
*/
class TeamLettersMigrationTest extends TestCase {

	public function testMigrationAddsBackfillsAndProtectsLetters(): void {
		$pdo = new PDO('sqlite::memory:');
		$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$pdo->exec('CREATE TABLE teams (
			id INTEGER PRIMARY KEY,
			tournament_id INTEGER NOT NULL,
			name TEXT NOT NULL,
			status TEXT NOT NULL,
			registered_at TEXT NULL
		)');
		$pdo->exec("INSERT INTO teams VALUES
			(1, 1, 'Forming', 'forming', NULL),
			(2, 1, 'Late', 'registered', '2026-11-02 10:00:00'),
			(3, 1, 'Early', 'registered', '2026-11-01 10:00:00'),
			(4, 2, 'Other', 'forming', NULL)");
		$pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/017_team_letters.sql'));
		$letters = $pdo->query('SELECT id, letter FROM teams ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
		self::assertSame(['1' => 'C', '2' => 'B', '3' => 'A', '4' => 'A'], array_map('strval', $letters) + []);
		foreach (["UPDATE teams SET letter = 'A' WHERE id = 2", "UPDATE teams SET letter = 'a' WHERE id = 4", "UPDATE teams SET letter = 'AB' WHERE id = 4"] as $bad) {
			try {
				$pdo->exec($bad);
				self::fail($bad);
			} catch (PDOException $e) {
				self::assertStringContainsStringIgnoringCase('constraint', $e->getMessage());
			}
		}
		$pdo->exec('UPDATE teams SET letter = NULL WHERE id IN (1, 2)');
		self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM teams WHERE letter IS NULL')->fetchColumn(), 'several teams may lack a letter');
	}
}
