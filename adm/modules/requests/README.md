# requests

Модуль заявок CRM: канбан, статусы, комментарии, сервисные API и настройки.

## Точки входа
- UI: `/adm/index.php?m=requests`
- API:
  - `/adm/index.php?m=requests&do=api_services`
  - `/adm/index.php?m=requests&do=api_request_add`
  - `/adm/index.php?m=requests&do=api_clients_lookup`
  - `/adm/index.php?m=requests&do=api_notify_status`

## Разрешенные do (основные)
- `modal_add`, `modal_view`, `modal_settings`
- `add`, `confirm`, `take`, `done`, `reassign`, `comment_add`, `settings_update`
- `api_services`, `api_request_add`, `api_clients_lookup`, `api_notify_status`

## Файлы
- VIEW: `adm/modules/requests/requests.php`
- Router: `adm/modules/requests/assets/php/main.php`
- Lib: `adm/modules/requests/assets/php/requests_lib.php`

## Документация
Подробное описание модуля: `docs/REQUESTS.md`.

## БД
Отдельного `install.sql` у модуля нет. Используются таблицы заявок и связанная операционная схема.
