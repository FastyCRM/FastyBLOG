<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_vk_cleanup_start.php
 * ROLE: do=vk_cleanup_start — старт фоновой задачи удаления постов VK.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_lib.php';
require_once __DIR__ . '/channel_bridge_jobs.php';
require_once __DIR__ . '/channel_bridge_vk_cleanup.php';

acl_guard(module_allowed_roles(CHANNEL_BRIDGE_MODULE_CODE));

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!channel_bridge_can_manage($roles)) {
  json_err(channel_bridge_t('channel_bridge.error_forbidden'), 403);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
  http_405('Method Not Allowed');
}

csrf_check((string)($_POST['csrf'] ?? ''));

try {
  $routeId = (int)($_POST['vk_cleanup_route_id'] ?? 0);
  $countRaw = trim((string)($_POST['vk_delete_last_count'] ?? ''));
  $linkSubstring = trim((string)($_POST['vk_delete_link_substring'] ?? ''));
  if ($routeId <= 0) {
    throw new InvalidArgumentException(channel_bridge_t('channel_bridge.error_vk_delete_route_required'));
  }
  if ($countRaw === '') {
    throw new InvalidArgumentException(channel_bridge_t('channel_bridge.error_vk_delete_count_required'));
  }
  if ($countRaw !== '*' && !preg_match('/^\d+$/', $countRaw)) {
    throw new InvalidArgumentException(channel_bridge_t('channel_bridge.error_vk_delete_count_invalid'));
  }
  $deleteCount = ($countRaw === '*') ? 0 : (int)$countRaw;
  if ($countRaw !== '*' && $deleteCount <= 0) {
    throw new InvalidArgumentException(channel_bridge_t('channel_bridge.error_vk_delete_count_invalid'));
  }

  $pdo = db();
  $settings = channel_bridge_settings_get($pdo);
  $result = channel_bridge_vk_cleanup_create_task($pdo, $routeId, $deleteCount, $uid, $settings, $linkSubstring);
  if (($result['ok'] ?? false) !== true) {
    throw new RuntimeException((string)($result['error'] ?? 'vk_cleanup_start_failed'));
  }

  $reason = trim((string)($result['reason'] ?? 'created'));
  $task = is_array($result['task'] ?? null) ? (array)$result['task'] : [];
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'vk_cleanup_start', $reason === 'already_running' ? 'warn' : 'info', [
    'route_id' => $routeId,
    'requested_count' => $deleteCount,
    'link_substring' => $linkSubstring,
    'reason' => $reason,
    'task_id' => (int)($task['id'] ?? 0),
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  json_ok([
    'reason' => $reason,
    'task' => $task,
  ]);
} catch (Throwable $e) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'vk_cleanup_start', 'error', [
    'error' => $e->getMessage(),
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));
  json_err($e->getMessage(), 422);
}
