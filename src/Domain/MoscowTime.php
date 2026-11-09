<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use P1Bot\Domain\Exception\ValidationError;

/** Users enter and read tournament times in Moscow time; the DB keeps UTC. */
final class MoscowTime
{
    public const TZ = 'Europe/Moscow';

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::TZ);
    }

    /** Parses "2026-11-14 19:00" (Moscow) into a UTC instant. */
    public static function parse(string $value): DateTimeImmutable
    {
        try {
            $local = new DateTimeImmutable(trim($value), self::zone());
        } catch (Exception) {
            throw new ValidationError(sprintf('Не понимаю время «%s». Пример: 2026-11-14 19:00 (МСК).', $value));
        }
        return $local->setTimezone(new DateTimeZone('UTC'));
    }

    public static function format(DateTimeInterface $utc, string $pattern = 'd.m.Y H:i'): string
    {
        return DateTimeImmutable::createFromInterface($utc)->setTimezone(self::zone())->format($pattern) . ' МСК';
    }
}
