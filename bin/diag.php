<?php

declare(strict_types=1);

// Quick health check: php bin/diag.php
// Webhook status, bot username, DB and migrations, logs dir, OpenAI key. Prints no secrets.

use P1Bot\App\Diagnostics;
use P1Bot\Http\CurlHttpClient;
use P1Bot\Support\Database;
use P1Bot\Telegram\BotApi;

require dirname(__DIR__) . '/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';

$token = (string) ($config['telegram']['token'] ?? '');
$diag = new Diagnostics(
    $config,
    $token !== '' ? new BotApi(new CurlHttpClient(), $token) : null,
    static function () use ($config): Database {
        return Database::fromConfig($config['db']);
    },
    dirname(__DIR__) . '/migrations',
);
fwrite(STDOUT, implode("\n", $diag->lines()) . "\n");
