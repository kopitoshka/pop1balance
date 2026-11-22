<?php

declare(strict_types=1);

namespace P1Bot\Domain;

/**
	Maps an organizer can pick for a tournament. The stored values are exactly these English
	strings; the first empty option on the form is not a value and cannot be saved.
*/
class TournamentMaps {
	public const VALUES = ['Classic', 'Evolving', 'Phoenix', 'custom'];

	public const REQUIRED_TEXT = 'Выбери карту';
	public const UNKNOWN_TEXT = 'Карта — Classic, Evolving, Phoenix или custom.';
	public const PLACEHOLDER = 'Выбери карту';

	public static function isValid(string $map): bool {
		return in_array($map, self::VALUES, true);
	}
}
