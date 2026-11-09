<?php

declare(strict_types=1);

use P1Bot\Support\Env;

/**
	Application configuration. Values come from the project .env file
	(see .env.example); real environment variables take precedence.
*/

$root = dirname(__DIR__);
Env::load($root . '/.env');

$db_dsn = Env::get('DB_DSN', '');
if ($db_dsn === '') {
	$db_dsn = sprintf(
		'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
		Env::get('DB_HOST', '127.0.0.1'),
		Env::int('DB_PORT', 3306),
		Env::get('DB_NAME', 'p1bot'),
	);
}

$reminders = array_values(array_filter(array_map(
	'intval',
	explode(',', Env::get('REMINDER_MINUTES', '60,15')),
), static function (int $m): bool {
	return $m > 0;
}));

return [
	'telegram' => [
		'token'          => Env::get('TELEGRAM_BOT_TOKEN', ''),
		'username'       => ltrim(Env::get('TELEGRAM_BOT_USERNAME', ''), '@'),
		'webhook_secret' => Env::get('TELEGRAM_WEBHOOK_SECRET', ''),
		'webhook_url'    => Env::get('WEBHOOK_URL', ''),
	],
	'owner_tg_id' => Env::int('BOT_OWNER_TG_ID', 0),
	'openai' => [
		'api_key'          => Env::get('OPENAI_API_KEY', ''),
		'model'            => Env::get('OPENAI_MODEL', 'gpt-6-luna'),
		'reasoning_effort' => Env::get('OPENAI_REASONING_EFFORT', 'low'),
		'base_url'         => Env::get('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
		'timeout'          => Env::int('OPENAI_TIMEOUT', 30),
	],
	'db' => [
		'dsn'      => $db_dsn,
		'user'     => Env::get('DB_USER', ''),
		'password' => Env::get('DB_PASSWORD', ''),
	],
	'tournament' => [
		'default_max_players' => Env::int('DEFAULT_MAX_PLAYERS', 24),
		'anyone_can_create'   => Env::bool('ANYONE_CAN_CREATE_TOURNAMENT', false),
		// Minutes before start when registration auto-closes for players (0 = only manual /close)
		'registration_close_minutes' => Env::int('REGISTRATION_CLOSE_MINUTES', 20),
	],
	'admins_cache_minutes'     => Env::int('ADMINS_CACHE_MINUTES', 10),
	'conversation_ttl_minutes' => Env::int('CONVERSATION_TTL_MINUTES', 30),
	// The bot's name for the AI (she speaks in the feminine gender); empty = no name
	'persona_name' => Env::get('BOT_PERSONA_NAME', ''),
	'cron' => [
		'reminder_minutes'   => $reminders === [] ? [60, 15] : $reminders,
		'finish_after_hours' => Env::int('FINISH_AFTER_HOURS', 2),
		// At the first reminder (60 min) also post the tournament to every other group the bot is in
		'remind_all_groups'  => Env::bool('REMIND_ALL_GROUPS', true),
	],
	// debug | info | error (debug also logs every raw update)
	'log_level' => Env::get('LOG_LEVEL', 'info'),
	// Per-user dialog logs in storage/logs/dialogs (1/0)
	'dialog_log' => Env::bool('DIALOG_LOG', true),
	'paths' => [
		'root'   => $root,
		'logs'   => $root . '/storage/logs',
		'prompt' => __DIR__ . '/prompts/system.md',
	],
];
