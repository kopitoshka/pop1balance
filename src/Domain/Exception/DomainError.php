<?php

declare(strict_types=1);

namespace P1Bot\Domain\Exception;

use RuntimeException;

/**
	Base class for business-rule violations. The message is user-facing (Russian)
	and may be shown in the chat as is.
*/
class DomainError extends RuntimeException {
}
