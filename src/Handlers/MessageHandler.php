<?php

declare(strict_types=1);

namespace P1Bot\Handlers;

use P1Bot\Ai\Assistant;
use P1Bot\Support\Logger;
use P1Bot\Telegram\BotIdentity;
use P1Bot\Telegram\TelegramApi;
use P1Bot\View\Html;
use P1Bot\View\Texts;
use Throwable;

/** Free-text messages addressed to the bot: AI when configured, otherwise a short help. */
final class MessageHandler
{
    public function __construct(
        private TelegramApi $api,
        private BotIdentity $bot,
        private ?Assistant $assistant,
        private Logger $logger,
    ) {
    }

    public function handle(Context $ctx): void
    {
        $chatId = $ctx->chatId();
        $replyTo = $ctx->message->messageId;

        if ($this->assistant === null) {
            $this->api->sendMessage($chatId, Texts::fallback($this->bot->username), $replyTo);
            return;
        }

        try {
            $reply = $this->assistant->reply($ctx);
        } catch (Throwable $e) {
            $this->logger->exception($e, 'assistant');
            $this->api->sendMessage($chatId, Texts::somethingWentWrong(), $replyTo);
            return;
        }

        $messageId = $this->api->sendMessage($chatId, Html::e($reply['text']), $replyTo);
        ($reply['remember'])($messageId);
    }
}
