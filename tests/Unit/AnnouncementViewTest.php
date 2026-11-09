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

class AnnouncementViewTest extends TestCase {

	public function testFullAnnouncement(): void {
		$utc = new DateTimeZone('UTC');
		$starts = new DateTimeImmutable('2026-11-14 16:00:00', $utc);
		$t = new Tournament(
			7,
			-100,
			1,
			'Кубок <выходного>',
			$starts,
			2,
			1,
			'3,4',
			7,
			4,
			TournamentStatus::REGISTRATION,
			null,
			null,
		);
		$t->number = 2; // global number (= id) shown on the card

		$players = [1 => new Player(1, 10, 'andrey', 'Андрей')];
		for ($i = 2; $i <= 9; $i++) {
			$players[$i] = new Player($i, 10 + $i, 'p' . $i, 'P' . $i);
		}
		$team = static function (
			int $id,
			string $name,
			array $member_ids,
			string $status = TeamStatus::REGISTERED,
		) use ($starts): TeamEntry {
			$members = array_map(static fn (int $p): TeamMember => new TeamMember($id, 7, $p), $member_ids);
			return new TeamEntry(new Team($id, 7, $member_ids[0], $name, $status, $starts, $starts), $members);
		};
		$lineup = Lineup::compute(
			4,
			[$team(1, 'Wolves', [2, 3]), $team(2, 'Bears', [4, 5]), $team(3, 'Late', [6, 7])],
			[new SoloPlayer(7, 8, $starts)],
			[$team(4, 'Forming', [9], TeamStatus::FORMING)],
		);

		$text = (new AnnouncementView('pop1_bot', $players))->render($t, $lineup);

		self::assertStringContainsString('🏆 <b>Кубок &lt;выходного&gt;</b> · №2', $text);
		self::assertStringContainsString('🗓 14.11.2026 ⏰ 19:00 МСК', $text);
		self::assertStringContainsString('👤 Организатор: <a href="tg://user?id=10">@andrey</a>', $text);
		self::assertStringContainsString('⚖️ Уровень: средний+ (7 баллов на двоих)', $text);
		self::assertStringContainsString('👥 Команды по 2 игрока', $text);
		self::assertStringContainsString('🟢 Идёт регистрация', $text);
		self::assertStringContainsString('🎟 Занято 4/4 · запас 3', $text);
		self::assertStringContainsString(
			"🛡 <b>Команды:</b>\n1. <b>Wolves</b> — 👑 <a href=\"tg://user?id=12\">@p2</a> (кэп), <a href=\"tg://user?id=13\">@p3</a>\n2. <b>Bears</b> — 👑 <a href=\"tg://user?id=14\">@p4</a> (кэп), <a href=\"tg://user?id=15\">@p5</a>",
			$text,
		);
		self::assertStringContainsString("⏳ <b>Запас:</b>\n1. <b>Late</b> — 👑 <a href=\"tg://user?id=16\">@p6</a> (кэп), <a href=\"tg://user?id=17\">@p7</a>\n🙋 Соло: <a href=\"tg://user?id=18\">@p8</a>", $text);
		self::assertStringContainsString("🧩 <b>Собираются:</b>\n• Forming — 👑 <a href=\"tg://user?id=19\">@p9</a> (кэп) (1/2)", $text);
		self::assertDoesNotMatchRegularExpression('/средний \(3\)|\(3\)/u', $text, 'player levels are not shown');
		self::assertStringContainsString('в личке у @pop1_bot', $text);
	}

	public function testClosedTournamentHasStatusLineAndNoCallToAction(): void {
		$starts = new DateTimeImmutable('2026-11-14 16:00:00', new DateTimeZone('UTC'));
		$t = new Tournament(1, -100, 1, 'Кубок', $starts, 3, null, null, null, 24, TournamentStatus::CLOSED, 5, null);
		$text = (new AnnouncementView('pop1_bot', []))->render($t, Lineup::compute(24, [], []));
		self::assertStringContainsString('🔒 Регистрация закрыта', $text);
		self::assertStringContainsString('без ограничения по уровню', $text);
		self::assertStringNotContainsString('Записаться', $text);
	}

	public function testListBlockShowsEmptyHintAndFooter(): void {
		$starts = new DateTimeImmutable('2026-11-14 16:00:00', new DateTimeZone('UTC'));
		$t = new Tournament(3, -100, 1, 'Кубок', $starts, 3, null, null, null, 24, TournamentStatus::DRAFT, null, null);
		$text = (new AnnouncementView('pop1_bot', []))->listBlock($t, Lineup::compute(24, [], []), 4000, 'подвал');
		self::assertStringContainsString('📝 Черновик', $text);
		self::assertStringContainsString('<i>Пока никто не записался.</i>', $text);
		self::assertStringEndsWith("\n\nподвал", $text);
	}

	public function testLongLineupIsCutWithHintAndFullMessagesSplit(): void {
		$starts = new DateTimeImmutable('2026-11-14 16:00:00', new DateTimeZone('UTC'));
		$t = new Tournament(
			9,
			-100,
			1,
			'Большой кубок',
			$starts,
			4,
			null,
			null,
			null,
			400,
			TournamentStatus::REGISTRATION,
			null,
			null,
		);
		$t->number = 1;
		$players = [];
		$teams = [];
		$pid = 1;
		for ($i = 1; $i <= 100; $i++) {
			$ids = [];
			for ($k = 0; $k < 4; $k++) {
				$ids[] = ++$pid;
				$players[$pid] = new Player($pid, 1000 + $pid, 'player_with_long_name_' . $pid, 'P');
			}
			$members = array_map(static fn (int $p): TeamMember => new TeamMember($i, 9, $p), $ids);
			$teams[] = new TeamEntry(
				new Team($i, 9, $ids[0], 'Команда номер ' . $i, TeamStatus::REGISTERED, $starts, $starts),
				$members,
			);
		}
		$lineup = Lineup::compute(400, $teams, []);
		$view = new AnnouncementView('pop1_bot', $players);

		$block = $view->listBlock($t, $lineup, 4000);
		self::assertLessThanOrEqual(4000, mb_strlen($block));
		self::assertMatchesRegularExpression('~<i>…и ещё \d+ строк[аи]?\. Полный состав: /info 1</i>$~u', $block);
		self::assertStringContainsString('1. <b>Команда номер 1</b>', $block);
		self::assertSame(substr_count($block, '<b>'), substr_count($block, '</b>'), 'no broken tags');

		$announcement = $view->render($t, $lineup);
		self::assertLessThanOrEqual(4000, mb_strlen($announcement));
		self::assertStringEndsWith('жми кнопку ниже 👇', $announcement);

		$messages = $view->fullMessages($t, $lineup);
		self::assertGreaterThan(1, count($messages));
		foreach ($messages as $m) {
			self::assertLessThanOrEqual(4000, mb_strlen($m));
		}
		self::assertStringContainsString('100. <b>Команда номер 100</b>', end($messages));
	}
}
