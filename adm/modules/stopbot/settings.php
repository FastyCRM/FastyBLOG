<?php
/**
 * FILE: /adm/modules/stopbot/settings.php
 * ROLE: Паспорт модуля stopbot (константы, таблицы, allow-list do).
 * RULES:
 *  - только параметры и константы;
 *  - без ACL и бизнес-логики.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

/**
 * STOPBOT_MODULE_CODE
 * Код модуля для роутинга и аудита.
 */
const STOPBOT_MODULE_CODE = 'stopbot';

/**
 * STOPBOT_TABLE_SETTINGS
 * Таблица настроек модуля.
 */
const STOPBOT_TABLE_SETTINGS = 'stopbot_settings';

/**
 * STOPBOT_TABLE_BOTS
 * Таблица ботов модуля.
 */
const STOPBOT_TABLE_BOTS = 'stopbot_bots';

/**
 * STOPBOT_TABLE_CHANNELS
 * Таблица привязанных каналов/чатов.
 */
const STOPBOT_TABLE_CHANNELS = 'stopbot_channels';

/**
 * STOPBOT_TABLE_CHAT_ADMINS
 * Локальный кеш администраторов MAX-чатов.
 */
const STOPBOT_TABLE_CHAT_ADMINS = 'stopbot_chat_admins';

/**
 * STOPBOT_TABLE_PROMOS
 * Таблица промокодов/ответов.
 */
const STOPBOT_TABLE_PROMOS = 'stopbot_promos';

/**
 * STOPBOT_TABLE_USER_ACCESS
 * Таблица доступа пользователей к ботам.
 */
const STOPBOT_TABLE_USER_ACCESS = 'stopbot_user_access';

/**
 * STOPBOT_TABLE_BIND_TOKENS
 * Таблица кодов привязки чатов.
 */
const STOPBOT_TABLE_BIND_TOKENS = 'stopbot_bind_tokens';

/**
 * STOPBOT_TABLE_LOGS
 * Таблица логов входящих сообщений и ответов.
 */
const STOPBOT_TABLE_LOGS = 'stopbot_logs';

/**
 * STOPBOT_TABLES
 * Полный список таблиц для do=install_db.
 */
const STOPBOT_TABLES = [
  STOPBOT_TABLE_SETTINGS,
  STOPBOT_TABLE_BOTS,
  STOPBOT_TABLE_CHANNELS,
  STOPBOT_TABLE_CHAT_ADMINS,
  STOPBOT_TABLE_PROMOS,
  STOPBOT_TABLE_USER_ACCESS,
  STOPBOT_TABLE_BIND_TOKENS,
  STOPBOT_TABLE_LOGS,
];

/**
 * STOPBOT_REQUIRED_TABLES
 * Таблицы, необходимые для runtime.
 */
const STOPBOT_REQUIRED_TABLES = [
  STOPBOT_TABLE_SETTINGS,
  STOPBOT_TABLE_BOTS,
  STOPBOT_TABLE_CHANNELS,
  STOPBOT_TABLE_CHAT_ADMINS,
  STOPBOT_TABLE_PROMOS,
  STOPBOT_TABLE_USER_ACCESS,
  STOPBOT_TABLE_BIND_TOKENS,
];

/**
 * STOPBOT_ALLOWED_DO
 * Разрешённые do-действия модуля.
 */
const STOPBOT_ALLOWED_DO = [
  'install_db',
  'settings_toggle_log',
  'settings_rules_save',

  'modal_bot_add',
  'modal_bot_update',
  'bot_add',
  'bot_update',
  'bot_toggle',
  'bot_delete',
  'bot_webhook_set',
  'bot_max_webhook_set',
  'bot_max_webhook_info',

  'bind_code_generate',
  'channel_probe',
  'channel_attach',
  'channel_admins_refresh',
  'channel_admin_delete',
  'channel_toggle',
  'channel_unbind',

  'modal_promo_add',
  'modal_promo_update',
  'promo_add',
  'promo_update',
  'promo_toggle',
  'promo_delete',
  'rule_add',
  'rule_delete',

  'user_attach',
  'user_detach',
];

/**
 * STOPBOT_PLATFORM_TG
 * Код платформы Telegram.
 */
const STOPBOT_PLATFORM_TG = 'tg';

/**
 * STOPBOT_PLATFORM_MAX
 * Код платформы MAX.
 */
const STOPBOT_PLATFORM_MAX = 'max';

/**
 * STOPBOT_BIND_CODE_TTL_MINUTES
 * Время жизни кода привязки в минутах.
 */
const STOPBOT_BIND_CODE_TTL_MINUTES = 30;

/**
 * STOPBOT_MANAGE_ROLES
 * Роли управления модулем.
 */
const STOPBOT_MANAGE_ROLES = ['admin', 'manager'];
