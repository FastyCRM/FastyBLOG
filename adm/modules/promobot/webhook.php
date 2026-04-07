<?php
/**
 * FILE: /adm/modules/promobot/webhook.php
 * ROLE: Входящая webhook-точка Telegram для promobot.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__, 3));
}

require_once ROOT_PATH . '/core/bootstrap.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/promobot_lib.php';

if (!function_exists('module_is_enabled') || !module_is_enabled(PROMOBOT_MODULE_CODE)) {
  json_err('Module disabled', 404);
}

$botId = (int)($_GET['bot_id'] ?? 0);
if ($botId <= 0) {
  json_err('Bot id required', 400);
}

try {
  $pdo = db();
  $result = promobot_tg_webhook_process($pdo, $botId);

  $http = (int)($result['http'] ?? 200);
  if ($http < 100 || $http > 599) $http = 200;

  if (($result['ok'] ?? false) !== true && $http >= 400) {
    promobot_audit_log($pdo, 'webhook', 'warn', [
      'bot_id' => $botId,
      'reason' => (string)($result['reason'] ?? ''),
      'message' => (string)($result['message'] ?? ''),
    ]);
    json_err((string)($result['message'] ?? 'Webhook error'), $http, [
      'result' => $result,
    ]);
  }

  promobot_audit_log($pdo, 'webhook', 'info', [
    'bot_id' => $botId,
    'reason' => (string)($result['reason'] ?? ''),
    'handled' => !empty($result['handled']) ? 1 : 0,
  ]);

  json_ok($result);
} catch (Throwable $e) {
  audit_log(PROMOBOT_MODULE_CODE, 'webhook', 'error', [
    'bot_id' => $botId,
    'error' => $e->getMessage(),
  ]);
  json_err('Webhook internal error', 500);
}
