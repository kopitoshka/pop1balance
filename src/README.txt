СТРУКТУРА src/
=============

Кратко: куда что класть и как идёт запрос. Язык кода — PHP 8, неймспейс P1Bot\….

КАК ТЕЧЁТ ЗАПРОС
---------------

Webhook (сообщение / кнопка в Telegram)

1. public/index.php (или аналог) → Bootstrap собирает зависимости.
2. Handlers\UpdateRouter разбирает Telegram\Update: членство бота в группе,
   callback, сообщение, правка.
3. Игрок пишется/обновляется в БД (PlayerRepository::ensureByTelegram) — из
   отправителя и из text_mention.
4. Группа: App\GroupChat (интенты: info / join / leave / manage) или редирект в
   личку.
5. Личка: Handlers\PrivateChatHandler — команды, ключевые слова, иначе AI.
6. Команды → Handlers\AdminCommands → Domain (TournamentService, TeamService,
   BalanceService, NickService…) → Repository.
7. Ответ пользователю → каталог View + Telegram\BotApi (через DialogLoggingApi
   для логов диалога).
8. Свободный текст в личке → Handlers\MessageHandler → Ai\Assistant →
   Ai\Dispatcher. ИИ только показывает информацию (справка, турниры,
   уровни, список игроков); действия — командами, кнопками и Mini App.

Cron

bin/cron.php → App\CronRunner: напоминания о старте, закрытие регистрации,
finish, анонсы через App\Announcer.

КАТАЛОГИ
--------

Handlers/ — вход с Telegram

Маршрутизация апдейтов и разбор команд/кнопок/AI-ответов.

    UpdateRouter.php
        Точка входа: тип апдейта → группа / личка / callback
    PrivateChatHandler.php
        Личка: /start, команды, keywords, AI
    AdminCommands.php
        Реализация slash-команд (/create_tournament, /level,
        /nick, /players_import, …)
    CallbackHandler.php
        Нажатия inline-кнопок
    MessageHandler.php
        Свободный текст → info-only AI или fallback;
        «да» на карточке черновика публикует без ИИ
    Context.php
        Сообщение + actor + права + группа

App/ — сценарии над доменом

Оркестрация: анонсы, группы, резолверы, cron, черновики.

    GroupChat.php
        Интенты в группе без полноценного AI
    Announcer.php
        Публикация/правка анонсов турнира во все группы
    CronRunner.php
        Периодические задачи
    PlayerResolver.php
        @user / «я» / IGN → известный игрок (и без
        Telegram, добавленный через /addplayer)
    TournamentResolver.php
        Номер турнира из текста
    DraftCards.php
        Карточка черновика «публикуем?»
    PrivateViews.php / PrivateGroups.php
        Списки и выбор группы в личке
    CreateAccess.php
        Кому можно создавать турниры
    GroupRedirect.php
        Кнопка «напиши в личку»
    Confirmation.php
        Подтверждения действий
    TournamentListing.php
        Сводка турниров
    TeamDisbandNotifier.php
        Уведомление при роспуске команды
    OrganizerTeams.php
        Организатор: список команд, каждая открывает форму
    PendingTextGuard.php
        Сброс pending по чужой кнопке/команде; «✖️ Отмена»
    TeamForm.php
        Mini App команды: создание/правка, поиск, контакты
        Telegram, выход, роспуск (initData HMAC)
    WebAppPicker.php
        Mini App /level: показ и установка уровня
    CreateTournamentForm.php
        /create_tournament: предложение формы, кнопка под
        списком, Mini App создания черновика (initData HMAC)
        и правки черновика («✏️ Поправить» на карточке);
        загрузка картинки турнира (imageUpload)
    TournamentImages.php
        Картинки турниров: выключатель, публичный URL и
        link_preview_options для сообщения об одном турнире
        (Announcer, DraftCards, PrivateViews, /tournaments N)
    WebAppFormError.php
        Ошибка формы Mini App: поле + причина для JSON
    TeamCards.php
        Карточка команды в личке
    SuperAdmin.php
        /su: режим суперадмина, grant / revoke / list, галочка на
        карточке игрока, обновление меню команд
    WebhookGate.php
        Проверка секрета webhook
    Diagnostics.php
        Текст для bin/diag.php
    NeedGroupChoice.php
        Исключение: нужно выбрать группу

Domain/ — бизнес-правила

Турниры, команды, уровни, ники. Без Telegram API.

    TournamentService.php
        Создание/редактирование/статусы; удаление черновиков
    TeamService.php
        Команды, соло, лимит баллов (live с профиля)
    BalanceService.php
        Шкалы уровней и рейтинги игроков
    NickService.php
        Игровые ники (IGN)
    Levels.php
        Парсинг уровней 1–6 и уровня турнира
    TournamentMaps.php
        Карты турнира: Classic, Evolving, Phoenix, custom
    Lineup.php
        Основной состав / запас
    AccessPolicy.php
        Права: isSuper (владелец или /su-режим, единая проверка для
        всего, что раньше было «только владелец»), canManageSu,
        isBotOwner (только личность), admin / manage
    MoscowTime.php
        Время МСК
    CreateTournament.php / EditTournament.php
        DTO входа
    TournamentStatus.php / TeamStatus.php
        Константы статусов

Domain/Model/ — сущности из БД

Tournament, Team, TeamMember, TeamEntry, SoloPlayer, Player, Balance, …

Domain/Exception/ — ошибки домена

ValidationError, PermissionDenied, NotFound, LevelLimitExceeded, InvalidLevel,
DomainError.

Repository/ — SQL к таблицам

Тонкий слой: find / insert / update. Без бизнес-логики.

PlayerRepository, TournamentRepository, TeamRepository, SoloPlayerRepository,
BalanceRepository, ChatRepository, AdminRepository, ConversationRepository,
TournamentAnnouncementRepository, GroupNoticeRepository, EditedReplyRepository,
PendingActionRepository, WebAppContextRepository.

View/ — тексты и разметка для пользователя

HTML-сообщения, кнопки, карточки. Без записи в БД.

    Texts.php
        /help (со справкой по уровням), приветствия
    Messages.php
        Короткие фразы, редиректы
    AnnouncementView.php
        Текст анонса и состава
    TournamentCard.php
        Карточка турнира в личке
    DeepLink.php
        t.me/bot?start=…
    WebAppUrl.php
        URL Mini App из WEBHOOK_URL (без хардкода домена)
    Html.php
        Экранирование

Telegram/ — клиент Telegram Bot API

    Update.php / Message.php / TgUser.php / CallbackQuery.php
        Разбор JSON апдейта
    WebAppInitData.php
        Проверка подписи initData Mini App
    BotApi.php / TelegramApi.php
        Вызовы API
    DialogLoggingApi.php
        Обёртка API + лог диалога
    BotIdentity.php
        id / username бота, owner
    CommandMenu.php
        Меню команд «/»: общее, личка, личное меню суперадмина
    ChatAdminSync.php
        Кэш админов группы
    TelegramError.php
        Ошибки API

Ai/ — OpenAI в личке

    Assistant.php
        Цикл «модель ↔ tools»
    Tools.php
        Схемы инструментов (только просмотр)
    Dispatcher.php
        Выполнение tool → Domain (без изменений данных)
    OpenAiClient.php / AiClient.php
        HTTP к API
    AiResponse.php / AiError.php
        Ответ / ошибка

Http/ — общий HTTP

HttpClient, CurlHttpClient, HttpResponse, HttpError — для Telegram и OpenAI.

Support/ — инфраструктура

Database, Migrations, Clock / SystemClock / FixedRandom, Logger, DialogLog,
ImageStore (файлы картинок в public/media: проверка, чистая копия в JPEG
без метаданных, случайное имя, удаление, уборка сирот),
SuLog (storage/logs/su.log: только операции /su), Env, DateFormat, RuPlural,
Redact, …

Корень src/

    Bootstrap.php
        Сборка всего графа зависимостей из config

ЗАМЕЧАНИЯ ПО ВОЗМОЖНЫМ ОБЪЕДИНЕНИЯМ (не трогали)
-----------------------------------------------

- Http/ маленький — теоретически можно вложить в Telegram/ + Ai/, но так проще
  шарить клиент.
- App/GroupRedirect.php и куски редиректа в UpdateRouter / Messages близки по
  смыслу.
- View/TournamentCard.php и фрагменты AnnouncementView.php частично дублируют
  поля турнира.
- Ai/AiClient.php vs OpenAiClient.php — тонкий интерфейс + одна реализация;
  оставлять имеет смысл для тестов.

PUBLIC/ — то, что отдает веб-сервер
----------------------------------

webhook.php     приём апдейтов Telegram
webapp_api.php  API Mini App: уровень, форма турнира
                (create_*, edit_*, tournament_image —
                загрузка картинки, multipart) и форма команды
                (team_bootstrap, team_search, team_submit,
                team_contacts, team_leave, team_disband)
webapp/         страницы Mini App (HTML/JS/CSS, без
                сборки): picker.* уровень, create.*
                форма /create_tournament, team.* команда
media/          картинки турниров (создаёт и чистит бот; в git только
                .gitignore). Веб-сервер отдаёт как статику; каталог
                должен быть доступен на запись пользователю PHP
