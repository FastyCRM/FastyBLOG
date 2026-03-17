# tg_system_users

Модуль Telegram-уведомлений для сотрудников CRM (admin/manager/user/specialist).

## Что делает
1. Хранит настройки Telegram-бота сотрудников.
2. Управляет глобальными событиями уведомлений.
3. Привязывает сотрудника к Telegram по коду (4 цифры).
4. Даёт единый вызов отправки из кода: `sendSystemTG('Сообщение')`.

## Ручная установка
1. Выполнить SQL вручную:
   - `adm/modules/tg_system_users/install.sql`
2. Включить модуль в «Модули».
3. Заполнить `bot_token`, `webhook_url`, `webhook_secret` в настройках модуля.

Важно: модуль не делает `CREATE/ALTER` в runtime.

## Точки входа
- Модуль: `/adm/index.php?m=tg_system_users`
- Webhook: `/core/telegram_webhook_system_users.php`
- Внутренний API отправки:
  - `/core/internal_api.php?m=tg_system_users&do=api_send`

## Быстрый вызов
```php
sendSystemTG('Новая заявка');
sendSystemTG('Плановые работы в 23:00', 'system_updates');
```

## Привязка сотрудника
1. Менеджер/админ генерирует код (4 цифры).
2. Сотрудник отправляет код боту обычным сообщением.
3. Бот привязывает `chat_id` к `user_id`.