<?php

declare(strict_types=1);

namespace P1Bot\App;

/**
 * Checks an incoming webhook request before anything else is built.
 * Returns [HTTP status, reason for the log, decoded update or null]. Never puts the secret in the reason.
 */
final class WebhookGate
{
    /** @return array{0: int, 1: string, 2: array<string, mixed>|null} */
    public static function check(string $method, ?string $secretHeader, string $expectedSecret, string $body): array
    {
        if ($method !== 'POST') {
            return [405, sprintf('method %s, expected POST', $method !== '' ? $method : '(none)'), null];
        }
        if ($expectedSecret === '') {
            return [403, 'TELEGRAM_WEBHOOK_SECRET is empty in .env', null];
        }
        if ($secretHeader === null) {
            return [403, 'secret header X-Telegram-Bot-Api-Secret-Token is missing', null];
        }
        if (!hash_equals($expectedSecret, $secretHeader)) {
            return [403, 'secret header is wrong (does it match TELEGRAM_WEBHOOK_SECRET? rerun bin/set_webhook.php)', null];
        }
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return [400, sprintf('bad JSON: %s, %d bytes', json_last_error_msg(), strlen($body)), null];
        }
        return [200, 'ok', $payload];
    }
}
