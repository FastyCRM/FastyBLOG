<?php
/**
 * FILE: /core/telegram_webhook_system_users.php
 * ROLE: Webhook endpoint для модуля tg_system_users
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__));
}

require_once ROOT_PATH . '/core/bootstrap.php';
require_once ROOT_PATH . '/adm/modules/tg_system_users/settings.php';
require_once ROOT_PATH . '/adm/modules/tg_system_users/assets/php/tg_system_users_lib.php';

if (!function_exists('module_is_enabled') || !module_is_enabled('tg_system_users')) {
  json_err('Module disabled', 404);
}

try {
  $pdo = db();
  $result = tg_system_users_webhook_process($pdo);

  $http = (int)($result['http'] ?? 200);
  if ($http < 100 || $http > 599) $http = 200;

  if (($result['ok'] ?? false) !== true && $http >= 400) {
    audit_log('tg_system_users', 'webhook', 'warn', [
      'reason' => (string)($result['reason'] ?? ''),
      'message' => (string)($result['message'] ?? ''),
    ]);
    json_err((string)($result['message'] ?? 'Webhook error'), $http, [
      'result' => $result,
    ]);
  }

  audit_log('tg_system_users', 'webhook', 'info', [
    'reason' => (string)($result['reason'] ?? ''),
    'handled' => !empty($result['handled']) ? 1 : 0,
    'update_id' => (int)($result['update_id'] ?? 0),
  ]);

  json_ok($result);
} catch (Throwable $e) {
  audit_log('tg_system_users', 'webhook', 'error', [
    'error' => $e->getMessage(),
  ]);
  json_err('Webhook internal error', 500);
}

