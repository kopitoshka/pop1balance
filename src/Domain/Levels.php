<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Exception\ValidationError;

/** Player levels 1..6 and their Russian names. */
final class Levels
{
    public const MIN = 1;
    public const MAX = 6;
    /** Level of a player that has no rating in the scale. */
    public const DEFAULT = 3;

    private const NAMES = [
        1 => 'начальный',
        2 => 'начальный+',
        3 => 'средний',
        4 => 'средний+',
        5 => 'высокий',
        6 => 'высокий+',
    ];

    public static function isValid(int $level): bool
    {
        return $level >= self::MIN && $level <= self::MAX;
    }

    public static function assertValid(int $level): void
    {
        if (!self::isValid($level)) {
            throw new ValidationError(sprintf('Уровень должен быть от %d до %d.', self::MIN, self::MAX));
        }
    }

    public static function name(int $level): string
    {
        self::assertValid($level);
        return self::NAMES[$level];
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return self::NAMES;
    }

    /** Accepts "4", "средний+", "Средний +" etc. Returns null if not recognised. */
    public static function parse(string $value): ?int
    {
        $value = trim($value);
        if (ctype_digit($value)) {
            $level = (int) $value;
            return self::isValid($level) ? $level : null;
        }
        $normalized = str_replace(' ', '', mb_strtolower($value));
        $found = array_search($normalized, self::NAMES, true);
        return $found === false ? null : $found;
    }

    /**
     * Parses a reference team like "3,3,4" or "средний, средний, средний+".
     *
     * @return list<int>
     */
    public static function parseReferenceTeam(string $value): array
    {
        $parts = preg_split('/\s*[,;]\s*/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            throw new ValidationError('Эталонная команда не задана. Пример: 3,3,4 или «средний, средний, средний+».');
        }
        $levels = [];
        foreach ($parts as $part) {
            $level = self::parse($part);
            if ($level === null) {
                throw new ValidationError(sprintf('Не понимаю уровень «%s». Допустимо 1–6 или название уровня.', $part));
            }
            $levels[] = $level;
        }
        return $levels;
    }

    /** Canonical storage form of a reference team: "3,3,4". */
    public static function formatReferenceTeam(array $levels): string
    {
        return implode(',', $levels);
    }

    /** Tournament level: round(max_points / team_size), e.g. 10/3 -> 3 (средний), 11/3 -> 4 (средний+). */
    public static function averageLevel(int $maxPoints, int $teamSize): int
    {
        $level = (int) round($maxPoints / $teamSize);
        return max(self::MIN, min(self::MAX, $level));
    }
}
