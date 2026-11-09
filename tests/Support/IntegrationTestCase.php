<?php

declare(strict_types=1);

namespace P1Bot\Tests\Support;

use P1Bot\Bootstrap;
use P1Bot\Domain\CreateTournament;
use P1Bot\Domain\Model\Player;
use P1Bot\Domain\Model\Tournament;
use P1Bot\Repository\AdminRepository;
use P1Bot\Repository\ChatRepository;
use P1Bot\Support\Database;
use PDO;
use PHPUnit\Framework\TestCase;

/** Fresh in-memory SQLite DB per test, wired like production. */
abstract class IntegrationTestCase extends TestCase
{
    protected const CHAT_ID = -1001234567890;
    protected const OWNER_TG_ID = 1000;

    protected Database $db;
    protected FrozenClock $clock;
    protected const BOT_ID = 555000;
    protected const BOT_USERNAME = 'pop1_bot';

    protected Bootstrap $app;
    protected FakeHttpClient $http;
    protected Player $owner;
    protected Player $admin;

    private int $nextTgId = 2000;

    protected function setUp(): void
    {
        $this->db = self::freshDatabase();
        $this->clock = new FrozenClock();
        $this->http = new FakeHttpClient();
        $this->app = new Bootstrap($this->db, $this->clock, $this->config(), $this->http, $this->logger());

        (new ChatRepository($this->db))->ensure(self::CHAT_ID, 'P1 турниры');
        $this->owner = $this->app->players->ensureByTelegram(self::OWNER_TG_ID, 'andrey', 'Андрей', $this->clock->now());
        $this->app->balances->ensureDefault($this->owner->id);
        $this->admin = $this->player('admin2');
        (new AdminRepository($this->db))->add(self::CHAT_ID, $this->admin->id);
    }

    /** @return array<string, mixed> */
    /** Override to capture log lines (null = logging off). */
    protected function logger(): ?\P1Bot\Support\Logger
    {
        return null;
    }

    protected function config(): array
    {
        return [
            'owner_tg_id' => self::OWNER_TG_ID,
            'telegram' => ['token' => self::BOT_ID . ':TEST', 'username' => self::BOT_USERNAME],
            'openai' => ['api_key' => '', 'model' => 'gpt-6-luna', 'reasoning_effort' => 'low'],
            'tournament' => ['default_max_players' => 24, 'anyone_can_create' => false],
            'cron' => ['reminder_minutes' => [60, 15], 'finish_after_hours' => 4],
        ];
    }

    /**
     * SQLite in-memory by default. Set P1BOT_TEST_MYSQL_DSN (+ _USER/_PASSWORD) to run the same
     * tests against an empty MySQL/MariaDB database using the real migrations/*.sql.
     */
    private static function freshDatabase(): Database
    {
        $dsn = getenv('P1BOT_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            $db = new Database(new PDO('sqlite::memory:'));
            $db->pdo()->exec((string) file_get_contents(__DIR__ . '/schema.sqlite.sql'));
            return $db;
        }

        $pdo = new PDO($dsn, (string) getenv('P1BOT_TEST_MYSQL_USER'), (string) getenv('P1BOT_TEST_MYSQL_PASSWORD'));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $pdo->exec("DROP TABLE `$table`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $migration) {
            $pdo->exec((string) file_get_contents($migration));
        }
        return new Database($pdo);
    }

    /** Telegram user id of a player created with player(). */
    protected function tgIdOf(Player $player): int
    {
        return (int) $player->tgUserId;
    }

    /**
     * Raw Telegram update with a group message.
     *
     * @param array<string, mixed> $extra merged into the message
     * @return array<string, mixed>
     */
    protected function groupMessage(Player $from, string $text, array $extra = []): array
    {
        static $updateId = 1;
        static $messageId = 1;
        $entities = [];
        if (str_starts_with($text, '/')) {
            $len = strcspn($text, " \n");
            $entities[] = ['type' => 'bot_command', 'offset' => 0, 'length' => mb_strlen(mb_substr($text, 0, $len))];
        }
        if (preg_match_all('/@\w+/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$mention, $byteOffset]) {
                $before = substr($text, 0, $byteOffset);
                $entities[] = [
                    'type' => 'mention',
                    'offset' => intdiv(strlen(mb_convert_encoding($before, 'UTF-16LE', 'UTF-8')), 2),
                    'length' => intdiv(strlen(mb_convert_encoding($mention, 'UTF-16LE', 'UTF-8')), 2),
                ];
            }
        }
        usort($entities, static fn (array $a, array $b): int => $a['offset'] <=> $b['offset']);
        return [
            'update_id' => $updateId++,
            'message' => $extra + [
                'message_id' => $messageId++,
                'chat' => ['id' => self::CHAT_ID, 'type' => 'supergroup', 'title' => 'P1 турниры'],
                'from' => ['id' => $this->tgIdOf($from), 'is_bot' => false, 'first_name' => $from->displayName, 'username' => $from->username],
                'date' => 0,
                'text' => $text,
                'entities' => $entities,
            ],
        ];
    }

    /** @param array<string, mixed> $raw */
    protected function dispatch(array $raw): void
    {
        $this->app->router->handle(\P1Bot\Telegram\Update::fromArray($raw));
    }

    protected function player(string $username): Player
    {
        return $this->app->players->ensureByTelegram($this->nextTgId++, $username, ucfirst($username), $this->clock->now());
    }

    /** Owner sets a level in the default scale. */
    protected function rate(Player $player, int $level): void
    {
        $this->app->balances->setLevel(self::CHAT_ID, $this->owner->id, $player->id, $level);
    }

    /** A null reference creates a legacy row without a level limit (no longer possible via the bot). */
    protected function openTournament(int $teamSize = 3, ?string $reference = '3,3,4', ?int $maxPlayers = null): Tournament
    {
        if ($reference === null) {
            $t = $this->app->tournamentRepo->create([
                'chat_id' => self::CHAT_ID,
                'organizer_id' => $this->owner->id,
                'title' => 'Субботний кубок',
                'starts_at' => \P1Bot\Domain\MoscowTime::parse('2026-11-14 19:00'),
                'team_size' => $teamSize,
                'balance_id' => null,
                'reference_team' => null,
                'max_points' => null,
                'max_players' => $maxPlayers ?? 24,
                'status' => \P1Bot\Domain\TournamentStatus::DRAFT,
            ], $this->clock->now());
            return $this->app->tournaments->openRegistration($this->owner->id, $t->id);
        }
        $t = $this->app->tournaments->create(new CreateTournament(
            self::CHAT_ID,
            $this->owner->id,
            'Субботний кубок',
            '2026-11-14 19:00',
            $teamSize,
            $reference,
            null,
            $maxPlayers,
        ));
        return $this->app->tournaments->openRegistration($this->owner->id, $t->id);
    }
}
