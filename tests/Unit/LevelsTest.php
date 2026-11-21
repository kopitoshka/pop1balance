<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Levels;
use PHPUnit\Framework\TestCase;

class LevelsTest extends TestCase {

	public function testNames(): void {
		self::assertSame('начальный', Levels::name(1));
		self::assertSame('средний', Levels::name(Levels::DEFAULT));
		self::assertSame('высокий+', Levels::name(6));
		self::assertCount(6, Levels::all());
	}

	public function testInvalidLevelIsRejected(): void {
		$this->expectException(ValidationError::class);
		Levels::name(7);
	}

	public function testParse(): void {
		self::assertSame(4, Levels::parse('4'));
		self::assertSame(4, Levels::parse('Средний +'));
		self::assertSame(2, Levels::parse('начальный+'));
		self::assertNull(Levels::parse('0'));
		self::assertNull(Levels::parse('нуб'));
	}

	/**
		@dataProvider synonymProvider
	*/
	public function testSynonymsTyposAndPlus(string $input, ?int $expected): void {
		self::assertSame($expected, Levels::parse($input));
	}

	/**
		@return array<string, array{string, ?int}>
	*/
	public function synonymProvider(): array {
		return [
			'слабый' => ['слабый', 1],
			'базовый' => ['Базовый', 1],
			'начинающий+' => ['начинающий+', 2],
			'слабый плюс' => ['слабый плюс', 2],
			'танк' => ['танк', 5],
			'танк+' => ['ТАНК +', 6],
			'typo выскоий' => ['выскоий', 5],
			'typo выскоий+' => ['выскоий+', 6],
			'typo срдений' => ['срдений', 3],
			'средний плюс' => ['Средний Плюс', 4],
			'ё' => ['начальный', 1],
			'double plus' => ['высокий++', null],
			'too short to guess' => ['ср', null],
			'unknown word' => ['нормальный', null],
			'out of range' => ['7', null],
			'high plus beyond max' => ['высокий+', 6],
		];
	}

	public function testDescribeReferenceTeam(): void {
		self::assertSame('средний, средний, средний+', Levels::describeReferenceTeam([3, 3, 4]));
	}

	public function testParseReferenceTeamWithSpacesAndPlusWords(): void {
		self::assertSame([3, 3, 4], Levels::parseReferenceTeam('средний средний средний плюс'));
		self::assertSame([1, 6, 3], Levels::parseReferenceTeam('слабый, танк + ,  3'));
		self::assertSame([5, 6], Levels::parseReferenceTeam('Выскоий, выскоий+'));
	}

	public function testParseReferenceTeam(): void {
		self::assertSame([3, 3, 4], Levels::parseReferenceTeam('3,3,4'));
		self::assertSame([3, 3, 4], Levels::parseReferenceTeam('средний, средний; средний+'));
		self::assertSame('3,3,4', Levels::formatReferenceTeam([3, 3, 4]));
	}

	public function testParseReferenceTeamRejectsGarbage(): void {
		$this->expectException(\P1Bot\Domain\Exception\InvalidLevel::class);
		Levels::parseReferenceTeam('3, сильный');
	}

	/**
		@dataProvider tournamentLevelProvider
	*/
	public function testParseTournamentLevel(string $value, int $team_size, ?array $levels, int $max_points): void {
		self::assertSame([$levels, $max_points], Levels::parseTournamentLevel($value, $team_size));
	}

	/**
		@return array<string, array{string, int, ?list<int>, int}>
	*/
	public static function tournamentLevelProvider(): array {
		return [
			'total' => ['10', 3, null, 10],
			'total min' => ['3', 3, null, 3],
			'total max old' => ['18', 3, null, 18],
			'total max' => ['27', 3, null, 27],
			'total for pairs' => [' 7 ', 2, null, 7],
			'names' => ['средний, средний, высокий', 3, [3, 3, 5], 11],
			'digits' => ['3,3,4', 3, [3, 3, 4], 10],
			'mixed and synonyms' => ['Танк, слабый плюс', 2, [5, 2], 7],
			'typos and spaces' => ['выскоий средний +', 2, [5, 4], 10],
			'four plus four plus four' => ['4,4,4', 3, [4, 4, 4], 15],
			'six and six' => ['6,6', 2, [6, 6], 15],
		];
	}

	/**
		@dataProvider badTournamentLevelProvider
	*/
	public function testParseTournamentLevelRejects(string $value, int $team_size, string $reason): void {
		try {
			Levels::parseTournamentLevel($value, $team_size);
			self::fail('expected InvalidLevel');
		} catch (\P1Bot\Domain\Exception\InvalidLevel $e) {
			self::assertStringContainsString($reason, $e->getMessage());
		}
	}

	/**
		@return array<string, array{string, int, string}>
	*/
	public static function badTournamentLevelProvider(): array {
		return [
			'empty' => ['', 3, 'Не указан'],
			'single name is not a shorthand' => ['средний', 3, 'уровней указано 1'],
			'too few' => ['средний, высокий', 3, 'уровней указано 2'],
			'too many' => ['3,3,3,3', 3, 'уровней указано 4'],
			'total too small' => ['2', 3, 'от 3 до 27'],
			'total too big' => ['28', 3, 'от 3 до 27'],
			'digit out of range in list' => ['3, 7', 2, 'Уровня «7» нет'],
			'unknown word' => ['средний, крутой', 2, 'Не понимаю уровень «крутой»'],
		];
	}

	/**
		@dataProvider averageProvider
	*/
	public function testAverageLevel(int $max_points, int $team_size, int $expected): void {
		self::assertSame($expected, Levels::averageLevel($max_points, $team_size));
	}

	/**
		@return array<string, array{int, int, int}>
	*/
	public function averageProvider(): array {
		return [
			'9/3 средний' => [9, 3, 3],
			'10/3 средний' => [10, 3, 3],
			'11/3 средний+' => [11, 3, 4],
			'7/2 rounds half up' => [7, 2, 4],
			'clamped high' => [30, 4, 6],
		];
	}

	/**
		@dataProvider teamPointsProvider
	*/
	public function testTeamPoints(array $levels, int $expected): void {
		self::assertSame($expected, Levels::teamPoints($levels));
	}

	/**
		@return array<string, array{list<int>, int}>
	*/
	public static function teamPointsProvider(): array {
		return [
			'5+4+2' => [[5, 4, 2], 12],
			'5+3+3' => [[5, 3, 3], 11],
			'4+4+4' => [[4, 4, 4], 15],
			'6+5+1' => [[6, 5, 1], 14],
			'5+5+5' => [[5, 5, 5], 21],
			'6+6' => [[6, 6], 15],
			'4+4+3' => [[4, 4, 3], 12],
			'6+1+1' => [[6, 1, 1], 8],
			'single' => [[5], 5],
			'empty' => [[], 0],
		];
	}

	public function testMaxTeamPoints(): void {
		self::assertSame(6, Levels::maxTeamPoints(1));
		self::assertSame(15, Levels::maxTeamPoints(2));
		self::assertSame(27, Levels::maxTeamPoints(3));
		self::assertSame(270, Levels::maxTeamPoints(12));
	}

	public function testConfigureChangesPairBonus(): void {
		try {
			Levels::configure(['pair_bonus_min_level' => 5, 'pair_bonus_offset' => 3]);
			self::assertSame(11, Levels::teamPoints([5, 4, 2]), '4+2 is below the raised min, no bonus');
			self::assertSame(14, Levels::teamPoints([5, 5, 2]), 'only the 5+5 pair: 12 + 2');
		} finally {
			Levels::configure();
		}
		self::assertSame(12, Levels::teamPoints([5, 4, 2]));
	}
}
