# services

Модуль услуг: категории, услуги и привязка услуг к специалистам.

## Точки входа
- UI: `/adm/index.php?m=services`
- Действия выполняются через `do` внутри модуля (`category_*`, `service_*`).

## Разрешенные do
- `modal_category_add`, `category_add`
- `modal_category_update`, `category_update`, `category_delete`
- `modal_service_add`, `service_add`
- `modal_service_update`, `service_update`, `service_delete`

## Файлы
- VIEW: `adm/modules/services/services.php`
- Router: `adm/modules/services/assets/php/main.php`
- Lib: `adm/modules/services/assets/php/services_lib.php`

## БД
Отдельного `install.sql` у модуля нет. Используются таблицы `services`, `specialties`, `specialty_services`, `user_services`.
