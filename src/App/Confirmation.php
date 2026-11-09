<?php

declare(strict_types=1);

namespace P1Bot\App;

/**
 * Is a message a clear "yes, publish"? Deliberately strict: anything with a correction,
 * a question, a negation or numbers is NOT a confirmation.
 */
final class Confirmation
{
    private const YES = [
        'да', 'ага', 'угу', 'ок', 'окей', 'окс', 'ok', 'okay', 'yes', 'yep', 'норм', 'нормально',
        'публикуем', 'публикуй', 'публикуйте', 'опубликуй', 'опубликуйте', 'публикация', 'публиковать',
        'верно', 'правильно', 'подтверждаю', 'подтверждено', 'давай', 'давайте', 'го', 'погнали',
        'отлично', 'супер', 'хорошо', 'годится', 'запускай', 'запускаем', 'можно', '+', '👍', '✅', '👌',
    ];

    /** Words that are fine next to a "yes" ("да, всё верно", "ок, публикуем"). */
    private const FILLER = ['все', 'так', 'конечно', 'именно', 'точно', 'уже', 'пожалуйста', 'плз', 'спасибо', 'бот', 'турнир', 'его', 'вперед'];

    private const NO_STEMS = ['не', 'нет', 'но', 'стоп', 'подожд', 'погод', 'попра', 'исправ', 'измен', 'поменя', 'замен', 'передела', 'отмен', 'кроме', 'только', 'однако', 'хотя'];

    public static function isClearYes(string $text): bool
    {
        $text = mb_strtolower(trim($text));
        if ($text === '' || mb_strlen($text) > 80 || str_contains($text, '?') || preg_match('/\d/u', $text)) {
            return false;
        }
        $text = str_replace('ё', 'е', $text);
        $tokens = preg_split('/[\s,.!;:()\-—]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $yes = 0;
        foreach ($tokens as $token) {
            foreach (self::NO_STEMS as $stem) {
                if ($token === $stem || (mb_strlen($stem) > 3 && str_starts_with($token, $stem))) {
                    return false;
                }
            }
            if (in_array($token, self::YES, true)) {
                $yes++;
                continue;
            }
            if (!in_array($token, self::FILLER, true)) {
                return false; // unknown words may carry a correction
            }
        }
        return $yes > 0;
    }
}
