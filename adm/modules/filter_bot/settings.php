<?php
/**
 * FILE: /adm/modules/filter_bot/settings.php
 * ROLE: Паспорт модуля filter_bot.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

const FILTER_BOT_MODULE_CODE = 'filter_bot';

const FILTER_BOT_TABLE_SETTINGS = 'filter_bot_settings';
const FILTER_BOT_TABLE_CHANNELS = 'filter_bot_channels';
const FILTER_BOT_TABLE_LOGS = 'filter_bot_logs';
const FILTER_BOT_TABLE_UPDATES = 'filter_bot_updates';

const FILTER_BOT_TABLES = [
  FILTER_BOT_TABLE_SETTINGS,
  FILTER_BOT_TABLE_CHANNELS,
  FILTER_BOT_TABLE_LOGS,
  FILTER_BOT_TABLE_UPDATES,
];

const FILTER_BOT_REQUIRED_TABLES = [
  FILTER_BOT_TABLE_SETTINGS,
  FILTER_BOT_TABLE_CHANNELS,
  FILTER_BOT_TABLE_LOGS,
  FILTER_BOT_TABLE_UPDATES,
];

const FILTER_BOT_ALLOWED_DO = [
  'install_db',
  'save',
  'channel_add',
  'channel_toggle',
  'channel_delete',
];

const FILTER_BOT_PLATFORM_TG = 'tg';
const FILTER_BOT_PLATFORM_MAX = 'max';

const FILTER_BOT_MANAGE_ROLES = ['admin', 'manager'];
