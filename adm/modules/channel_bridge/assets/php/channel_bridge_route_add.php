<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_route_add.php
 * ROLE: do=route_add — добавление маршрута.
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

$autoBindSource = ((int)($_POST['auto_bind_source'] ?? 0) === 1) ? 1 : 0;
$autoBindTarget = ((int)($_POST['auto_bind_target'] ?? 0) === 1) ? 1 : 0;

try {
  $pdo = db();
  $routeId = channel_bridge_route_add($pdo, [
    'title' => (string)($_POST['title'] ?? ''),
    'source_platform' => (string)($_POST['source_platform'] ?? ''),
    'source_chat_id' => (string)($_POST['source_chat_id'] ?? ''),
    'target_platform' => (string)($_POST['target_platform'] ?? ''),
    'target_chat_id' => (string)($_POST['target_chat_id'] ?? ''),
    'target_extra' => (string)($_POST['target_extra'] ?? ''),
    'blacklist_domains' => (string)($_POST['blacklist_domains'] ?? ''),
    'enabled' => (int)($_POST['enabled'] ?? 0),
    'auto_bind_source' => $autoBindSource,
    'auto_bind_target' => $autoBindTarget,
  ]);

  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'route_add', 'info', [
    'route_id' => $routeId,
    'auto_bind_source' => $autoBindSource,
    'auto_bind_target' => $autoBindTarget,
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  flash(channel_bridge_t('channel_bridge.flash_route_added'), 'ok');

  if ($autoBindSource === 1) {
    try {
      $code = channel_bridge_generate_bind_code($pdo, $routeId, CHANNEL_BRIDGE_BIND_SIDE_SOURCE, $uid);
      flash(channel_bridge_t('channel_bridge.flash_bind_code_source_created', ['code' => $code]), 'ok');
    } catch (Throwable $e) {
      flash(channel_bridge_t('channel_bridge.flash_bind_code_generate_error', ['error' => $e->getMessage()]), 'warn');
    }
  }

  if ($autoBindTarget === 1) {
    try {
      $code = channel_bridge_generate_bind_code($pdo, $routeId, CHANNEL_BRIDGE_BIND_SIDE_TARGET, $uid);
      flash(channel_bridge_t('channel_bridge.flash_bind_code_target_created', ['code' => $code]), 'ok');
    } catch (Throwable $e) {
      flash(channel_bridge_t('channel_bridge.flash_bind_code_generate_error', ['error' => $e->getMessage()]), 'warn');
    }
  }
} catch (Throwable $e) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'route_add', 'error', [
    'error' => $e->getMessage(),
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  flash(channel_bridge_t('channel_bridge.flash_route_add_error', ['error' => $e->getMessage()]), 'danger', 1);
}

redirect_return('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE);
