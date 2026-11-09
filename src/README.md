# Структура `src/`

Кратко: куда что класть и как идёт запрос. Язык кода — PHP 8, неймспейс `P1Bot\…`.

## Как течёт запрос

### Webhook (сообщение / кнопка в Telegram)

1. `public/index.php` (или аналог) → `Bootstrap` собирает зависимости.
2. `Handlers\UpdateRouter` разбирает `Telegram\Update`: членство бота в группе, callback, сообщение, правка.
3. Игрок пишется/обновляется в БД (`PlayerRepository::ensureByTelegram`) — из отправителя и из text_mention.
4. **Группа:** `App\GroupChat` (интенты: info / join / leave / manage) или редирект в личку.
5. **Личка:** `Handlers\PrivateChatHandler` — команды, ключевые слова, иначе AI.
6. Команды → `Handlers\AdminCommands` → **Domain** (`TournamentService`, `TeamService`, `BalanceService`, `NickService`…) → **Repository**.
7. Ответ пользователю → `View\*` + `Telegram\BotApi` (через `DialogLoggingApi` для логов диалога).
8. Свободный текст в личке → `Handlers\MessageHandler` → `Ai\Assistant` → `Ai\Dispatcher` (те же Domain-сервисы).

### Cron

`bin/cron.php` → `App\CronRunner`: напоминания о старте, закрытие регистрации, finish, анонсы через `App\Announcer`.

---

## Каталоги

### `Handlers/` — вход с Telegram

Маршрутизация апдейтов и разбор команд/кнопок/AI-ответов.

| Файл | Зачем |
|------|--------|
| `UpdateRouter.php` | Точка входа: тип апдейта → группа / личка / callback |
| `PrivateChatHandler.php` | Личка: /start, команды, keywords, AI |
| `AdminCommands.php` | Реализация slash-команд (/new, /level, /nick, …) |
| `CallbackHandler.php` | Нажатия inline-кнопок |
| `MessageHandler.php` | Свободный текст → AI или fallback |
| `Context.php` | Сообщение + actor + права + группа |

### `App/` — сценарии над доменом

Оркестрация: анонсы, группы, резолверы, cron, черновики.

| Файл | Зачем |
|------|--------|
| `GroupChat.php` | Интенты в группе без полноценного AI |
| `Announcer.php` | Публикация/правка анонсов турнира во все группы |
| `CronRunner.php` | Периодические задачи |
| `PlayerResolver.php` | `@user` / «я» / IGN → известный игрок с tg id |
| `TournamentResolver.php` | Номер турнира из текста |
| `DraftCards.php` | Карточка черновика «публикуем?» |
| `PrivateViews.php` / `PrivateGroups.php` | Списки и выбор группы в личке |
| `CreateAccess.php` | Кому можно создавать турниры |
| `GroupRedirect.php` | Кнопка «напиши в личку» |
| `Confirmation.php` | Подтверждения действий |
| `TournamentListing.php` | Сводка турниров |
| `TeamDisbandNotifier.php` | Уведомление при роспуске команды |
| `WebhookGate.php` | Проверка секрета webhook |
| `Diagnostics.php` | Текст для `bin/diag.php` |
| `NeedGroupChoice.php` | Исключение: нужно выбрать группу |

### `Domain/` — бизнес-правила

Турниры, команды, уровни, ники. Без Telegram API.

| Файл | Зачем |
|------|--------|
| `TournamentService.php` | Создание/редактирование/статусы турнира |
| `TeamService.php` | Команды, соло, лимит баллов (live с профиля) |
| `BalanceService.php` | Шкалы уровней и рейтинги игроков |
| `NickService.php` | Игровые ники (IGN) |
| `Levels.php` | Парсинг уровней 1–6 и уровня турнира |
| `Lineup.php` | Основной состав / резерв |
| `AccessPolicy.php` | Owner / admin / manage |
| `MoscowTime.php` | Время МСК |
| `CreateTournament.php` / `EditTournament.php` | DTO входа |
| `TournamentStatus.php` / `TeamStatus.php` | Константы статусов |

#### `Domain/Model/` — сущности из БД

`Tournament`, `Team`, `TeamMember`, `TeamEntry`, `SoloPlayer`, `Player`, `Balance`, …

#### `Domain/Exception/` — ошибки домена

`ValidationError`, `PermissionDenied`, `NotFound`, `LevelLimitExceeded`, `InvalidLevel`, `DomainError`.

### `Repository/` — SQL к таблицам

Тонкий слой: `find` / `insert` / `update`. Без бизнес-логики.

`PlayerRepository`, `TournamentRepository`, `TeamRepository`, `SoloPlayerRepository`, `BalanceRepository`, `ChatRepository`, `AdminRepository`, `ConversationRepository`, `TournamentAnnouncementRepository`, `GroupNoticeRepository`, `EditedReplyRepository`.

### `View/` — тексты и разметка для пользователя

HTML-сообщения, кнопки, карточки. Без записи в БД.

| Файл | Зачем |
|------|--------|
| `Texts.php` | /help, /levels, приветствия |
| `Messages.php` | Короткие фразы, редиректы |
| `AnnouncementView.php` | Текст анонса и состава |
| `TournamentCard.php` | Карточка турнира в личке |
| `DeepLink.php` | `t.me/bot?start=…` |
| `Html.php` | Экранирование |

### `Telegram/` — клиент Telegram Bot API

| Файл | Зачем |
|------|--------|
| `Update.php` / `Message.php` / `TgUser.php` / `CallbackQuery.php` | Разбор JSON апдейта |
| `BotApi.php` / `TelegramApi.php` | Вызовы API |
| `DialogLoggingApi.php` | Обёртка API + лог диалога |
| `BotIdentity.php` | id / username бота, owner |
| `ChatAdminSync.php` | Кэш админов группы |
| `TelegramError.php` | Ошибки API |

### `Ai/` — OpenAI в личке

| Файл | Зачем |
|------|--------|
| `Assistant.php` | Цикл «модель ↔ tools» |
| `Tools.php` | Схемы инструментов |
| `Dispatcher.php` | Выполнение tool → Domain |
| `OpenAiClient.php` / `AiClient.php` | HTTP к API |
| `AiResponse.php` / `AiError.php` | Ответ / ошибка |

### `Http/` — общий HTTP

`HttpClient`, `CurlHttpClient`, `HttpResponse`, `HttpError` — для Telegram и OpenAI.

### `Support/` — инфраструктура

`Database`, `Migrations`, `Clock` / `SystemClock` / `FixedRandom`, `Logger`, `DialogLog`, `Env`, `DateFormat`, `RuPlural`, `Redact`, …

### Корень `src/`

| Файл | Зачем |
|------|--------|
| `Bootstrap.php` | Сборка всего графа зависимостей из config |

---

## Замечания по возможным объединениям (не трогали)

- `Http/` маленький — теоретически можно вложить в `Telegram/` + `Ai/`, но так проще шарить клиент.
- `App/GroupRedirect.php` и куски редиректа в `UpdateRouter` / `Messages` близки по смыслу.
- `View/TournamentCard.php` и фрагменты `AnnouncementView.php` частично дублируют поля турнира.
- `Ai/AiClient.php` vs `OpenAiClient.php` — тонкий интерфейс + одна реализация; оставлять имеет смысл для тестов.
