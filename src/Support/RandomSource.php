<?php

declare(strict_types=1);

namespace P1Bot\Support;

/**
	Picks one item from a non-empty list. Inject FixedRandom in tests for deterministic replies.
*/
interface RandomSource {

	/**
		@param non-empty-list<mixed> $items
		@return mixed
	*/
	public function pick(array $items): mixed;
}
