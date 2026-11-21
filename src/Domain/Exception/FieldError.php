<?php

declare(strict_types=1);

namespace P1Bot\Domain\Exception;

/**
	A validation error that belongs to form fields (the player card): the first message plus
	field => message for every wrong field, so the form can mark them all at once.
*/
class FieldError extends ValidationError {
	public string $field;

	/**
		@param array<string, string> $fields field => message
	*/
	public function __construct(public array $fields) {
		parent::__construct((string) reset($fields));
		$this->field = (string) key($fields);
	}
}
