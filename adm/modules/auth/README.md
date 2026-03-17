# auth

Модуль авторизации (вход/выход) для админки CRM.

## Точки входа
- UI: `/adm/index.php?m=auth`
- login: `/adm/index.php?m=auth&do=login`
- logout: `/adm/index.php?m=auth&do=logout`

## Разрешенные do
- `login`
- `logout`

## Файлы
- VIEW: `adm/modules/auth/auth.php`
- Router: `adm/modules/auth/assets/php/main.php`
- Actions: `adm/modules/auth/assets/php/auth_login.php`, `adm/modules/auth/assets/php/auth_logout.php`

## БД
Отдельного `install.sql` у модуля нет. Используются системные таблицы пользователей/ролей из общей схемы проекта.
