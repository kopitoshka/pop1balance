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

final class TournamentServiceTest extends IntegrationTestCase
{
    public function testCreateComputesLimitAndStoresUtc(): void
    {
        $t = $this->app->tournaments->create(new CreateTournament(
            self::CHAT_ID,
            $this->admin->id,
            '  Субботний кубок ',
            '2026-11-14 19:00',
            3,
            'средний, средний, средний+',
        ));

        self::assertSame('Субботний кубок', $t->title);
        self::assertSame($this->admin->id, $t->organizerId);
        self::assertSame('2026-11-14 16:00:00', DateFormat::toDb($t->startsAt));
        self::assertSame('3,3,4', $t->referenceTeam);
        self::assertSame(10, $t->maxPoints);
        self::assertSame(24, $t->maxPlayers);
        self::assertSame($this->app->balances->findDefault()->id, $t->balanceId);
        self::assertSame(TournamentStatus::DRAFT, $t->status);
        self::assertSame('средний (10 баллов на троих)', $t->levelLabel());
    }

    public function testElevenPointsForThreeIsAveragePlus(): void
    {
        $t = $this->openTournament(3, '3,4,4');
        self::assertSame('средний+ (11 баллов на троих)', $t->levelLabel());
    }

    public function testNoReferenceMeansNoLimit(): void
    {
        $t = $this->openTournament(2, null, 16);
        self::assertNull($t->balanceId);
        self::assertNull($t->maxPoints);
        self::assertFalse($t->hasLevelLimit());
        self::assertSame('без ограничения по уровню', $t->levelLabel());
        self::assertSame(16, $t->maxPlayers);
    }

    public function testValidation(): void
    {
        $cases = [
            'team too big' => new CreateTournament(self::CHAT_ID, $this->owner->id, 'X', '2026-11-14 19:00', 5),
            'reference size mismatch' => new CreateTournament(self::CHAT_ID, $this->owner->id, 'X', '2026-11-14 19:00', 3, '3,3'),
            'past start' => new CreateTournament(self::CHAT_ID, $this->owner->id, 'X', '2026-11-01 19:00', 3),
            'empty title' => new CreateTournament(self::CHAT_ID, $this->owner->id, ' ', '2026-11-14 19:00', 3),
            'balance without reference' => new CreateTournament(self::CHAT_ID, $this->owner->id, 'X', '2026-11-14 19:00', 3, null, 1),
            'max players below team' => new CreateTournament(self::CHAT_ID, $this->owner->id, 'X', '2026-11-14 19:00', 3, null, null, 2),
        ];
        foreach ($cases as $name => $input) {
            try {
                $this->app->tournaments->create($input);
                self::fail("Expected ValidationError: $name");
            } catch (ValidationError $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    public function testOnlyAdminsCreateByDefault(): void
    {
        $vasya = $this->player('vasya');
        $this->expectException(PermissionDenied::class);
        $this->app->tournaments->create(new CreateTournament(self::CHAT_ID, $vasya->id, 'X', '2026-11-14 19:00', 3));
    }

    public function testStatusFlowAndPermissions(): void
    {
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

    public function testListActive(): void
    {
        $open = $this->openTournament();
        $this->app->tournaments->create(new CreateTournament(self::CHAT_ID, $this->owner->id, 'Черновик', '2026-11-15 19:00', 2));
        $cancelled = $this->openTournament();
        $this->app->tournaments->cancel($this->owner->id, $cancelled->id);

        $ids = array_map(static fn ($t): int => $t->id, $this->app->tournaments->listActive(self::CHAT_ID));
        self::assertSame([$open->id], $ids);
    }

    public function testEditLimitIsLockedOnceThereAreEntries(): void
    {
        $t = $this->openTournament();
        $t = $this->app->tournaments->edit($this->owner->id, $t->id, new EditTournament(teamSize: 2, referenceTeam: '4,4'));
        self::assertSame(2, $t->teamSize);
        self::assertSame(8, $t->maxPoints);

        $this->app->teams->joinSolo($t->id, $this->player('vasya')->id);
        $t = $this->app->tournaments->edit($this->owner->id, $t->id, new EditTournament(title: 'Новое имя', startsAtMsk: '2026-11-15 20:00'));
        self::assertSame('Новое имя', $t->title);
        self::assertSame('2026-11-15 17:00:00', DateFormat::toDb($t->startsAt));

        $this->expectException(ValidationError::class);
        $this->app->tournaments->edit($this->owner->id, $t->id, new EditTournament(removeLevelLimit: true));
    }

    public function testLineupSummaryAndHeadline(): void
    {
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

    public function testReminderWindow(): void
    {
        $t = $this->openTournament(); // starts 2026-11-14 16:00 UTC, clock is 2026-11-09 12:00 UTC
        self::assertSame([], $this->app->tournaments->dueForReminder(60));

        $this->clock->advance('+5 days +3 hours +30 minutes'); // 15:30 UTC on the 14th
        self::assertCount(1, $this->app->tournaments->dueForReminder(60));
        $this->app->tournaments->markReminded($t->id);
        self::assertSame([], $this->app->tournaments->dueForReminder(60));
    }
}
