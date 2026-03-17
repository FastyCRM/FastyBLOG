<?php
/**
 * FILE: /adm/modules/tg_system_users/assets/php/tg_system_users_toggle_event.php
 * ROLE: toggle_event — включение/выключение системного события глобально
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
 * $eventCode — код события.
 */
$eventCode = trim((string)($_POST['event_code'] ?? ''));
/**
 * $enabled — флаг включения.
 */
$enabled = ((int)($_POST['enabled'] ?? 0) === 1) ? 1 : 0;

if ($eventCode === '') {
  flash('Некорректный код события', 'warn');
  redirect('/adm/index.php?m=tg_system_users');
}

try {
  $pdo = db();
  tg_system_users_toggle_event($pdo, $eventCode, $enabled);

  audit_log('tg_system_users', 'toggle_event', 'info', [
    'event_code' => $eventCode,
    'enabled' => $enabled,
  ], 'module', null, $uid, $actorRole);

  flash('Событие обновлено', 'ok');
} catch (Throwable $e) {
  audit_log('tg_system_users', 'toggle_event', 'error', [
    'event_code' => $eventCode,
    'enabled' => $enabled,
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);
  flash('Ошибка обновления события', 'danger', 1);
}

redirect('/adm/index.php?m=tg_system_users');

