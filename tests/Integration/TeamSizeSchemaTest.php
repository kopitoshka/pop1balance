<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\MoscowTime;
use P1Bot\Domain\TournamentService;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Tests\Support\IntegrationTestCase;
use PDOException;

/**
	Team size 1..12: the DB check (SQLite test schema, or migrations 001..016 with P1BOT_TEST_MYSQL_DSN)
	and migration 016 itself.
*/
class TeamSizeSchemaTest extends IntegrationTestCase {

	protected function insert(int $team_size): void {
		$this->app->tournament_repo->create([
			'chat_id' => self::CHAT_ID,
			'organizer_id' => $this->owner->id,
			'title' => 'Размер ' . $team_size,
			'starts_at' => MoscowTime::parse('2026-11-23 20:00'),
			'team_size' => $team_size,
			'balance_id' => null,
			'reference_team' => null,
			'max_points' => null,
			'max_players' => 24,
			'status' => TournamentStatus::DRAFT,
		], $this->clock->now());
	}

	public function testCheckAllowsOneToTwelve(): void {
		foreach ([1, 5, 12] as $size) {
			$this->insert($size);
		}
		self::assertSame(1, $this->app->tournament_repo->find(1)->team_size);
		self::assertSame(12, $this->app->tournament_repo->find(3)->team_size);
		foreach ([0, 13] as $size) {
			try {
				$this->insert($size);
				self::fail('team_size ' . $size . ' must be rejected by the DB');
			} catch (PDOException $e) {
				self::assertStringContainsStringIgnoringCase('constraint', $e->getMessage());
			}
		}
	}

	public function testServiceLimitsMatchTheSchema(): void {
		self::assertSame(1, TournamentService::MIN_TEAM_SIZE);
		self::assertSame(12, TournamentService::MAX_TEAM_SIZE);
		$schema = (string) file_get_contents(dirname(__DIR__) . '/Support/schema.sqlite.sql');
		self::assertStringContainsString('CHECK (team_size BETWEEN 1 AND 12)', $schema);
	}

	public function testMigrationReplacesTheOldCheck(): void {
		$sql = (string) file_get_contents(dirname(__DIR__, 2) . '/migrations/016_team_size_1_to_12.sql');
		self::assertStringContainsString("CONSTRAINT_NAME = 'chk_tournaments_team_size'", $sql);
		self::assertStringContainsString('DROP CONSTRAINT chk_tournaments_team_size', $sql, 'MariaDB');
		self::assertStringContainsString('DROP CHECK chk_tournaments_team_size', $sql, 'MySQL 8');
		self::assertStringContainsString(
			'ADD CONSTRAINT chk_tournaments_team_size CHECK (team_size BETWEEN 1 AND 12)',
			$sql,
		);
		self::assertStringNotContainsString("\n    ", $sql, 'tabs, not spaces');
	}
}
