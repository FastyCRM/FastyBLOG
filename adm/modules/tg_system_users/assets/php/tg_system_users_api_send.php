<?php
/**
 * FILE: /adm/modules/tg_system_users/assets/php/tg_system_users_api_send.php
 * ROLE: api_send — API отправки системных Telegram-уведомлений
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/tg_system_users_lib.php';

/**
 * $cfg — конфигурация приложения.
 */
$cfg = function_exists('app_config') ? app_config() : [];
/**
 * $internalKey — ключ внутреннего API.
 */
$internalKey = trim((string)($cfg['internal_api']['key'] ?? ''));
/**
 * $headerKey — ключ из заголовка.
 */
$headerKey = trim((string)(function_exists('tg_get_header') ? tg_get_header('X-Internal-Api-Key') : ''));
/**
 * $queryKey — ключ из query/body.
 */
$queryKey = trim((string)($_REQUEST['key'] ?? ''));
/**
 * $hasInternalKeyAccess — доступ по internal_api key.
 */
$hasInternalKeyAccess = ($internalKey !== '' && ($headerKey === $internalKey || $queryKey === $internalKey));

/**
 * $uid — текущий пользователь.
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
/**
 * $roles — роли текущего пользователя.
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
/**
 * $canManage — право управления модулем.
 */
$canManage = tg_system_users_is_manage_role($roles);

if (!$hasInternalKeyAccess && !$canManage) {
  json_err('Forbidden', 403);
}

/**
 * $message — текст сообщения.
 */
$message = trim((string)($_REQUEST['message'] ?? $_REQUEST['text'] ?? ''));
if ($message === '') {
  json_err('Message required', 400);
}

/**
 * $eventCode — код события.
 */
$eventCode = trim((string)($_REQUEST['event_code'] ?? TG_SYSTEM_USERS_EVENT_GENERAL));
if ($eventCode === '') $eventCode = TG_SYSTEM_USERS_EVENT_GENERAL;

/**
 * $userIds — опциональный список user_id для точечной отправки.
 */
$userIds = [];
$rawUserIds = $_REQUEST['user_ids'] ?? [];
if (is_string($rawUserIds) && $rawUserIds !== '') {
  $rawUserIds = explode(',', $rawUserIds);
}
if (is_array($rawUserIds)) {
  foreach ($rawUserIds as $id) {
    $id = (int)$id;
    if ($id > 0) $userIds[] = $id;
  }
  $userIds = array_values(array_unique($userIds));
}

/**
 * $parseMode — опциональный parse mode для Telegram.
 */
$parseMode = trim((string)($_REQUEST['parse_mode'] ?? ''));

/**
 * $opts — опции отправки.
 */
$opts = [];
if ($userIds) $opts['user_ids'] = $userIds;
if ($parseMode !== '') $opts['parse_mode'] = $parseMode;

try {
  $pdo = db();
  $result = tg_system_users_send_system($pdo, $message, $eventCode, $opts);

  if (($result['ok'] ?? false) !== true) {
    json_err((string)($result['message'] ?? 'Send failed'), 422, [
      'result' => $result,
    ]);
  }

  audit_log('tg_system_users', 'api_send', 'info', [
    'event_code' => $eventCode,
    'targets' => (int)($result['targets'] ?? 0),
    'sent' => (int)($result['sent'] ?? 0),
    'failed' => (int)($result['failed'] ?? 0),
    'auth_mode' => $hasInternalKeyAccess ? 'internal_key' : 'session',
  ], 'module', null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  json_ok($result);
} catch (Throwable $e) {
  audit_log('tg_system_users', 'api_send', 'error', [
    'event_code' => $eventCode,
    'error' => $e->getMessage(),
  ], 'module', null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  json_err('Internal error', 500);
}

