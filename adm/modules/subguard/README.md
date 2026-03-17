# subguard

Служебный модуль-заглушка (stub) для контуров подписки.

## Назначение
- Модуль присутствует в реестре модулей и может включаться/выключаться через таблицу `modules`.
- Бизнес-действий не содержит.

## Точки входа
- UI: `/adm/index.php?m=subguard`

## Разрешенные do
- `view`

## Файлы
- VIEW: `adm/modules/subguard/subguard.php`
- Router: `adm/modules/subguard/assets/php/main.php`
- Settings: `adm/modules/subguard/settings.php`

## БД
Отдельного `install.sql` у модуля нет.
