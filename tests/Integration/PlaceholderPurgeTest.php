<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Tests\Support\IntegrationTestCase;

class PlaceholderPurgeTest extends IntegrationTestCase {

	/**
		A team captained by a player without Telegram, written straight to the repository:
		TeamService no longer lets such a player be captain.
		@param list<int> $others
	*/
	protected function legacyTeam(int $tournament_id, int $captain_id, string $name, array $others): \P1Bot\Domain\Model\TeamEntry {
		$team = $this->app->team_repo->create($tournament_id, $captain_id, $name, $this->clock->now());
		foreach (array_merge([$captain_id], $others) as $id) {
			$this->app->team_repo->addMember($team, $id, $this->clock->now());
		}
		return new \P1Bot\Domain\Model\TeamEntry($team, $this->app->team_repo->members($team->id));
	}

	public function testPurgeRemovesReferencedPlaceholderAndEmptyTeam(): void {
		$ph = $this->insertPlaceholder('tired_angel1');
		$t = $this->openTournament(2, null);
		$entry = $this->legacyTeam($t->id, $ph->id, 'Ghosts', []);
		$this->rate($ph, 4);

		$result = $this->app->players->purgePlaceholders();
		self::assertSame(1, $result['deleted']);
		self::assertSame(1, $result['teams_removed']);
		self::assertSame(0, $result['skipped']);
		self::assertNull($this->app->players->find($ph->id));
		self::assertSame(
			0,
			(int) $this->db->fetchValue('SELECT COUNT(*) FROM players WHERE tg_user_id IS NULL'),
		);
		self::assertSame(
			0,
			(int) $this->db->fetchValue('SELECT COUNT(*) FROM teams WHERE id = :id', ['id' => $entry->team->id]),
		);
		self::assertSame(
			0,
			(int) $this->db->fetchValue(
				'SELECT COUNT(*) FROM balance_ratings WHERE player_id = :p',
				['p' => $ph->id],
			),
		);
	}

	public function testPurgeReassignsCaptainAndKeepsTeamWithRealMembers(): void {
		$ph = $this->insertPlaceholder('old_mate');
		$real = $this->player('alive');
		$t = $this->openTournament(2, null);
		$entry = $this->legacyTeam($t->id, $ph->id, 'Mixed', [$real->id]);

		$result = $this->app->players->purgePlaceholders();
		self::assertSame(1, $result['deleted']);
		self::assertSame(0, $result['teams_removed']);
		$team = $this->db->fetchOne('SELECT * FROM teams WHERE id = :id', ['id' => $entry->team->id]);
		self::assertNotNull($team);
		self::assertSame($real->id, (int) $team['captain_id']);
		self::assertSame(
			1,
			(int) $this->db->fetchValue(
				'SELECT COUNT(*) FROM team_members WHERE team_id = :id',
				['id' => $entry->team->id],
			),
		);
	}

	public function testEnsureByTelegramKeepsUsernameWhenUpdateOmitsIt(): void {
		$p = $this->app->players->ensureByTelegram(9001, 'mata_user', 'Mata', $this->clock->now());
		self::assertSame('mata_user', $p->username);
		$again = $this->app->players->ensureByTelegram(9001, null, 'Mata Updated', $this->clock->now());
		self::assertSame('mata_user', $again->username);
		self::assertSame('Mata Updated', $again->display_name);
	}

	public function testUsersSharedWithoutUsernameStoresDisplayNameOnly(): void {
		$vasya = $this->player('picker2');
		$raw = [
			'update_id' => 900,
			'message' => [
				'message_id' => 90,
				'chat' => ['id' => $this->tgIdOf($vasya), 'type' => 'private'],
				'from' => [
					'id' => $this->tgIdOf($vasya),
					'is_bot' => false,
					'first_name' => 'Vasya',
					'username' => 'picker2',
				],
				'date' => 0,
				'users_shared' => [
					'request_id' => 1,
					'users' => [
						[
							'user_id' => 7549209141,
							'first_name' => 'Mata',
						],
					],
				],
			],
		];
		$this->dispatch($raw);
		$mata = $this->app->players->findByTelegramId(7549209141);
		self::assertNotNull($mata);
		self::assertNull($mata->username);
		self::assertSame('Mata', $mata->display_name);
		self::assertStringContainsString('Mata', $this->http->lastText());
		self::assertStringContainsString('новая', $this->http->lastText());
	}
}
