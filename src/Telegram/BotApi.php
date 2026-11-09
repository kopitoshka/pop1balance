<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

use P1Bot\Http\HttpClient;

/** Bot API over HTTPS (JSON bodies). Messages are sent with parse_mode=HTML and no link previews. */
final class BotApi implements TelegramApi
{
    private const BASE = 'https://api.telegram.org/bot';

    public function __construct(
        private HttpClient $http,
        private string $token,
        private int $timeout = 15,
    ) {
    }

    /** Bot user id is the numeric part of the token before ':'. */
    public static function botIdFromToken(string $token): int
    {
        $id = strstr($token, ':', true);
        return $id !== false && ctype_digit($id) ? (int) $id : 0;
    }

    public function getMe(): array
    {
        return $this->call('getMe', []);
    }

    public function sendMessage(int $chatId, string $text, ?int $replyToMessageId = null, array $options = []): int
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ];
        if ($replyToMessageId !== null) {
            $params['reply_parameters'] = ['message_id' => $replyToMessageId, 'allow_sending_without_reply' => true];
        }
        $result = $this->call('sendMessage', $options + $params);
        return (int) ($result['message_id'] ?? 0);
    }

    public function editMessageText(int $chatId, int $messageId, string $text, array $options = []): void
    {
        $this->call('editMessageText', $options + [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ]);
    }

    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $showAlert = false): void
    {
        $params = ['callback_query_id' => $callbackQueryId];
        if ($text !== '') {
            $params['text'] = $text;
            $params['show_alert'] = $showAlert;
        }
        $this->call('answerCallbackQuery', $params);
    }

    public function getChatAdministrators(int $chatId): array
    {
        return array_values($this->call('getChatAdministrators', ['chat_id' => $chatId]));
    }

    public function setWebhook(string $url, string $secretToken, array $allowedUpdates): void
    {
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => $allowedUpdates,
            'drop_pending_updates' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function call(string $method, array $params): array
    {
        $response = $this->http->postJson(self::BASE . $this->token . '/' . $method, $params, [], $this->timeout);
        $data = $response->json();
        if (($data['ok'] ?? false) !== true) {
            throw new TelegramError($method, (string) ($data['description'] ?? 'unknown error'), (int) ($data['error_code'] ?? $response->status));
        }
        $result = $data['result'] ?? [];
        return is_array($result) ? $result : ['value' => $result];
    }
}
