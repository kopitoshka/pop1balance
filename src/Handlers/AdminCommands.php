<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Texts;

/** Slash commands (fallback without AI). */
final class AdminCommands
{
    public function __construct(
        private TelegramApi $api,
        private BotIdentity $bot,
    ) {
    }

    public function supports(string $command): bool
    {
        return in_array($command, ['help', 'start'], true);
    }

    public function handle(Context $ctx, string $command, string $args): void
    {
        $this->api->sendMessage($ctx->chatId(), Texts::help($this->bot->username, $ctx->isAdmin), $ctx->message->messageId);
    }
}
