# P1 tournament bot (prototype)

Telegram bot for Population: One tournaments. PHP 8.0+, PDO/MySQL, no framework.
This step contains the domain core (levels, balance scales, tournaments, teams, solo players,
computed reserve) with tests. Telegram and OpenAI layers are stubs.

```bash
composer install
cp .env.example .env            # fill in tokens and DB
php bin/migrate.php             # applies migrations/001_init.sql to an empty DB
vendor/bin/phpunit              # tests on SQLite in-memory

# same tests against an empty MySQL/MariaDB database (uses migrations/001_init.sql):
P1BOT_TEST_MYSQL_DSN="mysql:host=127.0.0.1;dbname=p1bot_test;charset=utf8mb4" \
P1BOT_TEST_MYSQL_USER=root P1BOT_TEST_MYSQL_PASSWORD=... vendor/bin/phpunit
```

`migrations/001_init.sql` is the reference schema; `tests/Support/schema.sqlite.sql` mirrors it for tests.
