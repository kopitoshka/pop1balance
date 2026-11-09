<?php

declare(strict_types=1);

namespace P1Bot\Telegram;

use P1Bot\Http\HttpClient;
use P1Bot\Support\Logger;
use P1Bot\Support\Redact;
use Throwable;

/** Bot API over HTTPS (JSON bodies). Messages are sent with parse_mode=HTML and no link previews. */
final class BotApi implements TelegramApi
{
    private const BASE = 'https://api.telegram.org/bot';

    public function __construct(
        private HttpClient $http,
        private string $token,
        private int $timeout = 15,
        private ?Logger $logger = null,
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

    /** @return array<string, mixed> */
    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo', []);
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
        try {
            $response = $this->http->postJson(self::BASE . $this->token . '/' . $method, $params, [], $this->timeout);
        } catch (Throwable $e) {
            // The URL contains the token: never log it, and scrub it from the message just in case.
            $this->logger?->error(sprintf('telegram %s failed: %s', $method, Redact::text($e->getMessage(), [$this->token])), $this->target($params));
            throw $e;
        }
        $data = $response->json();
        if (($data['ok'] ?? false) !== true) {
            $description = (string) ($data['description'] ?? 'unknown error');
            $code = (int) ($data['error_code'] ?? $response->status);
            $line = sprintf('telegram %s ok=false: %d %s', $method, $code, Redact::text($description, [$this->token]));
            if (stripos($description, 'message is not modified') !== false) {
                $this->logger?->debug($line, $this->target($params));
            } else {
                $this->logger?->error($line, $this->target($params));
            }
            throw new TelegramError($method, $description, $code);
        }
        $result = $data['result'] ?? [];
        return is_array($result) ? $result : ['value' => $result];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function target(array $params): array
    {
        $target = [];
        foreach (['chat_id', 'message_id'] as $key) {
            if (isset($params[$key])) {
                $target[$key] = $params[$key];
            }
        }
        if (isset($params['text']) && is_string($params['text'])) {
            $target['text'] = mb_substr($params['text'], 0, 100);
        }
        return $target;
    }
}
