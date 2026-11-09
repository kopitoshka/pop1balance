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

final class TeamServiceTest extends IntegrationTestCase
{
    public function testFullTeamWithinLimitIsRegistered(): void
    {
        $t = $this->openTournament(); // 3 players, limit 10
        [$cap, $a, $b] = [$this->player('cap'), $this->player('a'), $this->player('b')];
        $this->rate($cap, 5);
        $this->rate($a, 1);
        // $b is unknown -> 3. 5 + 1 + 3 = 9 <= 10

        $entry = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id, $b->id]);

        self::assertSame(TeamStatus::REGISTERED, $entry->team->status);
        self::assertNotNull($entry->team->registeredAt);
        self::assertSame($cap->id, $entry->team->captainId);
        self::assertSame(9, $entry->points());
        self::assertSame(3, $entry->size());
    }

    public function testExceedingLimitSaysByHowMuch(): void
    {
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
            self::assertStringContainsString('перебор на 2 балла', $e->getMessage());
        }
        self::assertNull($this->app->teams->findByName($t->id, 'Too strong'), 'nothing persisted');
    }

    public function testPartialTeamIsFormingAndRegistersWhenFull(): void
    {
        $t = $this->openTournament();
        $cap = $this->player('cap');
        $entry = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves');
        self::assertSame(TeamStatus::FORMING, $entry->team->status);
        self::assertNull($entry->team->registeredAt);

        $this->app->teams->addPlayer($cap->id, $entry->team->id, $this->player('a')->id);
        $this->clock->advance('+10 minutes');
        $entry = $this->app->teams->addPlayer($cap->id, $entry->team->id, $this->player('b')->id);

        self::assertSame(TeamStatus::REGISTERED, $entry->team->status);
        self::assertSame('2026-11-09 12:10:00', $entry->team->registeredAt->format('Y-m-d H:i:s'));
    }

    public function testAddPlayerOverLimitOrOverSize(): void
    {
        $t = $this->openTournament();
        [$cap, $a, $strong, $extra] = [$this->player('cap'), $this->player('a'), $this->player('strong'), $this->player('extra')];
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

    public function testOnlyCaptainChangesRoster(): void
    {
        $t = $this->openTournament();
        [$cap, $a] = [$this->player('cap'), $this->player('a')];
        $team = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id])->team;

        $this->expectException(PermissionDenied::class);
        $this->app->teams->addPlayer($a->id, $team->id, $this->player('b')->id);
    }

    public function testLevelSnapshotIsFrozen(): void
    {
        $t = $this->openTournament();
        [$cap, $a] = [$this->player('cap'), $this->player('a')];
        $this->rate($a, 2);
        $team = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id])->team;

        $this->rate($a, 6); // rating changes after registration
        $levels = array_map(static fn (TeamMember $m): int => $m->levelSnapshot, $this->app->teams->get($team->id)->members);
        self::assertSame([3, 2], $levels);
    }

    public function testPlayerIsUniquePerTournament(): void
    {
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

    public function testAddingSoloPlayerToTeamRemovesSoloEntry(): void
    {
        $t = $this->openTournament();
        [$cap, $solo] = [$this->player('cap'), $this->player('solo')];
        $this->app->teams->joinSolo($t->id, $solo->id);
        self::assertSame('main', $this->app->tournaments->lineup($t->id)->placementOf($solo->id));

        $team = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves')->team;
        $this->app->teams->addPlayer($cap->id, $team->id, $solo->id);

        $lineup = $this->app->tournaments->lineup($t->id);
        self::assertSame([], $lineup->mainSolo);
        self::assertSame('forming', $lineup->placementOf($solo->id));
    }

    public function testSoloJoinAndLeave(): void
    {
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

    public function testRemovingMemberUnregistersAndReplaceKeepsQueuePlace(): void
    {
        $t = $this->openTournament();
        [$cap, $a, $b, $c] = [$this->player('cap'), $this->player('a'), $this->player('b'), $this->player('c')];
        $team = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id, $b->id])->team;
        $registeredAt = $team->registeredAt;

        $this->clock->advance('+1 hour');
        $entry = $this->app->teams->replacePlayer($cap->id, $team->id, $b->id, $c->id);
        self::assertSame(TeamStatus::REGISTERED, $entry->team->status);
        self::assertEquals($registeredAt, $entry->team->registeredAt);

        $entry = $this->app->teams->removePlayer($cap->id, $team->id, $c->id);
        self::assertSame(TeamStatus::FORMING, $entry->team->status);
        self::assertNull($entry->team->registeredAt);

        $this->expectException(ValidationError::class);
        $this->app->teams->removePlayer($cap->id, $team->id, $cap->id);
    }

    public function testLeaveTeamRenameAndTransferCaptain(): void
    {
        $t = $this->openTournament();
        [$cap, $a, $b] = [$this->player('cap'), $this->player('a'), $this->player('b')];
        $team = $this->app->teams->createTeam($t->id, $cap->id, 'Wolves', [$a->id, $b->id])->team;

        $entry = $this->app->teams->leaveTeam($b->id, $team->id);
        self::assertSame(2, $entry->size());

        $entry = $this->app->teams->renameTeam($cap->id, $team->id, 'Волки');
        self::assertSame('Волки', $entry->team->name);

        $entry = $this->app->teams->transferCaptain($cap->id, $team->id, $a->id);
        self::assertSame($a->id, $entry->team->captainId);

        $this->expectException(PermissionDenied::class);
        $this->app->teams->renameTeam($cap->id, $team->id, 'Снова Wolves');
    }

    public function testTeamNamesAreUniqueCaseInsensitive(): void
    {
        $t = $this->openTournament();
        $this->app->teams->createTeam($t->id, $this->player('cap1')->id, 'Волки');
        $this->expectException(ValidationError::class);
        $this->app->teams->createTeam($t->id, $this->player('cap2')->id, 'ВОЛКИ');
    }

    public function testWithdrawDeletesTeamAndReservePromotesItself(): void
    {
        $t = $this->openTournament(2, null, 4);
        $teams = [];
        foreach (['a', 'b', 'c'] as $s) {
            $teams[$s] = $this->app->teams->createTeam($t->id, $this->player("cap$s")->id, "Team $s", [$this->player("m$s")->id])->team;
            $this->clock->advance();
        }
        $lineup = $this->app->tournaments->lineup($t->id);
        self::assertSame([$teams['c']->id], array_map(static fn ($e): int => $e->team->id, $lineup->reserveTeams));

        $this->app->teams->withdrawTeam($teams['a']->captainId, $teams['a']->id);

        self::assertNull($this->app->teams->findByName($t->id, 'Team a'));
        $lineup = $this->app->tournaments->lineup($t->id);
        self::assertSame([$teams['b']->id, $teams['c']->id], array_map(static fn ($e): int => $e->team->id, $lineup->mainTeams));
        self::assertSame('занято 4/4, резерв 0', $lineup->summary());
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM team_members WHERE team_id = :id', ['id' => $teams['a']->id]));
    }

    public function testRegistrationMustBeOpen(): void
    {
        $t = $this->openTournament();
        $this->app->tournaments->closeRegistration($this->owner->id, $t->id);
        $this->expectException(ValidationError::class);
        $this->app->teams->joinSolo($t->id, $this->player('late')->id);
    }

    public function testNoBalanceMeansNoLimit(): void
    {
        $t = $this->openTournament(2, null);
        [$cap, $a] = [$this->player('cap'), $this->player('a')];
        $this->rate($cap, 6);
        $this->rate($a, 6);
        $entry = $this->app->teams->createTeam($t->id, $cap->id, 'Pros', [$a->id]);
        self::assertSame(TeamStatus::REGISTERED, $entry->team->status);
    }
}
