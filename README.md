# Pop1 tournament bot

Telegram bot for Population: One tournaments: tournament registration, teams (2–4 players)
with a captain, solo players, level balance (6 levels, per-admin rating scales), player limit
with computed reserve, announcements in the group, reminders before start. Players talk to the
bot in plain Russian (OpenAI, model `gpt-6-luna`); admins also have slash commands.

PHP 8.0+ (tested on 8.0 and 8.3), MySQL 8 / MariaDB 10.5+, no framework.

## How it works

- Webhook: `public/webhook.php`. **Everything happens in the private chat with the bot.** The group gets only
  announcements (cards), reminders and the info commands `/tournaments`, `/history`, `/info N`, `/levels`, `/help`.
  Any other command, @mention or reply to the bot in the group (joining, creating, editing, questions — admins
  included) gets one reply «Давай в личке 👉» with a URL button `https://t.me/<bot>?start=…` (see «Group»).
  No AI in the group.
- In private any text is answered (no mention needed): deep links, buttons, all commands and the AI with all
  tools (`src/Ai`): the model calls functions, PHP executes them through the domain services (`src/Domain`)
  and checks all permissions itself. Any next message within `CONVERSATION_TTL_MINUTES` continues the dialog.
- Without `OPENAI_API_KEY` free text in private gets a short hint; slash commands and buttons always work.
- Cron (`bin/cron.php`, every minute): reminders, auto-close registration `REGISTRATION_CLOSE_MINUTES` before start, `registration/closed → running → finished`, cleanup.

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

Updating later: `git pull && php bin/migrate.php && php bin/diag.php` (e.g. `003_tournament_card.sql` / `004_tournament_card_shown_to.sql` add `tournaments.card_msg_id` / `card_shown_to`; `006_players_dm.sql` adds `players.dm_ok`, `dm_started_at`, `dm_group_id` for the private chat;
`007_group_notices.sql` adds `group_notices` for `REMIND_ALL_GROUPS`).

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
`CONVERSATION_TTL_MINUTES` (30), `REMINDER_MINUTES` (`60,15`), `FINISH_AFTER_HOURS` (2), `OPENAI_TIMEOUT` (30),
`LOG_LEVEL` (`info`; `debug` | `info` | `error`, see Logs), `DIALOG_LOG` (1, see Dialog logs),
`BOT_PERSONA_NAME` (empty; see below), `REMIND_ALL_GROUPS` (1; see Usage → Group), `REGISTRATION_CLOSE_MINUTES` (20; registration auto-closes for players this many minutes before start; organizer/admins can still change lineups; 0 = only manual `/close`).

**Voice.** The bot speaks about herself in the feminine gender («записала», «поняла», «рада») — both the AI
(`config/prompts/system.md`) and all fixed texts. `BOT_PERSONA_NAME` (e.g. `Фемида`) is optional: when set, the
prompt tells the AI that this is her name; fixed texts never contain a name.

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
```
Also refreshes the Telegram command menu (`setMyCommands`: `/start`, `/tournaments`, `/history`, `/info`, `/levels`, `/help`).

```
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

`storage/logs/bot-YYYY-MM-DD.log`, `storage/logs/cron.log`. Timestamps are Moscow time (`MSK`), one file per Moscow day.
The directory must be writable by the php-fpm user; if it is not, lines go to the PHP `error_log` (php-fpm log) with a `[p1bot]` prefix.

`LOG_LEVEL` in `.env`:

- `info` (default): every rejected webhook request with its reason (405, 403 secret header missing/wrong — the secret
  itself is never written, 400 bad JSON); one line per update with type, chat, sender, the first 100 chars of the text
  and the decision; OpenAI calls (model, duration, status, error body); Telegram API errors (`ok=false` + description).
- `debug`: also the full raw update JSON.
- `error`: errors only.

Example:

```
[2026-11-09 18:42:07 MSK] INFO update 912345678 message: ignored: wrong bot username in mention (@pop1balance_bot, expected @pop1_bot from TELEGRAM_BOT_USERNAME) {"type":"message","chat":-1001234567890,"chat_type":"supergroup","from":1234,"username":"@vasya","text":"@pop1balance_bot привет","bot":"@pop1_bot"}
```

Decisions: `ignored: no mention/reply`, `ignored: wrong bot username in mention`,
`ignored: command /x@other_bot is for another bot`, `ignored: unknown command /x`,
`group: info /tournaments` (info commands answered in the group), `group: redirect (/new|mention|reply) start=t_5|g_-100…`,
`group: old button solo:5 -> private`, `private: start t_5`, `private: start g_-100…`, `private: start`,
`private: command /edit`, `private: unknown command /x`, `private: ask group (/info)`, `private: card reply`,
`private: ai`, `private: help` / `levels` / `tournaments` / `fallback text (AI off…)` (keywords, AI off),
`private: button psolo:5`, `private: edited: replied once`, `edited: replied once`, `ignored: edited, …`.

#### Dialog logs

`storage/logs/dialogs/<username>.log` (lowercase, without `@`, only `a-z0-9_`), or `id<telegram id>.log` for users
without a username: the readable history of one user with the bot. Logged: every message the bot processes (group
mentions, replies and commands addressed to it, all private messages, edited messages it answers as `[исправлено] …`,
buttons as `[кнопка] <label>`) and every reply to that interaction (messages, edits of private messages as
`(исправила сообщение) …`, button popups as `(всплывающее) …`). Plain text, HTML stripped, continuation lines start with a tab:

```
[2026-11-09 18:42:07 MSK] группа «P1 турниры» @vasya: @pop1_bot запиши меня
[2026-11-09 18:42:07 MSK] группа «P1 турниры» бот → @vasya: Давай в личке 👉
[2026-11-09 18:42:15 MSK] личка @vasya: /start t_3
[2026-11-09 18:42:15 MSK] личка бот → @vasya: Привет, Vasya! 👋 Вот этот турнир. Кнопки ниже — или просто напиши, что нужно.
[2026-11-09 18:42:15 MSK] личка бот → @vasya: 🏆 Субботний кубок · №1
	🗓 14.11.2026 ⏰ 19:00 МСК
	…
[2026-11-09 18:42:20 MSK] личка @vasya: [кнопка] 🙋 Записаться соло
[2026-11-09 18:42:20 MSK] личка бот → @vasya: (всплывающее) Записала тебя соло! Команду соберёте уже в игре.
```

Announcements, their edits and cron reminders are broadcasts and are not written there; the AI's tool calls stay
in the main log. `DIALOG_LOG=0` in `.env` turns it off (default 1). Same fallback as the main log: if the directory
is not writable, lines go to the PHP `error_log` with a `[p1bot]` prefix.

### Diagnostics

```bash
php bin/diag.php
```

Prints: webhook info from Telegram (host masked as `***`, pending updates, last delivery error, allowed_updates),
`getMe` username vs. `TELEGRAM_BOT_USERNAME`, DB connection and pending migrations, whether the logs dir is writable,
whether `OPENAI_API_KEY` is set (yes/no). No secrets are printed. Run it as the php-fpm user to check log permissions
for the webhook too, e.g. `sudo -u apache php bin/diag.php`.

## Usage

### Group

- The bot posts announcements (one URL button «💬 Записаться / вопросы в личке» → `start=t_<id>`), edits them when
  someone joins or leaves (no join messages in the group), and posts reminders.
- Tournaments are **global**: on publish the announcement is posted into **every** active group in `chats`.
  `/tournaments` and `/info` in any group list all tournaments. `tournaments.chat_id` is kept as optional origin (nullable).
- Reminders (`REMINDER_MINUTES`, default `60,15`) for published tournaments: with `REMIND_ALL_GROUPS=1` (default)
  only the **largest** mark (60 = hour before) posts the short «⏰ Через час стартует турнир …» notice into **every**
  active group (deep-link button). The 15-minute mark does **not** broadcast to groups. Deduped via `group_notices`.
  `REMIND_ALL_GROUPS=0` turns group broadcasts off (no private DM reminders yet). `REMINDER_MINUTES` key unchanged.
- In the group when mentioned/replied: info (`/tournaments`, `/history`, `/info`, `/levels`, `/help`, «какие турниры?»,
  «что нового?», «прошлые турниры»…) is answered there (help ends with a private-chat hint + button); «запиши меня» /
  «удали меня» join/leave solo when the tournament is unambiguous; management (`/new`, create/edit…) → random private
  redirect phrase + deep link; anything else → fixed «Не поняла 🙅‍♀️ Что я умею — /help» (no free-form AI chat in
  groups). Deep link is `t_<id>` when clear, else `g_<chat id>`.
- Buttons of old group messages (`solo`, `unsolo`, `howto`, `pub`, `fix`) answer «Теперь это в личке…» and open
  the private chat via `answerCallbackQuery(url=t.me/<bot>?start=t_<id>)`.

### Private chat

- `/start t_<id>` (from the announcement button): greeting, the full tournament view and buttons for the player's
  state: «🙋 Записаться соло» + «👥 Собрать команду»; «❌ Отписаться» (solo); «🚪 Выйти из команды» (member);
  «🚫 Распустить команду» (captain). Drafts open as the draft card for their organizer.
- `/start`, `/start g_<chat>`: greeting + active tournaments of all groups, each with an «open» button.
- «Собрать команду» asks for the name and players; the answer goes to the AI (the question is saved as dialog
  memory), e.g. «Wolves: @vasya, @petya». Adding/removing/replacing players, renaming, captain transfer — in plain text.
- Plain text: «какие турниры скоро?», «запиши команду Wolves: я, @vasya, @petya», «хочу участвовать соло»,
  «замени @vasya на @kolya», «где я записан?». Commands: `/tournaments`, `/info N`, `/levels`, `/help`.
- Anyone may join (solo/team); no group-membership check for registration. Create still requires a shared active
  group with the owner (or `ANYONE_CAN_CREATE_TOURNAMENT=1`).
- Every private message marks the player as having started the bot (`players.dm_ok`, `dm_started_at`, migration 006).
  No private reminders yet; reminders stay in the group.
- Edited messages in private get «Исправленные сообщения я не вижу…» once.

**Groups in private.** Tournament numbers are **global** (the tournament id). Lists show all tournaments (no
per-group headers). `players.dm_group_id` / deep link `g_<chat id>` / `grp:` buttons are kept for remembering a
group (legacy), but creating a tournament no longer asks which group it is for.

**Who may create / manage:**
- **Create** (`/new`, AI create): the bot **owner** always; otherwise a user who shares at least one **active** group
  with both the bot and the owner (`getChatMember`: member/administrator/creator/restricted-but-member), with a short
  cache. Or anyone if `ANYONE_CAN_CREATE_TOURNAMENT=1` (override, key kept). Refusal (feminine):
  «Создавать турниры могут только участники групп, где есть организатор P1. Записаться на турнир при этом можно 🙂».
- **Manage** (`/edit`, `/open`, `/close`, `/cancel`, publish): only the tournament's **author** (organizer) and
  the bot **owner** (`BOT_OWNER_TG_ID`). Group admins have no special rights to manage someone else's tournament.

Creating:
- `/new 23.11 20:00 | Кубок | 3 | 10 | 24` — date/time MSK | title | team size |
  tournament level (required, see «Tournament level» below) | player limit (default 24).
  Creates a **draft** (optional origin `chat_id` for history) and shows its card **in private** ending with
  «Публикуем или что-то поправить?» and the buttons «✅ Публикуем» / «✏️ Поправить». On a clear confirmation the
  announcement goes into **every** active group.
- `/edit [N] время 23.11 21:00` (fields: название, время, размер, уровень, лимит; `баланс N` — owner only). Editing a draft shows a fresh card.
  `/edit 1 уровень 10`, `/edit 1 уровень средний, средний, высокий`, `/edit 1 размер 2 уровень 7`.
- `/levels` — the six levels with points and synonyms and how the tournament level works (for everyone).
- `/open [id]` — for a draft: shows the same card and publishes only after a clear confirmation (see below);
  for a closed tournament: reopens registration right away. `/close [id]`; `/cancel [id]`
- All of this in plain text too («создай турнир на субботу в 20:00, тройки, уровень 10»).

Owner only (`BOT_OWNER_TG_ID`, shown in `/help` to the owner only):
- `/level @user 4 [balance]`, `/balance [id]`, `/edit N баланс M`; in plain text: set a player's level, create/show scales,
  pick a scale for a new tournament.

**Scales are hidden for now** (temporary product decision): cards, announcements, lists, `/help`, `/levels`,
the AI prompt and tool descriptions never mention rating scales. Only the owner changes player levels and a
tournament's scale; everyone else gets «Уровни игроков меняет только главный админ.» / «Это доступно только главному
админу.», and tournaments created by others always use the owner's default scale. The scale tables and columns stay
(see `AccessPolicy::LEVELS_OWNER_ONLY`), so this can be switched back on later.
- `/tournaments` — pretty list of active tournaments with teams (👑 captain), solo players, reserve and forming teams
  (in private also your own drafts; all drafts for the owner); `/history` — last 10 finished/cancelled (compact, № = id);
  `/info [N]` — full lineup of one tournament (works for finished too); `/help` — for everyone

The number can be omitted when only one tournament fits.

Edited messages are never processed (no commands, no AI). If an edit is in private, or mentions the bot or replies
to it in a group, the bot answers once per message: «Исправленные сообщения я не вижу 🙈 Напиши, пожалуйста, заново новым сообщением»
(dedupe table `edited_replies`, migration 005; rows older than 30 days are pruned). `edited_message` is in the
webhook's `allowed_updates`, so after updating rerun `php bin/set_webhook.php`.

### Tournament numbers

`№N` everywhere (lists, cards, announcements, `/info N`, `/edit N`, `/open N`, `/close N`, `/cancel N`, the AI's
`tournament_id`) is the tournament's **database id** — the same number in every group. Finished and cancelled
tournaments leave the active `/tournaments` list but keep their № for `/history` and `/info N`. Numbers do **not** shift when
another tournament finishes. No extra column or migration: the id is the number.
Inline buttons also use that id, so old buttons stay correct.

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

1. In plain text in private («создай турнир на субботу в 20:00, тройки, уровень 10») the bot
   collects title, MSK date/time, team size and the tournament level and asks for whatever is missing or invalid.
   Player limit defaults to 24, the level scale to the default one.
2. It creates a draft and shows the card with «Публикуем или что-то поправить?» in private.
3. Publishing (registration opens, the announcement is posted to the group) happens only on a clear confirmation from the
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

Code style: see [CODE_STYLE.md](CODE_STYLE.md) (tabs, no `final`/`private`, snake_case variables, same-line braces).

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
