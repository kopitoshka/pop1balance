<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

/** Telegram Bot API surface the bot needs. Real cURL implementation comes in the next step. */
interface TelegramApi
{
    /**
     * @param array<string, mixed> $options parse_mode, reply_markup, ...
     * @return int message_id of the sent message
     */
    public function sendMessage(int $chatId, string $text, ?int $replyToMessageId = null, array $options = []): int;

    /** @param array<string, mixed> $options */
    public function editMessageText(int $chatId, int $messageId, string $text, array $options = []): void;

    public function answerCallbackQuery(string $callbackQueryId, string $text = ''): void;

    public function setWebhook(string $url, string $secretToken): void;
}
