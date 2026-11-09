<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

class TgUser {

	public function __construct(
		public int $id,
		public bool $is_bot,
		public ?string $username,
		public string $first_name,
		public string $last_name = '',
	) {
	}

	/**
		@param array<string, mixed> $raw
	*/
	public static function fromArray(array $raw): self {
		$username = isset($raw['username']) && $raw['username'] !== '' ? (string) $raw['username'] : null;
		return new self(
			(int) ($raw['id'] ?? 0),
			(bool) ($raw['is_bot'] ?? false),
			$username,
			(string) ($raw['first_name'] ?? ''),
			(string) ($raw['last_name'] ?? ''),
		);
	}

	public function displayName(): string {
		$name = trim($this->first_name . ' ' . $this->last_name);
		return $name !== '' ? $name : ($this->username ?? 'игрок');
	}
}
