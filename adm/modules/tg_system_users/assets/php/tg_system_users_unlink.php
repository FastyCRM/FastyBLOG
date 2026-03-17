<?php
/**
 * FILE: /adm/modules/tg_system_users/assets/php/tg_system_users_unlink.php
 * ROLE: unlink — ручная отвязка Telegram у выбранного пользователя
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/tg_system_users_lib.php';

acl_guard(module_allowed_roles('tg_system_users'));

/**
 * $csrf — CSRF токен.
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

/**
 * $uid — текущий пользователь.
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
/**
 * $roles — роли текущего пользователя.
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
/**
 * $actorRole — роль для аудита.
 */
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if (!tg_system_users_is_manage_role($roles)) {
  flash('Доступ запрещён', 'danger', 1);
  redirect('/adm/index.php?m=tg_system_users');
}

/**
 * $targetUserId — пользователь, для которого снимаем привязку.
 */
$targetUserId = (int)($_POST['user_id'] ?? 0);
if ($targetUserId <= 0) {
  flash('Некорректный пользователь', 'warn');
  redirect('/adm/index.php?m=tg_system_users');
}

try {
  $pdo = db();
  $result = tg_system_users_unlink_user($pdo, $targetUserId);

  $hadActiveLink = ((int)($result['had_active_link'] ?? 0) === 1);

  audit_log('tg_system_users', 'unlink', 'info', [
    'target_user_id' => $targetUserId,
    'had_active_link' => $hadActiveLink ? 1 : 0,
  ], 'module', null, $uid, $actorRole);

  if ($hadActiveLink) {
    flash('Привязка Telegram снята.', 'ok');
  } else {
    flash('Активной привязки не было.', 'warn');
  }
} catch (Throwable $e) {
  audit_log('tg_system_users', 'unlink', 'error', [
    'target_user_id' => $targetUserId,
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);
  flash('Ошибка отвязки Telegram', 'danger', 1);
}

redirect('/adm/index.php?m=tg_system_users');

