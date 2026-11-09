<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Domain\TournamentStatus;
use P1Bot\Tests\Support\IntegrationTestCase;

final class CronRunnerTest extends IntegrationTestCase
{
    public function testRemindersAt60And15MinutesOnce(): void
    {
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
        self::assertStringContainsString('Через 60 минут старт турнира <b>Субботний кубок</b> (19:00 МСК)', $reminder['text']);
        self::assertStringContainsString('Занято 3/24, резерв 0.', $reminder['text']);
        self::assertStringContainsString('Играют: @cap, @mate, @solo', $reminder['text']);
        self::assertSame(1000, $reminder['reply_parameters']['message_id'], 'reply to the announcement');

        $this->clock->advance('+10 minutes');
        self::assertSame(0, $cron->run()['reminders'], 'not twice');

        $this->clock->advance('+35 minutes'); // 15:46 UTC = 14 min before start
        self::assertSame(1, $cron->run()['reminders']);
        self::assertStringContainsString('Через 15 минут', $this->http->lastText());
        self::assertSame(0, $cron->run()['reminders']);
    }

    public function testMissedWindowsSendOnlyTheLatestReminder(): void
    {
        $t = $this->openTournament();
        $this->clock->advance('+5 days +3 hours +50 minutes'); // 10 min before start, cron was down
        self::assertSame(1, $this->app->cron()->run()['reminders']);
        self::assertStringContainsString('Через 15 минут', $this->http->lastText());
        self::assertSame(0, $this->app->cron()->run()['reminders']);
        self::assertNotNull($this->app->tournamentRepo->find($t->id)->remindedAt);
    }

    public function testAutoStatusChangesAndAnnouncementRefresh(): void
    {
        $t = $this->openTournament();
        $this->app->announcer->publish($t->id);
        $draft = $this->app->tournaments->create(new \P1Bot\Domain\CreateTournament(self::CHAT_ID, $this->owner->id, 'Черновик', '2026-11-14 19:00', 2, '3,3'));
        $cron = $this->app->cron();

        $this->clock->advance('+5 days +4 hours'); // start time
        self::assertSame(1, $cron->run()['status_changes']);
        self::assertSame(TournamentStatus::RUNNING, $this->app->tournamentRepo->find($t->id)->status);
        self::assertSame(TournamentStatus::DRAFT, $this->app->tournamentRepo->find($draft->id)->status, 'drafts untouched');
        $edits = $this->http->sent('editMessageText');
        self::assertStringContainsString('📌 Идёт', end($edits)['text']);

        $this->clock->advance('+3 hours');
        self::assertSame(0, $cron->run()['status_changes']);
        $this->clock->advance('+1 hour');
        self::assertSame(1, $cron->run()['status_changes']);
        self::assertSame(TournamentStatus::FINISHED, $this->app->tournamentRepo->find($t->id)->status);
    }

    public function testExpiredConversationsAreDeleted(): void
    {
        $this->app->conversations->save(null, self::CHAT_ID, $this->owner->id, 1, [], $this->clock->now()->modify('+30 minutes'));
        self::assertSame(0, $this->app->cron()->run()['conversations_deleted']);
        $this->clock->advance('+31 minutes');
        self::assertSame(1, $this->app->cron()->run()['conversations_deleted']);
    }
}
