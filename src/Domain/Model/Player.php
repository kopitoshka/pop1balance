<?php

declare(strict_types=1);

namespace P1Bot\Domain\Model;

class Player {

	public function __construct(
		public int $id,
		public ?int $tg_user_id,
		public ?string $username,
		public string $display_name,
	) {
	}

	/**
		@param array<string, mixed> $row
	*/
	public static function fromRow(array $row): self {
		return new self(
			(int) $row['id'],
			$row['tg_user_id'] === null ? null : (int) $row['tg_user_id'],
			$row['username'] === null ? null : (string) $row['username'],
			(string) $row['display_name'],
		);
	}

	/**
		"@vasya" if known, otherwise the display name.
	*/
	public function mention(): string {
		if ($this->username !== null && $this->username !== '') {
			return '@' . $this->username;
		}
		return $this->display_name !== '' ? $this->display_name : 'игрок #' . $this->id;
	}
}
