<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_route_update.php
 * ROLE: do=route_update — обновление маршрута.
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
  channel_bridge_route_update($pdo, $id, [
    'title' => (string)($_POST['title'] ?? ''),
    'source_platform' => (string)($_POST['source_platform'] ?? ''),
    'source_chat_id' => (string)($_POST['source_chat_id'] ?? ''),
    'target_platform' => (string)($_POST['target_platform'] ?? ''),
    'target_chat_id' => (string)($_POST['target_chat_id'] ?? ''),
    'target_extra' => (string)($_POST['target_extra'] ?? ''),
    'blacklist_domains' => (string)($_POST['blacklist_domains'] ?? ''),
    'enabled' => (int)($_POST['enabled'] ?? 0),
  ]);

  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'route_update', 'info', [
    'route_id' => $id,
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  flash(channel_bridge_t('channel_bridge.flash_route_updated'), 'ok');
} catch (Throwable $e) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'route_update', 'error', [
    'route_id' => $id,
    'error' => $e->getMessage(),
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  flash(channel_bridge_t('channel_bridge.flash_route_update_error', ['error' => $e->getMessage()]), 'danger', 1);
}

redirect_return('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE);
