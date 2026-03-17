# max_comments

Модуль добавляет к постам в выбранных MAX-каналах inline-кнопку `open_app` с текстом `Комментарии` (или кастомным).

## Что делает
- берёт MAX API key из настроек `max_comments` (базовый URL: `https://platform-api.max.ru`);
- загружает chat_id каналов из маршрутов `channel_bridge` (target MAX) и даёт выбрать нужные;
- на webhook-событии `message_created` в выбранном канале:
  - читает сообщение через MAX API;
  - добавляет inline-кнопку `open_app`;
  - обновляет сообщение через `PUT /messages`.
- fallback: умеет poll-обход последних сообщений канала (если webhook-события не приходят).

## Точки входа
- UI: `/adm/index.php?m=max_comments`
- save: `/adm/index.php?m=max_comments&do=save`
- poll-now: `/adm/index.php?m=max_comments&do=poll_now`
- webhook: `/core/max_comments_webhook.php` (fallback: `/adm/modules/max_comments/webhook.php`, если core-файл отсутствует)
- mini app: `/adm/modules/max_comments/miniapp/index.html`
- CLI poller: `php adm/modules/max_comments/cron_poll.php --limit=20`

## Установка
1. Применить SQL: `adm/modules/max_comments/install.sql`
2. В `max_comments` заполнить MAX API key бота комментариев.
3. Открыть модуль `max_comments`, заполнить `MAX API key`, включить и выбрать каналы.
4. Сохранить настройки. Модуль создаст/проверит подписку MAX на `message_created`.
5. Если webhook не отдает `message_created`, запускать poller по cron (например, раз в минуту).

## Mini App
В папке `miniapp/` лежит стартовая заглушка с подключением:
- `https://st.max.ru/js/max-web-app.js`

Текущий экран выводит: `Добро пожаловать в фастибос`.
