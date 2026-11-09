<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Exception\InvalidLevel;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Support\RuPlural;

/**
	Player levels 1..6 and their Russian names.
*/
class Levels {
	public const MIN = 1;
	public const MAX = 6;
	/**
		Level of a player that has no rating in the scale.
	*/
	public const DEFAULT = 3;

	protected const NAMES = [
		1 => 'начальный',
		2 => 'начальный+',
		3 => 'средний',
		4 => 'средний+',
		5 => 'высокий',
		6 => 'высокий+',
	];

	public static function isValid(int $level): bool {
		return $level >= self::MIN && $level <= self::MAX;
	}

	public static function assertValid(int $level): void {
		if (!self::isValid($level)) {
			throw new ValidationError(sprintf('Уровень должен быть от %d до %d.', self::MIN, self::MAX));
		}
	}

	public static function name(int $level): string {
		self::assertValid($level);
		return self::NAMES[$level];
	}

	/**
		@return array<int, string>
	*/
	public static function all(): array {
		return self::NAMES;
	}

	/**
		Base words (without "+") that mean a level: canonical names and synonyms.
	*/
	protected const WORDS = [
		'начальный' => 1,
		'слабый' => 1,
		'базовый' => 1,
		'начинающий' => 1,
		'средний' => 3,
		'высокий' => 5,
		'танк' => 5,
	];

	/**
		Accepts "4", "средний+", "Средний плюс", synonyms ("слабый" = начальный, "танк" = высокий)
		and small typos ("выскоий+"). Returns null if not recognised or ambiguous.
	*/
	public static function parse(string $value): ?int {
		$value = self::normalize($value);
		if ($value === '') {
			return null;
		}
		if (ctype_digit($value)) {
			$level = (int) $value;
			return self::isValid($level) ? $level : null;
		}

		$plus = str_ends_with($value, '+');
		$word = $plus ? substr($value, 0, -1) : $value;
		if ($word === '' || str_contains($word, '+')) {
			return null;
		}
		$base = self::WORDS[$word] ?? self::fuzzyWord($word);
		if ($base === null) {
			return null;
		}
		return $plus ? $base + 1 : $base;
	}

	/**
		Tournament level (= the maximum team level), as typed by a user:
		 - one number: the total points per team (max_points), from teamSize×1 to teamSize×6, e.g. "10";
		 - levels of all players, exactly teamSize of them: "средний, средний, высокий", "3,3,5".
		A single level name is a list of one, so it only fits a team of one.

		@return array{0: list<int>|null, 1: int} [levels or null for a plain total, max_points]
		@throws InvalidLevel with a short reason; callers add the usage help
	*/
	public static function parseTournamentLevel(string $value, int $team_size): array {
		$parts = self::tokens($value);
		if ($parts === []) {
			throw new InvalidLevel('Не указан уровень турнира.');
		}
		if (count($parts) === 1 && ctype_digit($parts[0]) && $team_size > 1) {
			$total = (int) $parts[0];
			$min = $team_size * self::MIN;
			$max = $team_size * self::MAX;
			if ($total < $min || $total > $max) {
				throw new InvalidLevel(sprintf(
					'Уровень %d не подходит: для команды из %d %s сумма баллов должна быть от %d до %d.',
					$total,
					$team_size,
					RuPlural::choose($team_size, ['игрока', 'игроков', 'игроков']),
					$min,
					$max,
				));
			}
			return [null, $total];
		}

		$levels = [];
		foreach ($parts as $part) {
			$level = self::parse($part);
			if ($level === null) {
				throw new InvalidLevel(ctype_digit($part)
					? sprintf('Уровня «%s» нет: уровни игроков — от %d до %d.', $part, self::MIN, self::MAX)
					: sprintf('Не понимаю уровень «%s».', $part));
			}
			$levels[] = $level;
		}
		if (count($levels) !== $team_size) {
			throw new InvalidLevel(sprintf(
				'В команде %d %s, а уровней указано %d. Перечисли уровни всех игроков или напиши одним числом сумму баллов на команду.',
				$team_size,
				RuPlural::choose($team_size, ['игрок', 'игрока', 'игроков']),
				count($levels),
			));
		}
		return [$levels, array_sum($levels)];
	}

	/**
		Parses a list of levels: "3,3,4", "средний, средний, средний+", "средний средний средний плюс".

		@return list<int>
	*/
	public static function parseReferenceTeam(string $value): array {
		$parts = self::tokens($value);
		if ($parts === []) {
			throw new InvalidLevel('Не указан уровень турнира.');
		}
		$levels = [];
		foreach ($parts as $part) {
			$level = self::parse($part);
			if ($level === null) {
				throw new InvalidLevel(sprintf(
					'Не понимаю уровень «%s». Уровни: %s.',
					$part,
					implode(', ', self::NAMES),
				));
			}
			$levels[] = $level;
		}
		return $levels;
	}

	/**
		@return list<string> words/digits, with "+" / "плюс" attached to the previous word
	*/
	protected static function tokens(string $value): array {
		$tokens = preg_split('/[\s,;]+/u', mb_strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$parts = [];
		foreach ($tokens as $token) {
			if (($token === '+' || $token === 'плюс') && $parts !== []) {
				$parts[count($parts) - 1] .= '+';
				continue;
			}
			$parts[] = $token;
		}
		return $parts;
	}

	/**
		"средний, средний, средний+"
	*/
	public static function describeReferenceTeam(array $levels): string {
		return implode(', ', array_map([self::class, 'name'], $levels));
	}

	protected static function normalize(string $value): string {
		$value = mb_strtolower(trim($value));
		$value = str_replace('ё', 'е', $value);
		$value = (string) preg_replace('/\s*(плюс|\+)\s*$/u', '+', $value);
		$value = (string) preg_replace('/[\s«»"\'.!]+/u', '', $value);
		return $value;
	}

	/**
		Closest known word within a small edit distance; null when nothing is close or it is ambiguous.
	*/
	protected static function fuzzyWord(string $word): ?int {
		$length = mb_strlen($word);
		if ($length < 5) {
			return null;
		}
		$max_distance = $length >= 7 ? 2 : 1;
		$target = self::singleByte($word);
		$best = null;
		$best_distance = PHP_INT_MAX;
		$ambiguous = false;
		foreach (self::WORDS as $candidate => $level) {
			$distance = self::distance($target, self::singleByte($candidate));
			if ($distance < $best_distance) {
				[$best, $best_distance, $ambiguous] = [$level, $distance, false];
			} elseif ($distance === $best_distance && $level !== $best) {
				$ambiguous = true;
			}
		}
		return $best_distance <= $max_distance && !$ambiguous ? $best : null;
	}

	/**
		Optimal string alignment distance (a swap of neighbours counts as one edit).
	*/
	protected static function distance(string $a, string $b): int {
		$n = strlen($a);
		$m = strlen($b);
		$d = [];
		for ($i = 0; $i <= $n; $i++) {
			$d[$i][0] = $i;
		}
		for ($j = 0; $j <= $m; $j++) {
			$d[0][$j] = $j;
		}
		for ($i = 1; $i <= $n; $i++) {
			for ($j = 1; $j <= $m; $j++) {
				$cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
				$d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
				if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
					$d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
				}
			}
		}
		return $d[$n][$m];
	}

	/**
		One byte per letter so byte-level distance equals letter-level distance.
	*/
	protected static function singleByte(string $s): string {
		return (string) mb_convert_encoding($s, 'Windows-1251', 'UTF-8');
	}

	/**
		Canonical storage form of a reference team: "3,3,4".
	*/
	public static function formatReferenceTeam(array $levels): string {
		return implode(',', $levels);
	}

	/**
		Tournament level: round(max_points / team_size), e.g. 10/3 -> 3 (средний), 11/3 -> 4 (средний+).
	*/
	public static function averageLevel(int $max_points, int $team_size): int {
		$level = (int) round($max_points / $team_size);
		return max(self::MIN, min(self::MAX, $level));
	}
}
