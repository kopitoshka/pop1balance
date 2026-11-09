<?php

declare(strict_types=1);

// Telegram webhook entry point.
// 1. Verify X-Telegram-Bot-Api-Secret-Token.  2. Answer 200 right away.  3. Process the update.

use P1Bot\Bootstrap;
use P1Bot\Support\Logger;
use P1Bot\Telegram\Update;

require dirname(__DIR__) . '/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';
$logger = new Logger($config['paths']['logs']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$expected = (string) $config['telegram']['webhook_secret'];
$given = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
if ($expected === '' || !hash_equals($expected, $given)) {
    http_response_code(403);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

// Respond fast so Telegram does not retry; keep working after the connection is closed.
ignore_user_abort(true);
set_time_limit(90);
http_response_code(200);
header('Content-Type: application/json');
header('Content-Length: 2');
echo '{}';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    flush();
}

try {
    $app = Bootstrap::fromConfig($config);
    $app->ensureOwner();
    $app->router->handle(Update::fromArray($payload));
} catch (Throwable $e) {
    $logger->exception($e, 'update ' . ($payload['update_id'] ?? '?'));
}
