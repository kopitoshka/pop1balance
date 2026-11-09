<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\MoscowTime;
use P1Bot\Support\DateFormat;
use PHPUnit\Framework\TestCase;

final class MoscowTimeTest extends TestCase
{
    public function testMoscowInputIsStoredAsUtc(): void
    {
        $utc = MoscowTime::parse('2026-11-14 19:00');
        self::assertSame('2026-11-14 16:00:00', DateFormat::toDb($utc));
        self::assertSame('14.11.2026 19:00 МСК', MoscowTime::format($utc));
    }

    public function testGarbageIsRejected(): void
    {
        $this->expectException(ValidationError::class);
        MoscowTime::parse('в субботу вечером');
    }
}
