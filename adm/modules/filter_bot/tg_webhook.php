<?php
/**
 * FILE: /adm/modules/filter_bot/tg_webhook.php
 * ROLE: Webhook endpoint Telegram для filter_bot.
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
  $settings = filter_bot_settings_get($pdo);
  if (!filter_bot_tg_verify_webhook($settings)) {
    json_err('Forbidden', 403);
  }

  $result = filter_bot_tg_webhook_process($pdo);
  $http = (int)($result['http'] ?? 200);
  if ($http < 100 || $http > 599) $http = 200;

  if (($result['ok'] ?? false) !== true && $http >= 400) {
    json_err((string)($result['message'] ?? 'Webhook error'), $http, ['result' => $result]);
  }

  json_ok($result);
} catch (Throwable $e) {
  audit_log(FILTER_BOT_MODULE_CODE, 'tg_webhook', 'error', [
    'error' => $e->getMessage(),
  ]);
  json_err('Webhook internal error', 500);
}
