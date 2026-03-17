<?php
/**
 * FILE: /adm/modules/tg_system_clients/assets/php/tg_system_clients_api_send.php
 * ROLE: api_send — API отправки системных Telegram-уведомлений клиентам
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/tg_system_clients_lib.php';

$cfg = function_exists('app_config') ? app_config() : [];
$internalKey = trim((string)($cfg['internal_api']['key'] ?? ''));
$headerKey = trim((string)(function_exists('tg_get_header') ? tg_get_header('X-Internal-Api-Key') : ''));
$queryKey = trim((string)($_REQUEST['key'] ?? ''));
$hasInternalKeyAccess = ($internalKey !== '' && ($headerKey === $internalKey || $queryKey === $internalKey));

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$canManage = tg_system_clients_is_manage_role($roles);

if (!$hasInternalKeyAccess && !$canManage) {
  json_err('Forbidden', 403);
}

$message = trim((string)($_REQUEST['message'] ?? $_REQUEST['text'] ?? ''));
if ($message === '') {
  json_err('Message required', 400);
}

$eventCode = trim((string)($_REQUEST['event_code'] ?? TG_SYSTEM_CLIENTS_EVENT_GENERAL));
if ($eventCode === '') $eventCode = TG_SYSTEM_CLIENTS_EVENT_GENERAL;

$clientIds = [];
$rawClientIds = $_REQUEST['client_ids'] ?? ($_REQUEST['user_ids'] ?? []);
if (is_string($rawClientIds) && $rawClientIds !== '') {
  $rawClientIds = explode(',', $rawClientIds);
}
if (is_array($rawClientIds)) {
  foreach ($rawClientIds as $id) {
    $id = (int)$id;
    if ($id > 0) $clientIds[] = $id;
  }
  $clientIds = array_values(array_unique($clientIds));
}

$parseMode = trim((string)($_REQUEST['parse_mode'] ?? ''));

$opts = [];
if ($clientIds) $opts['client_ids'] = $clientIds;
if ($parseMode !== '') $opts['parse_mode'] = $parseMode;

try {
  $pdo = db();
  $result = tg_system_clients_send_system($pdo, $message, $eventCode, $opts);

  if (($result['ok'] ?? false) !== true) {
    json_err((string)($result['message'] ?? 'Send failed'), 422, [
      'result' => $result,
    ]);
  }

  audit_log('tg_system_clients', 'api_send', 'info', [
    'event_code' => $eventCode,
    'client_ids' => ($clientIds ? $clientIds : null),
    'targets' => (int)($result['targets'] ?? 0),
    'sent' => (int)($result['sent'] ?? 0),
    'failed' => (int)($result['failed'] ?? 0),
    'auth_mode' => $hasInternalKeyAccess ? 'internal_key' : 'session',
  ], 'module', null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  json_ok($result);
} catch (Throwable $e) {
  audit_log('tg_system_clients', 'api_send', 'error', [
    'event_code' => $eventCode,
    'error' => $e->getMessage(),
  ], 'module', null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  json_err('Internal error', 500);
}