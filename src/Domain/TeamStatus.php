<?php

declare(strict_types=1);

namespace P1Bot\Domain;

use InvalidArgumentException;

/**
	Team statuses (string constants: PHP 8.0 has no native enums).
*/
class TeamStatus {
	/**
		Roster not full yet; not in the queue.
	*/
	public const FORMING = 'forming';
	/**
		Roster full; queued by registered_at.
	*/
	public const REGISTERED = 'registered';

	protected function __construct() {
	}

	public static function assertValid(string $status): string {
		if ($status !== self::FORMING && $status !== self::REGISTERED) {
			throw new InvalidArgumentException("Unknown team status: $status");
		}
		return $status;
	}
}
