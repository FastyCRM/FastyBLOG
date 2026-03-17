<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_archive.php
 * ROLE: archive — архивирование заявки
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

if (!$isAdmin && !$isManager) {
  audit_log('requests', 'archive', 'warn', [
    'reason' => 'deny',
  ], 'request', null, $uid, $actorRole);
  flash('Доступ запрещён', 'danger', 1);
  redirect_return('/adm/index.php?m=requests');
}

/**
 * $id — заявка
 */
$id = (int)($_POST['id'] ?? 0);
/**
 * $reason — причина
 */
$reason = trim((string)($_POST['reason'] ?? ''));

if ($id <= 0) {
  audit_log('requests', 'archive', 'warn', [
    'reason' => 'validation',
    'id' => ($id > 0 ? $id : null),
  ], 'request', null, $uid, $actorRole);
  flash('Некорректная заявка', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

/**
 * $st — проверка заявки
 */
$st = $pdo->prepare("SELECT id FROM " . REQUESTS_TABLE . " WHERE id = :id LIMIT 1");
$st->execute([':id' => $id]);
/**
 * $existsId — найденная заявка
 */
$existsId = (int)($st->fetchColumn() ?: 0);

if ($existsId <= 0) {
  audit_log('requests', 'archive', 'warn', [
    'reason' => 'not_found',
    'id' => $id,
  ], 'request', $id, $uid, $actorRole);
  flash('Заявка не найдена', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

try {
  $pdo->prepare("\n    UPDATE " . REQUESTS_TABLE . "\n    SET slot_key = NULL, archived_at = NOW(), archived_reason = :reason, updated_at = NOW()\n    WHERE id = :id\n    LIMIT 1\n  ")->execute([
    ':reason' => ($reason !== '' ? $reason : null),
    ':id' => $id,
  ]);

  requests_add_history($pdo, $id, $uid, 'archive', null, null, [
    'reason' => $reason,
  ]);

  audit_log('requests', 'archive', 'info', [
    'id' => $id,
    'reason' => $reason,
  ], 'request', $id, $uid, $actorRole);

  flash('Заявка архивирована', 'ok');
} catch (Throwable $e) {
  audit_log('requests', 'archive', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'request', $id, $uid, $actorRole);

  flash('Ошибка архивации', 'danger', 1);
}

redirect_return('/adm/index.php?m=requests');
