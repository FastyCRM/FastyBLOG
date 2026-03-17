<?php
/**
 * FILE: /adm/modules/tg_system_users/assets/php/tg_system_users_generate_link.php
 * ROLE: generate_link - генерация короткого кода привязки сотрудника
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/tg_system_users_lib.php';

acl_guard(module_allowed_roles('tg_system_users'));

$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if (!tg_system_users_is_manage_role($roles)) {
  flash('Доступ запрещён', 'danger', 1);
  redirect('/adm/index.php?m=tg_system_users');
}

$targetUserId = (int)($_POST['user_id'] ?? 0);
if ($targetUserId <= 0) {
  flash('Некорректный пользователь', 'warn');
  redirect('/adm/index.php?m=tg_system_users');
}

try {
  $pdo = db();
  $token = tg_system_users_generate_link_token($pdo, $targetUserId, $uid);

  audit_log('tg_system_users', 'generate_link', 'info', [
    'target_user_id' => $targetUserId,
  ], 'module', null, $uid, $actorRole);

  flash('Код создан: ' . $token . '. Для привязки отправьте боту этот 4-значный код.', 'ok');
} catch (Throwable $e) {
  audit_log('tg_system_users', 'generate_link', 'error', [
    'target_user_id' => $targetUserId,
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);
  flash('Ошибка генерации кода привязки', 'danger', 1);
}

redirect('/adm/index.php?m=tg_system_users');