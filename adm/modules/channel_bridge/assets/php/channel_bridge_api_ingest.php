<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_api_ingest.php
 * ROLE: do=api_ingest — API приёма входящего сообщения для маршрутизации.
 *
 * ACCESS:
 *  - internal API key (X-Internal-Api-Key / key),
 *  - либо session admin/manager.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_lib.php';

channel_bridge_require_manage_or_internal();

$sourcePlatform = (string)($_REQUEST['source_platform'] ?? '');
$sourceChatId = (string)($_REQUEST['source_chat_id'] ?? '');
$sourceMessageId = (string)($_REQUEST['source_message_id'] ?? '');
$messageText = (string)($_REQUEST['message_text'] ?? $_REQUEST['text'] ?? '');

if (trim($sourcePlatform) === '' || trim($sourceChatId) === '' || trim($messageText) === '') {
  json_err(channel_bridge_t('channel_bridge.error_api_payload_required'), 400);
}

try {
  $pdo = db();
  $result = channel_bridge_ingest($pdo, [
    'source_platform' => $sourcePlatform,
    'source_chat_id' => $sourceChatId,
    'source_message_id' => $sourceMessageId,
    'message_text' => $messageText,
  ]);

  if (($result['ok'] ?? false) !== true) {
    json_err((string)($result['message'] ?? 'Ingest failed'), 422, $result);
  }

  json_ok($result);
} catch (Throwable $e) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'api_ingest', 'error', [
    'error' => $e->getMessage(),
  ]);
  json_err('Internal error', 500);
}

