<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Domain\Levels;
use PHPUnit\Framework\TestCase;

final class LevelsTest extends TestCase
{
    public function testNames(): void
    {
        self::assertSame('начальный', Levels::name(1));
        self::assertSame('средний', Levels::name(Levels::DEFAULT));
        self::assertSame('высокий+', Levels::name(6));
        self::assertCount(6, Levels::all());
    }

    public function testInvalidLevelIsRejected(): void
    {
        $this->expectException(ValidationError::class);
        Levels::name(7);
    }

    public function testParse(): void
    {
        self::assertSame(4, Levels::parse('4'));
        self::assertSame(4, Levels::parse('Средний +'));
        self::assertSame(2, Levels::parse('начальный+'));
        self::assertNull(Levels::parse('0'));
        self::assertNull(Levels::parse('нуб'));
    }

    /** @dataProvider synonymProvider */
    public function testSynonymsTyposAndPlus(string $input, ?int $expected): void
    {
        self::assertSame($expected, Levels::parse($input));
    }

    /** @return array<string, array{string, ?int}> */
    public function synonymProvider(): array
    {
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

    public function testDescribeReferenceTeam(): void
    {
        self::assertSame('средний, средний, средний+', Levels::describeReferenceTeam([3, 3, 4]));
    }

    public function testParseReferenceTeamWithSpacesAndPlusWords(): void
    {
        self::assertSame([3, 3, 4], Levels::parseReferenceTeam('средний средний средний плюс'));
        self::assertSame([1, 6, 3], Levels::parseReferenceTeam('слабый, танк + ,  3'));
        self::assertSame([5, 6], Levels::parseReferenceTeam('Выскоий, выскоий+'));
    }

    public function testParseReferenceTeam(): void
    {
        self::assertSame([3, 3, 4], Levels::parseReferenceTeam('3,3,4'));
        self::assertSame([3, 3, 4], Levels::parseReferenceTeam('средний, средний; средний+'));
        self::assertSame('3,3,4', Levels::formatReferenceTeam([3, 3, 4]));
    }

    public function testParseReferenceTeamRejectsGarbage(): void
    {
        $this->expectException(ValidationError::class);
        Levels::parseReferenceTeam('3, сильный');
    }

    /** @dataProvider averageProvider */
    public function testAverageLevel(int $maxPoints, int $teamSize, int $expected): void
    {
        self::assertSame($expected, Levels::averageLevel($maxPoints, $teamSize));
    }

    /** @return array<string, array{int, int, int}> */
    public function averageProvider(): array
    {
        return [
            '9/3 средний' => [9, 3, 3],
            '10/3 средний' => [10, 3, 3],
            '11/3 средний+' => [11, 3, 4],
            '7/2 rounds half up' => [7, 2, 4],
            'clamped high' => [30, 4, 6],
        ];
    }
}
