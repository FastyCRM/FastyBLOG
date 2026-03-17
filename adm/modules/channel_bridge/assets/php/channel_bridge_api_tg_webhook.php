<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_api_tg_webhook.php
 * ROLE: do=api_tg_webhook — обработка Telegram webhook (channel_post).
 *
 * ACCESS:
 *  - приоритетно: секрет из настроек модуля (X-Telegram-Bot-Api-Secret-Token),
 *  - fallback: internal API key (для диагностики/ручного вызова).
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_lib.php';

if (!function_exists('channel_bridge_spawn_mg_finalize')) {
  /**
   * Запускает асинхронный finalize media_group отдельным HTTP-запросом к себе.
   * Важно: основной Telegram webhook-ответ не блокируется этим вызовом.
   *
   * @param array<string,mixed> $payload
   * @return bool true, если async-запрос успешно отправлен в сокет
   */
  function channel_bridge_spawn_mg_finalize(array $payload): bool
  {
    $mediaGroupId = trim((string)($payload['tg_media_group_id'] ?? ''));
    $sourceChatId = trim((string)($payload['source_chat_id'] ?? ''));
    if ($mediaGroupId === '' || $sourceChatId === '') return false;

    $cfg = function_exists('app_config') ? (array)app_config() : [];
    $internalKey = trim((string)($cfg['internal_api']['key'] ?? ''));
    if ($internalKey === '') return false;

    $baseUrl = trim(channel_bridge_webhook_endpoint_url(true));
    if ($baseUrl === '' || stripos($baseUrl, 'http') !== 0) return false;

    $query = [
      'cb_mg_finalize' => '1',
    ];
    $url = $baseUrl . (strpos($baseUrl, '?') === false ? '?' : '&') . http_build_query($query);

    $u = @parse_url($url);
    if (!is_array($u)) return false;

    $scheme = strtolower(trim((string)($u['scheme'] ?? 'http')));
    $host = trim((string)($u['host'] ?? ''));
    if ($host === '') return false;
    $port = (int)($u['port'] ?? ($scheme === 'https' ? 443 : 80));
    if ($port <= 0) $port = ($scheme === 'https' ? 443 : 80);
    $path = trim((string)($u['path'] ?? '/'));
    if ($path === '') $path = '/';
    $q = trim((string)($u['query'] ?? ''));
    if ($q !== '') $path .= '?' . $q;

    $transport = ($scheme === 'https' ? 'ssl://' : '') . $host;
    $fp = @fsockopen($transport, $port, $errno, $errstr, 0.25);
    if (!$fp) return false;

    @stream_set_blocking($fp, false);

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) $body = '{}';

    $request =
      "POST " . $path . " HTTP/1.1\r\n" .
      "Host: " . $host . "\r\n" .
      "Content-Type: application/json\r\n" .
      "X-Internal-Api-Key: " . $internalKey . "\r\n" .
      "Connection: Close\r\n" .
      "Content-Length: " . strlen($body) . "\r\n\r\n" .
      $body;

    @fwrite($fp, $request);
    @fclose($fp);
    return true;
  }
}

try {
  $pdo = db();
  $settings = channel_bridge_settings_get($pdo);

  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'api_tg_webhook', 'info', [
    'stage' => 'start',
    'runtime_rev' => '2026-02-13-media-group-batch-v6-fallback-inline',
    'tg_enabled' => (int)($settings['tg_enabled'] ?? 0),
    'has_token' => (trim((string)($settings['tg_bot_token'] ?? '')) !== '') ? 1 : 0,
    'has_secret' => (trim((string)($settings['tg_webhook_secret'] ?? '')) !== '') ? 1 : 0,
  ]);

  $secret = trim((string)($settings['tg_webhook_secret'] ?? ''));
  /**
   * Важно:
   * - если секрет НЕ задан, webhook должен приниматься без секрета;
   * - если секрет задан, проверяем заголовок Telegram.
   */
  $secretOk = ($secret === '');
  if ($secret !== '' && function_exists('tg_verify_webhook_secret')) {
    $secretOk = tg_verify_webhook_secret($secret);
  }

  $internalKeyOk = channel_bridge_is_internal_key_request();
  if (!$secretOk && !$internalKeyOk) {
    audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'api_tg_webhook', 'warn', [
      'stage' => 'forbidden',
      'reason' => 'secret_or_key_failed',
    ]);
    json_err('Forbidden', 403);
  }

  /**
   * Внутренний async-finalize media_group.
   * Запускается отдельным fire-and-forget запросом и не зависит от Telegram webhook SLA.
   */
  if ((string)($_GET['cb_mg_finalize'] ?? '') === '1') {
    if (!$internalKeyOk) {
      json_err('Forbidden', 403);
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode((string)$raw, true);
    if (!is_array($payload)) {
      json_ok(['ok' => true, 'handled' => false, 'reason' => 'finalize_bad_payload']);
    }

    $mgId = trim((string)($payload['tg_media_group_id'] ?? ''));
    $mgChatId = trim((string)($payload['source_chat_id'] ?? ''));
    if ($mgId === '' || $mgChatId === '') {
      json_ok(['ok' => true, 'handled' => false, 'reason' => 'finalize_bad_meta']);
    }

    $lockPath = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'cb_mg_finalize_' . hash('sha256', $mgChatId . '|' . $mgId) . '.lock';
    $lockFp = @fopen($lockPath, 'c');
    if ($lockFp === false) {
      json_ok(['ok' => true, 'handled' => false, 'reason' => 'finalize_lock_open_failed']);
    }
    if (!@flock($lockFp, LOCK_EX | LOCK_NB)) {
      @fclose($lockFp);
      json_ok(['ok' => true, 'handled' => false, 'reason' => 'finalize_busy']);
    }
    register_shutdown_function(static function () use ($lockFp): void {
      @flock($lockFp, LOCK_UN);
      @fclose($lockFp);
    });

    $attempt = 0;
    $maxAttempts = 20; // ~10 сек по 500ms
    while ($attempt < $maxAttempts) {
      $mg = channel_bridge_media_group_collect($payload, 0, 1200);
      $mode = trim((string)($mg['mode'] ?? ''));
      $reason = trim((string)($mg['reason'] ?? ''));

      if ($mode === 'ready' && is_array($mg['payload'] ?? null)) {
        $bgRes = channel_bridge_ingest($pdo, (array)$mg['payload']);
        audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'media_group_bg_finalize', 'info', [
          'media_group_id' => trim((string)($mg['media_group_id'] ?? '')),
          'attempt' => $attempt + 1,
          'ok' => (int)(($bgRes['ok'] ?? false) === true),
          'reason' => trim((string)($bgRes['reason'] ?? '')),
          'sent' => (int)($bgRes['sent'] ?? 0),
          'failed' => (int)($bgRes['failed'] ?? 0),
        ]);
        json_ok(['ok' => true, 'handled' => true, 'reason' => 'finalized']);
      }

      if ($mode === 'error') {
        audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'media_group_bg_finalize', 'warn', [
          'media_group_id' => trim((string)($mg['media_group_id'] ?? '')),
          'attempt' => $attempt + 1,
          'reason' => 'collect_error',
          'error' => trim((string)($mg['error'] ?? '')),
        ]);
        json_ok(['ok' => true, 'handled' => false, 'reason' => 'collect_error']);
      }

      if ($reason === 'already_flushed') {
        json_ok(['ok' => true, 'handled' => false, 'reason' => 'already_flushed']);
      }

      usleep(500000);
      $attempt++;
    }

    // Fallback: не теряем пост, даже если альбом не добрал "идеальную" готовность.
    $bufPath = channel_bridge_media_group_buffer_path($mgChatId, $mgId);
    $buf = channel_bridge_media_group_lock_and_read($bufPath);
    if (($buf['ok'] ?? false) === true) {
      $fp = $buf['fp'];
      $st = is_array($buf['state'] ?? null) ? (array)$buf['state'] : [];

      $flushedAt = (float)($st['flushed_at'] ?? 0);
      if ($flushedAt <= 0) {
        $msgIds = [];
        foreach ((array)($st['message_ids'] ?? []) as $mid) {
          $mid = trim((string)$mid);
          if (preg_match('~^\d+$~', $mid)) $msgIds[] = $mid;
        }
        $msgIds = array_values(array_unique($msgIds));
        sort($msgIds, SORT_NUMERIC);

        $imgIds = [];
        foreach ((array)($st['photo_file_ids'] ?? []) as $pid) {
          $pid = trim((string)$pid);
          if ($pid !== '') $imgIds[] = $pid;
        }
        $imgIds = array_values(array_unique($imgIds));

        if ($msgIds || $imgIds) {
          $aggPayload = [
            'source_platform' => CHANNEL_BRIDGE_SOURCE_TG,
            'source_chat_id' => $mgChatId,
            'source_message_id' => 'mg:' . $mgId,
            'message_text' => channel_bridge_normalize_text((string)($st['message_text'] ?? '')),
            'tg_media_group_id' => $mgId,
            'tg_media_group_message_ids' => $msgIds,
            'tg_photo_file_ids' => $imgIds,
            'tg_photo_file_id' => ($imgIds[0] ?? ''),
          ];

          $st['flushed_at'] = microtime(true);
          $st['message_ids'] = $msgIds;
          $st['photo_file_ids'] = $imgIds;
          channel_bridge_media_group_write_and_unlock($fp, $st);

          $bgRes = channel_bridge_ingest($pdo, $aggPayload);
          audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'media_group_bg_finalize', 'info', [
            'media_group_id' => $mgId,
            'reason' => 'timeout_forced_dispatch',
            'ok' => (int)(($bgRes['ok'] ?? false) === true),
            'sent' => (int)($bgRes['sent'] ?? 0),
            'failed' => (int)($bgRes['failed'] ?? 0),
            'messages' => count($msgIds),
            'photos' => count($imgIds),
          ]);
          json_ok(['ok' => true, 'handled' => true, 'reason' => 'timeout_forced_dispatch']);
        }
      }

      channel_bridge_media_group_unlock($fp);
    }

    audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'media_group_bg_finalize', 'info', [
      'media_group_id' => trim((string)($payload['tg_media_group_id'] ?? '')),
      'attempts' => $maxAttempts,
      'reason' => 'timeout_pending',
    ]);
    json_ok(['ok' => true, 'handled' => false, 'reason' => 'timeout_pending']);
  }

  $update = function_exists('tg_read_update') ? (array)tg_read_update() : [];
  if (!$update) {
    audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'api_tg_webhook', 'warn', [
      'stage' => 'empty_update',
    ]);
    json_ok(['ok' => true, 'handled' => false, 'reason' => 'empty_update']);
  }

  $textMeta = channel_bridge_extract_tg_text_meta($update);
  if ($textMeta) {
    $chatType = strtolower(trim((string)($textMeta['chat_type'] ?? '')));
    $text = (string)($textMeta['text'] ?? '');

    /**
     * Для channel_post запрещаем "голую" 4-значную строку,
     * чтобы обычные посты не воспринимались как bind-код.
     * В канале принимаем только явную команду:
     *   /start 1234
     *   /bind 1234
     */
    if ($chatType === 'channel') {
      $bindCode = '';
      if (preg_match('~^/(?:start|bind)(?:@\w+)?\s+(\d{4})\s*$~u', trim($text), $m)) {
        $bindCode = trim((string)($m[1] ?? ''));
      }
    } else {
      $bindCode = channel_bridge_extract_bind_code_from_text($text);
    }

    if ($bindCode !== '') {
      $bind = channel_bridge_bind_route_by_code($pdo, $bindCode, $textMeta);

      if (($bind['ok'] ?? false) === true) {
        audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'bind_code', 'info', [
          'route_id' => (int)($bind['route_id'] ?? 0),
          'side' => (string)($bind['side'] ?? ''),
          'chat_id' => (string)($bind['chat_id'] ?? ''),
        ]);
      } else {
        audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'bind_code', 'warn', [
          'reason' => (string)($bind['reason'] ?? ''),
          'message' => (string)($bind['message'] ?? ''),
          'chat_id' => (string)($textMeta['chat_id'] ?? ''),
        ]);
      }

      $chatId = trim((string)($textMeta['chat_id'] ?? ''));
      $botToken = trim((string)($settings['tg_bot_token'] ?? ''));
      if ($chatId !== '' && $botToken !== '') {
        if (($bind['ok'] ?? false) === true) {
          $side = trim((string)($bind['side'] ?? ''));
          if ($side === CHANNEL_BRIDGE_BIND_SIDE_TARGET) {
            $reply = channel_bridge_t('channel_bridge.bind_reply_target_ok');
          } else {
            $reply = channel_bridge_t('channel_bridge.bind_reply_source_ok');
          }
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
    audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'api_tg_webhook', 'info', [
      'stage' => 'ignored',
      'reason' => 'not_channel_post',
    ]);
    json_ok(['ok' => true, 'handled' => false, 'reason' => 'not_channel_post']);
  }

  $result = channel_bridge_ingest($pdo, $payload);
  if (($result['ok'] ?? false) !== true) {
    json_err((string)($result['message'] ?? 'Ingest failed'), 422, $result);
  }

  if (trim((string)($result['reason'] ?? '')) === 'media_group_pending') {
    $spawned = channel_bridge_spawn_mg_finalize($payload);
    if (!$spawned) {
      audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'media_group_inline_finalize', 'warn', [
        'reason' => 'async_spawn_unavailable',
        'media_group_id' => trim((string)($payload['tg_media_group_id'] ?? '')),
      ]);

      $attempt = 0;
      $maxAttempts = 7; // ~2.1s
      while ($attempt < $maxAttempts) {
        $mg = channel_bridge_media_group_collect($payload, 0, 1200);
        $mode = trim((string)($mg['mode'] ?? ''));
        $reason = trim((string)($mg['reason'] ?? ''));

        if ($mode === 'ready' && is_array($mg['payload'] ?? null)) {
          $inlineRes = channel_bridge_ingest($pdo, (array)$mg['payload']);
          audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'media_group_inline_finalize', 'info', [
            'media_group_id' => trim((string)($mg['media_group_id'] ?? '')),
            'attempt' => $attempt + 1,
            'ok' => (int)(($inlineRes['ok'] ?? false) === true),
            'reason' => trim((string)($inlineRes['reason'] ?? '')),
            'sent' => (int)($inlineRes['sent'] ?? 0),
            'failed' => (int)($inlineRes['failed'] ?? 0),
          ]);
          if (($inlineRes['ok'] ?? false) === true) {
            json_ok($inlineRes);
          }
          json_err((string)($inlineRes['message'] ?? 'Inline finalize ingest failed'), 422, $inlineRes);
        }

        if ($mode === 'error') {
          audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'media_group_inline_finalize', 'warn', [
            'media_group_id' => trim((string)($payload['tg_media_group_id'] ?? '')),
            'attempt' => $attempt + 1,
            'reason' => 'collect_error',
            'error' => trim((string)($mg['error'] ?? '')),
          ]);
          break;
        }

        if ($reason === 'already_flushed') {
          json_ok(['ok' => true, 'handled' => false, 'reason' => 'already_flushed']);
        }

        usleep(300000);
        $attempt++;
      }
    }

    json_ok($result);
  }

  json_ok($result);
} catch (Throwable $e) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'api_tg_webhook', 'error', [
    'error' => $e->getMessage(),
  ]);
  json_err('Internal error', 500);
}
