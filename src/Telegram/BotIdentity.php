<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

/** Who the bot is and who owns it. */
final class BotIdentity
{
    public function __construct(
        public int $botId,
        public string $username,
        public int $ownerTgId,
    ) {
    }
}
