<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

/**
 * Parsed Telegram update. Stub: only the fields the domain needs are planned.
 * The bot reacts only to @mentions and replies to its own messages (privacy mode).
 */
final class Update
{
    /** @param array<string, mixed> $raw */
    public function __construct(private array $raw)
    {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self($raw);
    }

    /** @return array<string, mixed> */
    public function raw(): array
    {
        return $this->raw;
    }

    /** TODO: detect "@bot_username" mention or reply to a bot message. */
    public function isAddressedToBot(string $botUsername, int $botUserId): bool
    {
        throw new \LogicException('Not implemented yet');
    }
}
