<?php
/**
 * FILE: /adm/modules/max_comments/webhook.php
 * ROLE: Webhook endpoint MAX для модуля max_comments.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__, 3));
}

require_once ROOT_PATH . '/core/bootstrap.php';
require_once ROOT_PATH . '/adm/modules/max_comments/settings.php';
require_once ROOT_PATH . '/adm/modules/max_comments/assets/php/max_comments_lib.php';

if (!function_exists('module_is_enabled') || !module_is_enabled(MAX_COMMENTS_MODULE_CODE)) {
  json_err('Module disabled', 404);
}

try {
  $rawInput = (string)file_get_contents('php://input');
  $rawLen = strlen($rawInput);
  $payload = json_decode($rawInput, true);
  $payloadKeys = is_array($payload) ? array_values(array_slice(array_keys($payload), 0, 20)) : [];
  $updateTypeIn = '';
  if (is_array($payload)) {
    $updateTypeIn = trim((string)($payload['update_type'] ?? $payload['event_type'] ?? ''));
  }

  audit_log(MAX_COMMENTS_MODULE_CODE, 'webhook_hit', 'info', [
    'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
    'content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
    'content_length' => (int)($_SERVER['CONTENT_LENGTH'] ?? 0),
    'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
  ]);

  audit_log(MAX_COMMENTS_MODULE_CODE, 'webhook_in', 'info', [
    'raw_len' => $rawLen,
    'raw_preview' => substr($rawInput, 0, 1200),
    'payload_keys' => $payloadKeys,
    'update_type_in' => $updateTypeIn,
    'json_ok' => is_array($payload) ? 1 : 0,
  ]);

  $pdo = db();
  $result = max_comments_webhook_process($pdo, $rawInput);

  $http = (int)($result['http'] ?? 200);
  if ($http < 100 || $http > 599) $http = 200;

  if (($result['ok'] ?? false) !== true && $http >= 400) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'webhook', 'warn', [
      'reason' => (string)($result['reason'] ?? ''),
      'message' => (string)($result['message'] ?? ''),
      'update_type' => (string)($result['update_type'] ?? ''),
      'chat_id' => (string)($result['chat_id'] ?? ''),
      'message_id' => (string)($result['message_id'] ?? ''),
      'method' => (string)($result['method'] ?? ''),
      'raw_len' => (int)($result['raw_len'] ?? 0),
    ]);
    json_err((string)($result['message'] ?? 'Webhook error'), $http, ['result' => $result]);
  }

  audit_log(MAX_COMMENTS_MODULE_CODE, 'webhook', 'info', [
    'reason' => (string)($result['reason'] ?? ''),
    'handled' => !empty($result['handled']) ? 1 : 0,
    'changed' => (int)($result['changed'] ?? 0),
    'update_type' => (string)($result['update_type'] ?? ''),
    'chat_id' => (string)($result['chat_id'] ?? ''),
    'message_id' => (string)($result['message_id'] ?? ''),
    'method' => (string)($result['method'] ?? ''),
    'raw_len' => (int)($result['raw_len'] ?? 0),
  ]);

  json_ok($result);
} catch (Throwable $e) {
  audit_log(MAX_COMMENTS_MODULE_CODE, 'webhook', 'error', [
    'error' => $e->getMessage(),
  ]);
  json_err('Webhook internal error', 500);
}
