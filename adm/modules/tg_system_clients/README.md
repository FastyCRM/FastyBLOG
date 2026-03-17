# tg_system_clients

Модуль Telegram-уведомлений для клиентов CRM.

## Что делает
1. Хранит настройки клиентского Telegram-бота (token/webhook/TTL/retention).
2. Позволяет включать/выключать системные клиентские события глобально.
3. Позволяет привязывать Telegram-чат клиента по короткому коду (4 цифры).
4. Даёт единый вызов отправки из кода: `sendClientTG('Сообщение')`.
5. Поддерживает выборочную отправку по клиентам (`client_ids`).

## Ручная установка
1. Выполнить SQL вручную:
   - `adm/modules/tg_system_clients/install.sql`
2. Включить модуль в «Модули» (если выключен).
3. В настройках модуля задать `bot_token`, `webhook_url`, `webhook_secret`.

Важно: модуль не создаёт и не изменяет схему БД в runtime.

### Миграция со старой схемы
Если ранее применялся старый вариант с полями `user_id`, выполните вручную:
- `adm/modules/tg_system_clients/update_from_user_schema.sql`

## Точки входа
- Модуль: `/adm/index.php?m=tg_system_clients`
- Webhook: `/core/telegram_webhook_system_clients.php`
- Внутренний API отправки:
  - `/core/internal_api.php?m=tg_system_clients&do=api_send`
  - параметры: `message`, `event_code` (опц.), `client_ids` (опц.)

## События (базовые)
- `general`
- `requests_client_accepted`
- `requests_client_confirmed`
- `requests_client_changed`
- `requests_client_reminder_60m`
- `requests_client_thanks`
- `client_promotions`

## Быстрый вызов из кода
```php
sendClientTG('Напоминание о визите', 'requests_client_reminder_60m');
sendClientTG('Акция недели', 'client_promotions', ['client_ids' => [123, 456]]);
```

## Привязка клиента
1. Менеджер/админ генерирует 4-значный код в модуле.
2. Клиент отправляет этот код боту обычным сообщением.
3. Бот привязывает chat_id к `client_id`.

## Таблицы модуля
1. `tg_system_clients_settings`
2. `tg_system_clients_events`
3. `tg_system_clients_client_events`
4. `tg_system_clients_links`
5. `tg_system_clients_link_tokens`
6. `tg_system_clients_dispatch_log`
