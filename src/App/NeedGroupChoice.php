<?php

declare(strict_types=1);

namespace P1Bot\App;

use P1Bot\Domain\Exception\DomainError;

/**
	In private the action fits several groups: the user has to pick one (inline buttons grp:<chat id>).
*/
class NeedGroupChoice extends DomainError {

	/**
		@param array<int, string> $groups chat_id => title
	*/
	public function __construct(public array $groups, string $message = 'В какой группе? Выбери кнопкой ниже.') {
		parent::__construct($message);
	}
}
