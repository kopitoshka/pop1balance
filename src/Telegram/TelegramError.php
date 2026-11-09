<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

use RuntimeException;

/**
	Telegram returned ok=false.
*/
class TelegramError extends RuntimeException {

	/**
		@param array<string, mixed> $parameters Bot API "parameters" object (e.g. retry_after)
	*/
	public function __construct(
		string $method,
		string $description,
		int $code,
		public array $parameters = [],
	) {
		parent::__construct(sprintf('Telegram %s failed: %s', $method, $description), $code);
	}

	public function isNotModified(): bool {
		return str_contains($this->getMessage(), 'message is not modified');
	}

	public function retryAfter(): ?int {
		if (!isset($this->parameters['retry_after'])) {
			return null;
		}
		$n = (int) $this->parameters['retry_after'];
		return $n > 0 ? $n : null;
	}
}
