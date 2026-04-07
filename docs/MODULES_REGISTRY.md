# MODULES_REGISTRY

Источник: `crm2026.sql` (таблица `modules`) + файловая структура.

## 0) UI-правило для новых модулей
- При создании нового модуля в этом же изменении обязательно создать `adm/modules/<module_code>/README.md` и добавить модуль в этот реестр (`docs/MODULES_REGISTRY.md`).
- Новые модули строятся на системной 12-колоночной сетке из `adm/view/assets/css/base/main_grid.css`.
- Базовый layout: `.l-row` + `.l-col--*` (с адаптивными модификаторами `.l-col--xl-*`, `.l-col--lg-*`, `.l-col--sm-*`).
- По умолчанию использовать только глобальные стили `adm/view/assets/css`; модульный CSS допускается только в крайнем случае и по согласованию.
- Локальные классы модуля использовать вместе с grid-классами, а не вместо них.
- Не вводить отдельную “базовую” сетку модуля вместо системной.
- В формах по умолчанию использовать нативный `.select`; `data-ui-select="1"` — только в крайнем случае и по согласованию.
- Системную модалку `App.modal` использовать только по отдельному согласованию.
- Для адаптивных таблиц использовать схему `table-wrap table-wrap--compact-grid` + `table table--compact-grid` (без `table--modules`, если нужен режим без горизонтального скролла).
- Кнопку добавления (`+`) в шапке модуля выравнивать вправо.
- Эталон: `adm/modules/dashboard/dashboard.php`.

## 1) Сводная таблица
| code | name | enabled | menu | roles | has_settings |
|---|---|---:|---:|---|---:|
| auth | Авторизация | 1 | 0 | admin, manager, user | 1 |
| modules | Модули | 1 | 1 | admin | 0 |
| dashboard | Главная | 1 | 1 | admin, manager, user | 1 |
| users | Пользователи | 1 | 1 | admin, manager | 0 |
| subguard | Subscription Guard (stub) | 0 | 0 | admin | 0 |
| clients | Клиенты | 1 | 1 | admin, manager | 0 |
| personal_file | Личное дело | 1 | 1 | admin, manager, user, specialist | 1 |
| services | Услуги | 1 | 1 | admin, manager | 0 |
| requests | Заявки | 1 | 1 | admin, manager, user, specialist | 1 |
| calendar | Календарь | 1 | 1 | admin, manager, user, specialist | 1 |
| tg_system_users | TG системные | 1 | 1 | admin, manager | 1 |
| tg_system_clients | TG клиенты | 1 | 1 | admin, manager | 1 |
| oauth_tokens | OAuth токены | 1 | 1 | admin, user | 0 |
| ym_link_bot | YM Link Bot | 1 | 1 | admin, manager, user | 0 |
| bot_adv_calendar | Bot Adv Calendar | 1 | 1 | admin, manager | 1 |
| promobot | Промобот | 1 | 1 | admin, manager, user | 0 |
| channel_bridge | Channel Bridge | — | — | — | — |

> `roles` — из `modules.roles` (JSON). `has_settings` означает, что настройки есть и открываются через шестерню (modal_settings).
> Для `channel_bridge` значения пока не зафиксированы в `crm2026.sql` (модуль есть в файловой структуре, но не найден в дампе таблицы `modules`).

---

## 2) Модули поштучно

### auth
**Назначение:** вход/выход из системы (UI + form).
**Файлы:**
- VIEW: `adm/modules/auth/auth.php`
- settings: `adm/modules/auth/settings.php`
- router: `adm/modules/auth/assets/php/main.php`
- actions: `auth_login.php`, `auth_logout.php`
**Core‑зависимости:** `auth_login_by_phone`, `auth_logout`, `csrf_*`, `flash`, `redirect`.

### modules
**Назначение:** включение/выключение модулей.
**Файлы:**
- VIEW: `adm/modules/modules/modules.php`
- settings: `adm/modules/modules/settings.php`
- router: `adm/modules/modules/assets/php/main.php`
- actions: `modules_toggle.php`
**Core‑зависимости:** `db`, `acl_guard`, `modules_is_protected`, `audit_log`, `csrf_*`, `flash`.

### dashboard
**Назначение:** стартовый экран админки.
**Файлы:**
- VIEW: `adm/modules/dashboard/dashboard.php`
- settings: `adm/modules/dashboard/settings.php`
- router: `adm/modules/dashboard/assets/php/main.php`
- actions/modals: `dashboard_modal_settings.php`, `dashboard_profile_update.php`
- libs: `dashboard_lib.php`
**Core‑зависимости:** `db`, `acl_guard`, `auth_user_id`, `auth_user_roles`, `csrf_*`, `audit_log`, `flash`, `redirect_return`, `url`, `h`.
**Правило ролевой видимости (UI):**
- `user` (без `admin`/`manager`): показывать только 1 и 3 линию dashboard.
- `admin`, `manager`: показывать 1, 2 и 3 линию dashboard.

### users
**Назначение:** управление пользователями и ролями.
**Файлы:**
- VIEW: `adm/modules/users/users.php`
- settings: `adm/modules/users/settings.php`
- router: `adm/modules/users/assets/php/main.php`
- actions: `users_add.php`, `users_update.php`, `users_toggle.php`, `users_reset_password.php`
- modals: `users_modal_add.php`, `users_modal_update.php`
**Core‑зависимости:** `db`, `acl_guard`, `csrf_*`, `audit_log`, `flash`, `mailer_send`.

### clients
**Назначение:** база клиентов (CRUD + статус).
**Файлы:**
- VIEW: `adm/modules/clients/clients.php`
- settings: `adm/modules/clients/settings.php`
- router: `adm/modules/clients/assets/php/main.php`
- actions: `clients_add.php`, `clients_update.php`, `clients_toggle.php`, `clients_reset_password.php`
- modals: `clients_modal_add.php`, `clients_modal_update.php`
**Core‑зависимости:** `db`, `acl_guard`, `csrf_*`, `audit_log`, `flash`, `mailer_send`.

### personal_file
**Назначение:** личное дело клиента (карточка, заметки, файлы, история услуг, доступы).
**Файлы:**
- VIEW: `adm/modules/personal_file/personal_file.php`
- settings: `adm/modules/personal_file/settings.php`
- router: `adm/modules/personal_file/assets/php/main.php`
- actions: `personal_file_client_update.php`, `personal_file_note_add.php`, `personal_file_notes_pdf.php`,
  `personal_file_access_add.php`, `personal_file_access_delete.php`, `personal_file_access_reveal.php`,
  `personal_file_file_get.php`
- settings actions: `personal_file_settings_type_add.php`, `personal_file_settings_type_update.php`, `personal_file_settings_type_delete.php`,
  `personal_file_settings_ttl_add.php`, `personal_file_settings_ttl_update.php`, `personal_file_settings_ttl_delete.php`
- modals: `personal_file_modal_settings.php`
- API: `personal_file_api_link.php`, `personal_file_api_brief.php`, `personal_file_api_notes.php`,
  `personal_file_api_services.php`, `personal_file_api_accesses.php`
- libs: `personal_file_lib.php`
**Core‑зависимости:** `db`, `acl_guard`, `csrf_*`, `audit_log`, `flash`, `redirect_return`, `json_ok/json_err`, `url`, `h`, `crypto_*`.
**Фишки:**
- ролевой доступ к клиентам: `admin/manager` видят все, `user/specialist` — только своих клиентов с заявками `confirmed/in_work`;
- логины/пароли доступов хранятся в шифрованном виде;
- заметки поддерживают вложения и выгрузку PDF.
**Подробно:** `docs/PERSONAL_FILE.md`.

### services
**Назначение:** категории и услуги; привязка услуг к специалистам.
**Файлы:**
- VIEW: `adm/modules/services/services.php`
- settings: `adm/modules/services/settings.php`
- router: `adm/modules/services/assets/php/main.php`
- actions: `services_category_add.php`, `services_category_update.php`, `services_category_delete.php`,
  `services_service_add.php`, `services_service_update.php`, `services_service_delete.php`
- modals: `services_modal_category_add.php`, `services_modal_category_update.php`,
  `services_modal_service_add.php`, `services_modal_service_update.php`
- libs: `services_lib.php`
**Core‑зависимости:** `db`, `acl_guard`, `csrf_*`, `audit_log`, `flash`.
**Фишки:** категория «Без категории» создаётся автоматически и выключается, если пустая.

### requests
**Назначение:** канбан заявок + внешняя форма + счета/акт/договор при закрытии.
**Файлы:**
- VIEW: `adm/modules/requests/requests.php`
- settings: `adm/modules/requests/settings.php`
- router: `adm/modules/requests/assets/php/main.php`
- actions: `requests_add.php`, `requests_confirm.php`, `requests_take.php`, `requests_done.php`,
  `requests_archive.php`, `requests_comment_add.php`, `requests_settings_update.php`
- modals: `requests_modal_add.php`, `requests_modal_view.php`, `requests_modal_settings.php`
- API: `requests_api_services.php`, `requests_api_request_add.php`
- libs: `requests_lib.php`
**Core‑зависимости:** `db`, `acl_guard`, `csrf_*`, `audit_log`, `flash`, `mailer_send`, `sms_send` (stub).
**Фишки:**
- режим специалистов + режим интервалов;
- авто‑создание клиента;
- защита от дублей через `slot_key`;
- формирование счетов и позиций;
- свободные услуги создаются в `services` и попадают в «Без категории».

### calendar
**Назначение:** календарь/расписание на основе заявок (view‑only).
**Файлы:**
- VIEW: `adm/modules/calendar/calendar.php`
- settings: `adm/modules/calendar/settings.php`
- router: `adm/modules/calendar/assets/php/main.php`
- actions: `calendar_settings_update.php`
- modals: `calendar_modal_settings.php`, `calendar_modal_manager_settings.php`
- API: `calendar_api_main.php`, `api/calendar_api_specialists.php`, `api/calendar_api_slots_day.php`, `api/calendar_api_slots_nearest.php`
- libs: `calendar_lib.php`
**Core‑зависимости:** `db`, `acl_guard`, `csrf_*`, `url`, `h`.
**Фишки:**
- 2 режима (специалист/менеджер);
- персональные настройки режима (таблица `calendar_user_settings`);
- мини‑календарь для выбора даты (без записи в БД).

### oauth_tokens
**Назначение:** хранение OAuth-клиентов и обновление `access_token` через OAuth flow.
**Файлы:**
- VIEW: `adm/modules/oauth_tokens/oauth_tokens.php`
- settings: `adm/modules/oauth_tokens/settings.php`
- router: `adm/modules/oauth_tokens/assets/php/main.php`
- actions: `oauth_tokens_add.php`, `oauth_tokens_update.php`, `oauth_tokens_del.php`, `oauth_tokens_start.php`, `oauth_tokens_callback.php`
- libs: `oauth_tokens_lib.php`
**Core‑зависимости:** `db`, `acl_guard`, `csrf_*`, `audit_log`, `flash`, `redirect`, `json_ok/json_err`, `url`.
**Фишки:**
- роли: `admin` управляет токенами, `user` может запускать OAuth только для назначенного токена;
- OAuth callback с `state`-проверкой и TTL;
- ручная установка схемы через `adm/modules/oauth_tokens/install.sql` (без runtime DDL).

### ym_link_bot
**Назначение:** Telegram-бот для обработки постов/чатов и сборки финальных ссылок (включая интеграции OAuth/ORD).
**Файлы:**
- VIEW: `adm/modules/ym_link_bot/ym_link_bot.php`
- settings: `adm/modules/ym_link_bot/settings.php`
- router: `adm/modules/ym_link_bot/assets/php/main.php`
- webhook: `adm/modules/ym_link_bot/webhook.php`, `adm/modules/ym_link_bot/chat_webhook.php`
- API actions: `ym_link_bot_api_*.php`
- libs: `ym_link_bot_lib.php`
**Core‑зависимости:** `db`, `acl_guard`, `csrf_*`, `audit_log`, `json_ok/json_err`, `telegram_api`, `market_url`, `internal_api`.
**Фишки:**
- привязки пользователей/каналов/чатов через коды подтверждения;
- CRUD площадок, управление webhook из UI, очистка фото по периоду;
- SQL-скрипты для установки/миграций: `install.sql`, `merge_chat_mode.sql`, `enable_multi_binding_channel.sql`.

### bot_adv_calendar
**Назначение:** Telegram-бот рекламного календаря с общей моделью привязки для `user` (площадка) и `client` (покупатель рекламы).
**Файлы:**
- VIEW: `adm/modules/bot_adv_calendar/bot_adv_calendar.php`
- settings: `adm/modules/bot_adv_calendar/settings.php`
- router: `adm/modules/bot_adv_calendar/assets/php/main.php`
- actions: `bot_adv_calendar_modal_settings.php`, `bot_adv_calendar_modal_generate.php`, `bot_adv_calendar_settings_update.php`, `bot_adv_calendar_webhook_set.php`, `bot_adv_calendar_generate_link.php`, `bot_adv_calendar_unlink.php`
- webhook: `adm/modules/bot_adv_calendar/webhook.php`
- libs: `bot_adv_calendar_lib.php`
**Core-зависимости:** `db`, `acl_guard`, `csrf_*`, `audit_log`, `flash`, `telegram.php`.
**Фишки (MVP):**
- отдельные коды привязки для площадок и клиентов;
- отдельный webhook файла модуля;
- первая кнопка в Telegram: `Календарь рекламы`;
- без добавления новых статусов заявок (используются существующие статусы CRM).

### promobot
**Назначение:** выдача промокодов в чатах по ключевым словам (Telegram/MAX).
**Файлы:**
- VIEW: `adm/modules/promobot/promobot.php`
- settings: `adm/modules/promobot/settings.php`
- router: `adm/modules/promobot/assets/php/main.php`
- webhooks: `adm/modules/promobot/webhook.php`, `adm/modules/promobot/max_webhook.php`
- actions/modals: `promobot_bot_*`, `promobot_promo_*`, `promobot_user_*`, `promobot_channel_*`
- libs: `promobot_lib.php`
- SQL: `adm/modules/promobot/install.sql`
**Core-зависимости:** `db`, `acl_guard`, `csrf_*`, `audit_log`, `json_ok/json_err`, `telegram.php`.
**Фишки:**
- отдельные боты TG/MAX, привязка чатов через `/bind CODE`;
- поиск по подстроке в сообщениях и выдача текста ответа;
- ролевая модель доступа к ботам через таблицу `promobot_user_access`.

### channel_bridge
**Назначение:** модуль маршрутизации сообщений между платформами (TG/VK/MAX) с правилами и журналированием.
**Файлы:**
- VIEW: `adm/modules/channel_bridge/channel_bridge.php`
- settings: `adm/modules/channel_bridge/settings.php`
- router: `adm/modules/channel_bridge/assets/php/main.php`
- actions/modals: `channel_bridge_modal_settings.php`, `channel_bridge_settings_update.php`,
  `channel_bridge_modal_route_add.php`, `channel_bridge_route_add.php`,
  `channel_bridge_modal_route_update.php`, `channel_bridge_route_update.php`,
  `channel_bridge_route_toggle.php`, `channel_bridge_route_delete.php`,
  `channel_bridge_route_test.php`, `channel_bridge_route_bind_code.php`
- API: `channel_bridge_api_ingest.php`, `channel_bridge_api_tg_webhook.php`
- libs: `channel_bridge_lib.php`
- SQL: `adm/modules/channel_bridge/install.sql`
**Core‑зависимости:** `db`, `acl_guard`, `csrf_*`, `audit_log`, `json_ok/json_err`, интеграционные клиенты Telegram/VK/MAX (через функции модуля).
**Примечание:** статус включения и роли нужно зафиксировать в таблице `modules` (или в следующем дампе БД), чтобы синхронизировать с реестром.

### subguard
**Назначение:** заглушка подписки (stub).
**Файлы:** `adm/modules/subguard/*`.

---

## Telegram modules (new)

### tg_system_users
**Назначение:** системные Telegram-уведомления для сотрудников.
**Точки:**
- UI: `adm/index.php?m=tg_system_users`
- webhook: `core/telegram_webhook_system_users.php`
- helper: `sendSystemTG(string $message, string $eventCode = 'general', array $options = [])`

### tg_system_clients
**Назначение:** Telegram-уведомления для клиентов CRM.
**Точки:**
- UI: `adm/index.php?m=tg_system_clients`
- webhook: `core/telegram_webhook_system_clients.php`
- helper: `sendClientTG(string $message, string $eventCode = 'general', array $options = [])`

Примечание: установка таблиц обоих модулей выполняется только вручную SQL-скриптами (`install.sql`), без runtime DDL.

---

## I18N migration status
Source of truth: `docs/I18N_MODULES_ROADMAP.md`.

Summary:
- done: `dashboard`, `auth`, `modules`, `users`, `clients`, `services`
- next: `requests`
- todo: `calendar`, `personal_file`, `tg_system_users`, `tg_system_clients`, `oauth_tokens`, `ym_link_bot`, `bot_adv_calendar`, `subguard`, `channel_bridge`
