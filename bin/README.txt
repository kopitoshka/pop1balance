СКРИПТЫ bin/
============

Запуск из корня репозитория: php bin/<имя>.php …

migrate.php
  Применяет новые migrations/*.sql по порядку.

diag.php
  Здоровье бота / БД. Также:
  php bin/diag.php --player @nick
  php bin/diag.php --placeholders
  php bin/diag.php --resolve @nick

set_webhook.php
  Ставит webhook по TELEGRAM_* из .env.
  Также обновляет меню команд (в т.ч.
  /adduser и /history — только в личке).
  После обновления бота перезапусти скрипт —
  в allowed_updates появится inline_query.

  Inline-режим (подбор @ника): в @BotFather
  /setinline → выбрать бота → плейсхолдер
  «ник игрока». Затем php bin/set_webhook.php.

cron.php
  Напоминания и автозавершение турниров
  (обычно из crontab раз в минуту).

import_members.php
  Импорт участников группы из экспорта
  Telegram Desktop (JSON) в таблицу players.
  Большие файлы читаются потоком (без
  полной загрузки JSON в память).

  php bin/import_members.php --list-groups
  php bin/import_members.php path/result.json \
    [chat_id] [--dry-run]

  Без chat_id id берётся из экспорта:
  супергруппа (private/public_supergroup) →
  -100{id}; обычная группа → -{id}.

  Для каждого уникального user id из from_id /
  actor_id вызывается getChatMember. В БД
  попадают только живые участники (member /
  administrator / creator / restricted). Боты,
  left и kicked пропускаются. Placeholder-строки
  не создаются; при совпадении @username
  существующий placeholder сливается (как при
  обычном сообщении в группе). Никому не пишет.
  Лимит ≈20 запросов/с, при 429 ждёт retry_after.
  --dry-run считает статистику без записи в БД.
  Если getChat = chat not found — бота нет в
  этой группе: скрипт покажет активные группы
  из БД и подскажет передать верный chat_id.

  Как выгрузить чат в Desktop:
  группа → ⋮ → Export chat history → снять
  галочки с медиа → Format: JSON → сохранить
  result.json.

  Пример:
  php bin/import_members.php --list-groups
  php bin/import_members.php ~/Downloads/Chat/\
  result.json --dry-run
  php bin/import_members.php ~/Downloads/Chat/\
  result.json -1001234567890

resolve_username.py
  Разово резолвит @username через MTProto
  (Telethon + токен бота). PHP вызывает сам
  при неизвестном @нике; вручную:
  python3.11 bin/resolve_username.py nick

  Установка на AlmaLinux 8 (python3 = 3.6 —
  его не использовать; нужен 3.11):
  sudo dnf install -y python3.11 python3.11-pip
  python3.11 -m pip install --user -r \
    bin/requirements.txt

  В .env раскомментировать (секреты):
  TG_API_ID=…
  TG_API_HASH=…
  (токен уже в TELEGRAM_BOT_TOKEN)

  Проверка:
  php bin/diag.php --resolve @durov

  Сессия: storage/runtime/telethon_bot.session
  (chmod 600, в .gitignore). Бинарник Python
  задаётся в config/config.php
  (username_resolve.python = python3.11),
  не через .env. Telethon==1.45.0.

Подсказки @ника
  Если @username не найден (после точного
  совпадения и MTProto), бот предлагает до 5
  похожих известных игроков кнопками
  «Может, ты про…». Работает в /level, /nick
  и AI (добавить в команду и т.п.).
  Нужна миграция 012_pending_player_actions:
  php bin/migrate.php
