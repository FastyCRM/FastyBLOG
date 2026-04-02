<?php
/**
 * FILE: /adm/modules/channel_bridge/settings.php
 * ROLE: Паспорт модуля channel_bridge (константы, таблицы, allow-list do).
 * USAGE:
 *  - Подключается из VIEW и action-файлов модуля.
 *  - Хранит только параметры. Без ACL и без бизнес-логики.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

/**
 * CHANNEL_BRIDGE_MODULE_CODE
 * Код модуля для роутинга и аудита.
 */
const CHANNEL_BRIDGE_MODULE_CODE = 'channel_bridge';

/**
 * CHANNEL_BRIDGE_TABLE_SETTINGS
 * Таблица общих настроек интеграций (TG/VK/MAX + служебные флаги).
 */
const CHANNEL_BRIDGE_TABLE_SETTINGS = 'channel_bridge_settings';

/**
 * CHANNEL_BRIDGE_TABLE_ROUTES
 * Таблица правил маршрутизации "источник -> цель".
 */
const CHANNEL_BRIDGE_TABLE_ROUTES = 'channel_bridge_routes';

/**
 * CHANNEL_BRIDGE_TABLE_INBOX
 * Таблица входящих сообщений (дедупликация по source_message_id).
 */
const CHANNEL_BRIDGE_TABLE_INBOX = 'channel_bridge_inbox';

/**
 * CHANNEL_BRIDGE_TABLE_DISPATCH_LOG
 * Таблица журнала отправок по маршрутам.
 */
const CHANNEL_BRIDGE_TABLE_DISPATCH_LOG = 'channel_bridge_dispatch_log';

/**
 * CHANNEL_BRIDGE_TABLE_BIND_TOKENS
 * Таблица одноразовых кодов автопривязки chat/channel id к маршрутам.
 */
const CHANNEL_BRIDGE_TABLE_BIND_TOKENS = 'channel_bridge_bind_tokens';

/**
 * CHANNEL_BRIDGE_TABLE_LINK_SUFFIX_RULES
 * Таблица правил: root-domain -> приписка в конец сообщения.
 */
const CHANNEL_BRIDGE_TABLE_LINK_SUFFIX_RULES = 'channel_bridge_link_suffix_rules';

/**
 * CHANNEL_BRIDGE_TABLE_WEBHOOK_UPDATES
 * Таблица обработанных входящих update_id Telegram webhook.
 */
const CHANNEL_BRIDGE_TABLE_WEBHOOK_UPDATES = 'channel_bridge_webhook_updates';

/**
 * CHANNEL_BRIDGE_REQUIRED_TABLES
 * Обязательные таблицы модуля для текущего рантайма.
 * Дополнительные таблицы могут доезжать отдельным update.sql без падения webhook.
 */
const CHANNEL_BRIDGE_REQUIRED_TABLES = [
  CHANNEL_BRIDGE_TABLE_SETTINGS,
  CHANNEL_BRIDGE_TABLE_ROUTES,
  CHANNEL_BRIDGE_TABLE_INBOX,
  CHANNEL_BRIDGE_TABLE_DISPATCH_LOG,
  CHANNEL_BRIDGE_TABLE_BIND_TOKENS,
  CHANNEL_BRIDGE_TABLE_LINK_SUFFIX_RULES,
];

/**
 * CHANNEL_BRIDGE_TABLES
 * Полный список таблиц модуля для do=install_db.
 */
const CHANNEL_BRIDGE_TABLES = [
  CHANNEL_BRIDGE_TABLE_SETTINGS,
  CHANNEL_BRIDGE_TABLE_ROUTES,
  CHANNEL_BRIDGE_TABLE_INBOX,
  CHANNEL_BRIDGE_TABLE_DISPATCH_LOG,
  CHANNEL_BRIDGE_TABLE_BIND_TOKENS,
  CHANNEL_BRIDGE_TABLE_LINK_SUFFIX_RULES,
  CHANNEL_BRIDGE_TABLE_WEBHOOK_UPDATES,
];

/**
 * CHANNEL_BRIDGE_ALLOWED_DO
 * Разрешённые do-действия модуля.
 */
const CHANNEL_BRIDGE_ALLOWED_DO = [
  'modal_settings',
  'settings_update',
  'install_db',

  'modal_route_add',
  'route_add',
  'modal_route_update',
  'route_update',
  'route_toggle',
  'route_delete',
  'route_test',
  'route_bind_code',

  'tg_probe',
  'max_probe',
  'api_ingest',
  'api_tg_webhook',
];

/**
 * CHANNEL_BRIDGE_SOURCE_TG
 * Код платформы-источника Telegram.
 */
const CHANNEL_BRIDGE_SOURCE_TG = 'tg';

/**
 * CHANNEL_BRIDGE_TARGET_TG
 * Код платформы-цели Telegram.
 */
const CHANNEL_BRIDGE_TARGET_TG = 'tg';

/**
 * CHANNEL_BRIDGE_TARGET_VK
 * Код платформы-цели VK.
 */
const CHANNEL_BRIDGE_TARGET_VK = 'vk';

/**
 * CHANNEL_BRIDGE_TARGET_MAX
 * Код платформы-цели MAX.
 */
const CHANNEL_BRIDGE_TARGET_MAX = 'max';

/**
 * CHANNEL_BRIDGE_BIND_SIDE_SOURCE
 * Код стороны маршрута "источник" для автопривязки.
 */
const CHANNEL_BRIDGE_BIND_SIDE_SOURCE = 'source';

/**
 * CHANNEL_BRIDGE_BIND_SIDE_TARGET
 * Код стороны маршрута "цель" для автопривязки.
 */
const CHANNEL_BRIDGE_BIND_SIDE_TARGET = 'target';

/**
 * CHANNEL_BRIDGE_BIND_CODE_TTL_MINUTES
 * Время жизни одноразового кода привязки в минутах.
 */
const CHANNEL_BRIDGE_BIND_CODE_TTL_MINUTES = 30;

/**
 * CHANNEL_BRIDGE_TG_UPDATE_MAX_AGE_SECONDS
 * Максимальный допустимый возраст channel_post для автокросспоста.
 */
const CHANNEL_BRIDGE_TG_UPDATE_MAX_AGE_SECONDS = 86400;
