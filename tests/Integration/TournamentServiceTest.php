<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\CreateTournament;
use P1Bot\Domain\EditTournament;
use P1Bot\Domain\Exception\PermissionDenied;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Support\DateFormat;
use P1Bot\Tests\Support\IntegrationTestCase;
use P1Bot\View\Messages;

class TournamentServiceTest extends IntegrationTestCase {

	public function testCreateComputesLimitAndStoresUtc(): void {
		$t = $this->app->tournaments->create(new CreateTournament(
			self::CHAT_ID,
			$this->admin->id,
			'  Субботний кубок ',
			'2026-11-14 19:00',
			3,
			'средний, средний, средний+',
		));

		self::assertSame('Субботний кубок', $t->title);
		self::assertSame($this->admin->id, $t->organizer_id);
		self::assertSame('2026-11-14 16:00:00', DateFormat::toDb($t->starts_at));
		self::assertSame('3,3,4', $t->reference_team);
		self::assertSame(10, $t->max_points);
		self::assertSame(24, $t->max_players);
		self::assertSame($this->app->balances->findDefault()->id, $t->balance_id);
		self::assertSame(TournamentStatus::DRAFT, $t->status);
		self::assertSame('средний (10 баллов на троих)', $t->levelLabel());
	}

	public function testElevenPointsForThreeIsAveragePlus(): void {
		$t = $this->openTournament(3, '3,4,4');
		self::assertSame('средний+ (11 баллов на троих)', $t->levelLabel());
	}

	/**
		Legacy rows created before the reference team became mandatory still work without a limit.
	*/
	public function testLegacyTournamentWithoutReferenceHasNoLimit(): void {
		$t = $this->openTournament(2, null, 16);
		self::assertNull($t->balance_id);
		self::assertNull($t->max_points);
		self::assertFalse($t->hasLevelLimit());
		self::assertSame('без ограничения по уровню', $t->levelLabel());
		self::assertSame(16, $t->max_players);
	}

	public function testValidation(): void {
		$cases = [
			'team too big' => new CreateTournament(
				self::CHAT_ID,
				$this->owner->id,
				'X',
				'2026-11-14 19:00',
				5,
				'3,3,3,3,3',
			),
			'reference size mismatch' => new CreateTournament(
				self::CHAT_ID,
				$this->owner->id,
				'X',
				'2026-11-14 19:00',
				3,
				'3,3',
			),
			'no reference' => new CreateTournament(self::CHAT_ID, $this->owner->id, 'X', '2026-11-14 19:00', 3),
			'blank reference' => new CreateTournament(
				self::CHAT_ID,
				$this->owner->id,
				'X',
				'2026-11-14 19:00',
				3,
				'  ',
			),
			'bad level' => new CreateTournament(
				self::CHAT_ID,
				$this->owner->id,
				'X',
				'2026-11-14 19:00',
				3,
				'средний, средний, крутой',
			),
			'single level name' => new CreateTournament(
				self::CHAT_ID,
				$this->owner->id,
				'X',
				'2026-11-14 19:00',
				3,
				'средний',
			),
			'total too small' => new CreateTournament(self::CHAT_ID, $this->owner->id, 'X', '2026-11-14 19:00', 3, '2'),
			'total too big' => new CreateTournament(self::CHAT_ID, $this->owner->id, 'X', '2026-11-14 19:00', 3, '19'),
			'digit out of range in list' => new CreateTournament(
				self::CHAT_ID,
				$this->owner->id,
				'X',
				'2026-11-14 19:00',
				3,
				'3,3,7',
			),
			'past start' => new CreateTournament(self::CHAT_ID, $this->owner->id, 'X', '2026-11-01 19:00', 3, '3,3,4'),
			'empty title' => new CreateTournament(self::CHAT_ID, $this->owner->id, ' ', '2026-11-14 19:00', 3, '3,3,4'),
			'balance without reference' => new CreateTournament(
				self::CHAT_ID,
				$this->owner->id,
				'X',
				'2026-11-14 19:00',
				3,
				null,
				1,
			),
			'max players below team' => new CreateTournament(
				self::CHAT_ID,
				$this->owner->id,
				'X',
				'2026-11-14 19:00',
				3,
				'3,3,4',
				null,
				2,
			),
		];
		foreach ($cases as $name => $input) {
			try {
				$this->app->tournaments->create($input);
				self::fail("Expected a validation error: $name");
			} catch (ValidationError | \P1Bot\Domain\Exception\InvalidLevel $e) {
				self::assertNotSame('', $e->getMessage());
			}
		}
	}

	public function testOnlyAdminsCreateByDefault(): void {
		$vasya = $this->player('vasya');
		$this->expectException(PermissionDenied::class);
		$this->app->tournaments->create(new CreateTournament(self::CHAT_ID, $vasya->id, 'X', '2026-11-14 19:00', 3));
	}

	public function testStatusFlowAndPermissions(): void {
		$t = $this->openTournament();
		self::assertSame(TournamentStatus::REGISTRATION, $t->status);

		$vasya = $this->player('vasya');
		try {
			$this->app->tournaments->closeRegistration($vasya->id, $t->id);
			self::fail('Regular player must not close registration');
		} catch (PermissionDenied $e) {
			self::assertStringContainsString('организатор', $e->getMessage());
		}

		$t = $this->app->tournaments->closeRegistration($this->admin->id, $t->id);
		self::assertSame(TournamentStatus::CLOSED, $t->status);
		$t = $this->app->tournaments->start($this->owner->id, $t->id);
		$t = $this->app->tournaments->finish($this->owner->id, $t->id);
		self::assertSame(TournamentStatus::FINISHED, $t->status);

		$this->expectException(ValidationError::class);
		$this->app->tournaments->openRegistration($this->owner->id, $t->id);
	}

	public function testListActive(): void {
		$open = $this->openTournament();
		$this->app->tournaments->create(new CreateTournament(
			self::CHAT_ID,
			$this->owner->id,
			'Черновик',
			'2026-11-15 19:00',
			2,
			'3,3',
		));
		$cancelled = $this->openTournament();
		$this->app->tournaments->cancel($this->owner->id, $cancelled->id);

		$ids = array_map(static fn ($t): int => $t->id, $this->app->tournaments->listActive(self::CHAT_ID));
		self::assertSame([$open->id], $ids);
	}

	public function testEditLimitIsLockedOnceThereAreEntries(): void {
		$t = $this->openTournament();
		$t = $this->app->tournaments->edit(
			$this->owner->id,
			$t->id,
			new EditTournament(team_size: 2, reference_team: '4,4'),
		);
		self::assertSame(2, $t->team_size);
		self::assertSame(8, $t->max_points);

		$this->app->teams->joinSolo($t->id, $this->player('vasya')->id);
		$t = $this->app->tournaments->edit(
			$this->owner->id,
			$t->id,
			new EditTournament(title: 'Новое имя', starts_at_msk: '2026-11-15 20:00'),
		);
		self::assertSame('Новое имя', $t->title);
		self::assertSame('2026-11-15 17:00:00', DateFormat::toDb($t->starts_at));

		$this->expectException(ValidationError::class);
		$this->app->tournaments->edit($this->owner->id, $t->id, new EditTournament(remove_level_limit: true));
	}

	public function testLineupSummaryAndHeadline(): void {
		$t = $this->openTournament(2, null, 4);
		$captains = [];
		foreach (['a', 'b', 'c'] as $suffix) {
			$captain = $this->player("cap$suffix");
			$mate = $this->player("mate$suffix");
			$this->app->teams->createTeam($t->id, $captain->id, "Team $suffix", [$mate->id]);
			$captains[] = $captain;
			$this->clock->advance();
		}
		$this->app->teams->joinSolo($t->id, $this->player('solo1')->id);

		$lineup = $this->app->tournaments->lineup($t->id);
		self::assertSame('занято 4/4, резерв 3', $lineup->summary());
		self::assertSame(
			'Субботний кубок — 14.11.2026 19:00 МСК, команды по 2, уровень: без ограничения по уровню, занято 4/4, резерв 3',
			Messages::tournamentLine($t, $lineup),
		);

		// Raising the limit pulls reserve in automatically (nothing is stored).
		$this->app->tournaments->setMaxPlayers($this->owner->id, $t->id, 7);
		self::assertSame('занято 7/7, резерв 0', $this->app->tournaments->lineup($t->id)->summary());
	}

	public function testReminderWindow(): void {
		$t = $this->openTournament(); // starts 2026-11-14 16:00 UTC, clock is 2026-11-09 12:00 UTC
		self::assertSame([], $this->app->tournaments->dueReminders([60, 15]));

		$this->clock->advance('+5 days +3 hours +30 minutes'); // 15:30 UTC on the 14th
		$due = $this->app->tournaments->dueReminders([15, 60]);
		self::assertCount(1, $due);
		self::assertSame([$t->id, 60], [$due[0][0]->id, $due[0][1]]);
		$this->app->tournaments->markReminded($t->id);
		self::assertSame([], $this->app->tournaments->dueReminders([60, 15]));
	}

	public function testTotalLevelIsMaxPointsDirectly(): void {
		$t = $this->app->tournaments->create(new CreateTournament(
			self::CHAT_ID,
			$this->owner->id,
			'X',
			'2026-11-14 19:00',
			3,
			'10',
		));
		self::assertSame(10, $t->max_points);
		self::assertNull($t->reference_team);
		self::assertNotNull($t->balance_id);
		self::assertSame('средний (10 баллов на троих)', $t->levelLabel());

		$t = $this->app->tournaments->edit($this->owner->id, $t->id, new EditTournament(null, null, null, '18'));
		self::assertSame(18, $t->max_points);
		self::assertSame('высокий+ (18 баллов на троих)', $t->levelLabel());
		$t = $this->app->tournaments->edit($this->owner->id, $t->id, new EditTournament(null, null, null, '3'));
		self::assertSame('начальный (3 балла на троих)', $t->levelLabel());

		// A list sets max points to the sum and keeps the per-player levels.
		$t = $this->app->tournaments->edit(
			$this->owner->id,
			$t->id,
			new EditTournament(null, null, null, 'средний, средний, высокий'),
		);
		self::assertSame(11, $t->max_points);
		self::assertSame('3,3,5', $t->reference_team);

		// A new team size needs a level for it.
		try {
			$this->app->tournaments->edit($this->owner->id, $t->id, new EditTournament(null, null, 2));
			self::fail('size change without a level');
		} catch (\P1Bot\Domain\Exception\InvalidLevel $e) {
			self::assertStringContainsString('укажи и уровень', $e->getMessage());
		}
		$t = $this->app->tournaments->edit($this->owner->id, $t->id, new EditTournament(null, null, 2, '7'));
		self::assertSame([2, 7], [$t->team_size, $t->max_points]);

		// Only the scale changes: the plain total is kept.
		$t = $this->app->tournaments->edit(
			$this->owner->id,
			$t->id,
			new EditTournament(null, null, null, null, $t->balance_id),
		);
		self::assertSame(7, $t->max_points);
	}

	public function testLevelIsLockedAfterSignUps(): void {
		$t = $this->openTournament();
		$this->app->teams->joinSolo($t->id, $this->player('vasya')->id);
		try {
			$this->app->tournaments->edit($this->owner->id, $t->id, new EditTournament(null, null, null, '12'));
			self::fail('locked');
		} catch (ValidationError $e) {
			self::assertStringContainsString('Уровень турнира и размер команды уже не поменять', $e->getMessage());
			self::assertStringContainsString('отмени этот турнир', $e->getMessage());
		}
		// Title/limit are still editable.
		$t = $this->app->tournaments->edit(
			$this->owner->id,
			$t->id,
			new EditTournament('Новое имя', null, null, null, null, 30),
		);
		self::assertSame(30, $t->max_players);
	}
}
