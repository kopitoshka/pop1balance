<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Texts;

/** Free-text messages addressed to the bot. */
final class MessageHandler
{
    public function __construct(
        private TelegramApi $api,
        private BotIdentity $bot,
    ) {
    }

    public function handle(Context $ctx): void
    {
        $this->api->sendMessage($ctx->chatId(), Texts::fallback($this->bot->username), $ctx->message->messageId);
    }
}
