# PERSONAL_FILE (модуль личного дела)

## 1) Назначение
- Единая карточка клиента в админке.
- Содержит 3 вкладки: общая информация, услуги, доступы.
- Хранит заметки и файлы по клиенту.
- Показывает историю услуг из счетов (`request_invoices` + `request_invoice_items`).

## 2) Роли и доступ
- ACL входа в модуль: `modules.roles` для `personal_file` (`admin`, `manager`, `user`, `specialist`).
- `admin/manager`:
  - полный доступ к карточкам клиентов;
  - могут обновлять данные клиента;
  - управляют справочниками типов/сроков доступов;
  - могут добавлять/удалять доступы.
- `user/specialist`:
  - работают в пределах назначенных клиентов;
  - вкладка "Доступы" доступна только если есть заявка на пользователя в статусах `confirmed`/`in_work`.

## 3) Роутинг и do
- VIEW: `/adm/index.php?m=personal_file`
- Общий паттерн действий: `/adm/index.php?m=personal_file&do=<action>`

Разрешённые действия (`PERSONAL_FILE_ALLOWED_DO`):
- `modal_settings`
- `settings_type_add`, `settings_type_update`, `settings_type_delete`
- `settings_ttl_add`, `settings_ttl_update`, `settings_ttl_delete`
- `client_update`
- `note_add`, `notes_pdf`
- `access_add`, `access_delete`, `access_reveal`
- `file_get`
- `api_link`, `api_brief`, `api_notes`, `api_services`, `api_accesses`

## 4) Основные потоки
### Поиск и открытие клиента
- Поиск в шапке модуля.
- Для `admin/manager` поиск по всей базе клиентов.
- Для `user/specialist` поиск только по "своим" клиентам.

### Заметки
- `do=note_add` добавляет заметку и файлы.
- `do=notes_pdf` выгружает заметки в PDF с фильтром по периоду.

### Доступы (логин/пароль)
- `do=access_add` создаёт доступ (тип + срок + логин + пароль).
- `do=access_delete` удаляет доступ.
- `do=access_reveal` раскрывает или копирует логин/пароль по запросу UI.
- Логин/пароль сохраняются в шифрованном виде через `core/crypto.php`.

### Настройки модуля
- Только через шестерню и `do=modal_settings`.
- В модалке управляются 2 справочника:
  - типы доступов (`personal_file_access_types`);
  - сроки действия (`personal_file_access_ttls`).

## 5) API (внутримодульный AJAX)
- `GET /adm/index.php?m=personal_file&do=api_link&client_id=<id>` — ссылка на карточку.
- `GET /adm/index.php?m=personal_file&do=api_brief&client_id=<id>` — краткая карточка клиента.
- `GET /adm/index.php?m=personal_file&do=api_notes&client_id=<id>&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD` — заметки.
- `GET /adm/index.php?m=personal_file&do=api_services&client_id=<id>` — услуги клиента.
- `GET /adm/index.php?m=personal_file&do=api_accesses&client_id=<id>` — доступы (без секретов).

## 6) Таблицы
- `personal_file_notes`
- `personal_file_note_files`
- `personal_file_access`
- `personal_file_access_types`
- `personal_file_access_ttls`
- `personal_file_access_reminders`

Переиспользуемые таблицы:
- `clients`
- `requests`
- `request_comments`
- `request_invoices`
- `request_invoice_items`

## 7) Безопасность
- Во всех action-файлах: `if (!defined('ROOT_PATH')) exit;`.
- Во всех POST-экшенах: `csrf_check(...)`.
- Во всех action/modal/API: `acl_guard(...)`.
- Значимые действия логируются через `audit_log(...)`.

