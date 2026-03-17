<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_settings_type_update.php
 * ROLE: Обновление типа доступа (настройки модуля personal_file)
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php
 *  - /adm/modules/personal_file/assets/php/personal_file_lib.php
 *  - /core/flash.php (flash)
 *  - /core/response.php (redirect_return)
 *
 * NOTES:
 *  - Доступно только admin/manager.
 *  - Возврат на исходную страницу через return_url.
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

/**
 * Пользователь и роли
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if (!personal_file_can_manage($roles)) {
  flash('Доступ запрещён', 'danger', 1);
  redirect_return('/adm/index.php?m=personal_file');
}

/**
 * Входные данные
 */
$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$status = trim((string)($_POST['status'] ?? 'active'));
if (!in_array($status, ['active', 'disabled'], true)) {
  $status = 'active';
}

if ($id <= 0 || $name === '') {
  audit_log('personal_file', 'settings_type_update', 'warn', [
    'reason' => 'invalid',
    'id' => $id,
  ], 'access_type', $id ?: null, $uid, $actorRole);
  flash('Некорректные данные', 'warn');
  redirect_return('/adm/index.php?m=personal_file');
}

$pdo = db();

try {
  $st = $pdo->prepare("\n    UPDATE " . PERSONAL_FILE_ACCESS_TYPES_TABLE . "\n    SET name = :name, status = :status, updated_at = NOW()\n    WHERE id = :id\n    LIMIT 1\n  ");
  $st->execute([
    ':id' => $id,
    ':name' => $name,
    ':status' => $status,
  ]);

  audit_log('personal_file', 'settings_type_update', 'info', [
    'id' => $id,
    'status' => $status,
  ], 'access_type', $id, $uid, $actorRole);
  flash('Тип обновлён', 'ok');
} catch (Throwable $e) {
  audit_log('personal_file', 'settings_type_update', 'error', [
    'error' => $e->getMessage(),
    'id' => $id,
  ], 'access_type', $id, $uid, $actorRole);
  flash('Ошибка обновления типа', 'danger', 1);
}

redirect_return('/adm/index.php?m=personal_file');