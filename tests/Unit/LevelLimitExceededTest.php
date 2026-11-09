<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Domain\Exception\LevelLimitExceeded;
use PHPUnit\Framework\TestCase;

final class LevelLimitExceededTest extends TestCase
{
    public function testMessageSaysByHowMuch(): void
    {
        $e = new LevelLimitExceeded(12, 10);
        self::assertSame(2, $e->excess());
        self::assertSame('Сумма уровней команды 12, а лимит турнира 10: перебор на 2 балла.', $e->getMessage());
        self::assertStringEndsWith('перебор на 1 балл.', (new LevelLimitExceeded(11, 10))->getMessage());
        self::assertStringEndsWith('перебор на 5 баллов.', (new LevelLimitExceeded(15, 10))->getMessage());
    }
}
