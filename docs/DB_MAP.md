# DB_MAP

## 1) Таблицы (системные)
- `users` — пользователи системы (name/phone/email/status/ui_theme/pass_hash).
- `roles` — справочник ролей (admin/manager/user/specialist).
- `user_roles` — связь многие‑ко‑многим user↔role.
- `modules` — список модулей (enabled/menu/roles/has_settings/icon/sort).
- `auth_sessions` — активные сессии/remember.
- `login_attempts` — попытки входа.
- `password_resets` — токены сброса пароля.
- `audit_log` — аудит (DB‑лог событий).

## 2) Таблицы (доменные)
### Requests
- `requests` — заявки (status, client, service, specialist, visit_at, slot_key и пр.).
- `request_history` — история действий по заявке.
- `request_comments` — комментарии к заявке.
- `request_invoices` — счета/акты/договоры по заявке.
- `request_invoice_items` — позиции счета.
- `requests_settings` — настройки модуля заявок (use_specialists, use_time_slots). 
  `calendar_*` поля считаются legacy.

### Services
- `services` — услуги (name, price, status).
- `specialties` — категории услуг.
- `specialty_services` — связь категория↔услуга.
- `user_services` — связь специалист↔услуга.

### Calendar
- `specialist_schedule` — график специалиста (weekday, time_start, time_end, slot_min, slot_duration, lead_minutes, breaks_json).
- `calendar_user_settings` — персональные настройки календаря (mode, manager_spec_ids). *(таблица создаётся модулем, может отсутствовать в дампе).*

### Personal file
- `personal_file_notes` — заметки по клиенту.
- `personal_file_note_files` — файлы, прикреплённые к заметкам.
- `personal_file_access` — доступы клиента (логин/пароль в шифрованном виде).
- `personal_file_access_types` — справочник типов доступов.
- `personal_file_access_ttls` — справочник сроков действия доступов.
- `personal_file_access_reminders` — напоминания по срокам доступов.

## 3) Ключевые связи
- `users` ↔ `roles` через `user_roles`.
- `modules.roles` (JSON) определяет ACL.
- `requests.client_id` → `clients.id`.
- `requests.service_id` → `services.id`.
- `requests.specialist_user_id` → `users.id`.
- `request_history.request_id` → `requests.id`.
- `request_comments.request_id` → `requests.id`.
- `request_invoices.request_id` → `requests.id`.
- `request_invoice_items.invoice_id` → `request_invoices.id`.
- `specialty_services.specialty_id` → `specialties.id`.
- `specialty_services.service_id` → `services.id`.
- `user_services.user_id` → `users.id` (специалист).
- `user_services.service_id` → `services.id`.
- `specialist_schedule.user_id` → `users.id` (специалист).
- `personal_file_notes.client_id` → `clients.id`.
- `personal_file_notes.user_id` → `users.id`.
- `personal_file_note_files.note_id` → `personal_file_notes.id`.
- `personal_file_note_files.client_id` → `clients.id`.
- `personal_file_access.client_id` → `clients.id`.
- `personal_file_access.type_id` → `personal_file_access_types.id`.
- `personal_file_access.ttl_id` → `personal_file_access_ttls.id`.
- `personal_file_access.created_by` → `users.id`.
- `personal_file_access_reminders.access_id` → `personal_file_access.id`.
- `personal_file_access_reminders.client_id` → `clients.id`.

## 4) Где хранится конфиг модулей
- Глобальные параметры: `core/config.php`.
- Настройки заявок: `requests_settings`.
- Персональные настройки календаря: `calendar_user_settings`.

## Telegram tables
### tg_system_users
- `tg_system_users_settings`
- `tg_system_users_events`
- `tg_system_users_user_events`
- `tg_system_users_links`
- `tg_system_users_link_tokens`
- `tg_system_users_dispatch_log`

### tg_system_clients
- `tg_system_clients_settings`
- `tg_system_clients_events`
- `tg_system_clients_client_events`
- `tg_system_clients_links`
- `tg_system_clients_link_tokens`
- `tg_system_clients_dispatch_log`

### bot_adv_calendar
- `bot_adv_calendar_settings`
- `bot_adv_calendar_links` (`actor_type`: `user|client`, `actor_id`, `chat_id`)
- `bot_adv_calendar_link_tokens` (одноразовые 6-значные коды привязки в виде SHA-256)
- `bot_adv_calendar_dispatch_log`

## Future marketing/loyalty stub
Подготовительный SQL для будущего модуля акций/скидок/лояльности:
- `docs/sql/marketing_loyalty_stub.sql`

Таблицы заготовки:
- `marketing_programs`
- `marketing_program_rules`
- `marketing_client_loyalty`
- `marketing_client_programs`
