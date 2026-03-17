<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_reassign.php
 * ROLE: reassign — вернуть заявку в статус new
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/requests_lib.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('requests'));

/**
 * CSRF
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

/**
 * $pdo — БД
 */
$pdo = db();

/**
 * $uid — пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);

/**
 * $roles — роли
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

/**
 * $isAdmin/$isManager — доступ
 */
$isAdmin = requests_is_admin($roles);
$isManager = requests_is_manager($roles);

if (!$isAdmin && !$isManager) {
  flash('Доступ запрещён', 'danger', 1);
  redirect_return('/adm/index.php?m=requests');
}

/**
 * $actorRole — роль
 */
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

/**
 * $id — заявка
 */
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  audit_log('requests', 'reassign', 'warn', [
    'reason' => 'validation',
    'id' => ($id > 0 ? $id : null),
  ], 'request', null, $uid, $actorRole);
  flash('Некорректная заявка', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

/**
 * $row — заявка
 */
$st = $pdo->prepare("
  SELECT
    r.status,
    r.specialist_user_id,
    r.client_name,
    r.client_phone,
    r.visit_at,
    r.service_id,
    s.name AS service_name,
    u.name AS specialist_name
  FROM " . REQUESTS_TABLE . " r
  LEFT JOIN " . REQUESTS_SERVICES_TABLE . " s ON s.id = r.service_id
  LEFT JOIN users u ON u.id = r.specialist_user_id
  WHERE r.id = :id
  LIMIT 1
");
$st->execute([':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  audit_log('requests', 'reassign', 'warn', [
    'reason' => 'not_found',
    'id' => $id,
  ], 'request', $id, $uid, $actorRole);
  flash('Заявка не найдена', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

$status = (string)($row['status'] ?? '');
/**
 * $prevSpecialistId — специалист до переназначения.
 */
$prevSpecialistId = (int)($row['specialist_user_id'] ?? 0);
if ($status !== REQUESTS_STATUS_CONFIRMED) {
  audit_log('requests', 'reassign', 'warn', [
    'reason' => 'status_invalid',
    'id' => $id,
    'status' => $status,
  ], 'request', $id, $uid, $actorRole);
  flash('Статус заявки недопустим', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

try {
  $pdo->prepare("
    UPDATE " . REQUESTS_TABLE . "
    SET
      status = :status,
      confirmed_by = NULL,
      confirmed_at = NULL,
      service_id = NULL,
      specialist_user_id = NULL,
      visit_at = NULL,
      duration_min = NULL,
      price_total = NULL,
      slot_key = NULL,
      taken_by = NULL,
      taken_at = NULL,
      done_by = NULL,
      done_at = NULL,
      archived_at = NULL,
      archived_reason = NULL,
      updated_at = NOW()
    WHERE id = :id
    LIMIT 1
  ")->execute([
    ':status' => REQUESTS_STATUS_NEW,
    ':id' => $id,
  ]);

  requests_add_history($pdo, $id, $uid, 'reassign', $status, REQUESTS_STATUS_NEW, [
    'reason' => 'reset_confirmed',
  ]);

  audit_log('requests', 'reassign', 'info', [
    'id' => $id,
  ], 'request', $id, $uid, $actorRole);

  /**
   * $notifyResult — результат TG-уведомлений по изменению/снятию заявки.
   */
  $notifyResult = requests_tg_notify_status($pdo, $id, REQUESTS_NOTIFY_STATUS_CHANGED, [
    'actor_user_id' => $uid,
    'actor_role' => $actorRole,
    'status_from' => $status,
    'action' => 'reassign',
    'prev_specialist_id' => $prevSpecialistId,
    'snapshot' => [
      'client_name' => (string)($row['client_name'] ?? ''),
      'client_phone' => (string)($row['client_phone'] ?? ''),
      'visit_at' => (string)($row['visit_at'] ?? ''),
      'service_name' => (string)($row['service_name'] ?? ''),
      'service_id' => (int)($row['service_id'] ?? 0),
      'specialist_name' => (string)($row['specialist_name'] ?? ''),
      'specialist_user_id' => $prevSpecialistId,
    ],
  ]);
  if (($notifyResult['ok'] ?? false) !== true) {
    audit_log('requests', 'tg_notify', 'warn', [
      'request_id' => $id,
      'status_to' => REQUESTS_NOTIFY_STATUS_CHANGED,
      'reason' => (string)($notifyResult['reason'] ?? ''),
    ], 'request', $id, $uid, $actorRole);
  }

  flash('Заявка возвращена в новые', 'success');
} catch (Throwable $e) {
  audit_log('requests', 'reassign', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'request', $id, $uid, $actorRole);
  flash('Не удалось переназначить заявку', 'danger', 1);
}

redirect_return('/adm/index.php?m=requests');
