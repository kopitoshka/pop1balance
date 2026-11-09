<?php

declare(strict_types=1);

namespace P1Bot\Support;

/**
	Ordinal labels for team lists shown to users: A, B, … Z, AA, AB…
	Internal team ids stay numeric; these letters are display-only (and for
	parsing «команда B» / «команда 2» when a text command needs an ordinal).
*/
class TeamLetters {

	/**
		0-based index → A, B, … Z, AA, AB…
	*/
	public static function label(int $index): string {
		if ($index < 0) {
			throw new \InvalidArgumentException('Team letter index must be >= 0');
		}
		$n = $index + 1;
		$out = '';
		while ($n > 0) {
			$n--;
			$out = chr(ord('A') + ($n % 26)) . $out;
			$n = intdiv($n, 26);
		}
		return $out;
	}

	/**
		Prefix for a list line: «A. », «B. », …
	*/
	public static function prefix(int $index): string {
		return self::label($index) . '. ';
	}

	/**
		Parse a user token into a 0-based team ordinal.
		Accepts letters (A, b, AA) and 1-based digits (1, 2) for old habits.
		Null when the token is not an ordinal.
	*/
	public static function parse(string $token): ?int {
		$token = trim($token);
		if ($token === '') {
			return null;
		}
		if (preg_match('/^\d{1,4}$/', $token) === 1) {
			$n = (int) $token;
			return $n >= 1 ? $n - 1 : null;
		}
		if (preg_match('/^[A-Za-z]{1,3}$/', $token) !== 1) {
			return null;
		}
		$s = strtoupper($token);
		$n = 0;
		$len = strlen($s);
		for ($i = 0; $i < $len; $i++) {
			$n = $n * 26 + (ord($s[$i]) - ord('A') + 1);
		}
		return $n - 1;
	}
}
