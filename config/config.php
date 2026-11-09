<?php

declare(strict_types=1);

use P1Bot\Support\Env;

/**
 * Application configuration. Values come from the project .env file
 * (see .env.example); real environment variables take precedence.
 */

$root = dirname(__DIR__);
Env::load($root . '/.env');

return [
    'telegram' => [
        'token'          => Env::get('TELEGRAM_BOT_TOKEN', ''),
        'username'       => Env::get('TELEGRAM_BOT_USERNAME', ''),
        'webhook_secret' => Env::get('TELEGRAM_WEBHOOK_SECRET', ''),
        'webhook_url'    => Env::get('WEBHOOK_URL', ''),
    ],
    'owner_tg_id' => Env::int('BOT_OWNER_TG_ID', 0),
    'openai' => [
        'api_key'          => Env::get('OPENAI_API_KEY', ''),
        'model'            => Env::get('OPENAI_MODEL', 'gpt-6-luna'),
        'reasoning_effort' => Env::get('OPENAI_REASONING_EFFORT', 'low'),
    ],
    'db' => [
        'dsn'      => Env::get('DB_DSN', 'mysql:host=127.0.0.1;dbname=p1bot;charset=utf8mb4'),
        'user'     => Env::get('DB_USER', ''),
        'password' => Env::get('DB_PASSWORD', ''),
    ],
    'tournament' => [
        'default_max_players' => Env::int('DEFAULT_MAX_PLAYERS', 24),
        'anyone_can_create'   => Env::bool('ANYONE_CAN_CREATE_TOURNAMENT', false),
    ],
    'paths' => [
        'root'   => $root,
        'logs'   => $root . '/storage/logs',
        'prompt' => __DIR__ . '/prompts/system.md',
    ],
];
