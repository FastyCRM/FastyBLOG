<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_vk_cleanup_status.php
 * ROLE: do=vk_cleanup_status — статус фоновой задачи удаления постов VK.
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
  $pdo = db();
  channel_bridge_vk_cleanup_ensure_schema($pdo);

  $task = [];
  if ($routeId > 0) {
    $task = channel_bridge_vk_cleanup_find_active_task($pdo, $routeId);
    if (!$task) {
      $task = channel_bridge_vk_cleanup_find_latest_task($pdo, $routeId);
    }
    if ($task && in_array((string)($task['status'] ?? ''), ['scanning', 'queued', 'running'], true)) {
      channel_bridge_vk_cleanup_spawn_next_worker($pdo, (int)($task['id'] ?? 0));
    }
  }

  json_ok([
    'task' => $task ? channel_bridge_vk_cleanup_task_snapshot($pdo, $task) : null,
  ]);
} catch (Throwable $e) {
  json_err($e->getMessage(), 422);
}
