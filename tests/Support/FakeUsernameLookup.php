<?php

declare(strict_types=1);

namespace P1Bot\Tests\Support;

use P1Bot\App\UsernameLookup;

/**
	In-memory UsernameLookup for tests.
*/
class FakeUsernameLookup implements UsernameLookup {

	/**
		@param array<string, array{id: int, first_name: string, last_name: string, username: ?string}|null> $map
	*/
	public function __construct(
		protected array $map = [],
		protected bool $available = true,
	) {
	}

	public function isAvailable(): bool {
		return $this->available;
	}

	public function resolve(string $username): ?array {
		$key = mb_strtolower(ltrim($username, '@'));
		if (!array_key_exists($key, $this->map)) {
			return null;
		}
		return $this->map[$key];
	}
}
