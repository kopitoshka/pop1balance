<?php

declare(strict_types=1);

namespace P1Bot\Domain\Model;

class Balance {

	public function __construct(
		public int $id,
		public int $owner_id,
		public ?int $parent_id,
		public string $name,
		public bool $is_default,
	) {
	}

	/**
		@param array<string, mixed> $row
	*/
	public static function fromRow(array $row): self {
		return new self(
			(int) $row['id'],
			(int) $row['owner_id'],
			$row['parent_id'] === null ? null : (int) $row['parent_id'],
			(string) $row['name'],
			(bool) $row['is_default'],
		);
	}
}
