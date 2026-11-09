<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use DateTimeImmutable;
use P1Bot\Domain\Lineup;
use P1Bot\Domain\Model\SoloPlayer;
use P1Bot\Domain\Model\Team;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\TeamMember;
use P1Bot\Domain\TeamStatus;
use PHPUnit\Framework\TestCase;

class LineupTest extends TestCase {
	protected int $next_player = 1;

	public function testTeamsFirstThenSoloFillRemainingPlaces(): void {
		// 8 places: two teams of 3 fit (6), third team does not fit (2 left) -> reserve wholly,
		// then solo players registered later take the 2 free places, the third solo is reserve.
		$teams = [$this->team(1, 3), $this->team(2, 3), $this->team(3, 3)];
		$solo = [$this->solo(), $this->solo(), $this->solo()];

		$lineup = Lineup::compute(8, $teams, $solo);

		self::assertSame([1, 2], array_map(static fn (TeamEntry $e): int => $e->team->id, $lineup->main_teams));
		self::assertSame([3], array_map(static fn (TeamEntry $e): int => $e->team->id, $lineup->reserve_teams));
		self::assertCount(2, $lineup->main_solo);
		self::assertCount(1, $lineup->reserve_solo);
		self::assertSame(8, $lineup->occupied());
		self::assertSame(4, $lineup->reserveCount());
		self::assertSame(0, $lineup->freePlaces());
		self::assertSame('занято 8/8, запас 4', $lineup->summary());
	}

	public function testSmallerLaterTeamMayStillFit(): void {
		$lineup = Lineup::compute(5, [$this->team(1, 3), $this->team(2, 3), $this->team(3, 2)], []);

		self::assertSame([1, 3], array_map(static fn (TeamEntry $e): int => $e->team->id, $lineup->main_teams));
		self::assertSame('занято 5/5, запас 3', $lineup->summary());
	}

	public function testEmptyTournament(): void {
		$lineup = Lineup::compute(24, [], []);
		self::assertSame('занято 0/24, запас 0', $lineup->summary());
		self::assertSame(24, $lineup->freePlaces());
	}

	public function testPlacementOf(): void {
		$main = $this->team(1, 2);
		$reserve = $this->team(2, 2);
		$forming = $this->team(3, 1, TeamStatus::FORMING);
		$solo = $this->solo();
		$lineup = Lineup::compute(2, [$main, $reserve], [$solo], [$forming]);

		self::assertSame('main', $lineup->placementOf($main->members[0]->player_id));
		self::assertSame('reserve', $lineup->placementOf($reserve->members[1]->player_id));
		self::assertSame('forming', $lineup->placementOf($forming->members[0]->player_id));
		self::assertSame('reserve', $lineup->placementOf($solo->player_id));
		self::assertNull($lineup->placementOf(9999));
	}

	protected function team(int $id, int $size, string $status = TeamStatus::REGISTERED): TeamEntry {
		$now = new DateTimeImmutable('2026-11-09 12:00:00');
		$team = new Team($id, 1, $this->next_player, "Team $id", $status, $now, $now);
		$members = [];
		for ($i = 0; $i < $size; $i++) {
			$members[] = new TeamMember($id, 1, $this->next_player++);
		}
		return new TeamEntry($team, $members);
	}

	protected function solo(): SoloPlayer {
		return new SoloPlayer(1, $this->next_player++, new DateTimeImmutable('2026-11-09 12:00:00'));
	}
}
