<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_webhook_refresh.php
 * ROLE: do=webhook_refresh — принудительно применяет текущий Telegram webhook.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_lib.php';

acl_guard(module_allowed_roles(CHANNEL_BRIDGE_MODULE_CODE));

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!channel_bridge_can_manage($roles)) {
  flash(channel_bridge_t('channel_bridge.error_forbidden'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
  http_405('Method Not Allowed');
}

csrf_check((string)($_POST['csrf'] ?? ''));

try {
  $pdo = db();
  $settings = channel_bridge_settings_get($pdo);

  $tgEnabled = ((int)($settings['tg_enabled'] ?? 0) === 1);
  $tgToken = trim((string)($settings['tg_bot_token'] ?? ''));
  if (!$tgEnabled || $tgToken === '') {
    audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'webhook_refresh', 'warn', [
      'result' => 'skipped',
      'reason' => 'tg_disabled_or_token_empty',
    ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

    flash(channel_bridge_t('channel_bridge.flash_settings_saved_webhook_skipped'), 'warn');
    redirect_return('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE);
  }

  $apply = channel_bridge_apply_tg_webhook($settings);
  if (($apply['ok'] ?? false) === true) {
    audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'webhook_refresh', 'info', [
      'result' => 'ok',
    ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

    flash(channel_bridge_t('channel_bridge.flash_webhook_refreshed'), 'ok');
    redirect_return('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE);
  }

  $err = trim((string)($apply['description'] ?? ($apply['error'] ?? 'UNKNOWN')));
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'webhook_refresh', 'warn', [
    'result' => 'fail',
    'error' => $err,
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  flash(channel_bridge_t('channel_bridge.flash_webhook_refresh_failed', ['error' => $err]), 'warn');
} catch (Throwable $e) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'webhook_refresh', 'error', [
    'error' => $e->getMessage(),
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  flash(channel_bridge_t('channel_bridge.flash_webhook_refresh_failed', ['error' => $e->getMessage()]), 'danger', 1);
}

redirect_return('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE);
