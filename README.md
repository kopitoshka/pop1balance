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

Updating later: `git pull && php bin/migrate.php` (e.g. `003_tournament_card.sql` / `004_tournament_card_shown_to.sql` add `tournaments.card_msg_id` / `card_shown_to`).

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
- `/new 23.11 20:00 | Кубок | 3 | 10 | 24` — date/time MSK | title | team size |
  tournament level (required, see «Tournament level» below) | player limit (default 24).
  Creates a **draft** and shows its card ending with «Публикуем или что-то поправить?» and the buttons
  «✅ Публикуем» / «✏️ Поправить».
- `/edit [N] время 23.11 21:00` (fields: название, время, размер, уровень, лимит, баланс N). Editing a draft shows a fresh card.
  `/edit 1 уровень 10`, `/edit 1 уровень средний, средний, высокий`, `/edit 1 размер 2 уровень 7`.
- `/levels` — the six levels with points and synonyms and how the tournament level works (for everyone).
- `/open [id]` — for a draft: shows the same card and publishes only after a clear confirmation (see below);
  for a closed tournament: reopens registration right away. `/close [id]`; `/cancel [id]`
- `/level @user 4 [balance]`, `/balance [id]`
- `/tournaments` — pretty list of active tournaments with teams (👑 captain), solo players, reserve and forming teams
  (admins also see drafts); `/info [N]` — full lineup of one tournament; `/help` — for everyone

The number can be omitted when only one tournament fits. Admins can do all of this in plain text too.

Edited messages are never processed (no commands, no AI). If an edit mentions the bot or replies to it, the bot
answers once per message: «Исправленные сообщения я не вижу 🙈 Напиши, пожалуйста, заново новым сообщением»
(dedupe table `edited_replies`, migration 005; rows older than 30 days are pruned). `edited_message` is in the
webhook's `allowed_updates`, so after updating rerun `php bin/set_webhook.php`.

### Tournament numbers

Users never see database ids. `№N` everywhere (lists, cards, announcements, `/info N`, `/edit N`, `/open N`,
`/close N`, `/cancel N`, the AI's `tournament_id`) is a short per-chat number: the position among the chat's
tournaments that are not finished or cancelled (drafts included), ordered by start time, then id: 1, 2, 3...
It is computed on the fly (`App\TournamentResolver::numbers()`), nothing is stored.

Numbers are stable while the set of tournaments is unchanged, but **can shift** when an earlier tournament
finishes or is cancelled, or a new/moved tournament starts earlier. The bot then re-renders the other
announcements (`Announcer::renumber`) so they show the current numbers; always check `/tournaments`.
Players may see gaps in the numbering, because drafts are numbered but only visible to admins.
Inline buttons keep using real ids internally, so old buttons stay correct.

### Output format

`/tournaments`, `/info`, announcements and the AI list share one renderer (`View\AnnouncementView`):
🏆 title · №N, 🗓 date ⏰ time МСК, 👤 organizer, ⚖️ level, 👥 team size, status, 🎟 occupied/reserve, then
🛡 teams (👑 captain), 🙋 solo players, ⏳ reserve, 🧩 forming teams. Player levels are never shown.
Messages stay under Telegram's 4096 characters: the list is split into several messages, a too long
tournament block is cut with «…и ещё N строк. Полный состав: /info N», `/info` sends the full lineup in parts.
When the AI calls `list_tournaments`, the bot sends this block itself and the model only adds a short phrase.

### Creating a tournament (draft → card → confirmation)

Every tournament has a level (see below). There is no "no level" option any more
(old tournaments without a limit keep working).

1. In plain text («@bot создай турнир на субботу в 20:00, тройки, уровень 10») the bot
   collects title, MSK date/time, team size and the tournament level and asks for whatever is missing or invalid.
   Player limit defaults to 24, the level scale to the default one.
2. It creates a draft and shows the card with «Публикуем или что-то поправить?».
3. Publishing (registration opens, announcement is posted) happens only on a clear confirmation from the
   organizer, the admin the current card was shown to (who ran `/new`, `/edit` or `/open`) or the bot owner: the «Публикуем» button, or a reply to the card like «да», «публикуем», «ок», «всё верно».
   The check is done in PHP (`App\Confirmation`), not by the model: anything with a correction, a question,
   a negation or numbers («да, но время 21:00») is not a confirmation — the edits are applied and the card is shown again.
   The model can publish only via the `publish_tournament` tool, which re-checks the user's message.
4. Every change of a draft invalidates the previous card (`tournaments.card_msg_id`), so only the latest
   shown version can be confirmed.

### Levels

`1 начальный, 2 начальный+, 3 средний, 4 средний+, 5 высокий, 6 высокий+`. Synonyms: слабый / базовый /
начинающий = начальный, танк = высокий. Case-insensitive, «плюс» = «+» («слабый плюс» = 2, «танк+» = 6),
small typos are tolerated («выскоий» = высокий). Full help for users: `/levels` (the AI sends the same
text via the `levels_help` tool when asked «какие есть уровни»).

### Tournament level

«уровень», «уровень турнира», «максимальный уровень», «макс. уровень» are the same thing: the maximum total
points of a team (`tournaments.max_points`). A team is accepted when the sum of its players' levels fits.
It is set (in `/new`, `/edit` and plain text) in one of two ways:

- **one number** — the total itself: `10` for a team of 3 means `max_points = 10`. Allowed range:
  team_size × 1 … team_size × 6 (3–18 for threes). Stored with `reference_team = NULL`.
- **levels of all players**, exactly team_size of them (names, synonyms or digits 1–6):
  «средний, средний, высокий» / `3,3,5` → `max_points = 11`, `reference_team = '3,3,5'`.

A single level name («средний») is not a shorthand for teams of 2–4. Anything else (no value, wrong number
of levels, digit outside 1–6, total out of range, unknown word) is rejected with the reason plus the usage
help with examples and a pointer to `/levels`. The label («средний (10 баллов на троих)») is
round(max_points / team_size). Changing the team size needs a new level (`/edit 1 размер 2 уровень 7`).
After the first sign-up (team or solo) the level and team size are locked; the bot says so and suggests
cancelling and creating a new tournament. DB columns are unchanged (`reference_team`, `max_points`).

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
