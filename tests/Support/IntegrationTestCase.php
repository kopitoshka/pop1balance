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
    protected Bootstrap $app;
    protected Player $owner;
    protected Player $admin;

    private int $nextTgId = 2000;

    protected function setUp(): void
    {
        $this->db = self::freshDatabase();
        $this->clock = new FrozenClock();
        $this->app = new Bootstrap($this->db, $this->clock, [
            'owner_tg_id' => self::OWNER_TG_ID,
            'tournament' => ['default_max_players' => 24, 'anyone_can_create' => false],
        ]);

        (new ChatRepository($this->db))->ensure(self::CHAT_ID, 'P1 турниры');
        $this->owner = $this->app->players->ensureByTelegram(self::OWNER_TG_ID, 'andrey', 'Андрей', $this->clock->now());
        $this->app->balances->ensureDefault($this->owner->id);
        $this->admin = $this->player('admin2');
        (new AdminRepository($this->db))->add(self::CHAT_ID, $this->admin->id);
    }

    /**
     * SQLite in-memory by default. Set P1BOT_TEST_MYSQL_DSN (+ _USER/_PASSWORD) to run the same
     * tests against an empty MySQL/MariaDB database using the real migrations/001_init.sql.
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
        $pdo->exec((string) file_get_contents(dirname(__DIR__, 2) . '/migrations/001_init.sql'));
        return new Database($pdo);
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

    protected function openTournament(int $teamSize = 3, ?string $reference = '3,3,4', ?int $maxPlayers = null): Tournament
    {
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
