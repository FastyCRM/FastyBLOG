<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_access_delete.php
 * ROLE: Удаление доступа
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php
 *  - /adm/modules/personal_file/assets/php/personal_file_lib.php
 *  - /core/flash.php (flash)
 *  - /core/response.php (redirect)
 *
 * NOTES:
 *  - Удаление доступно только admin/manager.
 *
 * СПИСОК ФУНКЦИЙ: (нет)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/personal_file_lib.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('personal_file'));

/**
 * CSRF
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

$pdo = db();

/**
 * $uid — пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if (!personal_file_can_manage($roles)) {
  flash('Доступ запрещён', 'danger', 1);
  redirect_return('/adm/index.php?m=personal_file');
}

$id = (int)($_POST['id'] ?? 0);
$clientId = (int)($_POST['client_id'] ?? 0);
if ($id <= 0) {
  audit_log('personal_file', 'access_delete', 'warn', [
    'reason' => 'invalid_id',
    'id' => $id,
  ], 'personal_file_access', null, $uid, $actorRole);
  flash('Некорректный ID', 'warn');
  redirect_return('/adm/index.php?m=personal_file&client_id=' . $clientId);
}

try {
  $pdo->prepare("DELETE FROM " . PERSONAL_FILE_ACCESS_REMINDERS_TABLE . " WHERE access_id = :id")->execute([':id' => $id]);
  $pdo->prepare("DELETE FROM " . PERSONAL_FILE_ACCESS_TABLE . " WHERE id = :id LIMIT 1")->execute([':id' => $id]);

  audit_log('personal_file', 'access_delete', 'info', [
    'id' => $id,
    'client_id' => $clientId,
  ], 'personal_file_access', $id, $uid, $actorRole);
  flash('Доступ удалён', 'ok');
} catch (Throwable $e) {
  audit_log('personal_file', 'access_delete', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'personal_file_access', $id, $uid, $actorRole);
  flash('Ошибка удаления доступа', 'danger', 1);
}

redirect_return('/adm/index.php?m=personal_file&client_id=' . $clientId);
