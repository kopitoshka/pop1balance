<?php

declare(strict_types=1);

// Telegram webhook entry point.
// 1. Verify X-Telegram-Bot-Api-Secret-Token.  2. Answer 200 right away.  3. Process the update.
// Every rejected request is logged with its reason (the secret itself is never logged).

use P1Bot\App\WebhookGate;
use P1Bot\Bootstrap;
use P1Bot\Support\Logger;
use P1Bot\Telegram\Update;

require dirname(__DIR__) . '/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';
$logger = Logger::fromConfig($config);

$remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '?');
[$status, $reason, $payload] = WebhookGate::check(
	(string) ($_SERVER['REQUEST_METHOD'] ?? ''),
	isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? (string) $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] : null,
	(string) $config['telegram']['webhook_secret'],
	(string) file_get_contents('php://input'),
);
if ($payload === null) {
	$line = sprintf('webhook rejected %d: %s (from %s)', $status, $reason, $remote);
	if ($status === 405) {
		$logger->info($line);
	} else {
		$logger->error($line);
	}
	http_response_code($status);
	exit;
}
$logger->debug('webhook raw update', ['json' => $payload]);

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
	$app = Bootstrap::fromConfig($config, $logger);
	$app->ensureOwner();
	$app->router->handle(Update::fromArray($payload));
} catch (Throwable $e) {
	$logger->exception($e, 'update ' . ($payload['update_id'] ?? '?'));
}
