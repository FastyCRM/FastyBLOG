<?php
/**
 * FILE: /adm/modules/bot_adv_calendar/settings.php
 * ROLE: Константы модуля bot_adv_calendar
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

const BOT_ADV_CALENDAR_TABLE_SETTINGS = 'bot_adv_calendar_settings';
const BOT_ADV_CALENDAR_TABLE_USER_ACCESS = 'bot_adv_calendar_user_access';
const BOT_ADV_CALENDAR_TABLE_USER_OPTIONS = 'bot_adv_calendar_user_options';
const BOT_ADV_CALENDAR_TABLE_USER_WINDOWS = 'bot_adv_calendar_user_windows';
const BOT_ADV_CALENDAR_TABLE_LINKS = 'bot_adv_calendar_links';
const BOT_ADV_CALENDAR_TABLE_LINK_TOKENS = 'bot_adv_calendar_link_tokens';
const BOT_ADV_CALENDAR_TABLE_DISPATCH_LOG = 'bot_adv_calendar_dispatch_log';
const BOT_ADV_CALENDAR_TABLE_CLIENT_ONBOARDING = 'bot_adv_calendar_client_onboarding';

const BOT_ADV_CALENDAR_USERS_TABLE = 'users';
const BOT_ADV_CALENDAR_CLIENTS_TABLE = 'clients';

const BOT_ADV_CALENDAR_ALLOWED_DO = [
  'modal_settings',
  'modal_generate',
  'user_attach',
  'user_detach',
  'user_settings_save',
  'user_window_add',
  'user_window_delete',
  'settings_update',
  'webhook_set',
  'generate_link',
  'unlink',
  'requests_clear',
];

const BOT_ADV_CALENDAR_MANAGE_ROLES = ['admin', 'manager'];
const BOT_ADV_CALENDAR_ACTOR_TYPES = ['user', 'client'];
const BOT_ADV_CALENDAR_LINK_TOKEN_ACTOR_TYPES = ['user'];
