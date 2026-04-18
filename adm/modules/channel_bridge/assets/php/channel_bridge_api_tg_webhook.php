<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_api_tg_webhook.php
 * ROLE: Single Telegram webhook entrypoint for direct single posts and local media_group assembly.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_lib.php';

/**
 * Jobs-layer is optional at runtime:
 * - if file is absent or not deployed yet, webhook must continue in sync mode;
 * - if file exists, queue-mode will be used when runtime checks pass.
 */
$channelBridgeJobsFile = __DIR__ . '/channel_bridge_jobs.php';
if (is_file($channelBridgeJobsFile)) {
  require_once $channelBridgeJobsFile;
}

if (!function_exists('channel_bridge_tg_webhook_log')) {
  /**
   * Minimal audit wrapper for webhook flow.
   *
   * @param string $level
   * @param array<string,mixed> $context
   * @return void
   */
  function channel_bridge_tg_webhook_log(string $level, array $context): void
  {
    if (!function_exists('audit_log')) {
      return;
    }

    audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'tg_webhook', $level, $context);
  }
}

if (!function_exists('channel_bridge_tg_webhook_queue_ready')) {
  /**
   * Returns true when queue helper functions are loaded and callable.
   *
   * @return bool
   */
  function channel_bridge_tg_webhook_queue_ready(): bool
  {
    return function_exists('channel_bridge_jobs_table_available')
      && function_exists('channel_bridge_jobs_make_key')
      && function_exists('channel_bridge_jobs_enqueue');
  }
}

if (!function_exists('channel_bridge_tg_webhook_apply_db_timeouts')) {
  /**
   * Applies short DB lock timeouts to keep webhook latency predictable.
   *
   * @param PDO $pdo
   * @return void
   */
  function channel_bridge_tg_webhook_apply_db_timeouts(PDO $pdo): void
  {
    try {
      $pdo->exec('SET SESSION innodb_lock_wait_timeout = 2');
    } catch (Throwable $e) {
    }
    try {
      $pdo->exec('SET SESSION lock_wait_timeout = 2');
    } catch (Throwable $e) {
    }
  }
}

if (!function_exists('channel_bridge_tg_webhook_elapsed_ms')) {
  /**
   * Returns elapsed milliseconds between two monotonic timestamps.
   *
   * @param float $from
   * @param float $to
   * @return int
   */
  function channel_bridge_tg_webhook_elapsed_ms(float $from, float $to): int
  {
    if ($from <= 0 || $to <= 0 || $to < $from) {
      return 0;
    }
    return (int)max(0, round(($to - $from) * 1000));
  }
}

try {
  $tsStart = microtime(true);
  $tsReceived = 0.0;
  $tsDedup = 0.0;
  $tsStateSaved = 0.0;
  $tsJobCreated = 0.0;
  $deferredWorker = [
    'enabled' => false,
    'max_seconds' => 8,
    'max_jobs' => 20,
  ];

  register_shutdown_function(function () use (&$deferredWorker): void {
    if (($deferredWorker['enabled'] ?? false) !== true) {
      return;
    }
    if (!function_exists('channel_bridge_jobs_run_worker_loop')) {
      return;
    }

    if (function_exists('ignore_user_abort')) {
      @ignore_user_abort(true);
    }
    if (function_exists('fastcgi_finish_request')) {
      @fastcgi_finish_request();
    }

    try {
      $pdo = db();
      channel_bridge_tg_webhook_apply_db_timeouts($pdo);
      $settings = channel_bridge_settings_get($pdo);
      $maxSeconds = max(1, min(30, (int)($deferredWorker['max_seconds'] ?? 8)));
      $maxJobs = max(1, min(200, (int)($deferredWorker['max_jobs'] ?? 20)));
      $workerResult = channel_bridge_jobs_run_worker_loop($pdo, $settings, [
        'max_seconds' => $maxSeconds,
        'max_jobs' => $maxJobs,
      ]);
      channel_bridge_jobs_audit('worker_run', 'info', [
        'internal' => 1,
        'mode' => 'shutdown_local',
        'max_seconds' => $maxSeconds,
        'max_jobs' => $maxJobs,
        'claimed' => (int)($workerResult['claimed'] ?? 0),
        'done' => (int)($workerResult['done'] ?? 0),
        'retried' => (int)($workerResult['retried'] ?? 0),
        'failed' => (int)($workerResult['failed'] ?? 0),
      ]);
    } catch (Throwable $e) {
      channel_bridge_jobs_audit('worker_run', 'error', [
        'reason' => 'shutdown_local_exception',
        'error' => $e->getMessage(),
      ]);
    }
  });

  $pdo = db();
  channel_bridge_tg_webhook_apply_db_timeouts($pdo);
  $settings = channel_bridge_settings_get($pdo);

  $secret = trim((string)($settings['tg_webhook_secret'] ?? ''));
  $secretOk = ($secret === '');
  if ($secret !== '' && function_exists('tg_verify_webhook_secret')) {
    $secretOk = tg_verify_webhook_secret($secret);
  }

  $internalKeyOk = channel_bridge_is_internal_key_request();
  if (!$secretOk && !$internalKeyOk) {
    channel_bridge_tg_webhook_log('warn', [
      'reason' => 'forbidden',
      'remote_addr' => trim((string)($_SERVER['REMOTE_ADDR'] ?? '')),
    ]);
    json_err('Forbidden', 403);
  }

  $update = function_exists('tg_read_update') ? (array)tg_read_update() : [];
  $tsReceived = microtime(true);
  if (!$update) {
    json_ok(['ok' => true, 'handled' => false, 'reason' => 'empty_update']);
  }

  $updateMeta = channel_bridge_extract_tg_update_meta($update);
  if ($updateMeta) {
    $updateReg = channel_bridge_webhook_update_register($pdo, $updateMeta);
    $tsDedup = microtime(true);
    if (($updateReg['duplicate'] ?? false) === true) {
      channel_bridge_tg_webhook_log('info', [
        'reason' => 'duplicate_update',
        'update_id' => (int)($updateMeta['update_id'] ?? 0),
        'source_chat_id' => (string)($updateMeta['source_chat_id'] ?? ''),
        'source_message_id' => (string)($updateMeta['source_message_id'] ?? ''),
        'media_group_id' => (string)($updateMeta['media_group_id'] ?? ''),
      ]);
      json_ok(['ok' => true, 'handled' => false, 'reason' => 'duplicate_update']);
    }

    $updateType = trim((string)($updateMeta['update_type'] ?? ''));
    if ($updateType === 'edited_channel_post') {
      json_ok(['ok' => true, 'handled' => false, 'reason' => 'edited_channel_post_ignored']);
    }

    if (channel_bridge_is_tg_update_stale($updateMeta)) {
      channel_bridge_tg_webhook_log('info', [
        'reason' => 'stale_update_ignored',
        'update_id' => (int)($updateMeta['update_id'] ?? 0),
        'source_chat_id' => (string)($updateMeta['source_chat_id'] ?? ''),
        'source_message_id' => (string)($updateMeta['source_message_id'] ?? ''),
      ]);
      json_ok(['ok' => true, 'handled' => false, 'reason' => 'stale_update_ignored']);
    }
  }
  if ($tsDedup <= 0) {
    $tsDedup = microtime(true);
  }

  $textMeta = channel_bridge_extract_tg_text_meta($update);
  if ($textMeta) {
    $chatType = strtolower(trim((string)($textMeta['chat_type'] ?? '')));
    $text = (string)($textMeta['text'] ?? '');

    if ($chatType === 'channel') {
      $bindCode = '';
      if (preg_match('~^/(?:start|bind)(?:@\w+)?\s+(\d{4})\s*$~u', trim($text), $match)) {
        $bindCode = trim((string)($match[1] ?? ''));
      }
    } else {
      $bindCode = channel_bridge_extract_bind_code_from_text($text);
    }

    if ($bindCode !== '') {
      $bind = channel_bridge_bind_route_by_code($pdo, $bindCode, $textMeta);
      $chatId = trim((string)($textMeta['chat_id'] ?? ''));
      $botToken = trim((string)($settings['tg_bot_token'] ?? ''));
      if ($chatId !== '' && $botToken !== '') {
        if (($bind['ok'] ?? false) === true) {
          $side = trim((string)($bind['side'] ?? ''));
          $reply = ($side === CHANNEL_BRIDGE_BIND_SIDE_TARGET)
            ? channel_bridge_t('channel_bridge.bind_reply_target_ok')
            : channel_bridge_t('channel_bridge.bind_reply_source_ok');
          tg_send_message($botToken, $chatId, $reply);
        } else {
          $reply = trim((string)($bind['message'] ?? channel_bridge_t('channel_bridge.bind_reply_fail')));
          tg_send_message($botToken, $chatId, $reply);
        }
      }

      json_ok([
        'ok' => true,
        'handled' => true,
        'reason' => 'bind_code',
        'bind' => $bind,
      ]);
    }
  }

  $payload = channel_bridge_extract_tg_channel_post($update);
  if (!$payload) {
    json_ok(['ok' => true, 'handled' => false, 'reason' => 'not_channel_post']);
  }

  $payload['tg_update_id'] = (int)($updateMeta['update_id'] ?? 0);
  $messageType = (trim((string)($payload['tg_media_group_id'] ?? '')) === '') ? 'single' : 'media_group';

  $queueMode = false;
  $result = [];
  $queueReady = channel_bridge_tg_webhook_queue_ready();
  $stateTablesReady = channel_bridge_tg_state_tables_available($pdo);
  $jobsTableReady = $queueReady ? channel_bridge_jobs_table_available($pdo) : false;
  $canQueue = $queueReady && $stateTablesReady && $jobsTableReady;

  if (!$canQueue) {
    channel_bridge_tg_webhook_log('info', [
      'reason' => 'queue_unavailable',
      'queue_ready' => $queueReady ? 1 : 0,
      'state_tables_ready' => $stateTablesReady ? 1 : 0,
      'jobs_table_ready' => $jobsTableReady ? 1 : 0,
    ]);
  }

  if ($canQueue) {
    try {
      $sourceChatId = channel_bridge_norm_chat_id((string)($payload['source_chat_id'] ?? ''));
      $sourceMessageId = trim((string)($payload['source_message_id'] ?? ''));
      $mediaGroupId = trim((string)($payload['tg_media_group_id'] ?? ''));
      $activeRoutes = channel_bridge_collect_routes_for_source($pdo, CHANNEL_BRIDGE_SOURCE_TG, $sourceChatId);
      $routeDebug = channel_bridge_collect_route_debug_for_source($pdo, CHANNEL_BRIDGE_SOURCE_TG, $sourceChatId);
      $activeRouteIds = array_values(array_map(static function ($route): int {
        return (int)($route['id'] ?? 0);
      }, $activeRoutes));
      if (!$activeRoutes) {
        $result = [
          'ok' => true,
          'reason' => 'no_active_routes',
          'targets' => 0,
          'sent' => 0,
          'failed' => 0,
          'job_type' => '',
          'job_key' => '',
          'job_created' => 0,
          'active_routes' => 0,
          'queue_only' => (defined('CHANNEL_BRIDGE_WEBHOOK_QUEUE_ONLY') && CHANNEL_BRIDGE_WEBHOOK_QUEUE_ONLY) ? 1 : 0,
          'worker_spawn_ok' => 0,
          'worker_spawn_error' => 'no_job',
          'worker_spawn_endpoint' => '',
          'worker_spawn_ms' => 0,
          'worker_inline_fallback' => 0,
          'worker_inline_claimed' => 0,
          'worker_inline_done' => 0,
          'worker_inline_error' => '',
          'source_chat_id' => $sourceChatId,
          'source_message_id' => $sourceMessageId,
          'media_group_id' => $mediaGroupId,
          'active_route_ids' => [],
          'active_vk_route_ids' => (array)($routeDebug['active_vk_route_ids'] ?? []),
          'vk_candidates' => (array)($routeDebug['vk_candidates'] ?? []),
        ];
      } else {
        $stateResult = channel_bridge_tg_state_process_webhook($pdo, $settings, $payload, $updateMeta);
        if (($stateResult['ok'] ?? false) !== true) {
          $result = $stateResult;
        } else {
          $tsStateSaved = microtime(true);
          $sourceChatId = channel_bridge_norm_chat_id((string)($stateResult['source_chat_id'] ?? ($payload['source_chat_id'] ?? '')));
          $sourceMessageId = trim((string)($stateResult['source_message_id'] ?? ($payload['source_message_id'] ?? '')));
          $mediaGroupId = trim((string)($stateResult['media_group_id'] ?? ($payload['tg_media_group_id'] ?? '')));

          if ($mediaGroupId === '') {
            $jobType = CHANNEL_BRIDGE_JOB_TYPE_SINGLE_POST;
            $jobKey = channel_bridge_jobs_make_key($jobType, $sourceChatId, $sourceMessageId, '');
            $jobPayload = [
              'source_chat_id' => $sourceChatId,
              'source_message_id' => $sourceMessageId,
              'tg_update_id' => (int)($updateMeta['update_id'] ?? 0),
            ];
            $enqueue = channel_bridge_jobs_enqueue($pdo, $jobType, $jobKey, $jobPayload, 0);
          } else {
            $jobType = '';
            $jobKey = '';
            $spawnStart = microtime(true);
            $spawn = channel_bridge_tg_state_spawn_finalize($pdo, $sourceChatId, $mediaGroupId);
            $spawnMs = channel_bridge_tg_webhook_elapsed_ms($spawnStart, microtime(true));
            channel_bridge_tg_state_spawn_sweeper();
            $spawnReason = trim((string)($spawn['reason'] ?? ''));
            $spawnOk = (($spawn['ok'] ?? false) === true);
            $resultReason = 'finalize_spawn_failed';
            $spawnEndpoint = 'async_finalize';
            if ($spawnOk) {
              if ($spawnReason === 'already_running') {
                $resultReason = 'finalize_already_running';
                $spawnEndpoint = 'async_finalize_existing';
              } elseif ($spawnReason === 'already_sent') {
                $resultReason = 'media_group_already_sent';
                $spawnEndpoint = 'async_finalize_sent';
              } elseif ($spawnReason === 'already_terminal') {
                $resultReason = 'media_group_terminal';
                $spawnEndpoint = 'async_finalize_terminal';
              } else {
                $resultReason = 'finalize_spawned';
              }
            }
            $queueMode = true;
            $result = [
              'ok' => $spawnOk,
              'reason' => $resultReason,
              'message' => $spawnOk ? '' : (string)($spawn['error'] ?? 'Failed to spawn finalize'),
              'targets' => 0,
              'sent' => 0,
              'failed' => 0,
              'job_type' => '',
              'job_key' => '',
              'job_created' => 0,
              'active_routes' => count($activeRoutes),
              'queue_only' => 0,
              'worker_spawn_ok' => $spawnOk ? 1 : 0,
              'worker_spawn_error' => $spawnOk ? '' : (string)($spawn['error'] ?? 'spawn_failed'),
              'worker_spawn_endpoint' => $spawnEndpoint,
              'worker_spawn_ms' => $spawnMs,
              'worker_inline_fallback' => 0,
              'worker_inline_claimed' => 0,
              'worker_inline_done' => 0,
              'worker_inline_error' => '',
              'source_chat_id' => $sourceChatId,
              'source_message_id' => $sourceMessageId,
              'media_group_id' => $mediaGroupId,
              'active_route_ids' => $activeRouteIds,
              'active_vk_route_ids' => (array)($routeDebug['active_vk_route_ids'] ?? []),
              'vk_candidates' => (array)($routeDebug['vk_candidates'] ?? []),
            ];
          }

          if ($mediaGroupId === '') {
            if (($enqueue['ok'] ?? false) !== true) {
              $result = [
                'ok' => false,
                'reason' => 'job_enqueue_failed',
                'message' => (string)($enqueue['reason'] ?? 'Failed to enqueue job'),
              ];
            } else {
              $tsJobCreated = microtime(true);
              $queueMode = true;
              $queueOnly = (defined('CHANNEL_BRIDGE_WEBHOOK_QUEUE_ONLY') && CHANNEL_BRIDGE_WEBHOOK_QUEUE_ONLY);
              $spawnOk = 0;
              $spawnErr = $queueOnly ? 'queue_only_mode' : '';
              $spawnEndpoint = '';
              $spawnMs = 0;
              if (!$queueOnly) {
                if (function_exists('channel_bridge_jobs_run_worker_loop')) {
                  $deferredWorker['enabled'] = true;
                  $deferredWorker['max_seconds'] = 8;
                  $deferredWorker['max_jobs'] = 20;
                  $spawnOk = 1;
                  $spawnErr = '';
                  $spawnEndpoint = 'shutdown_local';
                } else {
                  $spawnErr = 'worker_local_unavailable';
                }
              }
              $result = [
                'ok' => true,
                'reason' => 'job_created',
                'targets' => 0,
                'sent' => 0,
                'failed' => 0,
                'job_type' => $jobType,
                'job_key' => $jobKey,
                'job_created' => (int)($enqueue['created'] ?? 0),
                'active_routes' => count($activeRoutes),
                'queue_only' => $queueOnly ? 1 : 0,
                'worker_spawn_ok' => $spawnOk,
                'worker_spawn_error' => $spawnErr,
                'worker_spawn_endpoint' => $spawnEndpoint,
                'worker_spawn_ms' => $spawnMs,
                'worker_inline_fallback' => 0,
                'worker_inline_claimed' => 0,
                'worker_inline_done' => 0,
                'worker_inline_error' => '',
                'source_chat_id' => $sourceChatId,
                'source_message_id' => $sourceMessageId,
                'media_group_id' => $mediaGroupId,
                'active_route_ids' => $activeRouteIds,
                'active_vk_route_ids' => (array)($routeDebug['active_vk_route_ids'] ?? []),
                'vk_candidates' => (array)($routeDebug['vk_candidates'] ?? []),
              ];
            }
          } else {
            $tsJobCreated = microtime(true);
          }
        }
      }
    } catch (Throwable $queueError) {
      $result = [
        'ok' => false,
        'reason' => 'queue_exception',
        'message' => $queueError->getMessage(),
      ];
      channel_bridge_tg_webhook_log('error', [
        'reason' => 'queue_exception',
        'error' => $queueError->getMessage(),
        'error_file' => $queueError->getFile(),
        'error_line' => (int)$queueError->getLine(),
      ]);
    }
  }

  if (!$canQueue) {
    $result = [
      'ok' => false,
      'reason' => 'queue_unavailable',
      'message' => 'Queue pipeline is unavailable',
    ];
  } elseif (($result['ok'] ?? false) !== true) {
    channel_bridge_tg_webhook_log('warn', [
      'reason' => 'queue_failed_no_sync_fallback',
      'queue_reason' => trim((string)($result['reason'] ?? 'queue_failed')),
    ]);
  }
  $ok = (($result['ok'] ?? false) === true);
  $reason = trim((string)($result['reason'] ?? ''));
  $handled = !in_array($reason, ['duplicate', 'media_group_already_sent', 'media_group_terminal', 'no_active_routes'], true);
  $tsResponse = microtime(true);

  $receivedMs = channel_bridge_tg_webhook_elapsed_ms($tsStart, $tsReceived);
  $dedupMs = channel_bridge_tg_webhook_elapsed_ms(($tsReceived > 0 ? $tsReceived : $tsStart), $tsDedup);
  $stateSavedMs = channel_bridge_tg_webhook_elapsed_ms(($tsDedup > 0 ? $tsDedup : ($tsReceived > 0 ? $tsReceived : $tsStart)), $tsStateSaved);
  $jobCreatedMs = channel_bridge_tg_webhook_elapsed_ms(($tsStateSaved > 0 ? $tsStateSaved : ($tsDedup > 0 ? $tsDedup : ($tsReceived > 0 ? $tsReceived : $tsStart))), $tsJobCreated);
  $responseFrom = $tsJobCreated > 0
    ? $tsJobCreated
    : ($tsStateSaved > 0
      ? $tsStateSaved
      : ($tsDedup > 0 ? $tsDedup : ($tsReceived > 0 ? $tsReceived : $tsStart)));
  $responseSentMs = channel_bridge_tg_webhook_elapsed_ms($responseFrom, $tsResponse);
  $totalMs = channel_bridge_tg_webhook_elapsed_ms($tsStart, $tsResponse);

  channel_bridge_tg_webhook_log($ok ? 'info' : 'error', [
    'message_type' => $messageType,
    'queue_mode' => $queueMode ? 1 : 0,
    'update_id' => (int)($updateMeta['update_id'] ?? 0),
    'media_group_id' => trim((string)($payload['tg_media_group_id'] ?? '')),
    'source_chat_id' => trim((string)($payload['source_chat_id'] ?? '')),
    'source_message_id' => trim((string)($payload['source_message_id'] ?? '')),
    'sent' => (int)($result['sent'] ?? 0),
    'failed' => (int)($result['failed'] ?? 0),
    'targets' => (int)($result['targets'] ?? 0),
    'active_routes' => (int)($result['active_routes'] ?? 0),
    'active_route_ids' => array_values(array_map('intval', (array)($result['active_route_ids'] ?? []))),
    'active_vk_route_ids' => array_values(array_map('intval', (array)($result['active_vk_route_ids'] ?? []))),
    'vk_candidates' => (array)($result['vk_candidates'] ?? []),
    'reason' => $reason,
    'job_type' => trim((string)($result['job_type'] ?? '')),
    'job_key' => trim((string)($result['job_key'] ?? '')),
    'job_created' => (int)($result['job_created'] ?? 0),
    'queue_only' => (int)($result['queue_only'] ?? 0),
    'worker_spawn_ok' => (int)($result['worker_spawn_ok'] ?? 0),
    'worker_spawn_error' => trim((string)($result['worker_spawn_error'] ?? '')),
    'worker_spawn_endpoint' => trim((string)($result['worker_spawn_endpoint'] ?? '')),
    'worker_spawn_ms' => (int)($result['worker_spawn_ms'] ?? 0),
    'worker_inline_fallback' => (int)($result['worker_inline_fallback'] ?? 0),
    'worker_inline_claimed' => (int)($result['worker_inline_claimed'] ?? 0),
    'worker_inline_done' => (int)($result['worker_inline_done'] ?? 0),
    'worker_inline_error' => trim((string)($result['worker_inline_error'] ?? '')),
    'received_ms' => $receivedMs,
    'dedup_ms' => $dedupMs,
    'state_saved_ms' => $stateSavedMs,
    'job_created_ms' => $jobCreatedMs,
    'response_sent_ms' => $responseSentMs,
    'total_ms' => $totalMs,
  ]);

  if (!$ok) {
    json_err((string)($result['message'] ?? $reason ?: 'Dispatch failed'), 500, $result);
  }

  json_ok([
    'ok' => true,
    'handled' => $handled,
    'reason' => ($reason !== '' ? $reason : 'dispatched'),
    'message_type' => $messageType,
    'media_group_id' => trim((string)($payload['tg_media_group_id'] ?? '')),
    'result' => $result,
  ]);
} catch (Throwable $e) {
  channel_bridge_tg_webhook_log('error', [
    'reason' => 'exception',
    'error' => $e->getMessage(),
    'error_file' => $e->getFile(),
    'error_line' => (int)$e->getLine(),
  ]);
  json_err('Internal error', 500);
}
