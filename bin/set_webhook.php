<?php

declare(strict_types=1);

// Registers the webhook with Telegram: php bin/set_webhook.php
// Uses TELEGRAM_BOT_TOKEN, WEBHOOK_URL and TELEGRAM_WEBHOOK_SECRET from .env.

use P1Bot\Http\CurlHttpClient;
use P1Bot\Telegram\BotApi;
use P1Bot\Telegram\Update;

require dirname(__DIR__) . '/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';

$token = (string) $config['telegram']['token'];
$url = (string) $config['telegram']['webhook_url'];
$secret = (string) $config['telegram']['webhook_secret'];

if ($token === '' || $url === '' || $secret === '') {
	fwrite(STDERR, "Fill TELEGRAM_BOT_TOKEN, WEBHOOK_URL and TELEGRAM_WEBHOOK_SECRET in .env first.\n");
	exit(1);
}
if (!preg_match('/^[A-Za-z0-9_-]{1,256}$/', $secret)) {
	fwrite(STDERR, "TELEGRAM_WEBHOOK_SECRET may contain only A-Z, a-z, 0-9, _ and - (1-256 chars).\n");
	exit(1);
}

$api = new BotApi(new CurlHttpClient(), $token);
try {
	$me = $api->getMe();
	fwrite(STDOUT, sprintf("Bot: @%s (id %d)\n", $me['username'] ?? '?', $me['id'] ?? 0));
	if (strcasecmp((string) ($me['username'] ?? ''), (string) $config['telegram']['username']) !== 0) {
		fwrite(STDOUT, "Warning: TELEGRAM_BOT_USERNAME in .env differs from the real bot username.\n");
	}
	if (($me['can_read_all_group_messages'] ?? false) === true) {
		fwrite(STDOUT, "Warning: privacy mode is OFF. Turn it on in @BotFather (/setprivacy -> Enable).\n");
	}
	$api->setWebhook($url, $secret, Update::ALLOWED_UPDATES);
	fwrite(STDOUT, "Webhook set: $url\n");
	fwrite(STDOUT, "allowed_updates: " . implode(", ", Update::ALLOWED_UPDATES) . "\n");
	$common = [
		['command' => 'start', 'description' => 'Турниры с кнопками записи'],
		['command' => 'tournaments', 'description' => 'Активные турниры'],
		['command' => 'level', 'description' => 'Свой или чужой уровень'],
		['command' => 'levels', 'description' => 'Справка по уровням'],
		['command' => 'help', 'description' => 'Подсказка по командам'],
	];
	// Private: no /start (Telegram has Start); no /history|/info|/adduser in menu.
	$private = [
		['command' => 'tournaments', 'description' => 'Активные турниры'],
		['command' => 'team', 'description' => 'Карточка твоей команды'],
		['command' => 'level', 'description' => 'Свой или чужой уровень'],
		['command' => 'nick', 'description' => 'Свой игровой ник'],
		['command' => 'levels', 'description' => 'Справка по уровням'],
		['command' => 'help', 'description' => 'Подсказка по командам'],
	];
	$api->setMyCommands($common);
	$api->setMyCommands($common, ['type' => 'all_group_chats']);
	$api->setMyCommands($private, ['type' => 'all_private_chats']);
	$owner_tg_id = (int) ($config['owner_tg_id'] ?? 0);
	if ($owner_tg_id > 0) {
		$api->setMyCommands($private, ['type' => 'chat', 'chat_id' => $owner_tg_id]);
		fwrite(STDOUT, "Owner private commands refreshed (chat {$owner_tg_id})\n");
	}
	fwrite(STDOUT, "Bot commands updated (menus without /history|/info|/adduser)\n");
} catch (Throwable $e) {
	fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
	exit(1);
}
