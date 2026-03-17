<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_route_test.php
 * ROLE: do=route_test — тестовая отправка по одному маршруту.
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

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  flash(channel_bridge_t('channel_bridge.error_bad_id'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE);
}

try {
  $pdo = db();
  $route = channel_bridge_route_find($pdo, $id);
  if (!$route) {
    throw new RuntimeException(channel_bridge_t('channel_bridge.error_route_not_found'));
  }

  $settings = channel_bridge_settings_get($pdo);
  $text = channel_bridge_t('channel_bridge.test_message') . ' [' . channel_bridge_now() . ']';
  $send = channel_bridge_send_by_route($settings, $route, $text);
  $ok = (($send['ok'] ?? false) === true);

  channel_bridge_log_dispatch($pdo, [
    'route_id' => $id,
    'source_platform' => 'manual',
    'source_chat_id' => 'manual',
    'source_message_id' => '',
    'target_platform' => (string)($route['target_platform'] ?? ''),
    'target_chat_id' => (string)($route['target_chat_id'] ?? ''),
    'message_text' => $text,
    'send_status' => $ok ? 'sent' : 'failed',
    'error_text' => (string)($send['error'] ?? ''),
    'response_raw' => $send['raw'] ?? '',
  ]);

  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'route_test', $ok ? 'info' : 'warn', [
    'route_id' => $id,
    'ok' => $ok ? 1 : 0,
    'error' => (string)($send['error'] ?? ''),
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  if ($ok) {
    flash(channel_bridge_t('channel_bridge.flash_test_ok'), 'ok');
  } else {
    flash(channel_bridge_t('channel_bridge.flash_test_fail', ['error' => (string)($send['error'] ?? 'unknown')]), 'danger', 1);
  }
} catch (Throwable $e) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'route_test', 'error', [
    'route_id' => $id,
    'error' => $e->getMessage(),
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  flash(channel_bridge_t('channel_bridge.flash_test_fail', ['error' => $e->getMessage()]), 'danger', 1);
}

redirect_return('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE);

