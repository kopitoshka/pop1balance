<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\MoscowTime;
use P1Bot\Support\DateFormat;
use PHPUnit\Framework\TestCase;

class MoscowTimeTest extends TestCase {

	public function testMoscowInputIsStoredAsUtc(): void {
		$utc = MoscowTime::parse('2026-11-14 19:00');
		self::assertSame('2026-11-14 16:00:00', DateFormat::toDb($utc));
		self::assertSame('14.11.2026 19:00 МСК', MoscowTime::format($utc));
	}

	public function testGarbageIsRejected(): void {
		$this->expectException(ValidationError::class);
		MoscowTime::parse('в субботу вечером');
	}

	public function testParseHuman(): void {
		$now = new \DateTimeImmutable('2026-11-09 12:00:00', new \DateTimeZone('UTC'));
		self::assertSame('2026-11-23 17:00:00', DateFormat::toDb(MoscowTime::parseHuman('23.11 20:00', $now)));
		self::assertSame(
			'2027-01-05 16:30:00',
			DateFormat::toDb(MoscowTime::parseHuman('05.01 19:30', $now)),
			'next year when the date has passed',
		);
		self::assertSame('2026-12-01 17:00:00', DateFormat::toDb(MoscowTime::parseHuman('1.12.26 20.00', $now)));
		self::assertSame('2026-11-23 17:00:00', DateFormat::toDb(MoscowTime::parseHuman('2026-11-23 20:00', $now)));
		self::assertSame('2026-11-23 20:00', MoscowTime::toInput(MoscowTime::parseHuman('23.11 20:00', $now)));
	}

	public function testParseHumanRejectsImpossibleDate(): void {
		$this->expectException(ValidationError::class);
		MoscowTime::parseHuman('31.02 20:00', new \DateTimeImmutable('2026-11-09 12:00:00'));
	}
}
