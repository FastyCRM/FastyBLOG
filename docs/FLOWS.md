# FLOWS

## 1) Login flow (adm)
1. Пользователь идёт на `/adm/index.php`.
2. Загружается `core/bootstrap.php` (сессия, конфиг, БД, auth, acl).
3. Если не авторизован — модуль `auth`.
4. `auth_login.php` проверяет CSRF, логин/пароль, статус пользователя.
5. Успех → redirect `?m=dashboard`, ошибка → flash + назад на `?m=auth`.

## 2) Module render flow
1. `/adm/index.php` → `/adm/view/index.php`.
2. По `m` выбирается модуль.
3. Подключается `adm/modules/<m>/<m>.php` (VIEW only).
4. VIEW не содержит логики БД/CRUD.

## 3) Action flow (do)
1. Запрос вида `?m=<module>&do=<action>`.
2. `/adm/modules/<module>/assets/php/main.php` проверяет allow‑list `*_ALLOWED_DO`.
3. Подключается файл `assets/php/<module>_<do>.php`.
4. В action:
   - `acl_guard(...)`
   - `csrf_check(...)` (если POST)
   - логика + `audit_log()`
   - `json_ok/json_err` или `flash + redirect`.

## 4) ACL flow
1. Роли берутся из `user_roles`.
2. Доступ к модулю — по `modules.roles` (JSON) через `module_allowed_roles()`.
3. В каждом action и modal — `acl_guard()`.

## 5) Audit/Log flow
1. Все значимые события → `audit_log()`.
2. Запись идёт в БД (`audit_log`).
3. При ошибке БД — fallback в `logs/audit-fallback.log`.

## 6) Internal API flow
1. Вызов: `/core/internal_api.php?m=<module>&do=api_*`.
2. Проверка ключа (если задан в `core/config.php` → `internal_api.key`).
3. Роутинг через `adm/modules/<module>/assets/php/main.php`.
4. Только JSON‑ответы (`json_ok/json_err`).

## 7) Notification flow
- Почта: `core/mailer.php` (SMTP, config → `core/config.php`).
- SMS: заглушка `core/sms.php` (если подключён).
- Telegram: `core/telegram.php` (если используется в модулях).

## Telegram flow (users + clients)
1. Менеджер/админ генерирует в модуле код привязки (4 цифры).
2. Пользователь/клиент отправляет этот код боту обычным сообщением.
3. Webhook сохраняет связь `chat_id` с `user_id` или `client_id`.
4. Бизнес-модули отправляют события через helper:
   - `sendSystemTG(...)` для сотрудников,
   - `sendClientTG(...)` для клиентов.
5. Доставка учитывает глобальные события и персональные on/off-флаги.