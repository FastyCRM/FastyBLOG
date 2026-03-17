# ym_link_bot

Копируемый модуль для Telegram-бота с отдельным webhook-файлом на каждый модуль/бот.

## Что уже реализовано
- Настройки бота в админке (token, secret, Affiliate key, geo, статические параметры).
- Привязка пользователей (OAuth access token хранится открыто, как вы запросили).
- Привязка каналов через код подтверждения `/bind CODE`.
- CRUD площадок (имя + CLID).
- Проверка/установка webhook из интерфейса.
- Обработка `channel_post`: ссылка -> очистка -> CLID -> ERID -> отправка готовых ссылок.
- Фото сохраняются по дням в `storage/ym_link_bot/<module>/photos/YYYY-MM-DD`.
- Очистка фото за период через кнопку в модуле.
- Дедупликация апдейтов Telegram по `update_id`.

## Подключение модуля
1. Скопировать папку `adm/modules/ym_link_bot` в новую папку, если нужен отдельный бот.
2. Добавить запись в таблицу `modules` (code должен совпадать с именем папки).
3. Открыть модуль в админке и заполнить настройки.
4. Нажать `Подключить webhook`.
5. Добавить бота в канал администратором канала.
6. Сгенерировать код в модуле и опубликовать в канале: `/bind CODE`.

Последовательность для ручного копирования

Выбрать короткий уникальный код модуля (лучше до 20 символов).
Скопировать папку adm/modules/ym_link_bot в adm/modules/<new_code>.
Переименовать ym_link_bot.php в <new_code>.php.
Добавить запись в таблицу modules с code=<new_code>.
Открыть index.php?m=<new_code> (схема создастся автоматически).
Заполнить настройки бота и нажать установку webhook в UI.
Проверить, что появились таблицы ymlb_<new_code>_* и папка storage/ym_link_bot/<new_code>/.

## Важно
- У каждого скопированного модуля свои таблицы (по префиксу имени папки модуля).
- Вебхук у каждого модуля свой: `/adm/modules/<module_code>/webhook.php`.
- Для доступа к боту пользователь должен быть привязан по `telegram_user_id`.

## Миграция chat -> link
- Если legacy-таблиц `ym_chat_link_bot` нет, но нужен перенос данных, сначала поднимите их вручную:
  `adm/modules/ym_link_bot/legacy_chat_link_tables.sql`
- Для объединения `ym_chat_link_bot` в `ym_link_bot` используйте SQL-скрипт:
  `adm/modules/ym_link_bot/merge_chat_mode.sql`
- Скрипт:
  расширяет схему `ym_link_bot` (`chat_mode_enabled`, тип привязки `chat_kind`);
  переносит привязки/чаты/площадки из `ymlb_ym_chat_link_bot_*` в `ymlb_ym_link_bot_*`;
  отключает модуль `ym_chat_link_bot` в `modules`.
- Для режима нескольких пользователей в одном канале/чате (разные OAuth/CLID) выполните:
  `adm/modules/ym_link_bot/enable_multi_binding_channel.sql`
- После проверки работы можно удалить legacy-таблицы отдельным скриптом:
  `adm/modules/ym_link_bot/cleanup_chat_link_tables.sql`

## Chat Mode Quick Notes
- `chat_mode_enabled = 1` enables chat/group processing in pipeline.
- If `chat_bot_separate = 0`, chat uses the main bot and main webhook (`webhook.php`).
- If `chat_bot_separate = 1`, chat uses dedicated bot token + `chat_webhook.php`.
- For group chats, if plain messages are not arriving, disable Telegram BotFather privacy mode for that bot (`/setprivacy -> Disable`).
- Bind chat by generating code in **Chat** and posting `/bind CODE` inside the target chat.
