<?php

declare(strict_types=1);

namespace P1Bot\Support;

/**
	mt_rand-based picker for production.
*/
class SystemRandom implements RandomSource {

	public function pick(array $items): mixed {
		if ($items === []) {
			throw new \InvalidArgumentException('Cannot pick from an empty list');
		}
		return $items[array_rand($items)];
	}
}
