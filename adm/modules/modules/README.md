# modules

Системный модуль управления включением/выключением модулей CRM.

## Точки входа
- UI: `/adm/index.php?m=modules`
- toggle: `/adm/index.php?m=modules&do=toggle`
- update icon: `/adm/index.php?m=modules&do=update_icon`

## Разрешенные do
- `view`
- `toggle`
- `update_icon`

## Файлы
- VIEW: `adm/modules/modules/modules.php`
- Router: `adm/modules/modules/assets/php/main.php`
- Actions: `adm/modules/modules/assets/php/modules_toggle.php`, `adm/modules/modules/assets/php/modules_update_icon.php`

## БД
Отдельного `install.sql` у модуля нет. Источник истины по модульным флагам: таблица `modules`.
