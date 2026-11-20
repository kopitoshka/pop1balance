<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Exception\ValidationError;

/**
	A Mini App form error: the message plus the field it belongs to (if any) and why it happened,
	so the page can show it under the right input or ask to reopen an outdated form.
*/
class WebAppFormError extends ValidationError {
	public const INVALID = 'invalid';
	public const EXPIRED = 'expired';
	public const FORBIDDEN = 'forbidden';

	/**
		@param array<string, string> $fields field => message, for several errors at once
	*/
	public function __construct(
		string $message,
		public string $field = '',
		public string $reason = self::INVALID,
		public array $fields = [],
	) {
		parent::__construct($message);
		if ($this->field !== '' && !isset($this->fields[$this->field])) {
			$this->fields[$this->field] = $message;
		}
	}

	/**
		@return array<string, mixed>
	*/
	public function toArray(): array {
		return [
			'ok' => false,
			'error' => $this->getMessage(),
			'reason' => $this->reason,
			'field' => $this->field !== '' ? $this->field : null,
			'fields' => (object) $this->fields,
		];
	}
}
