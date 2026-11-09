<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

final class TgUser
{
    public function __construct(
        public int $id,
        public bool $isBot,
        public ?string $username,
        public string $firstName,
        public string $lastName = '',
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $username = isset($raw['username']) && $raw['username'] !== '' ? (string) $raw['username'] : null;
        return new self(
            (int) ($raw['id'] ?? 0),
            (bool) ($raw['is_bot'] ?? false),
            $username,
            (string) ($raw['first_name'] ?? ''),
            (string) ($raw['last_name'] ?? ''),
        );
    }

    public function displayName(): string
    {
        $name = trim($this->firstName . ' ' . $this->lastName);
        return $name !== '' ? $name : ($this->username ?? 'игрок');
    }
}
