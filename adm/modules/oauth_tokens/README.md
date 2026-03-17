# oauth_tokens

Модуль хранения OAuth client_id/client_secret и обновления access_token через OAuth flow.

## Роли
- `admin`: создание, редактирование, удаление, назначение пользователя, запуск OAuth.
- `user`: видит только назначенный токен и может запускать обновление.

## Роутинг (канон)
- VIEW: `/adm/index.php?m=oauth_tokens`
- add: `/adm/index.php?m=oauth_tokens&do=add`
- update: `/adm/index.php?m=oauth_tokens&do=update`
- del: `/adm/index.php?m=oauth_tokens&do=del`
- start: `/adm/index.php?m=oauth_tokens&do=start`
- callback: `/adm/index.php?m=oauth_tokens&do=callback`

## Установка
1. Выполнить вручную SQL: `adm/modules/oauth_tokens/install.sql`.
2. Проверить запись в таблице `modules` (code=`oauth_tokens`).
3. В Яндекс OAuth указать Redirect URI:
   - `https://YOUR-DOMAIN/adm/index.php?m=oauth_tokens&do=callback`

Важно: модуль не создаёт/не меняет таблицы в runtime-коде.
