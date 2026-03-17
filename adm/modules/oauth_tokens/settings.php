<?php
/**
 * FILE: /adm/modules/oauth_tokens/settings.php
 * ROLE: Паспорт и константы модуля oauth_tokens (без бизнес-логики)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

/**
 * OAUTH_TOKENS_MODULE_CODE — код модуля.
 */
const OAUTH_TOKENS_MODULE_CODE = 'oauth_tokens';

/**
 * OAUTH_TOKENS_TABLE — таблица OAuth-токенов.
 */
const OAUTH_TOKENS_TABLE = 'oauth_tokens';

/**
 * OAUTH_TOKENS_USERS_TABLE — связка токена и пользователя CRM.
 */
const OAUTH_TOKENS_USERS_TABLE = 'oauth_token_users';

/**
 * OAUTH_TOKENS_STATE_SESSION_KEY — ключ хранения OAuth state в сессии.
 */
const OAUTH_TOKENS_STATE_SESSION_KEY = 'oauth_tokens_state';

/**
 * OAUTH_TOKENS_STATE_TTL_SECONDS — TTL state для callback.
 */
const OAUTH_TOKENS_STATE_TTL_SECONDS = 600;

/**
 * OAUTH_TOKENS_YANDEX_AUTH_URL — URL авторизации Яндекс OAuth.
 */
const OAUTH_TOKENS_YANDEX_AUTH_URL = 'https://oauth.yandex.ru/authorize';

/**
 * OAUTH_TOKENS_YANDEX_TOKEN_URL — URL обмена code на access_token.
 */
const OAUTH_TOKENS_YANDEX_TOKEN_URL = 'https://oauth.yandex.ru/token';

/**
 * OAUTH_TOKENS_ALLOWED_DO — разрешённые do-действия модуля.
 */
const OAUTH_TOKENS_ALLOWED_DO = [
  'add',
  'update',
  'del',
  'start',
  'callback',
];
