<?php
/**
 * FILE: /adm/modules/tg_system_users/settings.php
 * ROLE: Константы модуля Telegram-уведомлений для сотрудников
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

/**
 * TG_SYSTEM_USERS_TABLE_SETTINGS
 * Таблица настроек Telegram-бота.
 */
const TG_SYSTEM_USERS_TABLE_SETTINGS = 'tg_system_users_settings';

/**
 * TG_SYSTEM_USERS_TABLE_EVENTS
 * Таблица системных событий (глобальные on/off).
 */
const TG_SYSTEM_USERS_TABLE_EVENTS = 'tg_system_users_events';

/**
 * TG_SYSTEM_USERS_TABLE_USER_EVENTS
 * Таблица персональных настроек событий по пользователям.
 */
const TG_SYSTEM_USERS_TABLE_USER_EVENTS = 'tg_system_users_user_events';

/**
 * TG_SYSTEM_USERS_TABLE_LINKS
 * Таблица привязок user -> telegram chat.
 */
const TG_SYSTEM_USERS_TABLE_LINKS = 'tg_system_users_links';

/**
 * TG_SYSTEM_USERS_TABLE_LINK_TOKENS
 * Таблица одноразовых кодов привязки (4 цифры).
 */
const TG_SYSTEM_USERS_TABLE_LINK_TOKENS = 'tg_system_users_link_tokens';

/**
 * TG_SYSTEM_USERS_TABLE_DISPATCH_LOG
 * Таблица журнала отправок.
 */
const TG_SYSTEM_USERS_TABLE_DISPATCH_LOG = 'tg_system_users_dispatch_log';

/**
 * TG_SYSTEM_USERS_USERS_TABLE
 * Таблица пользователей CRM.
 */
const TG_SYSTEM_USERS_USERS_TABLE = 'users';

/**
 * TG_SYSTEM_USERS_ROLES_TABLE
 * Таблица ролей.
 */
const TG_SYSTEM_USERS_ROLES_TABLE = 'roles';

/**
 * TG_SYSTEM_USERS_USER_ROLES_TABLE
 * Таблица связей пользователей и ролей.
 */
const TG_SYSTEM_USERS_USER_ROLES_TABLE = 'user_roles';

/**
 * TG_SYSTEM_USERS_ALLOWED_DO
 * Разрешённые do-действия модуля.
 */
const TG_SYSTEM_USERS_ALLOWED_DO = [
  'modal_settings',
  'settings_update',
  'toggle_event',
  'generate_link',
  'unlink',
  'send_test',
  'api_send',
  'api_events',
];

/**
 * TG_SYSTEM_USERS_EVENT_GENERAL
 * Код события по умолчанию для универсальной рассылки.
 */
const TG_SYSTEM_USERS_EVENT_GENERAL = 'general';
