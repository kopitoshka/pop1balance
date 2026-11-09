<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\Exception\LevelLimitExceeded;
use P1Bot\Domain\Exception\NotFound;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Model\TeamMember;
use P1Bot\Domain\TeamStatus;
use P1Bot\Tests\Support\IntegrationTestCase;

class TeamServiceTest extends IntegrationTestCase {

	public function testFullTeamWithinLimitIsRegistered(): void {
		$t = $this->openTournament(); // 3 players, limit 10
		[$cap, $a, $b] = [$this->player('cap'), $this->player('a'), $this->player('b')];
		$this->rate($cap, 5);
		$this->rate($a, 1);
		// $b is unknown -> 3. 5 + 1 + 3 = 9 <= 10

		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id, $b->id]);

		self::assertSame(TeamStatus::REGISTERED, $entry->team->status);
		self::assertNotNull($entry->team->registered_at);
		self::assertSame($cap->id, $entry->team->captain_id);
		self::assertSame(9, $this->app->teams->pointsOf($entry));
		self::assertSame(3, $entry->size());
	}

	public function testExceedingLimitSaysByHowMuch(): void {
		$t = $this->openTournament();
		[$cap, $a, $b] = [$this->player('cap'), $this->player('a'), $this->player('b')];
		foreach ([$cap, $a, $b] as $p) {
			$this->rate($p, 4);
		}

		try {
			$this->app->teams->createTeam($t->id, $cap->id, 'Too strong', [$a->id, $b->id]);
			self::fail('Expected LevelLimitExceeded');
		} catch (LevelLimitExceeded $e) {
			self::assertSame(2, $e->excess());
			self::assertStringContainsString('Команда набирает 12', $e->getMessage());
			self::assertStringContainsString('лимит турнира 10', $e->getMessage());
			self::assertStringContainsString('@cap 4', $e->getMessage());
			self::assertStringContainsString('@a 4', $e->getMessage());
			self::assertStringContainsString('@b 4', $e->getMessage());
		}
		self::assertNull($this->app->teams->findByName($t->id, 'Too strong'), 'nothing persisted');
	}

	public function testPartialTeamIsFormingAndRegistersWhenFull(): void {
		$t = $this->openTournament();
		$cap = $this->player('cap');
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves');
		self::assertSame(TeamStatus::FORMING, $entry->team->status);
		self::assertNull($entry->team->registered_at);

		$this->app->teams->addPlayer($cap->id, $entry->team->id, $this->player('a')->id);
		$this->clock->advance('+10 minutes');
		$entry = $this->app->teams->addPlayer($cap->id, $entry->team->id, $this->player('b')->id);

		self::assertSame(TeamStatus::REGISTERED, $entry->team->status);
		self::assertSame('2026-11-09 12:10:00', $entry->team->registered_at->format('Y-m-d H:i:s'));
	}

	public function testAddPlayerOverLimitOrOverSize(): void {
		$t = $this->openTournament();
		[$cap, $a, $strong, $extra] = [
			$this->player('cap'),
			$this->player('a'),
			$this->player('strong'),
			$this->player('extra'),
		];
		$this->rate($strong, 6);
		$team = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id])->team;

		try {
			$this->app->teams->addPlayer($cap->id, $team->id, $strong->id); // 3 + 3 + 6 = 12
			self::fail('Expected LevelLimitExceeded');
		} catch (LevelLimitExceeded $e) {
			self::assertSame(2, $e->excess());
		}

		$this->app->teams->addPlayer($cap->id, $team->id, $this->player('b')->id);
		$this->expectException(ValidationError::class);
		$this->app->teams->addPlayer($cap->id, $team->id, $extra->id);
	}

	public function testOnlyCaptainChangesRoster(): void {
		$t = $this->openTournament();
		[$cap, $a] = [$this->player('cap'), $this->player('a')];
		$team = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id])->team;

		$this->expectException(PermissionDenied::class);
		$this->app->teams->addPlayer($a->id, $team->id, $this->player('b')->id);
	}

	public function testLevelChangeUpdatesTeamPointsLive(): void {
		$t = $this->openTournament(); // limit 10
		[$cap, $a, $b] = [$this->player('cap'), $this->player('a'), $this->player('b')];
		$this->rate($cap, 3);
		$this->rate($a, 3);
		$this->rate($b, 3);
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id, $b->id]);
		self::assertSame(9, $this->app->teams->pointsOf($entry));

		$this->rate($a, 5); // profile change immediately affects team total
		$entry = $this->app->teams->get($entry->team->id);
		self::assertSame(11, $this->app->teams->pointsOf($entry));

		// Adding a player uses live levels for the limit check (11 already over 10 if we tried another)
		try {
			$this->app->teams->createTeam($t->id, $this->player('cap2')->id, 'Bears', [
				$this->player('x')->id,
				$this->player('y')->id,
			]);
		} catch (\Throwable $e) {
			// unrelated — just ensure Wolves still readable
		}
		self::assertSame(11, $this->app->teams->pointsOf($this->app->teams->get($entry->team->id)));
	}

	public function testLiveLevelBlocksAddWhenOverLimit(): void {
		$t = $this->openTournament(); // limit 10
		[$cap, $a] = [$this->player('cap'), $this->player('a')];
		$this->rate($cap, 3);
		$this->rate($a, 3);
		$team = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id])->team;
		$strong = $this->player('strong');
		$this->rate($strong, 6); // 3+3+6 = 12 > 10
		try {
			$this->app->teams->addPlayer($cap->id, $team->id, $strong->id);
			self::fail('expected LevelLimitExceeded');
		} catch (LevelLimitExceeded $e) {
			self::assertStringContainsString('Команда набирает 12', $e->getMessage());
		}
		// Raising an existing member's level does not auto-kick; total just grows.
		$this->rate($a, 6);
		self::assertSame(9, $this->app->teams->pointsOf($this->app->teams->get($team->id))); // cap3+a6 = 9 with 2 members
	}

	public function testPlayerIsUniquePerTournament(): void {
		$t = $this->openTournament();
		[$cap1, $cap2, $a] = [$this->player('cap1'), $this->player('cap2'), $this->player('a')];
		$this->app->teams->createTeam($t->id, $cap1->id, 'Wolves', [$a->id]);

		try {
			$this->app->teams->createTeam($t->id, $cap2->id, 'Bears', [$a->id]);
			self::fail('Expected ValidationError');
		} catch (ValidationError $e) {
			self::assertSame('@a уже играет в команде «Wolves» на этом турнире.', $e->getMessage());
		}

		$this->expectException(ValidationError::class);
		$this->app->teams->joinSolo($t->id, $a->id);
	}

	public function testAddingSoloPlayerToTeamRemovesSoloEntry(): void {
		$t = $this->openTournament();
		[$cap, $solo] = [$this->player('cap'), $this->player('solo')];
		$this->app->teams->joinSolo($t->id, $solo->id);
		self::assertSame('main', $this->app->tournaments->lineup($t->id)->placementOf($solo->id));

		$team = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves')->team;
		$this->app->teams->addPlayer($cap->id, $team->id, $solo->id);

		$lineup = $this->app->tournaments->lineup($t->id);
		self::assertSame([], $lineup->main_solo);
		self::assertSame('forming', $lineup->placementOf($solo->id));
	}

	public function testSoloJoinAndLeave(): void {
		$t = $this->openTournament();
		$p = $this->player('p');
		$this->app->teams->joinSolo($t->id, $p->id);

		try {
			$this->app->teams->joinSolo($t->id, $p->id);
			self::fail('Double solo join must fail');
		} catch (ValidationError $e) {
			self::assertStringContainsString('уже записан', $e->getMessage());
		}

		$this->app->teams->leaveSolo($t->id, $p->id);
		self::assertNull($this->app->tournaments->lineup($t->id)->placementOf($p->id));
		$this->expectException(NotFound::class);
		$this->app->teams->leaveSolo($t->id, $p->id);
	}

	public function testRemovingMemberUnregistersAndReplaceKeepsQueuePlace(): void {
		$t = $this->openTournament();
		[$cap, $a, $b, $c] = [$this->player('cap'), $this->player('a'), $this->player('b'), $this->player('c')];
		$team = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id, $b->id])->team;
		$registered_at = $team->registered_at;

		$this->clock->advance('+1 hour');
		$entry = $this->app->teams->replacePlayer($cap->id, $team->id, $b->id, $c->id);
		self::assertSame(TeamStatus::REGISTERED, $entry->team->status);
		self::assertEquals($registered_at, $entry->team->registered_at);

		$entry = $this->app->teams->removePlayer($cap->id, $team->id, $c->id);
		self::assertSame(TeamStatus::FORMING, $entry->team->status);
		self::assertNull($entry->team->registered_at);

		$this->expectException(ValidationError::class);
		$this->app->teams->removePlayer($cap->id, $team->id, $cap->id);
	}

	public function testLeaveTeamRenameAndTransferCaptain(): void {
		$t = $this->openTournament();
		[$cap, $a, $b] = [$this->player('cap'), $this->player('a'), $this->player('b')];
		$team = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id, $b->id])->team;

		$entry = $this->app->teams->leaveTeam($b->id, $team->id);
		self::assertSame(2, $entry->size());

		$entry = $this->app->teams->renameTeam($cap->id, $team->id, 'Волки');
		self::assertSame('Волки', $entry->team->name);

		$entry = $this->app->teams->transferCaptain($cap->id, $team->id, $a->id);
		self::assertSame($a->id, $entry->team->captain_id);

		$this->expectException(PermissionDenied::class);
		$this->app->teams->renameTeam($cap->id, $team->id, 'Снова Wolves');
	}

	public function testTeamNamesAreUniqueCaseInsensitive(): void {
		$t = $this->openTournament();
		$this->app->teams->createTeam($t->id, $this->player('cap1')->id, 'Волки');
		$this->expectException(ValidationError::class);
		$this->app->teams->createTeam($t->id, $this->player('cap2')->id, 'ВОЛКИ');
	}

	public function testWithdrawDeletesTeamAndReservePromotesItself(): void {
		$t = $this->openTournament(2, null, 4);
		$teams = [];
		foreach (['a', 'b', 'c'] as $s) {
			$teams[$s] = $this->app->teams->createTeam(
				$t->id,
				$this->player("cap$s")->id,
				"Team $s",
				[$this->player("m$s")->id],
			)->team;
			$this->clock->advance();
		}
		$lineup = $this->app->tournaments->lineup($t->id);
		self::assertSame([$teams['c']->id], array_map(static fn ($e): int => $e->team->id, $lineup->reserve_teams));

		$this->app->teams->withdrawTeam($teams['a']->captain_id, $teams['a']->id);

		self::assertNull($this->app->teams->findByName($t->id, 'Team a'));
		$lineup = $this->app->tournaments->lineup($t->id);
		self::assertSame(
			[$teams['b']->id, $teams['c']->id],
			array_map(static fn ($e): int => $e->team->id, $lineup->main_teams),
		);
		self::assertSame('занято 4/4, резерв 0', $lineup->summary());
		self::assertSame(
			0,
			(int) $this->db->fetchValue(
				'SELECT COUNT(*) FROM team_members WHERE team_id = :id',
				['id' => $teams['a']->id],
			),
		);
	}

	public function testRegistrationMustBeOpen(): void {
		$t = $this->openTournament();
		$this->app->tournaments->closeRegistration($this->owner->id, $t->id);
		$this->expectException(ValidationError::class);
		$this->app->teams->joinSolo($t->id, $this->player('late')->id);
	}

	public function testNoBalanceMeansNoLimit(): void {
		$t = $this->openTournament(2, null);
		[$cap, $a] = [$this->player('cap'), $this->player('a')];
		$this->rate($cap, 6);
		$this->rate($a, 6);
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Pros', [$a->id]);
		self::assertSame(TeamStatus::REGISTERED, $entry->team->status);
	}

	/**
		/level (default scale) and createTeam must count the same levels.
		A tournament pointing at another empty scale must not invent different points.
	*/
	public function testLevelViewMatchesTeamPointsEvenIfTournamentScaleDiffers(): void {
		$t = $this->openTournament(); // limit 10
		[$cap, $a, $b] = [$this->player('cap'), $this->player('a'), $this->player('b')];
		$this->rate($cap, 3);
		$this->rate($a, 3);
		$this->rate($b, 3);
		$other = $this->app->balances->create(self::CHAT_ID, $this->owner->id, 'Другая');
		$this->app->tournament_repo->update($t->id, ['balance_id' => $other->id]);
		$t = $this->app->tournament_repo->find($t->id);

		$default = $this->app->balances->viewScaleId();
		self::assertSame(3, $this->app->balances->levelOf($default, $cap->id));
		self::assertSame(3, $this->app->balances->levelOf($default, $a->id));
		self::assertSame(3, $this->app->balances->levelOf($default, $b->id));

		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id, $b->id]);
		self::assertSame(9, $this->app->teams->pointsOf($entry));
	}

	/**
		Duplicate rows: real user without @username + placeholder @username with a level.
		Team used to count the placeholder (higher), while /level @mention showed the real user (default 3).
	*/
	public function testDuplicateUsernamePlaceholderMergesIntoRealUser(): void {
		$real = $this->app->players->ensureTelegramId(424242, 'Real', $this->clock->now());
		$placeholder = $this->insertPlaceholder('zoloto');
		self::assertNotSame($real->id, $placeholder->id);
		$this->rate($placeholder, 4);

		// Real user publishes username — placeholder ratings must move over.
		$merged = $this->app->players->ensureByTelegram(424242, 'zoloto', 'Real', $this->clock->now());
		self::assertSame($real->id, $merged->id);
		self::assertSame('zoloto', $merged->username);
		self::assertSame(4, $this->app->balances->levelOf($this->app->balances->viewScaleId(), $merged->id));
		self::assertNull($this->app->players->find($placeholder->id)->username);

		$t = $this->openTournament(); // limit 10
		$a = $this->player('mate_a');
		$b = $this->player('mate_b');
		$this->rate($a, 3);
		$this->rate($b, 3);
		// 4+3+3 = 10 — allowed; previously a phantom 4+3+3 on wrong ids vs view 3+3+3 caused confusion
		$entry = $this->app->teams->createTeam($t->id, $merged->id, 'Z Pack', [$a->id, $b->id]);
		self::assertSame(10, $this->app->teams->pointsOf($entry));
	}

	public function testCaptainAtMentionResolvesToActorNotPlaceholder(): void {
		$cap = $this->player('cap');
		$resolved = $this->app->player_resolver->fromReference('@cap', $cap);
		self::assertSame($cap->id, $resolved->id);
		$mate = $this->player('mate');
		$ids = [];
		foreach (['@cap', '@mate'] as $ref) {
			$ids[] = $this->app->player_resolver->fromReference($ref, $cap)->id;
		}
		$roster = array_values(array_unique(array_merge([$cap->id], $ids)));
		self::assertSame([$cap->id, $mate->id], $roster, 'captain must not appear twice');
	}

	public function testRefusalShowsPerPlayerBreakdownIncludingDefault(): void {
		$t = $this->openTournament(); // limit 10 → set to 9
		$this->app->tournament_repo->update($t->id, ['max_points' => 9]);
		[$cap, $a, $b] = [$this->player('cap'), $this->player('a'), $this->player('b')];
		$this->rate($cap, 4);
		$this->app->nicks->setOwn($cap->id, 'Zoloto');
		// a and b unknown → default 3
		try {
			$this->app->teams->createTeam($t->id, $cap->id, 'Nope', [$a->id, $b->id]);
			self::fail('expected limit');
		} catch (LevelLimitExceeded $e) {
			$msg = $e->getMessage();
			self::assertStringContainsString('Команда набирает 10', $msg);
			self::assertStringContainsString('лимит турнира 9', $msg);
			self::assertStringContainsString('Zoloto (@cap) 4', $msg);
			self::assertStringContainsString('@a 3 — уровень не задан, считаю 3', $msg);
			self::assertStringContainsString('@b 3 — уровень не задан, считаю 3', $msg);
		}
	}
}
