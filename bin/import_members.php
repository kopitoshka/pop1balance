<?php

declare(strict_types=1);

// Import current group members from a Telegram Desktop JSON export.
// Usage:
//   php bin/import_members.php --list-groups
//   php bin/import_members.php <path/result.json> [<chat_id>] [--dry-run]
// Does not message anyone. Never creates placeholders. Idempotent.
// Large exports are streamed (no full json_decode).

use P1Bot\App\ChatExportParser;
use P1Bot\App\MembersImporter;
use P1Bot\Http\CurlHttpClient;
use P1Bot\Repository\ChatRepository;
use P1Bot\Repository\PlayerRepository;
use P1Bot\Support\Database;
use P1Bot\Support\SystemClock;
use P1Bot\Telegram\BotApi;
use P1Bot\Telegram\TelegramError;

require dirname(__DIR__) . '/autoload.php';
$config = require dirname(__DIR__) . '/config/config.php';

$db = Database::fromConfig($config['db']);
$chats = new ChatRepository($db);

if (in_array('--list-groups', $argv, true)) {
	printActiveGroups($chats);
	exit(0);
}

$dry_run = in_array('--dry-run', $argv, true);
$args = array_values(array_filter(
	array_slice($argv, 1),
	static fn (string $a): bool => !in_array($a, ['--dry-run', '--list-groups'], true),
));

if ($args === []) {
	fwrite(STDERR, "Usage:\n");
	fwrite(STDERR, "  php bin/import_members.php --list-groups\n");
	fwrite(STDERR, "  php bin/import_members.php <path/result.json> [<chat_id>] [--dry-run]\n");
	exit(1);
}

$path = $args[0];
$chat_id_arg = isset($args[1]) ? (int) $args[1] : null;

if (!is_file($path) || !is_readable($path)) {
	fwrite(STDERR, "Файл не найден или не читается: {$path}\n");
	exit(1);
}

$parser = new ChatExportParser();
try {
	$parsed = $parser->parseFile($path);
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}

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

$api = new BotApi(new CurlHttpClient(), (string) $config['telegram']['token']);
$importer = new MembersImporter(
	$api,
	new PlayerRepository($db),
	$chats,
	new SystemClock(),
);

try {
	$stats = $importer->import($chat_id, $parsed['user_ids'], $dry_run);
} catch (Throwable $e) {
	fwrite(STDERR, "Ошибка getChat/импорта: " . $e->getMessage() . "\n");
	$not_found = $e instanceof TelegramError
		&& (stripos($e->getMessage(), 'chat not found') !== false || $e->getCode() === 400);
	if ($not_found || stripos($e->getMessage(), 'chat not found') !== false) {
		fwrite(STDERR, "Похоже, бота нет в этом чате, либо chat_id другой.\n");
		fwrite(STDERR, "Добавь бота в группу «{$parsed['name']}» или передай верный chat_id.\n");
		fwrite(STDERR, "Активные группы, где бот уже есть (из БД):\n");
		printActiveGroups($chats, STDERR);
		fwrite(STDERR, "Список ещё раз: php bin/import_members.php --list-groups\n");
	} else {
		fwrite(STDERR, "Проверьте, что бот в чате и chat_id верный ({$chat_id}).\n");
	}
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

/**
	@param resource $out
*/
function printActiveGroups(ChatRepository $chats, $out = null): void {
	$out = $out ?? STDOUT;
	$groups = $chats->listActiveGroups();
	if ($groups === []) {
		fwrite($out, "(пусто — бот ещё ни в одной группе не отмечен)\n");
		return;
	}
	foreach ($groups as $id => $title) {
		fwrite($out, sprintf("  %s  %d\n", $title !== '' ? $title : '?', $id));
	}
}
