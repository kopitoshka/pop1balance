<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Domain\Model\Player;
use P1Bot\Domain\PlayersImport;
use PHPUnit\Framework\TestCase;

class PlayersImportParseTest extends TestCase {

	/**
		@param list<array{raw: string, username: ?string, nick: ?string, level: ?int, error: ?string, key: ?string}> $rows
	*/
	protected function byRaw(array $rows): array {
		$out = [];
		foreach ($rows as $row) {
			$out[$row['raw']] = $row;
		}
		return $out;
	}

	public function testParsesNickWithSpacesAndOptionalLevel(): void {
		$rows = $this->byRaw(PlayersImport::parse(
			"Zoloto on heat 5\nZoloto on heat\n  \n@Kopito_User 4\n@plainuser\n",
		));
		self::assertSame([
			'raw' => 'Zoloto on heat 5',
			'username' => null,
			'nick' => 'Zoloto on heat',
			'level' => 5,
			'error' => null,
			'key' => 'n:zoloto on heat',
		], $rows['Zoloto on heat 5']);
		self::assertSame([null, 'Zoloto on heat', null, 'n:zoloto on heat'], [
			$rows['Zoloto on heat']['username'],
			$rows['Zoloto on heat']['nick'],
			$rows['Zoloto on heat']['level'],
			$rows['Zoloto on heat']['key'],
		]);
		self::assertSame([
			'raw' => '@Kopito_User 4',
			'username' => 'kopito_user',
			'nick' => null,
			'level' => 4,
			'error' => null,
			'key' => 'u:kopito_user',
		], $rows['@Kopito_User 4']);
		self::assertSame(['plainuser', null, 'u:plainuser'], [
			$rows['@plainuser']['username'],
			$rows['@plainuser']['level'],
			$rows['@plainuser']['key'],
		]);
		self::assertCount(4, $rows);
	}

	public function testDigitTokenOutsideRangeIsAnError(): void {
		$row = PlayersImport::parse('Stas 66')[0];
		self::assertSame('уровень должен быть от 1 до 6, а указан 66.', $row['error']);
		self::assertSame('n:stas', $row['key']);
		self::assertNull($row['level']);
	}

	public function testLastOccurrenceWinsIncludingAnError(): void {
		$kept = PlayersImport::lastWins(PlayersImport::parse("Vasya 5\nVasya 4\nPetya 9\nPetya 3"));
		$by_key = [];
		foreach ($kept as $row) {
			$by_key[$row['key']] = $row;
		}
		self::assertSame(4, $by_key['n:vasya']['level']);
		self::assertSame('Petya 3', $by_key['n:petya']['raw']);
		self::assertNull($by_key['n:petya']['error']);

		$kept = PlayersImport::lastWins(PlayersImport::parse("Vasya 5\nVasya 99"));
		self::assertSame('уровень должен быть от 1 до 6, а указан 99.', $kept[0]['error']);
		self::assertCount(1, $kept);
	}

	public function testFormatSplitsAddedAndUpdatedAndEscapes(): void {
		$added = new Player(1, null, null, 'Новый', 'Новый', 'новый');
		$updated = new Player(2, null, 'vasya', 'Vasya', 'Vasya', 'vasya');
		$broken = new Player(3, 7, null, '<b>', '<b>', '<b>');
		$html = PlayersImport::format([
			'added' => [['player' => $added, 'level' => 3]],
			'updated' => [['player' => $updated, 'old_level' => 3, 'new_level' => 5]],
			'skipped' => 2,
			'errors' => [['raw' => 'Stas 66', 'reason' => 'уровень должен быть от 1 до 6, а указан 66.']],
		]);
		self::assertStringContainsString('<b>Добавила</b> (1):', $html);
		self::assertStringContainsString('• Новый — средний (3)', $html);
		self::assertStringContainsString('<b>Обновила</b> (1):', $html);
		self::assertStringContainsString(
			'• <a href="https://t.me/vasya">Vasya (@vasya)</a> — средний (3) → высокий (5)',
			$html,
		);
		self::assertStringContainsString('Пропустила 2 строки (игрок уже есть и уровень не менялся).', $html);
		self::assertStringContainsString('• «Stas 66» — уровень должен быть от 1 до 6, а указан 66.', $html);
		$esc = PlayersImport::format([
			'added' => [['player' => $broken, 'level' => 1]],
			'updated' => [],
			'skipped' => 0,
			'errors' => [['raw' => 'a <b>', 'reason' => 'x']],
		]);
		self::assertStringContainsString('<a href="tg://user?id=7">&lt;b&gt;</a>', $esc);
		self::assertStringContainsString('«a &lt;b&gt;»', $esc);
	}
}
