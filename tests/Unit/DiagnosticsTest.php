<?php

declare(strict_types=1);

namespace P1Bot\Tests\Unit;

use P1Bot\App\Diagnostics;
use P1Bot\Support\Database;
use P1Bot\Support\Migrations;
use P1Bot\Telegram\BotApi;
use P1Bot\Tests\Support\FakeHttpClient;
use PDO;
use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase
{
    private const TOKEN = '777:TOKENtokenTOKENtokenTOKEN';

    private function migrationsDir(): string
    {
        return dirname(__DIR__, 2) . '/migrations';
    }

    private function config(): array
    {
        return [
            'telegram' => ['token' => self::TOKEN, 'username' => 'pop1_bot', 'webhook_secret' => 'whsecret', 'webhook_url' => 'https://bot.example.org/p1/webhook.php'],
            'openai' => ['api_key' => 'sk-abcdefghijklmnop'],
            'db' => ['password' => 'dbpass'],
            'log_level' => 'debug',
            'paths' => ['logs' => sys_get_temp_dir() . '/p1bot-diag-' . bin2hex(random_bytes(3))],
        ];
    }

    public function testReportFlagsProblemsWithoutSecrets(): void
    {
        $http = new FakeHttpClient();
        $http->onTelegram('getWebhookInfo', static fn (): array => ['ok' => true, 'result' => [
            'url' => 'https://other.example.org/webhook.php',
            'pending_update_count' => 7,
            'last_error_date' => 1762700000,
            'last_error_message' => 'Wrong response from the webhook: 403 Forbidden',
            'allowed_updates' => ['message', 'callback_query', 'my_chat_member'],
        ]]);
        $http->onTelegram('getMe', static fn (): array => ['ok' => true, 'result' => ['username' => 'pop1balance_bot', 'can_read_all_group_messages' => false]]);
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE schema_migrations (name TEXT PRIMARY KEY)');
        $pdo->exec("INSERT INTO schema_migrations (name) VALUES ('001_init.sql'), ('002_admins_sync.sql'), ('003_tournament_card.sql'), ('004_tournament_card_shown_to.sql')");

        $diag = new Diagnostics($this->config(), new BotApi($http, self::TOKEN), static fn (): Database => new Database($pdo), $this->migrationsDir());
        $out = implode("\n", $diag->lines());

        self::assertStringContainsString('url: https://***/webhook.php', $out);
        self::assertStringContainsString('differs from WEBHOOK_URL', $out);
        self::assertStringContainsString('pending_update_count: 7', $out);
        self::assertStringContainsString('MSK — Wrong response from the webhook: 403 Forbidden', $out);
        self::assertStringContainsString('!! missing edited_message', $out);
        self::assertStringContainsString('getMe username: @pop1balance_bot', $out);
        self::assertStringContainsString('match: NO', $out);
        self::assertStringContainsString('connection: ok', $out);
        self::assertStringContainsString('pending migrations: 005_edited_replies.sql', $out);
        self::assertStringContainsString('LOG_LEVEL: debug', $out);
        self::assertStringContainsString('OPENAI_API_KEY set: yes', $out);
        foreach (['other.example.org', 'bot.example.org', 'whsecret', 'TOKENtoken', 'sk-abcdef', 'dbpass'] as $secret) {
            self::assertStringNotContainsString($secret, $out);
        }
    }

    public function testDbFailureAndNoToken(): void
    {
        $config = $this->config();
        $config['openai']['api_key'] = '';
        $diag = new Diagnostics($config, null, static function (): Database {
            throw new \PDOException('Access denied for user (using password: dbpass)');
        }, $this->migrationsDir());
        $out = implode("\n", $diag->lines());
        self::assertStringContainsString('TELEGRAM_BOT_TOKEN is empty', $out);
        self::assertStringContainsString('connection: FAILED', $out);
        self::assertStringNotContainsString('dbpass', $out);
        self::assertStringContainsString('OPENAI_API_KEY set: no', $out);
    }

    public function testMigrationsWithoutTrackingTable(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $all = (new Migrations($pdo, $this->migrationsDir()))->all();
        self::assertSame($all, (new Migrations($pdo, $this->migrationsDir()))->pending());

        $pdo->exec('CREATE TABLE players (id INTEGER)');
        $migrations = new Migrations($pdo, $this->migrationsDir());
        self::assertFalse($migrations->hasTrackingTable());
        self::assertNotContains('001_init.sql', $migrations->pending());
        self::assertContains('005_edited_replies.sql', $migrations->pending());
    }

    public function testMaskUrl(): void
    {
        self::assertSame('(not set)', Diagnostics::maskUrl(''));
        self::assertSame('https://***:8443/a/webhook.php', Diagnostics::maskUrl('https://h.example:8443/a/webhook.php'));
    }
}
