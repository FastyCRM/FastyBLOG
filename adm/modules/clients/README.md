# clients

Модуль управления клиентами CRM (CRUD, статус, сброс пароля).

## Точки входа
- UI: `/adm/index.php?m=clients`
- API поиска: `/adm/index.php?m=clients&do=api_search`

## Разрешенные do
- `api_search`
- `modal_settings`, `modal_add`, `modal_update`
- `add`, `update`, `toggle`, `reset_password`, `clear`

## Файлы
- VIEW: `adm/modules/clients/clients.php`
- Router: `adm/modules/clients/assets/php/main.php`
- Actions: `adm/modules/clients/assets/php/clients_*.php`

## БД
Отдельного `install.sql` у модуля нет. Используются таблицы `clients` и связанные таблицы общей схемы.
