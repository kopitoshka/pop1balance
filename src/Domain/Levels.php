<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use P1Bot\Domain\Exception\InvalidLevel;
use P1Bot\Domain\Exception\ValidationError;
use P1Bot\Support\RuPlural;

/**
	Player levels 1..6 and their Russian names (Пехота…Тяжёлый танк).
	Official names come from config (tournament.level_names) or DEFAULT_NAMES.
	Old adjective names (начальный, средний+, …) stay accepted as synonyms when parsing.
*/
class Levels {
	public const MIN = 1;
	public const MAX = 6;
	/**
		Level of a player that has no rating in the scale.
	*/
	public const DEFAULT = 3;
	/**
		Default min level at which a pair gets a bonus (both players).
	*/
	public const PAIR_BONUS_MIN_LEVEL = 4;
	/**
		Default bonus for a strong pair: min(level_i, level_j) minus this offset.
	*/
	public const PAIR_BONUS_OFFSET = 3;

	protected static int $pair_bonus_min_level = self::PAIR_BONUS_MIN_LEVEL;
	protected static int $pair_bonus_offset = self::PAIR_BONUS_OFFSET;

	/**
		Default official names (with ё). A tournament limit is not named after a class:
		it is shown as «Макс. уровень: 11».
	*/
	public const DEFAULT_NAMES = [
		1 => 'Пехота',
		2 => 'Джип',
		3 => 'БТР',
		4 => 'Вертолёт',
		5 => 'Танк',
		6 => 'Тяжёлый танк',
	];

	/**
		@var array<int, string>
	*/
	protected static array $names = self::DEFAULT_NAMES;

	/**
		Historical and informal words still accepted when parsing (not shown as official names).
		«танк» is both the official name of level 5 and the old synonym of «высокий».
	*/
	protected const SYNONYMS = [
		'начальный' => 1,
		'слабый' => 1,
		'базовый' => 1,
		'начинающий' => 1,
		'средний' => 3,
		'высокий' => 5,
		'танк' => 5,
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
		return self::$names[$level];
	}

	/**
		@return array<int, string>
	*/
	public static function all(): array {
		return self::$names;
	}

	/**
		Accepts "4", official names («Вертолёт», «Тяжёлый танк»), old names ("средний+", "Средний плюс"),
		synonyms ("слабый" = Пехота, "танк" = Танк) and small typos ("выскоий+").
		Returns null if not recognised, ambiguous, or out of 1..6.
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
		$base = self::wordMap()[$word] ?? self::fuzzyWord($word);
		if ($base === null) {
			return null;
		}
		$level = $plus ? $base + 1 : $base;
		return self::isValid($level) ? $level : null;
	}

	/**
		Official names (normalized) plus SYNONYMS. «тяжёлый танк» is stored without spaces or ё.
		@return array<string, int>
	*/
	protected static function wordMap(): array {
		$map = self::SYNONYMS;
		foreach ([self::DEFAULT_NAMES, self::$names] as $set) {
			foreach ($set as $level => $name) {
				$key = self::normalize($name);
				if ($key !== '') {
					$map[$key] = $level;
				}
			}
		}
		return $map;
	}

	/**
		Tournament level (= the maximum team level), as typed by a user:
		 - one number: the total points per team (max_points), from teamSize×1 to maxTeamPoints(teamSize), e.g. "10";
		 - levels of all players, exactly teamSize of them: "БТР, БТР, Танк", "3,3,5" (max_points uses teamPoints).
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
			$max = self::maxTeamPoints($team_size);
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
		return [$levels, self::teamPoints($levels)];
	}

	/**
		Parses a list of levels: "3,3,4", "БТР, БТР, Вертолёт", "средний средний средний плюс".

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
					implode(', ', self::$names),
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
		return self::mergeNamedPhrases($parts);
	}

	/**
		Joins adjacent tokens that together are an official name («тяжёлый» + «танк»).
		Longest match wins. A trailing «+» stays on the last word of the phrase.

		@param list<string> $parts
		@return list<string>
	*/
	protected static function mergeNamedPhrases(array $parts): array {
		if ($parts === []) {
			return [];
		}
		$map = self::wordMap();
		$out = [];
		$n = count($parts);
		$i = 0;
		while ($i < $n) {
			$used = 1;
			for ($len = $n - $i; $len >= 2; $len--) {
				$chunk = array_slice($parts, $i, $len);
				$last = $chunk[$len - 1];
				$plus = str_ends_with($last, '+');
				if ($plus) {
					$chunk[$len - 1] = substr($last, 0, -1);
				}
				$key = self::normalize(implode(' ', $chunk));
				if ($key !== '' && isset($map[$key])) {
					$used = $len;
					break;
				}
			}
			$out[] = implode(' ', array_slice($parts, $i, $used));
			$i += $used;
		}
		return $out;
	}

	/**
		"БТР, БТР, Вертолёт"
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
		foreach (self::wordMap() as $candidate => $level) {
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
		Apply pair-bonus thresholds from config (or restore the defaults).

		@param array<string, mixed> $tournament the 'tournament' section of config.php
	*/
	public static function configure(array $tournament = []): void {
		self::$pair_bonus_min_level = (int) ($tournament['pair_bonus_min_level'] ?? self::PAIR_BONUS_MIN_LEVEL);
		self::$pair_bonus_offset = (int) ($tournament['pair_bonus_offset'] ?? self::PAIR_BONUS_OFFSET);
		self::$names = self::parseNames($tournament['level_names'] ?? null);
	}

	/**
		@param mixed $raw tournament.level_names: a map 1..6 => label, or null to use the defaults
		@return array<int, string>
	*/
	protected static function parseNames(mixed $raw): array {
		if (!is_array($raw)) {
			return self::DEFAULT_NAMES;
		}
		$names = [];
		for ($level = self::MIN; $level <= self::MAX; $level++) {
			$label = trim((string) ($raw[$level] ?? $raw[(string) $level] ?? ''));
			if ($label === '') {
				return self::DEFAULT_NAMES;
			}
			$names[$level] = $label;
		}
		return $names;
	}

	public static function pairBonusMinLevel(): int {
		return self::$pair_bonus_min_level;
	}

	public static function pairBonusOffset(): int {
		return self::$pair_bonus_offset;
	}

	/**
		Team strength: the sum of player levels plus a bonus for every unordered
		pair where both levels are at least the configured min (default 4).
		The bonus of a pair is min(level_i, level_j) minus the offset (default 3).
		Self-pairs are not counted.

		@param list<int> $levels
	*/
	public static function teamPoints(array $levels): int {
		$sum = 0;
		$n = count($levels);
		$min_level = self::$pair_bonus_min_level;
		$offset = self::$pair_bonus_offset;
		for ($i = 0; $i < $n; $i++) {
			$sum += $levels[$i];
			for ($j = $i + 1; $j < $n; $j++) {
				if ($levels[$i] >= $min_level && $levels[$j] >= $min_level) {
					$sum += min($levels[$i], $levels[$j]) - $offset;
				}
			}
		}
		return $sum;
	}

	/**
		Strongest possible team of this size (every player at MAX, with pair bonuses).
	*/
	public static function maxTeamPoints(int $team_size): int {
		if ($team_size <= 0) {
			return 0;
		}
		return self::teamPoints(array_fill(0, $team_size, self::MAX));
	}

	/**
		Average class of a limit: round(max_points / team_size), e.g. 10/3 -> 3 (БТР), 11/3 -> 4 (Вертолёт).
		Shown names of that class are for players; a tournament limit is displayed as «Макс. уровень: 11».
	*/
	public static function averageLevel(int $max_points, int $team_size): int {
		$level = (int) round($max_points / $team_size);
		return max(self::MIN, min(self::MAX, $level));
	}
}
