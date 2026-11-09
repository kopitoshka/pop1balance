<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use P1Bot\Domain\Exception\ValidationError;

/**
	Users enter and read tournament times in Moscow time; the DB keeps UTC.
*/
class MoscowTime {
	public const TZ = 'Europe/Moscow';

	public static function zone(): DateTimeZone {
		return new DateTimeZone(self::TZ);
	}

	/**
		Parses "2026-11-14 19:00" (Moscow) into a UTC instant.
	*/
	public static function parse(string $value): DateTimeImmutable {
		try {
			$local = new DateTimeImmutable(trim($value), self::zone());
		} catch (Exception) {
			throw new ValidationError(sprintf('Не понимаю время «%s». Пример: 2026-11-14 19:00 (МСК).', $value));
		}
		return $local->setTimezone(new DateTimeZone('UTC'));
	}

	/**
		Human input in Moscow time: "23.11 20:00", "23.11.2026 20:00", "2026-11-23 20:00".
		Without a year the nearest future date is used. Returns UTC.
	*/
	public static function parseHuman(string $value, DateTimeImmutable $now_utc): DateTimeImmutable {
		$value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
		if (preg_match('/^(\d{1,2})\.(\d{1,2})(?:\.(\d{2,4}))?\s+(\d{1,2})[:.](\d{2})$/u', $value, $m)) {
			$now_msk = $now_utc->setTimezone(self::zone());
			$year = $m[3] !== '' ? (int) $m[3] : (int) $now_msk->format('Y');
			if ($year < 100) {
				$year += 2000;
			}
			$candidate = self::fromParts($year, (int) $m[2], (int) $m[1], (int) $m[4], (int) $m[5], $value);
			if ($m[3] === '' && $candidate < $now_utc->modify('-1 day')) {
				$candidate = self::fromParts($year + 1, (int) $m[2], (int) $m[1], (int) $m[4], (int) $m[5], $value);
			}
			return $candidate;
		}
		if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{1,2}:\d{2}$/', $value)) {
			return self::parse($value);
		}
		throw new ValidationError(sprintf('Не понимаю дату «%s». Пиши так: 23.11 20:00 (по МСК).', $value));
	}

	/**
		"2026-11-23 20:00" in Moscow time, the format CreateTournament expects.
	*/
	public static function toInput(DateTimeImmutable $utc): string {
		return $utc->setTimezone(self::zone())->format('Y-m-d H:i');
	}

	protected static function fromParts(
		int $year,
		int $month,
		int $day,
		int $hour,
		int $minute,
		string $original,
	): DateTimeImmutable {
		if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59) {
			throw new ValidationError(sprintf('Такой даты нет: «%s».', $original));
		}
		return self::parse(sprintf('%04d-%02d-%02d %02d:%02d', $year, $month, $day, $hour, $minute));
	}

	public static function format(DateTimeInterface $utc, string $pattern = 'd.m.Y H:i'): string {
		return DateTimeImmutable::createFromInterface($utc)->setTimezone(self::zone())->format($pattern) . ' МСК';
	}
}
