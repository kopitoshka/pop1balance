<?php

declare(strict_types=1);

namespace P1Bot\App;

/**
	Looks up a Telegram @username that the bot has not seen yet (MTProto).
*/
interface UsernameLookup {

	/**
		True when API id/hash are set and the resolver can be attempted.
	*/
	public function isAvailable(): bool;

	/**
		@param string $username without @
		@return array{id: int, first_name: string, last_name: string, username: ?string}|null
	*/
	public function resolve(string $username): ?array;
}
