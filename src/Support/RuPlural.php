<?php

declare(strict_types=1);

namespace P1Bot\Support;

/**
	Russian plural forms.
*/
class RuPlural {

	/**
		@param array{0: string, 1: string, 2: string} $forms e.g. ['балл', 'балла', 'баллов']
	*/
	public static function choose(int $n, array $forms): string {
		$n = abs($n);
		$mod100 = $n % 100;
		$mod10 = $n % 10;
		if ($mod100 >= 11 && $mod100 <= 14) {
			return $forms[2];
		}
		return match ($mod10) {
			1 => $forms[0],
			2, 3, 4 => $forms[1],
			default => $forms[2],
		};
	}

	public static function points(int $n): string {
		return self::choose($n, ['балл', 'балла', 'баллов']);
	}
}
