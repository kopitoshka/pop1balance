<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\TournamentStatus;
use P1Bot\Tests\Support\IntegrationTestCase;

/**
	REGISTRATION_CLOSE_MINUTES: cron closes registration, and users cannot change lineups even if cron is late.
*/
class RegistrationCloseTest extends IntegrationTestCase {

	/**
		15:40 UTC = 20 minutes before 16:00 UTC (19:00 MSK).
	*/
	protected function toTwentyMinutesBefore(): void {
		$this->clock->advance('+5 days +3 hours +40 minutes');
	}

	public function testCronClosesRegistrationTwentyMinutesBeforeStart(): void {
		$t = $this->openTournament(2, null);
		$this->app->announcer->publish($t->id);
		$this->clock->advance('+5 days +3 hours +39 minutes'); // 21 min before
		self::assertSame(0, $this->app->cron()->run()['status_changes']);
		self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournament_repo->find($t->id)->status);

		$this->clock->advance('+1 minute'); // 20 min before
		self::assertSame(1, $this->app->cron()->run()['status_changes']);
		self::assertSame(TournamentStatus::CLOSED, $this->app->tournament_repo->find($t->id)->status);
		$edits = $this->http->sent('editMessageText');
		self::assertStringContainsString('🔒 Регистрация закрыта', end($edits)['text']);
		self::assertStringNotContainsString('Записаться командой или соло', end($edits)['text']);
	}

	public function testUserCannotJoinWhenTimeLockedEvenIfStatusStillOpen(): void {
		$t = $this->openTournament(2, null);
		$vasya = $this->player('vasya');
		$this->toTwentyMinutesBefore();
		// Cron did not run: status is still registration, but the action-time lock applies.
		self::assertSame(TournamentStatus::REGISTRATION, $this->app->tournament_repo->find($t->id)->status);
		self::assertTrue($this->app->tournaments->isRegistrationTimeLocked($t));
		try {
			$this->app->teams->joinSolo($t->id, $vasya->id);
			self::fail('expected ValidationError');
		} catch (ValidationError $e) {
			self::assertSame(
				'Регистрация закрылась за 20 минут до старта — составы уже не меняются.',
				$e->getMessage(),
			);
		}
	}

	public function testUserCannotLeaveAfterTimeLock(): void {
		$t = $this->openTournament(2, null);
		$vasya = $this->player('vasya');
		$this->app->teams->joinSolo($t->id, $vasya->id);
		$this->toTwentyMinutesBefore();
		try {
			$this->app->teams->leaveSolo($t->id, $vasya->id);
			self::fail('expected ValidationError');
		} catch (ValidationError $e) {
			self::assertStringContainsString('составы уже не меняются', $e->getMessage());
		}
		self::assertNotNull($this->app->solo_repo->find($t->id, $vasya->id));
	}

	public function testOrganizerCanStillWithdrawTeamAfterTimeLock(): void {
		$t = $this->openTournament(2, null);
		$cap = $this->player('cap');
		$mate = $this->player('mate');
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$mate->id]);
		$this->toTwentyMinutesBefore();
		$this->app->teams->withdrawTeam($this->owner->id, $entry->team->id);
		self::assertNull($this->app->teams->findByName($t->id, 'Wolves'));
	}

	public function testPrivateButtonsHiddenForUserWhenTimeLocked(): void {
		$t = $this->openTournament(2, null);
		$vasya = $this->player('vasya');
		$mate = $this->player('mate');
		$this->app->teams->joinSolo($t->id, $vasya->id);
		$entry = $this->app->teams->createTeam($t->id, $this->owner->id, 'Admins', [$mate->id]);
		$views = new \P1Bot\App\PrivateViews(
			$this->app->telegram,
			$this->app->tournaments,
			$this->app->teams,
			$this->app->announcer,
			$this->app->listing,
			$this->app->private_groups,
			$this->app->access,
		);
		self::assertNotSame([], $views->rows($t, $vasya), 'before lock: unsubscribe is shown');
		$this->toTwentyMinutesBefore();
		$t = $this->app->tournaments->get($t->id);
		self::assertSame([], $views->rows($t, $vasya), 'regular player: no buttons after lock');
		$rows = $views->rows($t, $this->owner);
		$callbacks = [];
		foreach ($rows as $row) {
			foreach ($row as $btn) {
				$callbacks[] = $btn['callback_data'] ?? '';
			}
		}
		self::assertContains('tcard:' . $entry->team->id, $callbacks, 'organizer still reaches own team');
		self::assertContains('oteams:' . $t->id, $callbacks, 'organizer keeps team list');
		self::assertNotContains('pdissolve:' . $entry->team->id, $callbacks, 'dissolve not on tournament card');
	}

	public function testGroupNoticeShowsClosedWhenTimeLocked(): void {
		$t = $this->openTournament(2, null);
		$this->toTwentyMinutesBefore();
		$text = $this->app->cron()->groupNotice($t, 20);
		self::assertStringContainsString('Регистрация уже закрыта', $text);
		self::assertStringNotContainsString('Ещё можно успеть', $text);
	}

	public function testOrganizerCanAddWhenClosedButRegularCannot(): void {
		$t = $this->openTournament(3);
		[$cap, $a, $b] = [$this->player('cap'), $this->player('a'), $this->player('b')];
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id]);
		$this->app->tournaments->closeRegistration($this->owner->id, $t->id);
		self::assertSame(TournamentStatus::CLOSED, $this->app->tournament_repo->find($t->id)->status);

		try {
			$this->app->teams->addPlayer($cap->id, $entry->team->id, $b->id);
			self::fail('captain must not grow roster when CLOSED');
		} catch (ValidationError $e) {
			self::assertStringContainsString('не идёт', $e->getMessage());
		}

		$entry = $this->app->teams->addPlayer($this->owner->id, $entry->team->id, $b->id);
		self::assertCount(3, $entry->members);

		$solo = $this->player('solo');
		$this->app->teams->joinSolo($t->id, $this->owner->id); // owner as solo ok when CLOSED
		self::assertNotNull($this->app->solo_repo->find($t->id, $this->owner->id));

		try {
			$this->app->teams->joinSolo($t->id, $solo->id);
			self::fail('regular solo join must fail when CLOSED');
		} catch (ValidationError $e) {
			self::assertStringContainsString('не идёт', $e->getMessage());
		}
	}

	public function testOrganizerCanReplaceOnAnotherTeamWhenClosed(): void {
		$t = $this->openTournament(3);
		[$cap, $a, $b, $c] = [
			$this->player('cap'), $this->player('a'), $this->player('b'), $this->player('c'),
		];
		$entry = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id, $b->id]);
		$this->app->tournaments->closeRegistration($this->owner->id, $t->id);
		$entry = $this->app->teams->replacePlayer($this->owner->id, $entry->team->id, $b->id, $c->id);
		$ids = array_map(static fn ($m) => $m->player_id, $entry->members);
		self::assertContains($c->id, $ids);
		self::assertNotContains($b->id, $ids);
	}

	public function testOrganizerCannotAddAfterStart(): void {
		$t = $this->openTournament(3);
		$entry = $this->app->teams->createTeam($t->id, $this->player('cap')->id, 'Wolves', [$this->player('a')->id]);
		$this->app->tournaments->closeRegistration($this->owner->id, $t->id);
		$this->app->tournaments->start($this->owner->id, $t->id);
		try {
			$this->app->teams->addPlayer($this->owner->id, $entry->team->id, $this->player('b')->id);
			self::fail('expected ValidationError after start');
		} catch (ValidationError $e) {
			self::assertStringContainsString('не идёт', $e->getMessage());
		}
	}
}
