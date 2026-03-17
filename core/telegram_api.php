<?php
/**
 * FILE: /core/telegram_api.php
 * ROLE: Core Telegram API endpoint for modules/integrations.
 *
 * Security model:
 * - if internal_api.key is set: key is required (header/query)
 * - if key is empty: only logged in admin/manager can call
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__));
}

require_once ROOT_PATH . '/core/bootstrap.php';
require_once ROOT_PATH . '/core/telegram.php';

/**
 * Telegram read operations/actions that may expose data.
 */
$readActions = [
  'get_me',
  'get_chat',
  'get_chat_member',
  'get_chat_administrators',
  'get_updates',
  'get_webhook_info',
  'read_update',
];

/**
 * Telegram write/admin actions.
 */
$writeActions = [
  'send_message',
  'channel_post',
  'set_webhook',
  'delete_webhook',
];

$action = strtolower(trim((string)($_REQUEST['action'] ?? $_REQUEST['do'] ?? '')));
if ($action === '') {
  json_err('Action required', 400);
}

if (!in_array($action, $readActions, true) && !in_array($action, $writeActions, true)) {
  json_err('Unknown action', 404);
}

$cfg = function_exists('app_config') ? app_config() : [];
$apiKey = trim((string)($cfg['internal_api']['key'] ?? ''));

$headerKey = trim((string)(function_exists('tg_get_header') ? tg_get_header('X-Internal-Api-Key') : ''));
$queryKey = trim((string)($_REQUEST['key'] ?? ''));

if ($apiKey !== '') {
  if ($headerKey !== $apiKey && $queryKey !== $apiKey) {
    if (function_exists('audit_log')) {
      audit_log('core', 'telegram_api_forbidden', 'warn', [
        'reason' => 'bad_internal_api_key',
        'action' => $action,
      ]);
    }
    json_err('Forbidden', 403);
  }
} else {
  $uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
  $roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
  $isAdmin = in_array('admin', $roles, true);
  $isManager = in_array('manager', $roles, true);

  if ($uid <= 0 || (!$isAdmin && !$isManager)) {
    if (function_exists('audit_log')) {
      audit_log('core', 'telegram_api_forbidden', 'warn', [
        'reason' => 'auth_required',
        'action' => $action,
      ]);
    }
    json_err('Forbidden', 403);
  }
}

if (!tg_is_enabled()) {
  json_err('Telegram disabled', 503);
}

$payload = $_REQUEST;
unset($payload['action'], $payload['do'], $payload['key']);

$token = tg_token('');
$result = tg_api_call($action, $payload, $token);

if (!is_array($result)) {
  json_err('Telegram API error', 500);
}

if (($result['ok'] ?? false) !== true) {
  if (function_exists('audit_log')) {
    audit_log('core', 'telegram_api_call_failed', 'warn', [
      'action' => $action,
      'error' => (string)($result['error'] ?? ''),
      'description' => (string)($result['description'] ?? ''),
      'error_code' => (int)($result['error_code'] ?? 0),
    ]);
  }
  json_err((string)($result['description'] ?? $result['error'] ?? 'Telegram API error'), 422, [
    'result' => $result,
  ]);
}

if (function_exists('audit_log')) {
  audit_log('core', 'telegram_api_call_ok', 'info', [
    'action' => $action,
  ]);
}

json_ok([
  'action' => $action,
  'result' => $result,
]);

