<?php

declare(strict_types=1);

namespace P1Bot\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/** DB datetime helpers: everything is stored as 'Y-m-d H:i:s' in UTC. */
final class DateFormat
{
    public const DB = 'Y-m-d H:i:s';

    public static function toDb(DateTimeInterface $time): string
    {
        return DateTimeImmutable::createFromInterface($time)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::DB);
    }

    public static function fromDb(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    public static function fromDbNullable(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : self::fromDb($value);
    }
}
