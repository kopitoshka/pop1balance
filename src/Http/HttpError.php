<?php

declare(strict_types=1);

namespace P1Bot\Http;

use RuntimeException;

/**
	Transport-level failure (DNS, timeout, invalid JSON).
*/
class HttpError extends RuntimeException {
}
