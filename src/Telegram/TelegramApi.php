<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

/** Telegram Bot API surface the bot needs. */
interface TelegramApi
{
    /** @return array<string, mixed> bot User object */
    public function getMe(): array;

    /**
     * Sends an HTML message. When $replyToMessageId is set the message is a reply
     * (sent even if the original was deleted).
     *
     * @param array<string, mixed> $options extra Bot API fields, e.g. reply_markup
     * @return int message_id of the sent message
     */
    public function sendMessage(int $chatId, string $text, ?int $replyToMessageId = null, array $options = []): int;

    /** @param array<string, mixed> $options */
    public function editMessageText(int $chatId, int $messageId, string $text, array $options = []): void;

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): void;

    /** @return list<array<string, mixed>> ChatMember objects */
    public function getChatAdministrators(int $chatId): array;

    /** @param list<string> $allowedUpdates */
    public function setWebhook(string $url, string $secretToken, array $allowedUpdates): void;
}
