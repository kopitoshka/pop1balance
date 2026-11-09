<?php

declare(strict_types=1);

namespace P1Bot\Support;

/**
	Always returns the item at $index (modulo count). For tests.
*/
class FixedRandom implements RandomSource {

	public function __construct(protected int $index = 0) {
	}

	public function pick(array $items): mixed {
		if ($items === []) {
			throw new \InvalidArgumentException('Cannot pick from an empty list');
		}
		$keys = array_keys($items);
		return $items[$keys[$this->index % count($keys)]];
	}
}
