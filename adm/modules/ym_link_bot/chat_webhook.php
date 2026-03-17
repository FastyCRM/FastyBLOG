<?php
/**
 * FILE: /adm/modules/ym_link_bot/chat_webhook.php
 * ROLE: Dedicated webhook listener for optional separate chat bot.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__, 3));
}

$moduleDir = basename(__DIR__);
$moduleRoot = ROOT_PATH . '/adm/modules/' . $moduleDir;
require_once ROOT_PATH . '/core/bootstrap.php';
require_once $moduleRoot . '/settings.php';
require_once $moduleRoot . '/assets/php/ym_link_bot_lib.php';

if (!function_exists('module_is_enabled') || !module_is_enabled(ymlb_module_code())) {
  json_err('Module disabled', 404);
}

try {
  $pdo = db();
  $result = ymlb_webhook_process_chat($pdo);
  $http = (int)($result['http'] ?? 200);
  if ($http < 100 || $http > 599) $http = 200;

  if (($result['ok'] ?? false) !== true && $http >= 400) {
    audit_log(ymlb_module_code(), 'chat_webhook', 'warn', [
      'reason' => (string)($result['reason'] ?? ''),
      'message' => (string)($result['message'] ?? ''),
    ]);
    json_err((string)($result['message'] ?? 'Webhook error'), $http, ['result' => $result]);
  }

  audit_log(ymlb_module_code(), 'chat_webhook', 'info', [
    'reason' => (string)($result['reason'] ?? ''),
    'handled' => !empty($result['handled']) ? 1 : 0,
    'update_id' => (int)($result['update_id'] ?? 0),
  ]);

  json_ok($result);
} catch (Throwable $e) {
  audit_log(ymlb_module_code(), 'chat_webhook', 'error', [
    'error' => $e->getMessage(),
  ]);
  json_err('Webhook internal error', 500);
}

