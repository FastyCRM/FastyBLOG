<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_api_tg_webhook.php
 * ROLE: Single Telegram webhook entrypoint for direct single posts and local media_group assembly.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_lib.php';

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

try {
  $pdo = db();
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
  if (!$update) {
    json_ok(['ok' => true, 'handled' => false, 'reason' => 'empty_update']);
  }

  $updateMeta = channel_bridge_extract_tg_update_meta($update);
  if ($updateMeta) {
    $updateReg = channel_bridge_webhook_update_register($pdo, $updateMeta);
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

  if (channel_bridge_tg_state_tables_available($pdo)) {
    $result = channel_bridge_tg_state_process_webhook($pdo, $settings, $payload, $updateMeta);
  } else {
    $result = channel_bridge_ingest($pdo, $payload);
  }
  $ok = (($result['ok'] ?? false) === true);
  $reason = trim((string)($result['reason'] ?? ''));
  $handled = !in_array($reason, ['duplicate', 'media_group_already_sent'], true);

  channel_bridge_tg_webhook_log($ok ? 'info' : 'error', [
    'message_type' => $messageType,
    'update_id' => (int)($updateMeta['update_id'] ?? 0),
    'media_group_id' => trim((string)($payload['tg_media_group_id'] ?? '')),
    'source_chat_id' => trim((string)($payload['source_chat_id'] ?? '')),
    'source_message_id' => trim((string)($payload['source_message_id'] ?? '')),
    'sent' => (int)($result['sent'] ?? 0),
    'failed' => (int)($result['failed'] ?? 0),
    'targets' => (int)($result['targets'] ?? 0),
    'reason' => $reason,
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
