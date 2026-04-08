<?php
/**
 * FILE: /adm/modules/filter_bot/max_webhook.php
 * ROLE: Webhook endpoint MAX для filter_bot.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__, 3));
}

require_once ROOT_PATH . '/core/bootstrap.php';
require_once ROOT_PATH . '/adm/modules/filter_bot/settings.php';
require_once ROOT_PATH . '/adm/modules/filter_bot/assets/php/filter_bot_lib.php';

if (!function_exists('module_is_enabled') || !module_is_enabled(FILTER_BOT_MODULE_CODE)) {
  json_err('Module disabled', 404);
}

try {
  $pdo = db();
  $result = filter_bot_max_webhook_process($pdo);
  $http = (int)($result['http'] ?? 200);
  if ($http < 100 || $http > 599) $http = 200;

  if (($result['ok'] ?? false) !== true && $http >= 400) {
    json_err((string)($result['message'] ?? 'Webhook error'), $http, ['result' => $result]);
  }

  json_ok($result);
} catch (Throwable $e) {
  audit_log(FILTER_BOT_MODULE_CODE, 'max_webhook', 'error', [
    'error' => $e->getMessage(),
  ]);
  json_err('Webhook internal error', 500);
}
