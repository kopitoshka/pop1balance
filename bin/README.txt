СКРИПТЫ bin/
============

Запуск из корня репозитория: php bin/<имя>.php …

migrate.php
  Применяет новые migrations/*.sql по порядку.

diag.php
  Здоровье бота / БД. Также:
  php bin/diag.php --player @nick
  php bin/diag.php --placeholders

set_webhook.php
  Ставит webhook по TELEGRAM_* из .env.

cron.php
  Напоминания и автозавершение турниров
  (обычно из crontab раз в минуту).

import_members.php
  Импорт участников группы из экспорта
  Telegram Desktop (JSON) в таблицу players.

  php bin/import_members.php path/result.json \
    [chat_id] [--dry-run]

  Без chat_id id берётся из экспорта:
  супергруппа → -100{id}, обычная группа → -{id}.

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

  Как выгрузить чат в Desktop:
  группа → ⋮ → Export chat history → снять
  галочки с медиа → Format: JSON → сохранить
  result.json.

  Пример:
  php bin/import_members.php ~/Downloads/Chat/\
  result.json --dry-run
  php bin/import_members.php ~/Downloads/Chat/\
  result.json -1001234567890
