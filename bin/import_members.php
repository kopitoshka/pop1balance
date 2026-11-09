<?php

declare(strict_types=1);

// Import current group members from a Telegram Desktop JSON export.
// Usage: php bin/import_members.php <path/result.json> [<chat_id>] [--dry-run]
// Does not message anyone. Never creates placeholders. Idempotent.

use P1Bot\App\ChatExportParser;
use P1Bot\App\MembersImporter;
use P1Bot\Http\CurlHttpClient;
use P1Bot\Repository\ChatRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Database;
use P1Bot\Support\SystemClock;
use P1Bot\Telegram\BotApi;

require dirname(__DIR__) . '/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';

$dry_run = in_array('--dry-run', $argv, true);
$args = array_values(array_filter(
	array_slice($argv, 1),
	static fn (string $a): bool => $a !== '--dry-run',
));

if ($args === []) {
	fwrite(STDERR, "Usage: php bin/import_members.php <path/result.json> [<chat_id>] [--dry-run]\n");
	exit(1);
}

$path = $args[0];
$chat_id_arg = isset($args[1]) ? (int) $args[1] : null;

if (!is_file($path) || !is_readable($path)) {
	fwrite(STDERR, "Файл не найден или не читается: {$path}\n");
	exit(1);
}

$size = (int) filesize($path);
if ($size > 40 * 1024 * 1024) {
	fwrite(STDERR, sprintf(
		"Внимание: файл %.1f МБ — json_decode может съесть много памяти.\n",
		$size / (1024 * 1024),
	));
	@ini_set('memory_limit', '512M');
}

$raw = file_get_contents($path);
if ($raw === false) {
	fwrite(STDERR, "Не удалось прочитать файл.\n");
	exit(1);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
	fwrite(STDERR, "Невалидный JSON: " . json_last_error_msg() . "\n");
	exit(1);
}
unset($raw);

$parser = new ChatExportParser();
try {
	$parsed = $parser->parse($data);
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
unset($data);

$chat_id = $chat_id_arg ?? $parsed['chat_id'];
fwrite(STDOUT, sprintf(
	"Экспорт: «%s» type=%s export_id=%d → chat_id=%d\nУникальных user id: %d%s\n",
	$parsed['name'] !== '' ? $parsed['name'] : '?',
	$parsed['type'] !== '' ? $parsed['type'] : '?',
	$parsed['export_id'],
	$chat_id,
	count($parsed['user_ids']),
	$dry_run ? ' [dry-run]' : '',
));

$db = Database::fromConfig($config['db']);
$api = new BotApi(new CurlHttpClient(), (string) $config['telegram']['token']);
$importer = new MembersImporter(
	$api,
	new PlayerRepository($db),
	new ChatRepository($db),
	new SystemClock(),
);

try {
	$stats = $importer->import($chat_id, $parsed['user_ids'], $dry_run);
} catch (Throwable $e) {
	fwrite(STDERR, "Ошибка getChat/импорта: " . $e->getMessage() . "\n");
	fwrite(STDERR, "Проверьте, что бот в чате и chat_id верный ({$chat_id}).\n");
	exit(1);
}

fwrite(STDOUT, sprintf(
	"Чат: %s (%d)\n" .
	"Найдено id: %d\n" .
	"Новых: %d\n" .
	"Обновлено: %d\n" .
	"Слито с placeholder: %d\n" .
	"left/kicked: %d\n" .
	"Ботов пропущено: %d\n" .
	"Ошибок: %d\n",
	$stats['chat_title'],
	$stats['chat_id'],
	$stats['total_ids'],
	$stats['imported_new'],
	$stats['updated_existing'],
	$stats['merged_placeholders'],
	$stats['left_or_kicked'],
	$stats['bots_skipped'],
	$stats['errors'],
));
foreach ($stats['error_samples'] as $sample) {
	fwrite(STDOUT, "  · {$sample}\n");
}
exit($stats['errors'] > 0 ? 2 : 0);
