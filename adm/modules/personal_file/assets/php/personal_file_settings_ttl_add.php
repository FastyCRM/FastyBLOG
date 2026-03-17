<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_settings_ttl_add.php
 * ROLE: Добавление срока жизни доступа (настройки модуля personal_file)
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
$months = (int)($_POST['months'] ?? 0);
$isPermanent = (int)($_POST['is_permanent'] ?? 0);

if ($name === '') {
  audit_log('personal_file', 'settings_ttl_add', 'warn', [
    'reason' => 'empty_name',
  ], 'access_ttl', null, $uid, $actorRole);
  flash('Название не заполнено', 'warn');
  redirect_return('/adm/index.php?m=personal_file');
}

if ($isPermanent !== 1 && $months <= 0) {
  audit_log('personal_file', 'settings_ttl_add', 'warn', [
    'reason' => 'months_required',
    'name' => $name,
  ], 'access_ttl', null, $uid, $actorRole);
  flash('Укажите количество месяцев', 'warn');
  redirect_return('/adm/index.php?m=personal_file');
}

if ($isPermanent === 1) {
  $months = 0;
}

$pdo = db();

/**
 * Сортировка (по нарастающей)
 */
$sort = 1000;
try {
  $stSort = $pdo->query("SELECT MAX(sort) FROM " . PERSONAL_FILE_ACCESS_TTLS_TABLE);
  $maxSort = $stSort ? (int)$stSort->fetchColumn() : 0;
  if ($maxSort > 0) $sort = $maxSort + 10;
} catch (Throwable $e) {
  $sort = 1000;
}

try {
  $st = $pdo->prepare("\n    INSERT INTO " . PERSONAL_FILE_ACCESS_TTLS_TABLE . "\n      (name, months, is_permanent, status, sort, created_at, updated_at)\n    VALUES\n      (:name, :months, :is_permanent, 'active', :sort, NOW(), NOW())\n  ");
  $st->execute([
    ':name' => $name,
    ':months' => $months,
    ':is_permanent' => ($isPermanent === 1 ? 1 : 0),
    ':sort' => $sort,
  ]);

  audit_log('personal_file', 'settings_ttl_add', 'info', [
    'name' => $name,
    'months' => $months,
    'is_permanent' => ($isPermanent === 1),
  ], 'access_ttl', null, $uid, $actorRole);
  flash('Срок добавлен', 'ok');
} catch (Throwable $e) {
  audit_log('personal_file', 'settings_ttl_add', 'error', [
    'error' => $e->getMessage(),
  ], 'access_ttl', null, $uid, $actorRole);
  flash('Ошибка добавления срока', 'danger', 1);
}

redirect_return('/adm/index.php?m=personal_file');