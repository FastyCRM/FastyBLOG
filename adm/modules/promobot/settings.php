<?php
/**
 * FILE: /adm/modules/promobot/settings.php
 * ROLE: Паспорт модуля promobot (константы, таблицы, allow-list do).
 * RULES:
 *  - только параметры и константы;
 *  - без ACL и бизнес-логики.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

/**
 * PROMOBOT_MODULE_CODE
 * Код модуля для роутинга и аудита.
 */
const PROMOBOT_MODULE_CODE = 'promobot';

/**
 * PROMOBOT_TABLE_SETTINGS
 * Таблица настроек модуля.
 */
const PROMOBOT_TABLE_SETTINGS = 'promobot_settings';

/**
 * PROMOBOT_TABLE_BOTS
 * Таблица ботов модуля.
 */
const PROMOBOT_TABLE_BOTS = 'promobot_bots';

/**
 * PROMOBOT_TABLE_CHANNELS
 * Таблица привязанных каналов/чатов.
 */
const PROMOBOT_TABLE_CHANNELS = 'promobot_channels';

/**
 * PROMOBOT_TABLE_PROMOS
 * Таблица промокодов/ответов.
 */
const PROMOBOT_TABLE_PROMOS = 'promobot_promos';

/**
 * PROMOBOT_TABLE_USER_ACCESS
 * Таблица доступа пользователей к ботам.
 */
const PROMOBOT_TABLE_USER_ACCESS = 'promobot_user_access';

/**
 * PROMOBOT_TABLE_BIND_TOKENS
 * Таблица кодов привязки чатов.
 */
const PROMOBOT_TABLE_BIND_TOKENS = 'promobot_bind_tokens';

/**
 * PROMOBOT_TABLE_LOGS
 * Таблица логов входящих сообщений и ответов.
 */
const PROMOBOT_TABLE_LOGS = 'promobot_logs';

/**
 * PROMOBOT_TABLES
 * Полный список таблиц для do=install_db.
 */
const PROMOBOT_TABLES = [
  PROMOBOT_TABLE_SETTINGS,
  PROMOBOT_TABLE_BOTS,
  PROMOBOT_TABLE_CHANNELS,
  PROMOBOT_TABLE_PROMOS,
  PROMOBOT_TABLE_USER_ACCESS,
  PROMOBOT_TABLE_BIND_TOKENS,
  PROMOBOT_TABLE_LOGS,
];

/**
 * PROMOBOT_REQUIRED_TABLES
 * Таблицы, необходимые для runtime.
 */
const PROMOBOT_REQUIRED_TABLES = [
  PROMOBOT_TABLE_SETTINGS,
  PROMOBOT_TABLE_BOTS,
  PROMOBOT_TABLE_CHANNELS,
  PROMOBOT_TABLE_PROMOS,
  PROMOBOT_TABLE_USER_ACCESS,
  PROMOBOT_TABLE_BIND_TOKENS,
];

/**
 * PROMOBOT_ALLOWED_DO
 * Разрешённые do-действия модуля.
 */
const PROMOBOT_ALLOWED_DO = [
  'install_db',
  'settings_toggle_log',

  'modal_bot_add',
  'modal_bot_update',
  'bot_add',
  'bot_update',
  'bot_toggle',
  'bot_delete',
  'bot_webhook_set',

  'bind_code_generate',
  'channel_toggle',
  'channel_unbind',

  'modal_promo_add',
  'modal_promo_update',
  'promo_add',
  'promo_update',
  'promo_toggle',
  'promo_delete',

  'user_attach',
  'user_detach',
];

/**
 * PROMOBOT_PLATFORM_TG
 * Код платформы Telegram.
 */
const PROMOBOT_PLATFORM_TG = 'tg';

/**
 * PROMOBOT_PLATFORM_MAX
 * Код платформы MAX.
 */
const PROMOBOT_PLATFORM_MAX = 'max';

/**
 * PROMOBOT_BIND_CODE_TTL_MINUTES
 * Время жизни кода привязки в минутах.
 */
const PROMOBOT_BIND_CODE_TTL_MINUTES = 30;

/**
 * PROMOBOT_MANAGE_ROLES
 * Роли управления модулем.
 */
const PROMOBOT_MANAGE_ROLES = ['admin', 'manager'];