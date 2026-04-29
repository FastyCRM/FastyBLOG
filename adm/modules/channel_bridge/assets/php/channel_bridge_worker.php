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
  $trace = trim((string)($_REQUEST['trace'] ?? ''));
  $remoteAddr = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
  $userAgent = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
  $tokenPresent = (trim((string)($_REQUEST['token'] ?? '')) !== '');
  $internal = channel_bridge_is_internal_key_request() || channel_bridge_is_worker_request();
  channel_bridge_jobs_audit('worker_entry', 'info', [
    'trace' => $trace,
    'method' => $method,
    'remote_addr' => $remoteAddr,
    'user_agent' => $userAgent,
    'internal' => ($internal ? 1 : 0),
    'token_present' => ($tokenPresent ? 1 : 0),
    'max_seconds' => (int)($_REQUEST['max_seconds'] ?? 0),
    'max_jobs' => (int)($_REQUEST['max_jobs'] ?? 0),
    'idle_wait_first_claim_ms' => (int)($_REQUEST['idle_wait_first_claim_ms'] ?? 0),
    'idle_wait_after_work_ms' => (int)($_REQUEST['idle_wait_after_work_ms'] ?? 0),
  ]);
  if (!$internal) {
    channel_bridge_jobs_audit('worker_entry', 'warn', [
      'trace' => $trace,
      'reason' => 'non_internal_request',
      'method' => $method,
      'remote_addr' => $remoteAddr,
      'user_agent' => $userAgent,
    ]);
    channel_bridge_require_manage_or_internal();
    if ($method !== 'POST') {
      channel_bridge_jobs_audit('worker_entry', 'warn', [
        'trace' => $trace,
        'reason' => 'method_not_allowed',
        'method' => $method,
      ]);
      http_405('Method Not Allowed');
    }
    csrf_check((string)($_POST['csrf'] ?? ''));
  }

  $pdo = db();
  if (!channel_bridge_jobs_table_available($pdo)) {
    channel_bridge_jobs_audit('worker_entry', 'error', [
      'trace' => $trace,
      'reason' => 'jobs_table_missing',
    ]);
    json_err('Jobs table is missing', 500, ['reason' => 'jobs_table_missing']);
  }

  $settings = channel_bridge_settings_get($pdo);
  $maxSeconds = (int)($_REQUEST['max_seconds'] ?? 20);
  $maxJobs = (int)($_REQUEST['max_jobs'] ?? 25);
  $idleWaitFirstClaimMs = (int)($_REQUEST['idle_wait_first_claim_ms'] ?? 300);
  $idleWaitAfterWorkMs = (int)($_REQUEST['idle_wait_after_work_ms'] ?? 3500);
  $result = channel_bridge_jobs_run_worker_loop($pdo, $settings, [
    'max_seconds' => $maxSeconds,
    'max_jobs' => $maxJobs,
    'idle_wait_first_claim_ms' => $idleWaitFirstClaimMs,
    'idle_wait_after_work_ms' => $idleWaitAfterWorkMs,
  ]);

  channel_bridge_jobs_audit('worker_run', 'info', [
    'trace' => $trace,
    'internal' => $internal ? 1 : 0,
    'max_seconds' => max(1, min(30, $maxSeconds)),
    'max_jobs' => max(1, min(200, $maxJobs)),
    'idle_wait_first_claim_ms' => max(200, min(30000, $idleWaitFirstClaimMs)),
    'idle_wait_after_work_ms' => max(100, min(30000, $idleWaitAfterWorkMs)),
    'claimed' => (int)($result['claimed'] ?? 0),
    'done' => (int)($result['done'] ?? 0),
    'retried' => (int)($result['retried'] ?? 0),
    'failed' => (int)($result['failed'] ?? 0),
  ]);

  json_ok($result);
} catch (Throwable $e) {
  channel_bridge_jobs_audit('worker_run', 'error', [
    'trace' => trim((string)($_REQUEST['trace'] ?? '')),
    'reason' => 'exception',
    'error' => $e->getMessage(),
    'error_file' => $e->getFile(),
    'error_line' => (int)$e->getLine(),
  ]);
  json_err('Internal error', 500);
}
