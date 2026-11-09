<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

use RuntimeException;

/** Telegram returned ok=false. */
final class TelegramError extends RuntimeException
{
    public function __construct(string $method, string $description, int $code)
    {
        parent::__construct(sprintf('Telegram %s failed: %s', $method, $description), $code);
    }

    public function isNotModified(): bool
    {
        return str_contains($this->getMessage(), 'message is not modified');
    }
}
