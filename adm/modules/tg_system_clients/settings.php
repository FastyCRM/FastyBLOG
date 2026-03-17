<?php
/**
 * FILE: /adm/modules/tg_system_clients/settings.php
 * ROLE: Константы модуля Telegram-уведомлений для клиентов CRM
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

/**
 * TG_SYSTEM_CLIENTS_TABLE_SETTINGS
 * Таблица настроек Telegram-бота.
 */
const TG_SYSTEM_CLIENTS_TABLE_SETTINGS = 'tg_system_clients_settings';

/**
 * TG_SYSTEM_CLIENTS_TABLE_EVENTS
 * Таблица глобальных событий бота.
 */
const TG_SYSTEM_CLIENTS_TABLE_EVENTS = 'tg_system_clients_events';

/**
 * TG_SYSTEM_CLIENTS_TABLE_CLIENT_EVENTS
 * Таблица персональных on/off настроек событий по клиентам.
 */
const TG_SYSTEM_CLIENTS_TABLE_CLIENT_EVENTS = 'tg_system_clients_client_events';

/**
 * TG_SYSTEM_CLIENTS_TABLE_LINKS
 * Таблица активных привязок client -> telegram chat.
 */
const TG_SYSTEM_CLIENTS_TABLE_LINKS = 'tg_system_clients_links';

/**
 * TG_SYSTEM_CLIENTS_TABLE_LINK_TOKENS
 * Таблица одноразовых кодов привязки (4 цифры).
 */
const TG_SYSTEM_CLIENTS_TABLE_LINK_TOKENS = 'tg_system_clients_link_tokens';

/**
 * TG_SYSTEM_CLIENTS_TABLE_DISPATCH_LOG
 * Таблица журнала отправок.
 */
const TG_SYSTEM_CLIENTS_TABLE_DISPATCH_LOG = 'tg_system_clients_dispatch_log';

/**
 * TG_SYSTEM_CLIENTS_CLIENTS_TABLE
 * Таблица клиентов CRM.
 */
const TG_SYSTEM_CLIENTS_CLIENTS_TABLE = 'clients';

/**
 * TG_SYSTEM_CLIENTS_REQUESTS_TABLE
 * Таблица заявок CRM (для поиска клиентов по активности).
 */
const TG_SYSTEM_CLIENTS_REQUESTS_TABLE = 'requests';

/**
 * TG_SYSTEM_CLIENTS_ALLOWED_DO
 * Разрешённые do-действия модуля.
 */
const TG_SYSTEM_CLIENTS_ALLOWED_DO = [
  'modal_settings',
  'settings_update',
  'toggle_event',
  'generate_link',
  'unlink',
  'send_test',
  'api_send',
  'api_events',
  'api_clients_search',
];

/**
 * TG_SYSTEM_CLIENTS_EVENT_GENERAL
 * Код общего события по умолчанию.
 */
const TG_SYSTEM_CLIENTS_EVENT_GENERAL = 'general';

/**
 * События клиента по заявкам.
 */
const TG_SYSTEM_CLIENTS_EVENT_REQUEST_ACCEPTED = 'requests_client_accepted';
const TG_SYSTEM_CLIENTS_EVENT_REQUEST_CONFIRMED = 'requests_client_confirmed';
const TG_SYSTEM_CLIENTS_EVENT_REQUEST_CHANGED = 'requests_client_changed';
const TG_SYSTEM_CLIENTS_EVENT_REQUEST_REMINDER_60M = 'requests_client_reminder_60m';
const TG_SYSTEM_CLIENTS_EVENT_REQUEST_THANKS = 'requests_client_thanks';
const TG_SYSTEM_CLIENTS_EVENT_PROMOTIONS = 'client_promotions';