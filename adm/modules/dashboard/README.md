# dashboard

Стартовый экран админки CRM.

## Точки входа
- UI: `/adm/index.php?m=dashboard`
- Настройки: `/adm/index.php?m=dashboard&do=modal_settings`
- Обновление профиля: `/adm/index.php?m=dashboard&do=profile_update`

## Разрешенные do
- `modal_settings`
- `profile_update`

## Файлы
- VIEW: `adm/modules/dashboard/dashboard.php`
- Router: `adm/modules/dashboard/assets/php/main.php`
- Lib: `adm/modules/dashboard/assets/php/dashboard_lib.php`

## БД
Отдельного `install.sql` у модуля нет. Используются таблицы пользователей и операционные таблицы общей схемы.
