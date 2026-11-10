<?php

declare(strict_types=1);

// Mini App API: search and submit. Auth is Telegram initData HMAC, not the webhook secret.

use P1Bot\App\WebAppPicker;
use P1Bot\Bootstrap;
use P1Bot\Domain\Exception\DomainError;
use P1Bot\Support\Logger;

require dirname(__DIR__) . '/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';
$logger = Logger::fromConfig($config);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
	http_response_code(204);
	exit;
}

function webapp_read_body(): array {
	$raw = (string) file_get_contents('php://input');
	if ($raw === '') {
		return [];
	}
	$json = json_decode($raw, true);
	return is_array($json) ? $json : [];
}

function webapp_fail(int $status, string $error): void {
	http_response_code($status);
	echo json_encode(['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
	exit;
}

$body = webapp_read_body();
$op = (string) ($body['op'] ?? $_GET['op'] ?? '');
$token = (string) ($body['ctx'] ?? $_GET['ctx'] ?? '');
$init = (string) ($body['init_data'] ?? $_GET['init_data'] ?? '');
if ($init === '' && isset($_SERVER['HTTP_X_TELEGRAM_INIT_DATA'])) {
	$init = (string) $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'];
}

if ($op === '' || $token === '') {
	webapp_fail(400, 'Нет контекста подбора.');
}

try {
	$app = Bootstrap::fromConfig($config, $logger);
	$app->ensureOwner();
	$picker = $app->webapp_picker;
	if ($op === 'bootstrap') {
		$result = $picker->bootstrap($init, $token);
	} elseif ($op === 'search') {
		$query = (string) ($body['q'] ?? $_GET['q'] ?? '');
		$result = $picker->search($init, $token, $query);
	} elseif ($op === 'submit') {
		$ids = $body['player_ids'] ?? [];
		if (!is_array($ids)) {
			webapp_fail(400, 'Никого не выбрали.');
		}
		$level = null;
		if (isset($body['level']) && $body['level'] !== null && $body['level'] !== '') {
			$level = (int) $body['level'];
		}
		$result = $picker->submit($init, $token, $ids, $level);
	} else {
		webapp_fail(400, 'Неизвестная команда.');
	}
	echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (DomainError $e) {
	$logger->info('webapp: ' . $e->getMessage());
	webapp_fail(400, $e->getMessage());
} catch (Throwable $e) {
	$logger->exception($e, 'webapp_api');
	webapp_fail(500, 'Не получилось обработать запрос. Попробуй ещё раз.');
}
