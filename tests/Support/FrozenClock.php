<?php

declare(strict_types=1);

namespace P1Bot\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use P1Bot\Support\Clock;

/**
	Test clock that only moves when told to.
*/
class FrozenClock implements Clock {
	protected DateTimeImmutable $now;

	public function __construct(string $utc = '2026-11-09 12:00:00') {
		$this->now = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
	}

	public function now(): DateTimeImmutable {
		return $this->now;
	}

	public function advance(string $modifier = '+1 minute'): void {
		$this->now = $this->now->modify($modifier);
	}
}
