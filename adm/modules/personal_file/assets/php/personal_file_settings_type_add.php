<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_settings_type_add.php
 * ROLE: Добавление типа доступа (настройки модуля personal_file)
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
$name = trim((string)($_POST['name'] ?? ''));

if ($name === '') {
  audit_log('personal_file', 'settings_type_add', 'warn', [
    'reason' => 'empty_name',
  ], 'access_type', null, $uid, $actorRole);
  flash('Название не заполнено', 'warn');
  redirect_return('/adm/index.php?m=personal_file');
}

$pdo = db();

try {
  $st = $pdo->prepare("\n    INSERT INTO " . PERSONAL_FILE_ACCESS_TYPES_TABLE . " (name, status, created_at, updated_at)\n    VALUES (:name, 'active', NOW(), NOW())\n  ");
  $st->execute([':name' => $name]);

  audit_log('personal_file', 'settings_type_add', 'info', [
    'name' => $name,
  ], 'access_type', null, $uid, $actorRole);
  flash('Тип добавлен', 'ok');
} catch (Throwable $e) {
  audit_log('personal_file', 'settings_type_add', 'error', [
    'error' => $e->getMessage(),
  ], 'access_type', null, $uid, $actorRole);
  flash('Ошибка добавления типа', 'danger', 1);
}

redirect_return('/adm/index.php?m=personal_file');