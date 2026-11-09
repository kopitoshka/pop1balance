<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Support\TeamLetters;
use PHPUnit\Framework\TestCase;

class TeamLettersTest extends TestCase {

	public function testLabelsAreExcelStyleLetters(): void {
		self::assertSame('A', TeamLetters::label(0));
		self::assertSame('B', TeamLetters::label(1));
		self::assertSame('Z', TeamLetters::label(25));
		self::assertSame('AA', TeamLetters::label(26));
		self::assertSame('AB', TeamLetters::label(27));
		self::assertSame('AZ', TeamLetters::label(51));
		self::assertSame('BA', TeamLetters::label(52));
		self::assertSame('A. ', TeamLetters::prefix(0));
	}

	public function testParseAcceptsLettersAndDigits(): void {
		self::assertSame(0, TeamLetters::parse('A'));
		self::assertSame(0, TeamLetters::parse('a'));
		self::assertSame(1, TeamLetters::parse('B'));
		self::assertSame(25, TeamLetters::parse('Z'));
		self::assertSame(26, TeamLetters::parse('AA'));
		self::assertSame(27, TeamLetters::parse('ab'));
		self::assertSame(0, TeamLetters::parse('1'));
		self::assertSame(1, TeamLetters::parse('2'));
		self::assertNull(TeamLetters::parse(''));
		self::assertNull(TeamLetters::parse('0'));
		self::assertNull(TeamLetters::parse('Wolves'));
		self::assertNull(TeamLetters::parse('AAAA'));
		self::assertNull(TeamLetters::parse('A1'));
	}

	public function testParseRoundTrip(): void {
		foreach ([0, 1, 25, 26, 27, 51, 52, 100, 700] as $i) {
			self::assertSame($i, TeamLetters::parse(TeamLetters::label($i)));
		}
	}
}
