<?php

declare(strict_types=1);

// Telegram webhook entry point. Stub: verifies the secret token and acknowledges the update.
// Routing to MessageHandler / CallbackHandler is implemented in the next step.

require dirname(__DIR__) . '/vendor/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';

$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($config['telegram']['webhook_secret'] === '' || !hash_equals($config['telegram']['webhook_secret'], $secret)) {
    http_response_code(403);
    exit;
}

$update = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($update)) {
    http_response_code(400);
    exit;
}

// TODO: P1Bot\Telegram\Update::fromArray($update) -> handlers.
http_response_code(200);
