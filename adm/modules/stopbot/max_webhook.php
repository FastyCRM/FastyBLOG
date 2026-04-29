<?php
/**
 * FILE: /adm/modules/stopbot/max_webhook.php
 * ROLE: Входящая webhook-точка MAX для stopbot.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__, 3));
}

require_once ROOT_PATH . '/core/bootstrap.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/stopbot_lib.php';

if (!function_exists('module_is_enabled') || !module_is_enabled(STOPBOT_MODULE_CODE)) {
  json_err('Module disabled', 404);
}

$botId = (int)($_GET['bot_id'] ?? 0);
if ($botId <= 0) {
  json_err('Bot id required', 400);
}

$raw = file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) $payload = [];
$traceId = 'mxs_' . str_replace('.', '', uniqid('', true));
$meta = function_exists('stopbot_max_extract_message') ? stopbot_max_extract_message($payload) : [];

audit_log(STOPBOT_MODULE_CODE, 'max_webhook_dispatch', 'info', [
  'trace_id' => $traceId,
  'phase' => 'received',
  'handler_module' => STOPBOT_MODULE_CODE,
  'handler_script' => '/adm/modules/stopbot/max_webhook.php',
  'bot_id' => $botId,
  'chat_id' => (string)($meta['chat_id'] ?? ''),
  'message_id' => (string)($meta['message_id'] ?? ''),
  'message_text' => stopbot_excerpt((string)($meta['text'] ?? ''), 120),
  'payload_size' => strlen((string)$raw),
]);

try {
  $pdo = db();
  $result = stopbot_max_webhook_process($pdo, $botId, $payload, $traceId);

  $http = (int)($result['http'] ?? 200);
  if ($http < 100 || $http > 599) $http = 200;

  if (($result['ok'] ?? false) !== true && $http >= 400) {
    audit_log(STOPBOT_MODULE_CODE, 'max_webhook_dispatch', 'warn', [
      'trace_id' => $traceId,
      'phase' => 'failed_response',
      'handler_module' => STOPBOT_MODULE_CODE,
      'handler_script' => '/adm/modules/stopbot/max_webhook.php',
      'bot_id' => $botId,
      'http' => $http,
      'reason' => (string)($result['reason'] ?? ''),
      'handled' => !empty($result['handled']) ? 1 : 0,
    ]);
    stopbot_audit_log($pdo, 'max_webhook', 'warn', [
      'bot_id' => $botId,
      'reason' => (string)($result['reason'] ?? ''),
      'message' => (string)($result['message'] ?? ''),
      'trace_id' => $traceId,
    ]);
    json_err((string)($result['message'] ?? 'Webhook error'), $http, [
      'result' => $result,
    ]);
  }

  stopbot_audit_log($pdo, 'max_webhook', 'info', [
    'bot_id' => $botId,
    'reason' => (string)($result['reason'] ?? ''),
    'handled' => !empty($result['handled']) ? 1 : 0,
    'trace_id' => $traceId,
  ]);
  audit_log(STOPBOT_MODULE_CODE, 'max_webhook_dispatch', 'info', [
    'trace_id' => $traceId,
    'phase' => 'completed',
    'handler_module' => STOPBOT_MODULE_CODE,
    'handler_script' => '/adm/modules/stopbot/max_webhook.php',
    'bot_id' => $botId,
    'http' => $http,
    'reason' => (string)($result['reason'] ?? ''),
    'handled' => !empty($result['handled']) ? 1 : 0,
  ]);

  json_ok($result);
} catch (Throwable $e) {
  audit_log(STOPBOT_MODULE_CODE, 'max_webhook', 'error', [
    'bot_id' => $botId,
    'error' => $e->getMessage(),
    'trace_id' => $traceId ?? '',
  ]);
  audit_log(STOPBOT_MODULE_CODE, 'max_webhook_dispatch', 'error', [
    'trace_id' => $traceId ?? '',
    'phase' => 'exception',
    'handler_module' => STOPBOT_MODULE_CODE,
    'handler_script' => '/adm/modules/stopbot/max_webhook.php',
    'bot_id' => $botId,
    'error' => $e->getMessage(),
  ]);
  json_err('Webhook internal error', 500);
}
