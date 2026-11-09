<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\TournamentStatus;
use P1Bot\Tests\Support\IntegrationTestCase;

class CronRunnerTest extends IntegrationTestCase {

	public function testRemindersAt60And15MinutesOnce(): void {
		// Starts 2026-11-14 16:00 UTC (19:00 MSK); clock is 2026-11-09 12:00 UTC.
		$t = $this->openTournament(2, null);
		$this->app->announcer->publish($t->id);
		$this->app->teams->createTeam($t->id, $this->player('cap')->id, 'Wolves', [$this->player('mate')->id]);
		$this->app->teams->joinSolo($t->id, $this->player('solo')->id);
		$cron = $this->app->cron();
		$this->http->reset();

		self::assertSame(0, $cron->run()['reminders']);

		$this->clock->advance('+5 days +3 hours +1 minute'); // 15:01 UTC = 59 min before start
		self::assertSame(1, $cron->run()['reminders']);
		$reminder = $this->http->sent('sendMessage')[0];
		self::assertStringContainsString('Через час стартует турнир <b>Субботний кубок</b>!', $reminder['text']);
		self::assertStringContainsString('🎟 Занято 3/24', $reminder['text']);
		self::assertSame(
			[['text' => '💬 Записаться / вопросы в личке', 'url' => 'https://t.me/pop1_bot?start=t_' . $t->id]],
			$reminder['reply_markup']['inline_keyboard'][0],
		);

		$this->clock->advance('+10 minutes');
		self::assertSame(0, $cron->run()['reminders'], 'not twice');

		$this->http->reset();
		$this->clock->advance('+35 minutes'); // 15:46 UTC = 14 min before start
		self::assertSame(1, $cron->run()['reminders'], '15-min mark is still due and claimed');
		self::assertSame([], $this->http->sent('sendMessage'), '15-min mark does not broadcast to groups');
		self::assertSame(0, $cron->run()['reminders']);
	}

	public function testMissedWindowsClaimLatestWithoutGroupBroadcastPastHour(): void {
		$t = $this->openTournament();
		$this->http->reset();
		$this->clock->advance('+5 days +3 hours +50 minutes'); // 10 min before start, cron was down
		self::assertSame(1, $this->app->cron()->run()['reminders']);
		self::assertSame([], $this->http->sent('sendMessage'), 'past the hour mark: 15-min does not broadcast');
		self::assertSame(0, $this->app->cron()->run()['reminders']);
		self::assertNotNull($this->app->tournament_repo->find($t->id)->reminded_at);
	}

	public function testAutoStatusChangesAndAnnouncementRefresh(): void {
		$t = $this->openTournament();
		$this->app->announcer->publish($t->id);
		$draft = $this->app->tournaments->create(new \P1Bot\Domain\CreateTournament(
			self::CHAT_ID,
			$this->owner->id,
			'Черновик',
			'2026-11-14 19:00',
			2,
			'3,3',
		));
		$cron = $this->app->cron();

		$this->clock->advance('+5 days +4 hours'); // start time
		self::assertSame(1, $cron->run()['status_changes']);
		self::assertSame(TournamentStatus::RUNNING, $this->app->tournament_repo->find($t->id)->status);
		self::assertSame(
			TournamentStatus::DRAFT,
			$this->app->tournament_repo->find($draft->id)->status,
			'drafts untouched',
		);
		$edits = $this->http->sent('editMessageText');
		self::assertStringContainsString('🎮 Идёт', end($edits)['text']);

		$this->clock->advance('+3 hours');
		self::assertSame(0, $cron->run()['status_changes']);
		$this->clock->advance('+1 hour');
		self::assertSame(1, $cron->run()['status_changes']);
		self::assertSame(TournamentStatus::FINISHED, $this->app->tournament_repo->find($t->id)->status);
	}

	public function testExpiredConversationsAreDeleted(): void {
		$this->app->conversations->save(
			null,
			self::CHAT_ID,
			$this->owner->id,
			1,
			[],
			$this->clock->now()->modify('+30 minutes'),
		);
		self::assertSame(0, $this->app->cron()->run()['conversations_deleted']);
		$this->clock->advance('+31 minutes');
		self::assertSame(1, $this->app->cron()->run()['conversations_deleted']);
	}
}
