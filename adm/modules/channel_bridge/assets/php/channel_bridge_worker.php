<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_worker.php
 * ROLE: Internal DB-worker endpoint for channel_bridge jobs queue.
 * DEPENDENCIES:
 *  - /adm/modules/channel_bridge/settings.php
 *  - /adm/modules/channel_bridge/assets/php/channel_bridge_lib.php
 *  - /adm/modules/channel_bridge/assets/php/channel_bridge_jobs.php
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_lib.php';
require_once __DIR__ . '/channel_bridge_jobs.php';

try {
  $internal = channel_bridge_is_internal_key_request() || channel_bridge_is_worker_request();
  if (!$internal) {
    channel_bridge_require_manage_or_internal();
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
      http_405('Method Not Allowed');
    }
    csrf_check((string)($_POST['csrf'] ?? ''));
  }

  $pdo = db();
  if (!channel_bridge_jobs_table_available($pdo)) {
    json_err('Jobs table is missing', 500, ['reason' => 'jobs_table_missing']);
  }

  $settings = channel_bridge_settings_get($pdo);
  $maxSeconds = (int)($_REQUEST['max_seconds'] ?? 8);
  $maxJobs = (int)($_REQUEST['max_jobs'] ?? 25);
  $result = channel_bridge_jobs_run_worker_loop($pdo, $settings, [
    'max_seconds' => $maxSeconds,
    'max_jobs' => $maxJobs,
  ]);

  channel_bridge_jobs_audit('worker_run', 'info', [
    'internal' => $internal ? 1 : 0,
    'max_seconds' => max(1, min(30, $maxSeconds)),
    'max_jobs' => max(1, min(200, $maxJobs)),
    'claimed' => (int)($result['claimed'] ?? 0),
    'done' => (int)($result['done'] ?? 0),
    'retried' => (int)($result['retried'] ?? 0),
    'failed' => (int)($result['failed'] ?? 0),
  ]);

  json_ok($result);
} catch (Throwable $e) {
  channel_bridge_jobs_audit('worker_run', 'error', [
    'reason' => 'exception',
    'error' => $e->getMessage(),
    'error_file' => $e->getFile(),
    'error_line' => (int)$e->getLine(),
  ]);
  json_err('Internal error', 500);
}

