# Pop1 tournament bot

Telegram bot for Population: One tournaments: tournament registration, teams (1–12 players)
with a captain, solo players, level balance (6 levels, per-admin rating scales), player limit
with computed reserve, announcements in the group, reminders before start. Players talk to the
bot in plain Russian (OpenAI, model `gpt-6-luna`) for help, tournaments, levels and the players list;
actions go through slash commands, buttons and Mini Apps.

PHP 8.0+ (tested on 8.0 and 8.3), MySQL 8 / MariaDB 10.5+, no framework.

## How it works

- Webhook: `public/webhook.php`. **Everything happens in the private chat with the bot.** The group gets only
  announcements (cards), reminders and the info commands `/tournaments`, `/tournaments N`, `/level`, `/players`, `/help`, `/help_level`
  (`/history`, `/level all` and `/nick` are private-only).
  Any other command, @mention or reply to the bot in the group (joining, creating, editing, questions — admins
  included) gets one reply «Давай в личке 👉» with a URL button `https://t.me/<bot>?start=…` (see «Group»).
  No AI in the group.
- In private any text is answered (no mention needed): deep links, buttons, all commands and an **info-only AI**
  (`src/Ai`): help, tournament list/info/history, levels, the players list, nicks (view) and where you are signed up.
  The model cannot create or edit tournaments, sign people up, change teams, or set nicks or levels — it points
  to `/create_tournament`, card buttons, `/nick`, `/level` and `/players`. PHP runs the tools and checks permissions.
  Any next message within `CONVERSATION_TTL_MINUTES` continues the dialog.
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
    location = /webapp_api.php {
        fastcgi_pass unix:/run/php-fpm/www.sock;
        fastcgi_param SCRIPT_FILENAME $document_root/webapp_api.php;
        include fastcgi_params;
    }
    location /webapp/ {
        try_files $uri =404;
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
Also refreshes the Telegram command menu (`setMyCommands`; `/history` only in `all_private_chats`; `/level` in all scopes).

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
`group: info /tournaments` (info commands answered in the group), `group: redirect (/create_tournament|mention|reply) start=t_5|g_-100…`,
`group: old button solo:5 -> private`, `private: start t_5`, `private: start g_-100…`, `private: start`,
`private: command /open`, `private: unknown command /x`, `private: ask group (/tournaments)`, `private: card reply`,
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
[2026-11-09 18:42:15 MSK] личка бот → @vasya: Привет, Vasya! 👋 Вот этот турнир, кнопки — ниже.
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
  `/tournaments` in any group lists active tournaments; `/tournaments N` shows one (including finished). `tournaments.chat_id` is kept as optional origin (nullable).
- Reminders (`REMINDER_MINUTES`, default `60,15`) for published tournaments: with `REMIND_ALL_GROUPS=1` (default)
  only the **largest** mark (60 = hour before) posts the short «⏰ Через час стартует турнир …» notice into **every**
  active group (deep-link button). The 15-minute mark does **not** broadcast to groups. Deduped via `group_notices`.
  `REMIND_ALL_GROUPS=0` turns group broadcasts off (no private DM reminders yet). `REMINDER_MINUTES` key unchanged.
- In the group when mentioned/replied: info (`/tournaments`, `/tournaments N`, `/level`, `/help`, `/help_level`, «какие турниры?», «какие уровни?»,
  «что нового?», «какой у меня уровень?»…) is answered there (help ends with a private-chat hint + button); «запиши меня» /
  «удали меня» join/leave solo when the tournament is unambiguous; management and private-only (`/create_tournament`, `/history`,
  «прошлые турниры»…) → random private redirect phrase + deep link; anything else → fixed
  «Не поняла 🙅‍♀️ Что я умею — /help» (no free-form AI chat in groups). Deep link is `t_<id>` when clear, else `g_<chat id>`.
- Buttons of old group messages (`solo`, `unsolo`, `howto`, `pub`, `fix`) answer «Теперь это в личке…» and open
  the private chat via `answerCallbackQuery(url=t.me/<bot>?start=t_<id>)`.

### Private chat

- `/start t_<id>` (from the announcement button): greeting, the full tournament view and buttons for the player's
  state: «🛡 Собрать команду» + «🙋 Участвовать соло»; «❌ Отписаться» (solo); «✏️ Редактировать команду «…»»
  (member/captain). Drafts open as the draft card for their organizer.
- `/start`, `/start g_<chat>`: greeting + active tournaments of all groups, each with an «open» button.
- **Team Mini App** (`public/webapp/team.*`, ops `team_bootstrap` / `team_search` / `team_submit` /
  `team_contacts` / `team_leave` / `team_disband`, `webapp_contexts` action `team_form`, TTL 6 h). A new-team button («➕ Добавить команду» and «🛡 Собрать команду») keeps that token for the whole lifetime: each form opening sends a random `opening_id`, a repeated submit returns the first team, and the organizer can add another team from the same button. A regular player who already has a team gets that team (edit), not a second one. The «➕ Создать турнир» / `/create_tournament` button uses the same opening_id rule. New team:
  the opener is captain, the name is prefilled from `players.preferred_team_name` (migration 018: the
  last team they captained, set at once on creation, rename and captain transfer, never cleared;
  skipped if taken in this tournament).
  The organizer/owner may remove anyone (themselves and the captain included) and build a team of
  other people: the captain is the one picked with «сделать капитаном», by default the first player
  of the roster. An organizer who already plays in this tournament gets an empty roster. Regular
  players cannot remove themselves (they leave with «❌ Отменить регистрацию»). An empty roster is
  never saved (`TeamService::saveForm`). Edit: captain,
  tournament organizer and owner; a member sees it read-only with «❌ Отменить регистрацию» (captain gets a DM).
  Roster 1…team_size, live level sum with a red over-limit error, search by IGN / @username / name (spaces and
  `_` ignored), players of other teams disabled, «👤 Выбрать из Telegram» (form is saved as a draft, the app
  closes, `request_users` id 5, then «✏️ Вернуться к форме» reopens it). Ordinary adds/removes send no DMs;
  disband keeps the old notifications. Every save goes through `TeamService::saveForm` (one transaction:
  rights, size, points, duplicate name, other teams, close window). In groups only `t.me/?start=tf_<token>`
  links, never `web_app` buttons. Old roster buttons (`padd`, `prem`, `tadd`, `trem`, `ot*`) only reopen
  the form; TeamAddFlow and RegistrationFillFlow are gone.
- **Team letters** (migration 017): `teams.letter` A–Z, unique per tournament, first free on creation,
  freed on disband, 27th team and later get none («?.»). Organizer/owner change it in the form grid.
  All lists use the stored letter, never the list position.
- The private-chat AI answers questions like «какие турниры скоро?», «какой у меня уровень?», «где я записан?»,
  «покажи игроков» (it can send the same `/players` list). To sign up or build a team, use `/tournaments` and the
  buttons on the tournament card. Commands: `/tournaments`, `/tournaments N`, `/players`, `/help`, `/help_level`.
- Anyone may join (solo/team); no group-membership check for registration. Create still requires a shared active
  group with the owner (or `ANYONE_CAN_CREATE_TOURNAMENT=1`).
- Every private message marks the player as having started the bot (`players.dm_ok`, `dm_started_at`, migration 006).
  No private reminders yet; reminders stay in the group.
- Edited messages in private get «Исправленные сообщения я не вижу…» once.

**Groups in private.** Tournament numbers are **global** (the tournament id). Lists show all tournaments (no
per-group headers). `players.dm_group_id` / deep link `g_<chat id>` / `grp:` buttons are kept for remembering a
group (legacy), but creating a tournament no longer asks which group it is for.

**Who may create / manage:**
- **Create** (`/create_tournament` form): the bot **owner** always; otherwise a user who shares at least one **active** group
  with both the bot and the owner (`getChatMember`: member/administrator/creator/restricted-but-member), with a short
  cache. Or anyone if `ANYONE_CAN_CREATE_TOURNAMENT=1` (override, key kept). Refusal (feminine):
  «Создавать турниры могут только участники групп, где есть организатор P1. Записаться на турнир при этом можно 🙂».
- **Manage** (card buttons «✏️ Поправить» / «✏️ Изменить турнир» / «🗑 Удалить» / «❌ Отменить турнир», `/open`, publish): only the tournament's **author** (organizer) and
  the bot **owner** (`BOT_OWNER_TG_ID`). Group admins have no special rights to manage someone else's tournament.

Creating:
- `/create_tournament` (private only: in the private and owner command menus, not in groups, and as «➕ Создать турнир» under the
  tournament list for those who may create) opens the **Mini App form** (`public/webapp/create.*`,
  API `webapp_api.php` ops `create_bootstrap` / `create_submit`, initData HMAC + `webapp_contexts`
  token, TTL 6 h). Fields: title (1–255), date and time **MSK** in the future (hint «Регистрация
  закроется в HH:MM»), team size 1–12 (default 3), tournament level («По игрокам»: a level per
  player, or «Суммой»: total team_size…team_size×6), player limit (default 24, team_size…1000).
  Date and time use the page's own controls, not the device locale: a Russian calendar starting on
  Monday (past days disabled) and 24-hour hour/minute selects (minutes in steps of 5).
  The server validates everything again. In a group the command answers with a deep link
  `?start=create` to the private chat (web_app buttons work only in private).
  The form creates a **draft** (optional origin `chat_id` for history) and shows its card **in private**
  ending with «Публикуем или что-то поправить?» and the buttons «✅ Публикуем» / «✏️ Поправить». On a
  clear confirmation the announcement goes into **every** active group. The form never publishes.
- «✏️ Поправить» on a draft card opens the same form in edit mode (`create.html?ctx=…&mode=edit`,
  ops `edit_bootstrap` / `edit_submit`): prefilled, heading «Правка турнира», button «Сохранить».
  It saves through `TournamentService::edit` (author or owner) and sends the updated card.
  A card outside private gets a `?start=t_<id>` deep link instead. Old cards with `fix:<id>` get a form button.
  Without an https `WEBHOOK_URL` the form is unavailable («попробуй чуть позже»).
- «✏️ Изменить турнир» on the private tournament card (author and owner, until finished or cancelled)
  opens the same form for a **published** tournament (heading «Изменить турнир»). Once a team or solo
  player is registered, team size is disabled with an explanation (TournamentService::edit rule);
  the tournament level may still be changed. Teams that then go over the limit stay in and are marked
  «⚠️ выше уровня» in lists, cards and the team form; nobody is messaged. An unchanged start time is not re-sent (reminders stay). After saving the announcements are
  updated in place, then «Обновила турнир «…», анонсы поправила.» and a fresh tournament card.
  Finished/cancelled tournaments: «Турнир «…» уже завершён, менять его в форме нельзя.»
- Creating in free text via the AI is gone: use `/create_tournament`. `/new` was removed.
- `/edit` and `/cancel` are gone (unknown command): drafts are edited/deleted with «✏️ Поправить» /
  «🗑 Удалить», published tournaments with «✏️ Изменить турнир» / «❌ Отменить турнир».
- `/players` (replaces `/levels`, no alias) — every known player grouped by level, highest first, then
  «Уровень не ставили — считаю средний (3)». Level headers are `<blockquote>`; each player is ONE name
  (`Player::name()`: in-game nick, else @username, else Telegram display name), linked to the Telegram
  profile when bound (tg id or username), no level next to it. Deleted players are not listed. Same access as `/levels` had: anyone in private and groups.
  The owner also sees how to set levels and nicks, and **only in the owner's private chat** a ✏️ after each name opens the player card (below); groups and other users never get pencils.
- **Player card** (owner only; `public/webapp/player.*`, entry `app.html`, API ops `player_bootstrap` / `player_search` /
  `player_save` / `player_delete` / `player_merge`; `PlayerAdmin` is the domain layer, `PlayerForm` the Mini App glue).
  - Opening: the ✏️ link is a Direct Mini App link `https://t.me/<bot>/<short>?startapp=p<id>` (one tap) when `telegram.miniapp_short_name` is set
    (config key, env override `TELEGRAM_MINIAPP_SHORT_NAME`); otherwise `https://t.me/<bot>?start=pe_<id>` and the bot answers with a web_app button.
    `app.html` dispatches on the signed `start_param` (`p<id>` → `player.html`). Every API call validates initData and checks owner-only; the
    pencil only hides noise, a copied link gives nothing to anybody else.
  - BotFather: `/newapp` → choose the bot → title/description/photo → Web App URL `https://<host>[/<dir>]/webapp/app.html` (same host and dir as `WEBHOOK_URL`)
    → short name, e.g. `players` (link `t.me/<bot>/players`). Put the short name into `telegram.miniapp_short_name`. No `set_webhook.php` needed.
  - Fields: nick (unique, may be cleared), level 1–6 / «нет», @username, preferred team name, merge, delete. The form follows Telegram theme params.
  - @username: editing a card not bound to Telegram is free (unique). On a bound card, a different @username detaches that Telegram account
    (tg id and DM flags are cleared; nick, level and teams stay) and needs a confirmation checkbox; the next person who writes to the bot with that @username takes over the card. Clearing the @username of a bound card is refused; the owner's own @username is not editable here.
  - Delete = the whole player, soft (`players.deleted_at`, migration 020). Blocked, with the place named, while the player is in an active team,
    captain of an active team, organizer of an active (or draft) tournament, in a solo sign-up, or owns a scale. Otherwise tg id, @username and nick are
    freed (a returning person becomes a new card), the row stays so history shows «удалённый игрок». Not listed in `/players`, pickers, search, `/addplayer` uniqueness, import matching.
  - Merge (this card → chosen survivor): re-points team_members, `captain_id`, solo sign-ups, organizer, scales, rating authorship, nick history; ratings
    are kept for the survivor where it has none; the survivor's own nick/@username/level/tg binding win, missing ones are taken from the merged card. Refused when both cards are bound to different Telegram
    accounts, when both played in the same tournament (any status), or when the source is the owner's card.
- The level reference (scale 1–6 with names, the tournament level, default level 3) is part of `/help`.
- **Players without Telegram are allowed again** (reverses the 2026-11-09 rule behind migration 014's
  purge). `/addplayer` with no arguments keeps the Telegram picker for everyone. Owner and anyone who may
  create tournaments (`CreateAccess`), private only:
  - `/addplayer @username [nick]` — a row with `tg_user_id` NULL and the username (lower-case, no @);
    the optional rest is the in-game nick. A known @username is not duplicated: the nick is set with the
    `/nick @user` override (taken nick moves over), reply «Игрок @… уже есть…». The first visit of that
    @username claims the row (`PlayerRepository::ensureByTelegram`): tg id bound, nick/levels/rosters kept.
  - `/addplayer nick` — nick only: no tg id, no username, display name = nick. A taken nick is refused.
  - Such players get no DMs (skipped silently) and open no forms. A regular captain still cannot
    hand them captaincy (`TeamService::NO_TELEGRAM_CAPTAIN`). The tournament organizer or the bot
    owner may make them captain (createTeamAsOrganizer, transfer, team form save); the form shows
    «сделать капитаном» for them only to those admins. The default captain is still the first
    player with Telegram, or the first roster player when nobody has one. They are found by the
    team form search and the level picker; the owner sets levels with `/level @user 4` or
    `/level Nick 4`, nicks with `/nick @user Nick` or `/nick "Old nick" New nick`. Duplicates are
    fine; there is no merge.
  - Links everywhere (lineups, announcements, team cards, `/players`, `/level all`): @username →
    `https://t.me/…`, else tg id → `tg://user?id=…`, else plain text.
  - No command deletes a player; duplicates are removed by hand in the DB.
- `/open [id]` — for a draft: shows the same card and publishes only after a clear confirmation (see below);
  for a closed tournament: reopens registration right away.
- The AI does not perform these actions; it only shows information and points to the commands and buttons above.

Owner only (`BOT_OWNER_TG_ID`, shown in `/help` to the owner only):
- `/players_import` — bulk add or update players (private only). Each line is an in-game nick or `@username`; the last space-separated token, if it is a number 1–6, is the level (digits outside 1–6 are an error). A new player without a level gets 3; an existing player without a level on the line is skipped; a given level is set (the same level is skipped). Last duplicate line wins, cap 300 lines, one transaction (error lines are reported, they do not abort the rest). Bare command waits for the next message with «✖️ Отмена». Reply lists added and updated players separately, then skip/error counts, and is split if longer than a Telegram message.
- `/level @user 4 [balance]`, `/balance [id]`. Scales stay hidden from users; only the owner sets levels,
  via `/level` (or the player card), not via the AI.

**Scales are hidden for now** (temporary product decision): cards, announcements, lists, `/help`, `/players`,
the AI prompt and tool descriptions never mention rating scales. Only the owner changes player levels and a
tournament's scale; everyone else gets «Уровни игроков меняет только главный админ.» / «Это доступно только главному
админу.», and tournaments created by others always use the owner's default scale. The scale tables and columns stay
(see `AccessPolicy::LEVELS_OWNER_ONLY`), so this can be switched back on later.
- `/tournaments` — pretty list of active tournaments with teams (👑 captain), solo players, reserve and forming teams
  (in private also your own drafts; all drafts for the owner); `/history` — last 10 finished/cancelled (private only;
  compact, № = id); `/tournaments N` — full lineup of one tournament (works for finished too; `/info` is a silent alias);
  `/help` — commands; `/help_level` — how levels and team strength are counted. The command menu no longer lists `/start` or `/info`.

The number can be omitted when only one tournament fits.

Edited messages are never processed (no commands, no AI). If an edit is in private, or mentions the bot or replies
to it in a group, the bot answers once per message: «Исправленные сообщения я не вижу 🙈 Напиши, пожалуйста, заново новым сообщением»
(dedupe table `edited_replies`, migration 005; rows older than 30 days are pruned). `edited_message` is in the
webhook's `allowed_updates`, so after updating rerun `php bin/set_webhook.php`.

### Tournament numbers

`№N` everywhere (lists, cards, announcements, `/tournaments N`, `/open N`, the AI's
`tournament_id`) is the tournament's **database id** — the same number in every group. Finished and cancelled
tournaments leave the active `/tournaments` list but keep their № for `/history` and `/tournaments N`. Numbers do **not** shift when
another tournament finishes. No extra column or migration: the id is the number.
Inline buttons also use that id, so old buttons stay correct.

### Output format

`/tournaments`, `/tournaments N`, announcements and the AI list share one renderer (`View\AnnouncementView`):
🏆 title · №N, 🗓 date ⏰ time МСК, 👤 organizer, ⚖️ level, 👥 team size, status, 🎟 occupied/reserve, then
🛡 teams (👑 captain), 🙋 solo players, ⏳ reserve, 🧩 forming teams. Player levels are never shown.
Messages stay under Telegram's 4096 characters: the list is split into several messages, a too long
tournament block is cut with «…и ещё N строк. Полный состав: /tournaments N», `/tournaments N` sends the full lineup in parts.
When the AI calls `list_tournaments`, the bot sends this block itself and the model only adds a short phrase.

### Creating a tournament (draft → card → confirmation)

Every tournament has a level (see below). There is no "no level" option any more
(old tournaments without a limit keep working).

1. `/create_tournament` (or «➕ Создать турнир» on the list) opens the Mini App form: title, MSK date/time,
   team size, tournament level; player limit defaults to 24.
2. The form creates a draft and shows the card with «Публикуем или что-то поправить?» in private.
3. Publishing (registration opens, the announcement is posted to the group) happens only on a clear confirmation from the
   organizer, the admin the current card was shown to (who created it, edited it or ran `/open`) or the bot owner: the «Публикуем» button, or a reply to the card like «да», «публикуем», «ок», «всё верно».
   Any other reply to the card gets a short hint to use the buttons; it is not sent to the AI.
   The check is done in PHP (`App\Confirmation`), not by the model: anything with a correction, a question,
   a negation or numbers («да, но время 21:00») is not a confirmation — the edits are applied and the card is shown again.
   The model can publish only via the `publish_tournament` tool, which re-checks the user's message.
4. Every change of a draft invalidates the previous card (`tournaments.card_msg_id`), so only the latest
   shown version can be confirmed.

### Levels

`1 начальный, 2 начальный+, 3 средний, 4 средний+, 5 высокий, 6 высокий+`. Parsing synonyms: слабый / базовый /
начинающий = начальный, танк = высокий («слабый» accepted but not shown in `/help`). Case-insensitive, «плюс» = «+»
(«слабый плюс» = 2, «танк+» = 6),
small typos are tolerated («выскоий» = высокий). Full help for users: the end of `/help` (the AI sends the same
text via the `levels_help` tool when asked «какие есть уровни»).

### Tournament level

«уровень», «уровень турнира», «максимальный уровень», «макс. уровень» are the same thing: the maximum
team strength (`tournaments.max_points`). Strength is the sum of the players' levels plus a pair bonus:
for every unordered pair where both levels are ≥ 4, add `min(level_i, level_j) - 3`. So `5+4+2` is 12
and `5+3+3` stays 11. A team is accepted when its strength fits the limit.
It is set (in the form and plain text) in one of two ways:

- **one number** — the total itself: `10` for a team of 3 means `max_points = 10`. Allowed range:
  team_size × 1 … the strength of a team of all 6s with pair bonuses (3–27 for threes). Stored with
  `reference_team = NULL`.
- **levels of all players**, exactly team_size of them (names, synonyms or digits 1–6):
  «средний, средний, высокий» / `3,3,5` → `max_points = 11`, `reference_team = '3,3,5'`;
  `4,4,4` → `max_points = 15` (12 plus three pair bonuses of 1).

A single level name («средний») is accepted only for one-player teams (team_size = 1). Anything else (no value, wrong number
of levels, digit outside 1–6, total out of range, unknown word) is rejected with the reason plus the usage
help. The label («средний (10 баллов на троих)») is
round(sum of the reference-team levels / team_size) when the limit was set per player, otherwise
round(max_points / team_size). Changing the team size needs a new level.
After the first sign-up (team or solo) team size is locked (disabled in the form); the tournament
level may still be changed. Teams that then go over the limit stay registered and are marked
«⚠️ выше уровня». The bot still refuses a size change. DB columns are unchanged (`reference_team`, `max_points`).

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
