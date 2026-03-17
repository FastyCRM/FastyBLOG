<?php
/**
 * FILE: /adm/modules/ym_link_bot/settings.php
 * ROLE: Constants for ym_link_bot module.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

const YM_LINK_BOT_ALLOWED_DO = [
  'api_bootstrap',
  'api_save_settings',
  'api_binding_save',
  'api_binding_delete',
  'api_channel_code',
  'api_chat_code',
  'api_channel_toggle',
  'api_channel_delete',
  'api_site_save',
  'api_site_delete',
  'api_build_link',
  'api_webhook_info',
  'api_webhook_set',
  'api_chat_webhook_info',
  'api_chat_webhook_set',
  'api_cleanup_photos',
];

const YM_LINK_BOT_MANAGE_ROLES = ['admin', 'manager'];

// ORD requests enabled.
const YM_LINK_BOT_ORD_ENABLED = true;
