# Pop1 tournament bot

Telegram bot for Population: One tournaments: tournament registration, teams (2–4 players)
with a captain, solo players, level balance (6 levels, per-admin rating scales), player limit
with computed reserve, announcements in the group, reminders before start. Players talk to the
bot in plain Russian (OpenAI, model `gpt-6-luna`); admins also have slash commands.

PHP 8.0+ (tested on 8.0 and 8.3), MySQL 8 / MariaDB 10.5+, no framework.

## How it works

- Webhook: `public/webhook.php`. In groups the bot reacts only to slash commands, @mentions of the bot
  and replies to its messages (privacy mode ON). Private chats are ignored for now.
- Free text goes to the AI (`src/Ai`): the model calls functions, PHP executes them through the
  domain services (`src/Domain`) and checks all permissions itself. Replies to the bot continue
  the dialog for `CONVERSATION_TTL_MINUTES`.
- Without `OPENAI_API_KEY` mentions get a short help; slash commands always work.
- Cron (`bin/cron.php`, every minute): reminders, `registration → running → finished`, cleanup.

## Deployment: AlmaLinux 8 + PHP-FPM 8.0 (Remi)

### 1. Packages

```bash
sudo dnf install -y epel-release https://rpms.remirepo.net/enterprise/remi-release-8.rpm
sudo dnf module reset -y php
sudo dnf module enable -y php:remi-8.0
sudo dnf install -y php php-fpm php-cli php-pdo php-mysqlnd php-mbstring php-json php-xml git unzip
php -v        # PHP 8.0.x; curl is built into php-common on Remi

# Composer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer && rm composer-setup.php

# MariaDB (skip if you already have MySQL/MariaDB)
sudo dnf module enable -y mariadb:10.11 && sudo dnf install -y mariadb-server
sudo systemctl enable --now mariadb php-fpm
```

### 2. Code

```bash
sudo mkdir -p /var/www/pop1bot && sudo chown $USER /var/www/pop1bot
git clone https://github.com/kopitoshka/pop1balance.git /var/www/pop1bot
cd /var/www/pop1bot
# composer is not needed in production: autoload.php loads src/ directly
cp .env.example .env && chmod 640 .env     # then fill it in, see below
sudo chown -R apache:apache storage        # or nginx:nginx — the php-fpm user must write logs
```

Updating later: `git pull && php bin/migrate.php`. (e.g. `003_tournament_card.sql` adds `tournaments.card_msg_id`).

### 3. Database

```sql
CREATE DATABASE p1bot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'p1bot'@'localhost' IDENTIFIED BY 'strong-password';
GRANT ALL PRIVILEGES ON p1bot.* TO 'p1bot'@'localhost';
```

```bash
php bin/migrate.php      # applies migrations/*.sql, remembers what was applied
```

### 4. Web server (subdomain, document root = `public/`)

Only `public/` must be reachable from the web: `.env`, `config/`, `storage/` stay outside.

nginx:

```nginx
server {
    listen 443 ssl http2;
    server_name pop1.example.com;
    root /var/www/pop1bot/public;

    ssl_certificate     /etc/letsencrypt/live/pop1.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/pop1.example.com/privkey.pem;

    location = /webhook.php {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/webhook.php;
        include fastcgi_params;
    }
    location / { return 404; }
}
```

Apache (`/etc/httpd/conf.d/pop1bot.conf`, php-fpm via the default Remi handler):

```apache
<VirtualHost *:443>
    ServerName pop1.example.com
    DocumentRoot /var/www/pop1bot/public
    <Directory /var/www/pop1bot/public>
        Require all granted
        AllowOverride None
    </Directory>
    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/pop1.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/pop1.example.com/privkey.pem
</VirtualHost>
```

Telegram needs HTTPS with a valid certificate (e.g. `certbot --nginx -d pop1.example.com`).
If SELinux is enforcing: `sudo chcon -R -t httpd_sys_rw_content_t /var/www/pop1bot/storage`
and `sudo setsebool -P httpd_can_network_connect 1` (the bot calls Telegram and OpenAI).

### 5. `.env`

| Key | What |
|---|---|
| `TELEGRAM_BOT_TOKEN` | token from @BotFather |
| `TELEGRAM_BOT_USERNAME` | bot username without `@` |
| `TELEGRAM_WEBHOOK_SECRET` | random string, only `A-Z a-z 0-9 _ -`, e.g. `openssl rand -hex 32` |
| `WEBHOOK_URL` | `https://<subdomain>/webhook.php` |
| `BOT_OWNER_TG_ID` | your numeric Telegram id (ask @userinfobot); owner = admin everywhere, owns the default balance |
| `OPENAI_API_KEY` | OpenAI key; empty = AI off |
| `OPENAI_MODEL` | default `gpt-6-luna` |
| `OPENAI_REASONING_EFFORT` | default `low` |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | database (or `DB_DSN` with a full PDO DSN) |

Optional: `DEFAULT_MAX_PLAYERS` (24), `ANYONE_CAN_CREATE_TOURNAMENT` (0), `ADMINS_CACHE_MINUTES` (10),
`CONVERSATION_TTL_MINUTES` (30), `REMINDER_MINUTES` (`60,15`), `FINISH_AFTER_HOURS` (4), `OPENAI_TIMEOUT` (30).

### 6. BotFather

1. `/newbot` → name and username → copy the token into `.env`.
2. `/setprivacy` → choose the bot → **Enable** (privacy mode ON: the bot sees only commands,
   mentions and replies to itself).
3. `/setjoingroups` → **Enable**.
4. Optional `/setcommands`:
   ```
   tournaments - Активные турниры
   info - Состав турнира
   help - Подсказка
   ```
5. Add the bot to the group. Making it a group admin is not required (admins are read with
   getChatAdministrators); if you do make it admin, it will receive all messages, but it still
   answers only mentions, replies and commands.

### 7. Webhook

```bash
php bin/set_webhook.php
# Bot: @pop1_tournament_bot (id ...)
# Webhook set: https://pop1.example.com/webhook.php
```

The script warns if privacy mode is off or `TELEGRAM_BOT_USERNAME` differs from the real one.
Check: `curl https://api.telegram.org/bot<TOKEN>/getWebhookInfo`.

### 8. Cron

```bash
crontab -e -u apache     # same user as php-fpm
* * * * * /usr/bin/php /var/www/pop1bot/bin/cron.php >> /var/www/pop1bot/storage/logs/cron.log 2>&1
```

### Logs

`storage/logs/bot-YYYY-MM-DD.log` (errors and AI tool calls), `storage/logs/cron.log`.

## Usage

Players (in the group, mention the bot or reply to it):
- «@bot какие турниры скоро?», «@bot запиши команду Wolves: я, @vasya, @petya»,
  «@bot хочу участвовать соло», «@bot замени @vasya на @kolya», «@bot где я записан?»
- Buttons under the announcement: «Хочу участвовать» (solo), «Передумал».

Admins:
- `/new 23.11 20:00 | Кубок | 3 | средний, средний, средний+ | 24` — date/time MSK | title | team size |
  reference team (required, one level per player; digits `3,3,4` work too) | player limit (default 24).
  Creates a **draft** and shows its card ending with «Публикуем или что-то поправить?» and the buttons
  «✅ Публикуем» / «✏️ Поправить».
- `/edit [id] время 23.11 21:00` (fields: название, время, размер, эталон, лимит, баланс N). Editing a draft shows a fresh card.
- `/open [id]` — explicitly open registration and post the announcement (also reopens a closed one); `/close [id]`; `/cancel [id]`
- `/level @user 4 [balance]`, `/balance [id]`
- `/tournaments`, `/info [id]`, `/help` — for everyone

The id can be omitted when only one tournament fits. Admins can do all of this in plain text too.

### Creating a tournament (draft → card → confirmation)

Every tournament has a balance: the organizer names the strongest allowed team by levels, e.g.
«средний, средний, средний+» (team limit = sum of the levels = 10; the number of levels = team size).
There is no "no balance" option any more (old tournaments without a limit keep working).

1. In plain text («@bot создай турнир на субботу в 20:00, тройки, эталон средний, средний, средний+») the bot
   collects title, MSK date/time, team size and the reference team and asks for whatever is missing.
   Player limit defaults to 24, the level scale to the default one.
2. It creates a draft and shows the card with «Публикуем или что-то поправить?».
3. Publishing (registration opens, announcement is posted) happens only on a clear confirmation from the
   organizer: the «Публикуем» button, or a reply to the card like «да», «публикуем», «ок», «всё верно».
   The check is done in PHP (`App\Confirmation`), not by the model: anything with a correction, a question,
   a negation or numbers («да, но время 21:00») is not a confirmation — the edits are applied and the card is shown again.
   The model can publish only via the `publish_tournament` tool, which re-checks the user's message.
4. Every change of a draft invalidates the previous card (`tournaments.card_msg_id`), so only the latest
   shown version can be confirmed.

### Levels

`1 начальный, 2 начальный+, 3 средний, 4 средний+, 5 высокий, 6 высокий+`. Synonyms: слабый / базовый /
начинающий = начальный, танк = высокий. Case-insensitive, «плюс» = «+» («слабый плюс» = 2, «танк+» = 6),
small typos are tolerated («выскоий» = высокий).

## Development

```bash
composer install
vendor/bin/phpunit                    # SQLite in-memory, no network (HTTP is faked)

# same tests against an empty MySQL/MariaDB database, using migrations/*.sql:
P1BOT_TEST_MYSQL_DSN="mysql:host=127.0.0.1;dbname=p1bot_test;charset=utf8mb4" \
P1BOT_TEST_MYSQL_USER=root P1BOT_TEST_MYSQL_PASSWORD=... vendor/bin/phpunit
```

`migrations/*.sql` is the reference schema; `tests/Support/schema.sqlite.sql` mirrors all migrations.
`composer.json` pins `config.platform.php` to 8.0.30, so dependencies stay PHP 8.0 compatible.

Layout: `src/Domain` (rules), `src/Repository` (PDO), `src/Telegram` (Bot API, updates),
`src/Handlers` (routing, commands, buttons), `src/Ai` (OpenAI, tools, dispatcher),
`src/App` (announcements, cron, resolvers), `src/View` (texts).
