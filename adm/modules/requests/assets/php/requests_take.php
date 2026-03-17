<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_take.php
 * ROLE: take — принять в работу
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
 * $isAdmin — админ
 */
$isAdmin = requests_is_admin($roles);

/**
 * $isManager — менеджер
 */
$isManager = requests_is_manager($roles);

/**
 * $actorRole — роль
 */
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

/**
 * $settings — настройки
 */
$settings = requests_settings_get($pdo);

/**
 * $useSpecialists — режим специалистов
 */
$useSpecialists = ((int)($settings['use_specialists'] ?? 0) === 1);

/**
 * $id — заявка
 */
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  audit_log('requests', 'take', 'warn', [
    'reason' => 'validation',
    'id' => ($id > 0 ? $id : null),
  ], 'request', null, $uid, $actorRole);
  flash('Некорректная заявка', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

/**
 * $row — заявка
 */
/**
 * $st — запрос заявки
 */
$st = $pdo->prepare("SELECT status, specialist_user_id FROM " . REQUESTS_TABLE . " WHERE id = :id LIMIT 1");
$st->execute([':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  audit_log('requests', 'take', 'warn', [
    'reason' => 'not_found',
    'id' => $id,
  ], 'request', $id, $uid, $actorRole);
  flash('Заявка не найдена', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

/**
 * $status — статус
 */
$status = (string)($row['status'] ?? '');

if ($status !== REQUESTS_STATUS_CONFIRMED) {
  audit_log('requests', 'take', 'warn', [
    'reason' => 'status_invalid',
    'id' => $id,
    'status' => $status,
  ], 'request', $id, $uid, $actorRole);
  flash('Заявка не в статусе подтверждена', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

/**
 * $specId — специалист
 */
$specId = (int)($row['specialist_user_id'] ?? 0);

if ($useSpecialists && $specId > 0 && $specId !== $uid) {
  audit_log('requests', 'take', 'warn', [
    'reason' => 'specialist_locked',
    'id' => $id,
    'specialist_id' => $specId,
  ], 'request', $id, $uid, $actorRole);
  flash('Заявка закреплена за специалистом', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

try {
  $pdo->prepare("\n    UPDATE " . REQUESTS_TABLE . "\n    SET
      status = :status,
      taken_by = :uid,
      taken_at = NOW(),
      updated_at = NOW()
    WHERE id = :id
    LIMIT 1
  ")->execute([
    ':status' => REQUESTS_STATUS_IN_WORK,
    ':uid' => $uid,
    ':id' => $id,
  ]);

  requests_add_history($pdo, $id, $uid, 'take', REQUESTS_STATUS_CONFIRMED, REQUESTS_STATUS_IN_WORK, []);

  audit_log('requests', 'take', 'info', [
    'id' => $id,
  ], 'request', $id, $uid, $actorRole);

  /**
   * $notifyResult — результат TG-уведомлений по взятию в работу.
   */
  $notifyResult = requests_tg_notify_status($pdo, $id, REQUESTS_STATUS_IN_WORK, [
    'actor_user_id' => $uid,
    'actor_role' => $actorRole,
    'status_from' => REQUESTS_STATUS_CONFIRMED,
    'action' => 'take',
  ]);
  if (($notifyResult['ok'] ?? false) !== true) {
    audit_log('requests', 'tg_notify', 'warn', [
      'request_id' => $id,
      'status_to' => REQUESTS_STATUS_IN_WORK,
      'reason' => (string)($notifyResult['reason'] ?? ''),
    ], 'request', $id, $uid, $actorRole);
  }

  flash('Заявка в работе', 'ok');
} catch (Throwable $e) {
  audit_log('requests', 'take', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'request', $id, $uid, $actorRole);

  flash('Ошибка принятия заявки', 'danger', 1);
}

redirect_return('/adm/index.php?m=requests');
