<?php
/**
 * FILE: /adm/modules/bot_adv_calendar/webhook.php
 * ROLE: Входящая webhook-точка Telegram бота bot_adv_calendar
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__, 3));
}

require_once ROOT_PATH . '/core/bootstrap.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/bot_adv_calendar_lib.php';

if (!function_exists('module_is_enabled') || !module_is_enabled('bot_adv_calendar')) {
  json_err('Module disabled', 404);
}

try {
  $pdo = db();
  $result = bot_adv_calendar_webhook_process($pdo);

  $http = (int)($result['http'] ?? 200);
  if ($http < 100 || $http > 599) $http = 200;

  if (($result['ok'] ?? false) !== true && $http >= 400) {
    audit_log('bot_adv_calendar', 'webhook', 'warn', [
      'reason' => (string)($result['reason'] ?? ''),
      'message' => (string)($result['message'] ?? ''),
    ]);
    json_err((string)($result['message'] ?? 'Webhook error'), $http, [
      'result' => $result,
    ]);
  }

  audit_log('bot_adv_calendar', 'webhook', 'info', [
    'reason' => (string)($result['reason'] ?? ''),
    'handled' => !empty($result['handled']) ? 1 : 0,
    'update_id' => (int)($result['update_id'] ?? 0),
  ]);

  json_ok($result);
} catch (Throwable $e) {
  audit_log('bot_adv_calendar', 'webhook', 'error', [
    'error' => $e->getMessage(),
  ]);
  json_err('Webhook internal error', 500);
}
