<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_jobs.php
 * ROLE: DB queue and worker helpers for channel_bridge.
 * DEPENDENCIES:
 *  - /adm/modules/channel_bridge/settings.php (or settings_v2.php via lib)
 *  - /adm/modules/channel_bridge/assets/php/channel_bridge_lib.php
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/channel_bridge_vk_cleanup.php';

if (!function_exists('channel_bridge_jobs_table_available')) {
  /**
   * Checks whether queue table exists.
   *
   * @param PDO $pdo
   * @return bool
   */
  function channel_bridge_jobs_table_available(PDO $pdo): bool
  {
    return channel_bridge_schema_table_exists($pdo, CHANNEL_BRIDGE_TABLE_JOBS);
  }

  /**
   * Builds worker auth token from app secret/internal key.
   *
   * @return string
   */
  function channel_bridge_jobs_worker_auth_token(): string
  {
    $cfg = function_exists('app_config') ? (array)app_config() : [];
    $secret = trim((string)($cfg['internal_api']['key'] ?? ''));
    if ($secret === '') {
      $secret = trim((string)($cfg['security']['app_secret'] ?? ''));
    }
    if ($secret === '') {
      return '';
    }

    return hash('sha256', 'channel_bridge_worker|' . $secret);
  }

  /**
   * Validates worker token from request.
   *
   * @return bool
   */
  function channel_bridge_is_worker_request(): bool
  {
    $expected = channel_bridge_jobs_worker_auth_token();
    if ($expected === '') {
      return false;
    }

    $token = trim((string)($_REQUEST['token'] ?? ''));
    return ($token !== '' && hash_equals($expected, $token));
  }

  /**
   * Builds worker endpoint URL.
   *
   * @param bool $absolute
   * @return string
   */
  function channel_bridge_worker_endpoint_url(bool $absolute = true): string
  {
    $path = function_exists('url')
      ? (string)url('/core/channel_bridge_worker.php')
      : '/core/channel_bridge_worker.php';

    if (!$absolute) {
      return $path;
    }

    $root = channel_bridge_public_root_url();
    return ($root === '') ? $path : ($root . $path);
  }

  /**
   * Returns list of worker endpoints in preferred order.
   * First: direct core endpoint, second: module dispatcher fallback.
   *
   * @param bool $absolute
   * @return array<int,string>
   */
  function channel_bridge_worker_endpoint_candidates(bool $absolute = true): array
  {
    $urls = [];

    $core = channel_bridge_worker_endpoint_url($absolute);
    if (trim($core) !== '') {
      $urls[] = trim($core);
    }

    $adm = function_exists('url')
      ? (string)url('/adm/index.php?m=channel_bridge&do=worker')
      : '/adm/index.php?m=channel_bridge&do=worker';

    if ($absolute) {
      $root = channel_bridge_public_root_url();
      $adm = ($root === '') ? $adm : ($root . $adm);
    }

    if (trim($adm) !== '' && !in_array(trim($adm), $urls, true)) {
      $urls[] = trim($adm);
    }

    return $urls;
  }

  /**
   * Writes queue lifecycle event to audit log.
   *
   * @param string $event
   * @param string $level
   * @param array<string,mixed> $context
   * @return void
   */
  function channel_bridge_jobs_audit(string $event, string $level, array $context): void
  {
    if (!function_exists('audit_log')) {
      return;
    }

    audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'jobs_' . trim($event), trim($level), $context);
  }

  /**
   * Encodes job payload to JSON.
   *
   * @param array<string,mixed> $payload
   * @return string
   */
  function channel_bridge_jobs_payload_encode(array $payload): string
  {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '{}';
  }

  /**
   * Decodes job payload from JSON.
   *
   * @param string $payloadJson
   * @return array<string,mixed>
   */
  function channel_bridge_jobs_payload_decode(string $payloadJson): array
  {
    $decoded = json_decode($payloadJson, true);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Returns available_at datetime with delay.
   *
   * @param int $delaySeconds
   * @return string
   */
  function channel_bridge_jobs_available_at(int $delaySeconds): string
  {
    return date('Y-m-d H:i:s', time() + max(0, $delaySeconds));
  }

  /**
   * Returns retry delay for attempt number (1-based).
   *
   * @param int $attempt
   * @return int
   */
  function channel_bridge_jobs_retry_delay(int $attempt): int
  {
    $stairs = CHANNEL_BRIDGE_JOBS_RETRY_DELAYS_SECONDS;
    if (!$stairs) {
      return 2;
    }
    $attempt = max(1, $attempt);
    $idx = min(count($stairs) - 1, $attempt - 1);
    return max(1, (int)($stairs[$idx] ?? 2));
  }

  /**
   * Builds canonical idempotent job key.
   *
   * @param string $jobType
   * @param string $sourceChatId
   * @param string $sourceMessageId
   * @param string $mediaGroupId
   * @return string
   */
  function channel_bridge_jobs_make_key(string $jobType, string $sourceChatId, string $sourceMessageId = '', string $mediaGroupId = ''): string
  {
    $sourceChatId = channel_bridge_norm_chat_id($sourceChatId);
    $sourceMessageId = trim($sourceMessageId);
    $mediaGroupId = trim($mediaGroupId);

    if ($jobType === CHANNEL_BRIDGE_JOB_TYPE_SINGLE_POST) {
      return 'single:' . $sourceChatId . ':' . $sourceMessageId;
    }
    if ($jobType === CHANNEL_BRIDGE_JOB_TYPE_ALBUM_FINALIZE) {
      return 'album_finalize:' . $sourceChatId . ':' . $mediaGroupId;
    }
    if ($jobType === CHANNEL_BRIDGE_JOB_TYPE_ALBUM_PUBLISH || $jobType === CHANNEL_BRIDGE_JOB_TYPE_RETRY_PUBLISH) {
      return 'album_publish:' . $sourceChatId . ':' . $mediaGroupId;
    }

    return trim($jobType) . ':' . hash('sha256', $sourceChatId . '|' . $sourceMessageId . '|' . $mediaGroupId);
  }

  /**
   * Enqueues job row with idempotency by unique job_key.
   *
   * @param PDO $pdo
   * @param string $jobType
   * @param string $jobKey
   * @param array<string,mixed> $payload
   * @param int $delaySeconds
   * @return array<string,mixed>
   */
  function channel_bridge_jobs_enqueue(PDO $pdo, string $jobType, string $jobKey, array $payload, int $delaySeconds = 0): array
  {
    if (!channel_bridge_jobs_table_available($pdo)) {
      return ['ok' => false, 'reason' => 'jobs_table_missing'];
    }

    $jobType = trim($jobType);
    $jobKey = trim($jobKey);
    if ($jobType === '' || $jobKey === '') {
      return ['ok' => false, 'reason' => 'job_meta_required'];
    }

    $st = $pdo->prepare("
      INSERT INTO " . CHANNEL_BRIDGE_TABLE_JOBS . "
      (
        job_type, job_key, status, payload_json, attempts, available_at, locked_at, locked_by, last_error
      )
      VALUES
      (
        :job_type, :job_key, :status_new, :payload_json, 0, :available_at, NULL, '', ''
      )
      ON DUPLICATE KEY UPDATE
        payload_json = IF(status = :status_failed_1, VALUES(payload_json), payload_json),
        status = IF(status = :status_failed_2, VALUES(status), status),
        available_at = IF(status = :status_failed_3, VALUES(available_at), available_at),
        locked_at = IF(status = :status_failed_4, NULL, locked_at),
        locked_by = IF(status = :status_failed_5, '', locked_by),
        last_error = IF(status = :status_failed_6, '', last_error),
        updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([
      ':job_type' => $jobType,
      ':job_key' => $jobKey,
      ':status_new' => CHANNEL_BRIDGE_JOB_STATUS_NEW,
      ':status_failed_1' => CHANNEL_BRIDGE_JOB_STATUS_FAILED,
      ':status_failed_2' => CHANNEL_BRIDGE_JOB_STATUS_FAILED,
      ':status_failed_3' => CHANNEL_BRIDGE_JOB_STATUS_FAILED,
      ':status_failed_4' => CHANNEL_BRIDGE_JOB_STATUS_FAILED,
      ':status_failed_5' => CHANNEL_BRIDGE_JOB_STATUS_FAILED,
      ':status_failed_6' => CHANNEL_BRIDGE_JOB_STATUS_FAILED,
      ':payload_json' => channel_bridge_jobs_payload_encode($payload),
      ':available_at' => channel_bridge_jobs_available_at($delaySeconds),
    ]);

    return [
      'ok' => true,
      'created' => ((int)$st->rowCount() === 1) ? 1 : 0,
      'job' => channel_bridge_jobs_find_by_key($pdo, $jobKey),
    ];
  }

  /**
   * Finds job by unique key.
   *
   * @param PDO $pdo
   * @param string $jobKey
   * @return array<string,mixed>
   */
  function channel_bridge_jobs_find_by_key(PDO $pdo, string $jobKey): array
  {
    $jobKey = trim($jobKey);
    if ($jobKey === '') {
      return [];
    }

    $st = $pdo->prepare("
      SELECT *
      FROM " . CHANNEL_BRIDGE_TABLE_JOBS . "
      WHERE job_key = :job_key
      LIMIT 1
    ");
    $st->execute([':job_key' => $jobKey]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
      return [];
    }
    $row['payload'] = channel_bridge_jobs_payload_decode((string)($row['payload_json'] ?? '{}'));
    return $row;
  }

  /**
   * Claims one available new job.
   *
   * @param PDO $pdo
   * @param string $workerId
   * @return array<string,mixed>
   */
  function channel_bridge_jobs_claim(PDO $pdo, string $workerId): array
  {
    if (!channel_bridge_jobs_table_available($pdo)) {
      return [];
    }

    $workerId = trim($workerId);
    if ($workerId === '') {
      return [];
    }

    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("
        SELECT *
        FROM " . CHANNEL_BRIDGE_TABLE_JOBS . "
        WHERE status = :status_new
          AND available_at <= :now_at
        ORDER BY available_at ASC, id ASC
        LIMIT 1
        FOR UPDATE
      ");
      $st->execute([
        ':status_new' => CHANNEL_BRIDGE_JOB_STATUS_NEW,
        ':now_at' => channel_bridge_now(),
      ]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!is_array($row)) {
        $pdo->commit();
        return [];
      }

      $upd = $pdo->prepare("
        UPDATE " . CHANNEL_BRIDGE_TABLE_JOBS . "
        SET
          status = :status_processing,
          locked_at = :locked_at,
          locked_by = :locked_by,
          updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
          AND status = :status_new
      ");
      $upd->execute([
        ':status_processing' => CHANNEL_BRIDGE_JOB_STATUS_PROCESSING,
        ':locked_at' => channel_bridge_now(),
        ':locked_by' => $workerId,
        ':id' => (int)($row['id'] ?? 0),
        ':status_new' => CHANNEL_BRIDGE_JOB_STATUS_NEW,
      ]);
      if ((int)$upd->rowCount() !== 1) {
        $pdo->rollBack();
        return [];
      }

      $pdo->commit();
      $row['status'] = CHANNEL_BRIDGE_JOB_STATUS_PROCESSING;
      $row['locked_at'] = channel_bridge_now();
      $row['locked_by'] = $workerId;
      $row['payload'] = channel_bridge_jobs_payload_decode((string)($row['payload_json'] ?? '{}'));
      return $row;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }
  }

  /**
   * Marks job as done.
   *
   * @param PDO $pdo
   * @param int $jobId
   * @return bool
   */
  function channel_bridge_jobs_mark_done(PDO $pdo, int $jobId): bool
  {
    $jobId = (int)$jobId;
    if ($jobId <= 0) {
      return false;
    }

    $st = $pdo->prepare("
      UPDATE " . CHANNEL_BRIDGE_TABLE_JOBS . "
      SET
        status = :status_done,
        locked_at = NULL,
        locked_by = '',
        last_error = '',
        updated_at = CURRENT_TIMESTAMP
      WHERE id = :id
    ");
    $st->execute([
      ':status_done' => CHANNEL_BRIDGE_JOB_STATUS_DONE,
      ':id' => $jobId,
    ]);
    return ((int)$st->rowCount() === 1);
  }

  /**
   * Marks job as failed.
   *
   * @param PDO $pdo
   * @param int $jobId
   * @param string $error
   * @return bool
   */
  function channel_bridge_jobs_mark_failed(PDO $pdo, int $jobId, string $error): bool
  {
    $jobId = (int)$jobId;
    if ($jobId <= 0) {
      return false;
    }

    $st = $pdo->prepare("
      UPDATE " . CHANNEL_BRIDGE_TABLE_JOBS . "
      SET
        status = :status_failed,
        locked_at = NULL,
        locked_by = '',
        last_error = :last_error,
        updated_at = CURRENT_TIMESTAMP
      WHERE id = :id
    ");
    $st->execute([
      ':status_failed' => CHANNEL_BRIDGE_JOB_STATUS_FAILED,
      ':last_error' => mb_substr(trim($error), 0, 250),
      ':id' => $jobId,
    ]);
    return ((int)$st->rowCount() === 1);
  }

  /**
   * Requeues job with delay.
   *
   * @param PDO $pdo
   * @param int $jobId
   * @param string $error
   * @param int $delaySeconds
   * @param bool $incrementAttempts
   * @param string $nextJobType
   * @param array<string,mixed>|null $payload
   * @return bool
   */
  function channel_bridge_jobs_requeue(
    PDO $pdo,
    int $jobId,
    string $error,
    int $delaySeconds,
    bool $incrementAttempts = true,
    string $nextJobType = '',
    ?array $payload = null
  ): bool {
    $jobId = (int)$jobId;
    if ($jobId <= 0) {
      return false;
    }

    $delaySeconds = max(0, $delaySeconds);
    $nextJobType = trim($nextJobType);
    $payloadJson = is_array($payload) ? channel_bridge_jobs_payload_encode($payload) : '';

    $sql = "
      UPDATE " . CHANNEL_BRIDGE_TABLE_JOBS . "
      SET
        status = :status_new,
        available_at = :available_at,
        locked_at = NULL,
        locked_by = '',
        last_error = :last_error,
        attempts = attempts " . ($incrementAttempts ? "+ 1" : "+ 0") . ",
        updated_at = CURRENT_TIMESTAMP
    ";
    if ($nextJobType !== '') {
      $sql .= ", job_type = :job_type";
    }
    if ($payloadJson !== '') {
      $sql .= ", payload_json = :payload_json";
    }
    $sql .= " WHERE id = :id";

    $st = $pdo->prepare($sql);
    $bind = [
      ':status_new' => CHANNEL_BRIDGE_JOB_STATUS_NEW,
      ':available_at' => channel_bridge_jobs_available_at($delaySeconds),
      ':last_error' => mb_substr(trim($error), 0, 250),
      ':id' => $jobId,
    ];
    if ($nextJobType !== '') {
      $bind[':job_type'] = $nextJobType;
    }
    if ($payloadJson !== '') {
      $bind[':payload_json'] = $payloadJson;
    }

    $st->execute($bind);
    return ((int)$st->rowCount() === 1);
  }

  /**
   * Checks if error should be retried.
   *
   * @param string $reason
   * @param string $message
   * @return bool
   */
  function channel_bridge_jobs_is_retryable_error(string $reason, string $message): bool
  {
    $text = strtolower(trim($reason . ' ' . $message));
    $hardFail = [
      'forbidden',
      'bad request',
      'chat not found',
      'not found',
      'channel_not_registered',
      'route_not_found',
      'bad_meta',
      'state_payload_missing',
      'module_disabled',
      'skip_no_photo',
    ];
    foreach ($hardFail as $needle) {
      if ($needle !== '' && strpos($text, $needle) !== false) {
        return false;
      }
    }

    $transient = [
      'timeout',
      'timed out',
      'connection',
      'temporar',
      'http_5',
      'http 5',
      'gateway',
      'rate limit',
      'too many requests',
      'network',
      'curl',
    ];
    foreach ($transient as $needle) {
      if ($needle !== '' && strpos($text, $needle) !== false) {
        return true;
      }
    }

    return false;
  }

  /**
   * Converts ingest result to queue action.
   *
   * @param array<string,mixed> $result
   * @return array<string,mixed>
   */
  function channel_bridge_jobs_classify_ingest_result(array $result): array
  {
    $ok = (($result['ok'] ?? false) === true);
    $reason = trim((string)($result['reason'] ?? ''));
    $message = trim((string)($result['message'] ?? ''));
    $sent = (int)($result['sent'] ?? 0);
    $failed = (int)($result['failed'] ?? 0);

    if ($ok && $failed === 0) {
      return ['status' => 'done', 'reason' => ($reason !== '' ? $reason : 'publish_done')];
    }
    if ($ok && $sent > 0 && $failed > 0) {
      return ['status' => 'failed', 'reason' => 'partial_dispatch', 'error' => ($message !== '' ? $message : $reason)];
    }
    if ($ok && $reason === 'media_group_pending') {
      return ['status' => 'pending', 'reason' => 'media_group_pending', 'delay_seconds' => 1];
    }
    if (channel_bridge_jobs_is_retryable_error($reason, $message)) {
      return ['status' => 'retry', 'reason' => ($reason !== '' ? $reason : 'temporary_error'), 'error' => ($message !== '' ? $message : $reason)];
    }

    return ['status' => 'failed', 'reason' => ($reason !== '' ? $reason : 'publish_failed'), 'error' => ($message !== '' ? $message : $reason)];
  }

  /**
   * Syncs downloaded photo statuses into TG state tables.
   *
   * @param PDO $pdo
   * @param array<string,mixed> $payload
   * @return void
   */
  function channel_bridge_jobs_sync_tg_photo_state(PDO $pdo, array $payload): void
  {
    channel_bridge_tg_state_sync_photo_state($pdo, $payload);
  }

  /**
   * Claims album for queue flow when quiet window is reached.
   *
   * @param PDO $pdo
   * @param string $sourceChatId
   * @param string $mediaGroupId
   * @param int $quietMs
   * @return array<string,mixed>
   */
  function channel_bridge_tg_state_album_try_claim_queue(PDO $pdo, string $sourceChatId, string $mediaGroupId, int $quietMs): array
  {
    $sourceChatId = channel_bridge_norm_chat_id($sourceChatId);
    $mediaGroupId = trim($mediaGroupId);
    $quietMs = max(200, min(20000, $quietMs));
    if ($sourceChatId === '' || $mediaGroupId === '') {
      return ['mode' => 'error', 'error' => 'bad_meta'];
    }

    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("
        SELECT *
        FROM " . CHANNEL_BRIDGE_TABLE_TG_ALBUMS . "
        WHERE source_chat_id = :source_chat_id
          AND media_group_id = :media_group_id
        LIMIT 1
        FOR UPDATE
      ");
      $st->execute([
        ':source_chat_id' => $sourceChatId,
        ':media_group_id' => $mediaGroupId,
      ]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!is_array($row)) {
        $pdo->commit();
        return ['mode' => 'error', 'error' => 'album_not_found'];
      }

      $dispatchStatus = trim((string)($row['dispatch_status'] ?? 'pending'));
      if ($dispatchStatus === 'sent') {
        $pdo->commit();
        return ['mode' => 'sent', 'reason' => 'already_sent'];
      }

      if ($dispatchStatus === 'dispatching') {
        $startedTs = channel_bridge_tg_state_datetime_ts((string)($row['dispatch_started_at'] ?? ''));
        if ($startedTs > 0 && (time() - $startedTs) >= CHANNEL_BRIDGE_MEDIA_GROUP_DISPATCH_STALE_SECONDS) {
          $pdo->prepare("
            UPDATE " . CHANNEL_BRIDGE_TABLE_TG_ALBUMS . "
            SET
              dispatch_status = 'pending',
              dispatch_token = '',
              dispatch_revision = 0,
              dispatch_started_at = NULL
            WHERE id = :id
          ")->execute([':id' => (int)($row['id'] ?? 0)]);
          $dispatchStatus = 'pending';
        }
      }

      if ($dispatchStatus === 'dispatching') {
        $pdo->commit();
        return ['mode' => 'pending', 'reason' => 'dispatching'];
      }

      $itemsCount = (int)($row['items_count'] ?? 0);
      $lastSeenTs = channel_bridge_tg_state_datetime_ts((string)($row['last_seen_at'] ?? ''));
      $firstSeenTs = channel_bridge_tg_state_datetime_ts((string)($row['first_seen_at'] ?? ''));
      $photosTotal = (int)($row['photos_total'] ?? 0);
      $photosDownloaded = (int)($row['photos_downloaded'] ?? 0);
      $photosFailed = (int)($row['photos_failed'] ?? 0);
      if ($lastSeenTs <= 0) {
        $lastSeenTs = time();
      }
      if ($firstSeenTs <= 0) {
        $firstSeenTs = $lastSeenTs;
      }
      $idleMs = max(0, (int)round((microtime(true) - $lastSeenTs) * 1000));
      $ageMs = max(0, (int)round((microtime(true) - $firstSeenTs) * 1000));
      if ($itemsCount < (int)CHANNEL_BRIDGE_MEDIA_GROUP_MIN_ITEMS) {
        $pdo->commit();
        return [
          'mode' => 'pending',
          'reason' => 'await_more_items',
          'idle_ms' => $idleMs,
          'age_ms' => $ageMs,
          'min_items' => (int)CHANNEL_BRIDGE_MEDIA_GROUP_MIN_ITEMS,
        ];
      }
      if ($ageMs < CHANNEL_BRIDGE_MEDIA_GROUP_MIN_AGE_MS) {
        $pdo->commit();
        return [
          'mode' => 'pending',
          'reason' => 'await_window',
          'idle_ms' => $idleMs,
          'age_ms' => $ageMs,
        ];
      }
      if ($idleMs < $quietMs) {
        $pdo->commit();
        return ['mode' => 'pending', 'reason' => 'await_idle', 'idle_ms' => $idleMs, 'age_ms' => $ageMs];
      }

      $dispatchToken = hash('sha256', $sourceChatId . '|' . $mediaGroupId . '|' . microtime(true) . '|' . mt_rand(1, PHP_INT_MAX));
      $pdo->prepare("
        UPDATE " . CHANNEL_BRIDGE_TABLE_TG_ALBUMS . "
        SET
          dispatch_status = 'dispatching',
          dispatch_token = :dispatch_token,
          dispatch_revision = revision,
          dispatch_started_at = :dispatch_started_at,
          last_error = ''
        WHERE id = :id
      ")->execute([
        ':dispatch_token' => $dispatchToken,
        ':dispatch_started_at' => channel_bridge_now(),
        ':id' => (int)($row['id'] ?? 0),
      ]);
      $pdo->commit();

      return [
        'mode' => 'ready',
        'dispatch_token' => $dispatchToken,
        'idle_ms' => $idleMs,
        'age_ms' => $ageMs,
        'photos_total' => $photosTotal,
        'photos_downloaded' => $photosDownloaded,
        'photos_failed' => $photosFailed,
      ];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      throw $e;
    }
  }

  /**
   * Handles one claimed queue job.
   *
   * @param PDO $pdo
   * @param array<string,mixed> $settings
   * @param array<string,mixed> $job
   * @return array<string,mixed>
   */
  function channel_bridge_jobs_handle_claimed_job(PDO $pdo, array $settings, array $job): array
  {
    $jobType = trim((string)($job['job_type'] ?? ''));
    $payload = is_array($job['payload'] ?? null) ? (array)$job['payload'] : [];
    $sourceChatId = channel_bridge_norm_chat_id((string)($payload['source_chat_id'] ?? ''));
    $sourceMessageId = trim((string)($payload['source_message_id'] ?? ''));
    $mediaGroupId = trim((string)($payload['media_group_id'] ?? ''));
    $dispatchToken = trim((string)($payload['dispatch_token'] ?? ''));

    if ($jobType === CHANNEL_BRIDGE_JOB_TYPE_SINGLE_POST) {
      if ($sourceChatId === '' || $sourceMessageId === '') {
        return ['status' => 'failed', 'reason' => 'bad_meta', 'error' => 'single meta required'];
      }

      $dispatchPayload = channel_bridge_tg_state_single_payload_from_db($pdo, $sourceChatId, $sourceMessageId);
      if (!$dispatchPayload) {
        return ['status' => 'failed', 'reason' => 'state_payload_missing', 'error' => 'single payload not found'];
      }

      $dispatchPayload = channel_bridge_tg_preload_photos($settings, $dispatchPayload);
      channel_bridge_jobs_sync_tg_photo_state($pdo, $dispatchPayload);
      return channel_bridge_jobs_classify_ingest_result(channel_bridge_ingest($pdo, $dispatchPayload));
    }

    if ($jobType === CHANNEL_BRIDGE_JOB_TYPE_ALBUM_FINALIZE) {
      if ($sourceChatId === '' || $mediaGroupId === '') {
        return ['status' => 'failed', 'reason' => 'bad_meta', 'error' => 'album finalize meta required'];
      }

      $claim = channel_bridge_tg_state_album_try_claim_queue($pdo, $sourceChatId, $mediaGroupId, CHANNEL_BRIDGE_JOBS_ALBUM_QUIET_MS);
      $mode = trim((string)($claim['mode'] ?? ''));
      if ($mode === 'sent') {
        return ['status' => 'done', 'reason' => 'already_sent'];
      }
      if ($mode === 'pending') {
        return ['status' => 'pending', 'reason' => trim((string)($claim['reason'] ?? 'await_idle')), 'delay_seconds' => 1];
      }
      if ($mode !== 'ready') {
        $error = trim((string)($claim['error'] ?? 'album_claim_failed'));
        if (channel_bridge_jobs_is_retryable_error('album_claim', $error)) {
          return ['status' => 'retry', 'reason' => 'album_claim_retry', 'error' => $error];
        }
        return ['status' => 'failed', 'reason' => 'album_claim_failed', 'error' => $error];
      }

      $publishPayload = [
        'source_chat_id' => $sourceChatId,
        'media_group_id' => $mediaGroupId,
        'dispatch_token' => trim((string)($claim['dispatch_token'] ?? '')),
      ];
      $publishKey = channel_bridge_jobs_make_key(CHANNEL_BRIDGE_JOB_TYPE_ALBUM_PUBLISH, $sourceChatId, '', $mediaGroupId);
      $enq = channel_bridge_jobs_enqueue($pdo, CHANNEL_BRIDGE_JOB_TYPE_ALBUM_PUBLISH, $publishKey, $publishPayload, 0);
      if (($enq['ok'] ?? false) !== true) {
        return ['status' => 'retry', 'reason' => 'album_publish_enqueue_failed', 'error' => trim((string)($enq['reason'] ?? 'enqueue_failed'))];
      }

      return ['status' => 'done', 'reason' => 'album_publish_enqueued'];
    }

    if ($jobType === CHANNEL_BRIDGE_JOB_TYPE_VK_CLEANUP) {
      $taskId = (int)($payload['task_id'] ?? 0);
      if ($taskId <= 0) {
        return ['status' => 'failed', 'reason' => 'bad_meta', 'error' => 'cleanup task_id required'];
      }

      return channel_bridge_vk_cleanup_handle_task_job($pdo, $settings, $taskId);
    }

    if (!in_array($jobType, [CHANNEL_BRIDGE_JOB_TYPE_ALBUM_PUBLISH, CHANNEL_BRIDGE_JOB_TYPE_RETRY_PUBLISH], true)) {
      return ['status' => 'failed', 'reason' => 'job_type_not_supported', 'error' => 'unsupported job type'];
    }

    if ($sourceChatId === '' || $mediaGroupId === '') {
      return ['status' => 'failed', 'reason' => 'bad_meta', 'error' => 'album publish meta required'];
    }

    if ($dispatchToken === '') {
      $claim = channel_bridge_tg_state_album_try_claim_queue($pdo, $sourceChatId, $mediaGroupId, CHANNEL_BRIDGE_JOBS_ALBUM_QUIET_MS);
      $mode = trim((string)($claim['mode'] ?? ''));
      if ($mode === 'sent') {
        return ['status' => 'done', 'reason' => 'already_sent'];
      }
      if ($mode === 'pending') {
        return ['status' => 'pending', 'reason' => trim((string)($claim['reason'] ?? 'await_idle')), 'delay_seconds' => 1];
      }
      if ($mode !== 'ready') {
        $error = trim((string)($claim['error'] ?? 'album_claim_failed'));
        if (channel_bridge_jobs_is_retryable_error('album_claim', $error)) {
          return ['status' => 'retry', 'reason' => 'album_claim_retry', 'error' => $error];
        }
        return ['status' => 'failed', 'reason' => 'album_claim_failed', 'error' => $error];
      }
      $dispatchToken = trim((string)($claim['dispatch_token'] ?? ''));
      $payload['dispatch_token'] = $dispatchToken;
    }

    $dispatchPayload = channel_bridge_tg_state_album_payload_from_db($pdo, $sourceChatId, $mediaGroupId);
    if (!$dispatchPayload) {
      if ($dispatchToken !== '') {
        channel_bridge_tg_state_album_finish($pdo, $sourceChatId, $mediaGroupId, $dispatchToken, false, 'TG_STATE_PAYLOAD_EMPTY');
      }
      return ['status' => 'failed', 'reason' => 'state_payload_missing', 'error' => 'album payload not found'];
    }

    $dispatchPayload = channel_bridge_tg_preload_photos($settings, $dispatchPayload);
    channel_bridge_jobs_sync_tg_photo_state($pdo, $dispatchPayload);

    $classified = channel_bridge_jobs_classify_ingest_result(channel_bridge_ingest($pdo, $dispatchPayload));
    $classified['payload'] = $payload;
    if (trim((string)($classified['status'] ?? '')) === 'done') {
      if ($dispatchToken !== '') {
        channel_bridge_tg_state_album_finish($pdo, $sourceChatId, $mediaGroupId, $dispatchToken, true, '');
      }
      return $classified;
    }
    if (trim((string)($classified['status'] ?? '')) === 'retry') {
      return $classified;
    }

    if ($dispatchToken !== '') {
      $err = trim((string)($classified['error'] ?? $classified['reason'] ?? 'dispatch_failed'));
      channel_bridge_tg_state_album_finish($pdo, $sourceChatId, $mediaGroupId, $dispatchToken, false, $err);
    }
    return $classified;
  }

  /**
   * Runs one short worker loop.
   *
   * @param PDO $pdo
   * @param array<string,mixed> $settings
   * @param array<string,mixed> $options
   * @return array<string,mixed>
   */
  function channel_bridge_jobs_run_worker_loop(PDO $pdo, array $settings, array $options = []): array
  {
    $maxSeconds = max(1, min(30, (int)($options['max_seconds'] ?? 8)));
    $maxJobs = max(1, min(200, (int)($options['max_jobs'] ?? 25)));
    $idleSleepUs = max(50000, min(500000, (int)($options['idle_sleep_us'] ?? 200000)));
    $idleWaitFirstClaimMs = max(200, min(3000, (int)($options['idle_wait_first_claim_ms'] ?? 1500)));
    $idleWaitAfterWorkMs = max(100, min(2000, (int)($options['idle_wait_after_work_ms'] ?? 500)));
    $workerId = trim((string)($options['worker_id'] ?? ''));
    if ($workerId === '') {
      $workerId = 'cbw-' . getmypid() . '-' . substr(sha1((string)microtime(true)), 0, 8);
    }

    $watchdog = channel_bridge_jobs_watchdog($pdo, 30);
    $deadline = microtime(true) + $maxSeconds;
    $claimed = 0;
    $done = 0;
    $retried = 0;
    $failed = 0;
    $pending = 0;
    $idleSince = 0.0;

    while ($claimed < $maxJobs && microtime(true) < $deadline) {
      $job = channel_bridge_jobs_claim($pdo, $workerId);
      if (!$job) {
        $now = microtime(true);
        if ($idleSince <= 0.0) {
          $idleSince = $now;
        }

        $idleMs = (int)(($now - $idleSince) * 1000);
        $waitBudgetMs = ($claimed > 0) ? $idleWaitAfterWorkMs : $idleWaitFirstClaimMs;
        if ($idleMs >= $waitBudgetMs) {
          break;
        }

        usleep($idleSleepUs);
        continue;
      }

      $idleSince = 0.0;
      $claimed++;

      $jobId = (int)($job['id'] ?? 0);
      $jobType = trim((string)($job['job_type'] ?? ''));
      $jobKey = trim((string)($job['job_key'] ?? ''));
      $result = channel_bridge_jobs_handle_claimed_job($pdo, $settings, $job);
      $status = trim((string)($result['status'] ?? 'failed'));
      $reason = trim((string)($result['reason'] ?? ''));
      $error = trim((string)($result['error'] ?? $reason));
      $payloadOverride = is_array($result['payload'] ?? null) ? (array)$result['payload'] : null;

      if ($status === 'done') {
        channel_bridge_jobs_mark_done($pdo, $jobId);
        $done++;
        channel_bridge_jobs_audit('publish_done', 'info', ['job_id' => $jobId, 'job_type' => $jobType, 'job_key' => $jobKey, 'reason' => $reason]);
        continue;
      }

      if ($status === 'pending') {
        $delay = max(1, (int)($result['delay_seconds'] ?? 1));
        channel_bridge_jobs_requeue($pdo, $jobId, ($reason !== '' ? $reason : 'pending'), $delay, false, '', $payloadOverride);
        $pending++;
        continue;
      }

      if ($status === 'retry') {
        $attempt = (int)($job['attempts'] ?? 0) + 1;
        if ($attempt > CHANNEL_BRIDGE_JOBS_MAX_ATTEMPTS) {
          $payload = is_array($job['payload'] ?? null) ? (array)$job['payload'] : [];
          if ($jobType === CHANNEL_BRIDGE_JOB_TYPE_VK_CLEANUP) {
            channel_bridge_vk_cleanup_abort_task_from_payload($pdo, $payload, ($error !== '' ? $error : 'retry_limit_reached'));
          }
          $chatId = channel_bridge_norm_chat_id((string)($payload['source_chat_id'] ?? ''));
          $groupId = trim((string)($payload['media_group_id'] ?? ''));
          $token = trim((string)($payload['dispatch_token'] ?? ''));
          if ($chatId !== '' && $groupId !== '' && $token !== '' && in_array($jobType, [CHANNEL_BRIDGE_JOB_TYPE_ALBUM_PUBLISH, CHANNEL_BRIDGE_JOB_TYPE_RETRY_PUBLISH], true)) {
            channel_bridge_tg_state_album_finish($pdo, $chatId, $groupId, $token, false, ($error !== '' ? $error : 'retry_limit_reached'));
          }
          channel_bridge_jobs_mark_failed($pdo, $jobId, ($error !== '' ? $error : 'retry_limit_reached'));
          $failed++;
          continue;
        }

        $delay = channel_bridge_jobs_retry_delay($attempt);
        $nextType = ($jobType === CHANNEL_BRIDGE_JOB_TYPE_ALBUM_PUBLISH) ? CHANNEL_BRIDGE_JOB_TYPE_RETRY_PUBLISH : '';
        channel_bridge_jobs_requeue($pdo, $jobId, $error, $delay, true, $nextType, $payloadOverride);
        $retried++;
        channel_bridge_jobs_audit('retry_scheduled', 'warn', ['job_id' => $jobId, 'job_type' => $jobType, 'job_key' => $jobKey, 'attempt' => $attempt, 'delay_seconds' => $delay, 'error' => $error]);
        continue;
      }

      if ($jobType === CHANNEL_BRIDGE_JOB_TYPE_VK_CLEANUP) {
        $payload = is_array($job['payload'] ?? null) ? (array)$job['payload'] : [];
        channel_bridge_vk_cleanup_abort_task_from_payload($pdo, $payload, ($error !== '' ? $error : 'job_failed'));
      }
      channel_bridge_jobs_mark_failed($pdo, $jobId, ($error !== '' ? $error : 'job_failed'));
      $failed++;
      channel_bridge_jobs_audit('failed', 'error', ['job_id' => $jobId, 'job_type' => $jobType, 'job_key' => $jobKey, 'reason' => $reason, 'error' => $error]);
    }

    return [
      'ok' => true,
      'worker_id' => $workerId,
      'claimed' => $claimed,
      'done' => $done,
      'retried' => $retried,
      'failed' => $failed,
      'pending' => $pending,
      'watchdog_requeued' => (int)($watchdog['requeued'] ?? 0),
      'watchdog_failed' => (int)($watchdog['failed'] ?? 0),
      'finished_at' => channel_bridge_now(),
    ];
  }

  /**
   * Requeues stale processing jobs or marks them failed.
   *
   * @param PDO $pdo
   * @param int $limit
   * @return array<string,int>
   */
  function channel_bridge_jobs_watchdog(PDO $pdo, int $limit = 50): array
  {
    if (!channel_bridge_jobs_table_available($pdo)) {
      return ['requeued' => 0, 'failed' => 0];
    }

    $limit = max(1, min(200, $limit));
    $staleAt = date('Y-m-d H:i:s', time() - CHANNEL_BRIDGE_JOBS_LOCK_STALE_SECONDS);
    $st = $pdo->prepare("
      SELECT id, attempts, job_type, payload_json
      FROM " . CHANNEL_BRIDGE_TABLE_JOBS . "
      WHERE status = :status_processing
        AND locked_at IS NOT NULL
        AND locked_at <= :stale_at
      ORDER BY locked_at ASC, id ASC
      LIMIT " . $limit . "
    ");
    $st->execute([
      ':status_processing' => CHANNEL_BRIDGE_JOB_STATUS_PROCESSING,
      ':stale_at' => $staleAt,
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
      $rows = [];
    }

    $requeued = 0;
    $failed = 0;
    foreach ($rows as $row) {
      $id = (int)($row['id'] ?? 0);
      $attempts = (int)($row['attempts'] ?? 0);
      if ($id <= 0) {
        continue;
      }

      if ($attempts >= CHANNEL_BRIDGE_JOBS_MAX_ATTEMPTS) {
        $jobType = trim((string)($row['job_type'] ?? ''));
        if ($jobType === CHANNEL_BRIDGE_JOB_TYPE_VK_CLEANUP) {
          $payload = channel_bridge_jobs_payload_decode((string)($row['payload_json'] ?? '{}'));
          channel_bridge_vk_cleanup_abort_task_from_payload($pdo, $payload, 'worker_stale_timeout');
        }
        if (in_array($jobType, [CHANNEL_BRIDGE_JOB_TYPE_ALBUM_PUBLISH, CHANNEL_BRIDGE_JOB_TYPE_RETRY_PUBLISH], true)) {
          $payload = channel_bridge_jobs_payload_decode((string)($row['payload_json'] ?? '{}'));
          $chatId = channel_bridge_norm_chat_id((string)($payload['source_chat_id'] ?? ''));
          $groupId = trim((string)($payload['media_group_id'] ?? ''));
          $token = trim((string)($payload['dispatch_token'] ?? ''));
          if ($chatId !== '' && $groupId !== '' && $token !== '') {
            channel_bridge_tg_state_album_finish($pdo, $chatId, $groupId, $token, false, 'worker_stale_timeout');
          }
        }
        if (channel_bridge_jobs_mark_failed($pdo, $id, 'worker_stale_timeout')) {
          $failed++;
        }
        continue;
      }

      $delay = channel_bridge_jobs_retry_delay(max(1, $attempts + 1));
      if (channel_bridge_jobs_requeue($pdo, $id, 'worker_stale_retry', $delay, false, '', null)) {
        $requeued++;
      }
    }

    return ['requeued' => $requeued, 'failed' => $failed];
  }

  /**
   * Spawns short worker request asynchronously.
   *
   * @param array<string,mixed> $options
   * @return array<string,mixed>
   */
  function channel_bridge_jobs_spawn_worker_async(array $options = []): array
  {
    $token = channel_bridge_jobs_worker_auth_token();
    if ($token === '') {
      return ['ok' => false, 'error' => 'worker_token_empty'];
    }

    $baseUrls = channel_bridge_worker_endpoint_candidates(true);
    if (!$baseUrls) {
      return ['ok' => false, 'error' => 'worker_url_empty'];
    }

    $maxSeconds = max(1, min(30, (int)($options['max_seconds'] ?? 8)));
    $maxJobs = max(1, min(200, (int)($options['max_jobs'] ?? 20)));
    $timeout = (float)($options['timeout'] ?? 0.20);
    if ($timeout < 0.05) {
      $timeout = 0.05;
    }
    if ($timeout > 1.00) {
      $timeout = 1.00;
    }
    $query = [
      'token' => $token,
      'max_seconds' => $maxSeconds,
      'max_jobs' => $maxJobs,
    ];

    $last = ['ok' => false, 'error' => 'worker_spawn_failed'];
    foreach ($baseUrls as $baseUrl) {
      if ($baseUrl === '' || stripos($baseUrl, 'http') !== 0) {
        continue;
      }

      $url = $baseUrl . (strpos($baseUrl, '?') === false ? '?' : '&') . http_build_query($query);
      $spawn = channel_bridge_http_fire_and_forget($url, [], $timeout);
      if (($spawn['ok'] ?? false) === true) {
        return $spawn + ['endpoint' => $baseUrl];
      }
      $last = $spawn + ['endpoint' => $baseUrl];
    }

    return $last;
  }

  /**
   * Moves pending queue jobs to failed state without running worker.
   * Also releases claimed TG album dispatch tokens for publish jobs.
   *
   * @param PDO $pdo
   * @param string $errorText
   * @return array<string,mixed>
   */
  function channel_bridge_jobs_manual_reset_pending(PDO $pdo, string $errorText = 'manual_reset'): array
  {
    if (!channel_bridge_jobs_table_available($pdo)) {
      return ['ok' => false, 'reason' => 'jobs_table_missing'];
    }

    $errorText = trim($errorText);
    if ($errorText === '') {
      $errorText = 'manual_reset';
    }

    $st = $pdo->prepare("
      SELECT id, job_type, payload_json
      FROM " . CHANNEL_BRIDGE_TABLE_JOBS . "
      WHERE status IN (:status_new, :status_processing)
      ORDER BY id ASC
    ");
    $st->execute([
      ':status_new' => CHANNEL_BRIDGE_JOB_STATUS_NEW,
      ':status_processing' => CHANNEL_BRIDGE_JOB_STATUS_PROCESSING,
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
      $rows = [];
    }

    if (!$rows) {
      return [
        'ok' => true,
        'selected' => 0,
        'updated' => 0,
        'released_albums' => 0,
      ];
    }

    $upd = $pdo->prepare("
      UPDATE " . CHANNEL_BRIDGE_TABLE_JOBS . "
      SET
        status = :status_failed,
        locked_at = NULL,
        locked_by = '',
        last_error = :last_error,
        updated_at = CURRENT_TIMESTAMP
      WHERE status IN (:status_new, :status_processing)
    ");
    $upd->execute([
      ':status_failed' => CHANNEL_BRIDGE_JOB_STATUS_FAILED,
      ':status_new' => CHANNEL_BRIDGE_JOB_STATUS_NEW,
      ':status_processing' => CHANNEL_BRIDGE_JOB_STATUS_PROCESSING,
      ':last_error' => $errorText,
    ]);
    $updated = (int)$upd->rowCount();

    $releasedAlbums = 0;
    foreach ($rows as $row) {
      $jobType = trim((string)($row['job_type'] ?? ''));
      if ($jobType === CHANNEL_BRIDGE_JOB_TYPE_VK_CLEANUP) {
        $payload = channel_bridge_jobs_payload_decode((string)($row['payload_json'] ?? '{}'));
        channel_bridge_vk_cleanup_abort_task_from_payload($pdo, $payload, $errorText);
        continue;
      }
      if (!in_array($jobType, [CHANNEL_BRIDGE_JOB_TYPE_ALBUM_PUBLISH, CHANNEL_BRIDGE_JOB_TYPE_RETRY_PUBLISH], true)) {
        continue;
      }

      $payload = channel_bridge_jobs_payload_decode((string)($row['payload_json'] ?? '{}'));
      $sourceChatId = channel_bridge_norm_chat_id((string)($payload['source_chat_id'] ?? ''));
      $mediaGroupId = trim((string)($payload['media_group_id'] ?? ''));
      $dispatchToken = trim((string)($payload['dispatch_token'] ?? ''));
      if ($sourceChatId === '' || $mediaGroupId === '' || $dispatchToken === '') {
        continue;
      }

      $release = channel_bridge_tg_state_album_finish($pdo, $sourceChatId, $mediaGroupId, $dispatchToken, false, $errorText);
      if (trim((string)($release['reason'] ?? '')) === 'released') {
        $releasedAlbums++;
      }
    }

    return [
      'ok' => true,
      'selected' => count($rows),
      'updated' => $updated,
      'released_albums' => $releasedAlbums,
    ];
  }

  /**
   * Returns queue diagnostics for admin page.
   *
   * @param PDO $pdo
   * @return array<string,mixed>
   */
  function channel_bridge_jobs_stats(PDO $pdo): array
  {
    if (!channel_bridge_jobs_table_available($pdo)) {
      return [
        'available' => false,
        'new' => 0,
        'processing' => 0,
        'done' => 0,
        'failed' => 0,
        'stuck' => 0,
        'oldest_pending_age_sec' => 0,
        'last_errors' => [],
        'recent' => [],
      ];
    }

    $counts = [
      CHANNEL_BRIDGE_JOB_STATUS_NEW => 0,
      CHANNEL_BRIDGE_JOB_STATUS_PROCESSING => 0,
      CHANNEL_BRIDGE_JOB_STATUS_DONE => 0,
      CHANNEL_BRIDGE_JOB_STATUS_FAILED => 0,
    ];
    $rows = $pdo->query("
      SELECT status, COUNT(*) AS cnt
      FROM " . CHANNEL_BRIDGE_TABLE_JOBS . "
      GROUP BY status
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) {
      foreach ($rows as $row) {
        $status = trim((string)($row['status'] ?? ''));
        if (array_key_exists($status, $counts)) {
          $counts[$status] = (int)($row['cnt'] ?? 0);
        }
      }
    }

    $stuckAt = date('Y-m-d H:i:s', time() - CHANNEL_BRIDGE_JOBS_LOCK_STALE_SECONDS);
    $stuckSt = $pdo->prepare("
      SELECT COUNT(*)
      FROM " . CHANNEL_BRIDGE_TABLE_JOBS . "
      WHERE status = :status_processing
        AND locked_at IS NOT NULL
        AND locked_at <= :stale_at
    ");
    $stuckSt->execute([
      ':status_processing' => CHANNEL_BRIDGE_JOB_STATUS_PROCESSING,
      ':stale_at' => $stuckAt,
    ]);
    $stuck = (int)$stuckSt->fetchColumn();

    $oldestSt = $pdo->prepare("
      SELECT created_at
      FROM " . CHANNEL_BRIDGE_TABLE_JOBS . "
      WHERE status = :status_new
      ORDER BY created_at ASC, id ASC
      LIMIT 1
    ");
    $oldestSt->execute([':status_new' => CHANNEL_BRIDGE_JOB_STATUS_NEW]);
    $oldestAt = trim((string)$oldestSt->fetchColumn());
    $oldestAge = 0;
    if ($oldestAt !== '') {
      $ts = strtotime($oldestAt);
      if ($ts !== false) {
        $oldestAge = max(0, time() - $ts);
      }
    }

    $errors = [];
    $errRows = $pdo->query("
      SELECT id, job_type, job_key, attempts, last_error, updated_at
      FROM " . CHANNEL_BRIDGE_TABLE_JOBS . "
      WHERE status = '" . CHANNEL_BRIDGE_JOB_STATUS_FAILED . "'
      ORDER BY updated_at DESC, id DESC
      LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($errRows)) {
      foreach ($errRows as $row) {
        $errors[] = [
          'id' => (int)($row['id'] ?? 0),
          'job_type' => (string)($row['job_type'] ?? ''),
          'job_key' => (string)($row['job_key'] ?? ''),
          'attempts' => (int)($row['attempts'] ?? 0),
          'last_error' => (string)($row['last_error'] ?? ''),
          'updated_at' => (string)($row['updated_at'] ?? ''),
        ];
      }
    }

    $recent = [];
    $recentRows = $pdo->query("
      SELECT id, job_type, job_key, status, attempts, available_at, locked_at, updated_at, last_error
      FROM " . CHANNEL_BRIDGE_TABLE_JOBS . "
      ORDER BY id DESC
      LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($recentRows)) {
      foreach ($recentRows as $row) {
        $recent[] = [
          'id' => (int)($row['id'] ?? 0),
          'job_type' => (string)($row['job_type'] ?? ''),
          'job_key' => (string)($row['job_key'] ?? ''),
          'status' => (string)($row['status'] ?? ''),
          'attempts' => (int)($row['attempts'] ?? 0),
          'available_at' => (string)($row['available_at'] ?? ''),
          'locked_at' => (string)($row['locked_at'] ?? ''),
          'updated_at' => (string)($row['updated_at'] ?? ''),
          'last_error' => (string)($row['last_error'] ?? ''),
        ];
      }
    }

    return [
      'available' => true,
      'new' => (int)$counts[CHANNEL_BRIDGE_JOB_STATUS_NEW],
      'processing' => (int)$counts[CHANNEL_BRIDGE_JOB_STATUS_PROCESSING],
      'done' => (int)$counts[CHANNEL_BRIDGE_JOB_STATUS_DONE],
      'failed' => (int)$counts[CHANNEL_BRIDGE_JOB_STATUS_FAILED],
      'stuck' => $stuck,
      'oldest_pending_age_sec' => $oldestAge,
      'last_errors' => $errors,
      'recent' => $recent,
    ];
  }
}
