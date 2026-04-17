<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_api_tg_finalize.php
 * ROLE: Separate internal entrypoint that finalizes one persisted Telegram album.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_lib.php';

try {
  if (!channel_bridge_is_internal_key_request() && !channel_bridge_is_tg_finalize_request()) {
    channel_bridge_require_manage_or_internal();
  }

  $sourceChatId = channel_bridge_norm_chat_id((string)($_REQUEST['source_chat_id'] ?? ''));
  $mediaGroupId = trim((string)($_REQUEST['media_group_id'] ?? ''));
  $dispatchToken = trim((string)($_REQUEST['dispatch_token'] ?? ''));
  if ($sourceChatId === '' || $mediaGroupId === '') {
    json_err('source_chat_id and media_group_id are required', 400);
  }

  $pdo = db();
  $settings = channel_bridge_settings_get($pdo);
  $result = channel_bridge_tg_state_finalize_album($pdo, $settings, $sourceChatId, $mediaGroupId, $dispatchToken);
  if (($result['ok'] ?? false) !== true) {
    json_err((string)($result['message'] ?? $result['reason'] ?? 'Finalize failed'), 500, $result);
  }

  json_ok($result);
} catch (Throwable $e) {
  if (function_exists('audit_log')) {
    audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'media_group_finalize', 'error', [
      'reason' => 'exception',
      'error' => $e->getMessage(),
      'error_file' => $e->getFile(),
      'error_line' => (int)$e->getLine(),
      'source_chat_id' => trim((string)($_REQUEST['source_chat_id'] ?? '')),
      'media_group_id' => trim((string)($_REQUEST['media_group_id'] ?? '')),
    ]);
  }
  json_err('Internal error', 500);
}
