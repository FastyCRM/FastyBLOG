<?php
/**
 * FILE: /core/telegram_webhook.php
 * ROLE: Public webhook endpoint for Telegram updates.
 *
 * Notes:
 * - should be configured in Telegram via setWebhook
 * - verifies X-Telegram-Bot-Api-Secret-Token if configured
 * - optional custom handler file can define tg_webhook_on_update(array $update)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__));
}

require_once ROOT_PATH . '/core/bootstrap.php';
require_once ROOT_PATH . '/core/telegram.php';

$token = tg_token('');
if ($token === '' || !tg_is_enabled()) {
  if (function_exists('audit_log')) {
    audit_log('core', 'telegram_webhook_disabled', 'warn', [
      'enabled' => tg_is_enabled() ? 1 : 0,
      'has_token' => ($token !== '') ? 1 : 0,
    ]);
  }
  json_err('Telegram disabled', 503);
}

if (!tg_verify_webhook_secret()) {
  if (function_exists('audit_log')) {
    audit_log('core', 'telegram_webhook_forbidden', 'warn', []);
  }
  json_err('Forbidden', 403);
}

$update = tg_read_update();
if (!$update) {
  if (function_exists('audit_log')) {
    audit_log('core', 'telegram_webhook_empty_update', 'warn', []);
  }
  json_ok([
    'received' => false,
    'reason' => 'empty_update',
  ]);
}

$handler = null;
$handlerFile = tg_webhook_handler_file();
if ($handlerFile !== '' && is_file($handlerFile)) {
  require_once $handlerFile;
  if (function_exists('tg_webhook_on_update')) {
    $handler = 'tg_webhook_on_update';
  }
}

try {
  $dispatch = tg_webhook_dispatch_update($update, $handler);
} catch (Throwable $e) {
  if (function_exists('audit_log')) {
    audit_log('core', 'telegram_webhook_handler_error', 'error', [
      'message' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine(),
    ]);
  }
  json_err('Webhook handler error', 500);
}

if (function_exists('audit_log')) {
  audit_log('core', 'telegram_webhook_received', 'info', [
    'update_id' => (int)($dispatch['meta']['update_id'] ?? 0),
    'type' => (string)($dispatch['meta']['type'] ?? ''),
    'chat_id' => (string)($dispatch['meta']['chat_id'] ?? ''),
    'handled' => ($dispatch['handled'] ?? false) ? 1 : 0,
  ]);
}

json_ok([
  'received' => true,
  'handled' => ($dispatch['handled'] ?? false) ? true : false,
  'meta' => (array)($dispatch['meta'] ?? []),
]);

