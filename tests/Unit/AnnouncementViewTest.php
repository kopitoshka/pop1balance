<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use P1Bot\Domain\Lineup;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\SoloPlayer;
use P1Bot\Domain\Model\Team;
use P1Bot\Domain\Model\TeamEntry;
use P1Bot\Domain\Model\TeamMember;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Domain\TeamStatus;
use P1Bot\Domain\TournamentStatus;
use P1Bot\View\AnnouncementView;
use PHPUnit\Framework\TestCase;

final class AnnouncementViewTest extends TestCase
{
    public function testFullAnnouncement(): void
    {
        $utc = new DateTimeZone('UTC');
        $starts = new DateTimeImmutable('2026-11-14 16:00:00', $utc);
        $t = new Tournament(7, -100, 1, 'Кубок <выходного>', $starts, 2, 1, '3,4', 7, 4, TournamentStatus::REGISTRATION, null, null);

        $players = [1 => new Player(1, 10, 'andrey', 'Андрей')];
        for ($i = 2; $i <= 9; $i++) {
            $players[$i] = new Player($i, 10 + $i, 'p' . $i, 'P' . $i);
        }
        $team = static function (int $id, string $name, array $memberIds, string $status = TeamStatus::REGISTERED) use ($starts): TeamEntry {
            $members = array_map(static fn (int $p): TeamMember => new TeamMember($id, 7, $p, 3), $memberIds);
            return new TeamEntry(new Team($id, 7, $memberIds[0], $name, $status, $starts, $starts), $members);
        };
        $lineup = Lineup::compute(
            4,
            [$team(1, 'Wolves', [2, 3]), $team(2, 'Bears', [4, 5]), $team(3, 'Late', [6, 7])],
            [new SoloPlayer(7, 8, 3, $starts)],
            [$team(4, 'Forming', [9], TeamStatus::FORMING)],
        );

        $text = (new AnnouncementView('pop1_bot', $players))->render($t, $lineup);

        self::assertStringContainsString('🏆 <b>Кубок &lt;выходного&gt;</b> (№7)', $text);
        self::assertStringContainsString('🕗 14.11.2026 19:00 МСК', $text);
        self::assertStringContainsString('Организатор: @andrey', $text);
        self::assertStringContainsString('Уровень: средний+ (7 баллов на двоих)', $text);
        self::assertStringContainsString('Команды по 2 игрока', $text);
        self::assertStringContainsString('Занято 4/4, резерв 3', $text);
        self::assertStringContainsString("<b>Команды:</b>\n1. <b>Wolves</b> — @p2 (кэп), @p3\n2. <b>Bears</b> — @p4 (кэп), @p5", $text);
        self::assertStringContainsString("<b>Резерв:</b>\n1. <b>Late</b> — @p6 (кэп), @p7\nСоло: @p8", $text);
        self::assertStringContainsString("<b>Собираются:</b>\n• Forming — @p9 (кэп)", $text);
        self::assertStringContainsString('напиши @pop1_bot', $text);
    }

    public function testClosedTournamentHasStatusLineAndNoCallToAction(): void
    {
        $starts = new DateTimeImmutable('2026-11-14 16:00:00', new DateTimeZone('UTC'));
        $t = new Tournament(1, -100, 1, 'Кубок', $starts, 3, null, null, null, 24, TournamentStatus::CLOSED, 5, null);
        $text = (new AnnouncementView('pop1_bot', []))->render($t, Lineup::compute(24, [], []));
        self::assertStringContainsString('📌 Регистрация закрыта', $text);
        self::assertStringContainsString('без ограничения по уровню', $text);
        self::assertStringNotContainsString('Записаться', $text);
    }
}
