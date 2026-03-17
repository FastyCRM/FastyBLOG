<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_api_notify_status.php
 * ROLE: API отправки статусных TG-уведомлений по заявке
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/requests_lib.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_405('Method Not Allowed');
}

/**
 * $cfg — конфигурация приложения.
 */
$cfg = function_exists('app_config') ? (array)app_config() : [];
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
 * $roles — роли пользователя.
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
/**
 * $allowedRoles — роли доступа к модулю requests.
 */
$allowedRoles = function_exists('module_allowed_roles') ? (array)module_allowed_roles('requests') : [];
/**
 * $hasSessionAccess — доступ через активную сессию и ACL.
 */
$hasSessionAccess = ($uid > 0 && function_exists('acl_roles_intersect') && acl_roles_intersect($roles, $allowedRoles));

if (!$hasInternalKeyAccess && !$hasSessionAccess) {
  json_err('Forbidden', 403);
}

/**
 * $requestId — ID заявки.
 */
$requestId = (int)($_REQUEST['request_id'] ?? 0);
/**
 * $statusTo — целевой статус уведомления.
 */
$statusTo = trim((string)($_REQUEST['status_to'] ?? ''));
/**
 * $statusFrom — исходный статус (опционально, для контекста).
 */
$statusFrom = trim((string)($_REQUEST['status_from'] ?? ''));
/**
 * $action — действие-источник (опционально).
 */
$action = trim((string)($_REQUEST['action'] ?? ''));
/**
 * $prevSpecialistId — предыдущий специалист (опционально).
 */
$prevSpecialistId = (int)($_REQUEST['prev_specialist_id'] ?? 0);
/**
 * $skipActor — исключать инициатора из получателей (0/1).
 */
$skipActor = ((int)($_REQUEST['skip_actor'] ?? 0) === 1) ? 1 : 0;

if ($requestId <= 0 || $statusTo === '') {
  json_err('request_id and status_to are required', 422);
}

try {
  /**
   * $pdo — БД.
   */
  $pdo = db();

  /**
   * $notifyResult — результат отправки уведомлений.
   */
  $notifyResult = requests_tg_notify_status($pdo, $requestId, $statusTo, [
    'status_from' => ($statusFrom !== '' ? $statusFrom : null),
    'action' => ($action !== '' ? $action : null),
    'prev_specialist_id' => ($prevSpecialistId > 0 ? $prevSpecialistId : null),
    'skip_actor' => $skipActor,
    'actor_user_id' => ($hasSessionAccess ? $uid : 0),
    'actor_role' => (function_exists('auth_user_role') ? (string)auth_user_role() : ''),
  ]);

  if (($notifyResult['ok'] ?? false) !== true) {
    $reason = trim((string)($notifyResult['reason'] ?? 'notify_failed'));
    if ($reason === 'request_not_found') {
      json_err('Request not found', 404, ['result' => $notifyResult]);
    }
    if ($reason === 'status_invalid' || $reason === 'request_invalid') {
      json_err('Invalid input', 422, ['result' => $notifyResult]);
    }
    json_err('Notify failed', 422, ['result' => $notifyResult]);
  }

  json_ok($notifyResult);
} catch (Throwable $e) {
  audit_log('requests', 'api_notify_status', 'error', [
    'request_id' => $requestId,
    'status_to' => ($statusTo !== '' ? $statusTo : null),
    'error' => $e->getMessage(),
  ], 'request', ($requestId > 0 ? $requestId : null), ($uid > 0 ? $uid : null), (function_exists('auth_user_role') ? (string)auth_user_role() : null));

  json_err('Internal error', 500);
}
