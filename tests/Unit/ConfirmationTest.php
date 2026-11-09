<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\App\Confirmation;
use PHPUnit\Framework\TestCase;

final class ConfirmationTest extends TestCase
{
    /** @dataProvider yes */
    public function testClearYes(string $text): void
    {
        self::assertTrue(Confirmation::isClearYes($text), $text);
    }

    /** @dataProvider notYes */
    public function testNotAConfirmation(string $text): void
    {
        self::assertFalse(Confirmation::isClearYes($text), $text);
    }

    /** @return array<string, array{string}> */
    public static function yes(): array
    {
        $cases = ['да', 'Да!', 'ДА, всё верно', 'ок', 'Окей, публикуем', 'публикуем', 'Публикуй', 'всё верно', 'верно',
            'давай', 'го', '+', '👍', 'да, конечно', 'ок, спасибо', 'Да. Публикуем!'];
        return array_combine($cases, array_map(static fn (string $c): array => [$c], $cases));
    }

    /** @return array<string, array{string}> */
    public static function notYes(): array
    {
        $cases = ['', 'нет', 'не публикуй', 'да, но время 21:00', 'ок, только лимит 30', 'подожди', 'погоди',
            'поправь название', 'всё верно?', 'хм', 'вроде да', 'да, кроме времени', 'измени время', 'отмена',
            'да нет наверное', 'давай завтра в 20', 'ну наверное можно', 'стоп'];
        return array_combine(array_map(static fn (string $c): string => $c === '' ? '(empty)' : $c, $cases), array_map(static fn (string $c): array => [$c], $cases));
    }
}
