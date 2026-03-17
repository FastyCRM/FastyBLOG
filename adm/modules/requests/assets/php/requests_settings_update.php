<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_settings_update.php
 * ROLE: settings_update — обновление настроек модуля
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
 * $actorRole — роль
 */
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if (!$isAdmin) {
  audit_log('requests', 'settings', 'warn', [
    'reason' => 'deny',
  ], 'request', null, $uid, $actorRole);
  flash('Доступ запрещён', 'danger', 1);
  redirect_return('/adm/index.php?m=requests');
}

/**
 * $useSpecialists — значение
 */
$useSpecialists = (int)($_POST['use_specialists'] ?? 0);
$useSpecialists = $useSpecialists === 1 ? 1 : 0;

/**
 * $useTimeSlots — интервальный режим
 */
$useTimeSlots = (int)($_POST['use_time_slots'] ?? 0);
$useTimeSlots = $useTimeSlots === 1 ? 1 : 0;
if ($useSpecialists === 0) {
  $useTimeSlots = 0;
}

try {
  /**
   * $sql — запрос настроек
   */
  $sql = "\n    INSERT INTO " . REQUESTS_SETTINGS_TABLE . " (id, use_specialists, use_time_slots)
    VALUES (1, :use1, :slots1)
    ON DUPLICATE KEY UPDATE use_specialists = :use2, use_time_slots = :slots2
  ";

  $pdo->prepare($sql)->execute([
    ':use1' => $useSpecialists,
    ':use2' => $useSpecialists,
    ':slots1' => $useTimeSlots,
    ':slots2' => $useTimeSlots,
  ]);

  audit_log('requests', 'settings', 'info', [
    'use_specialists' => $useSpecialists,
    'use_time_slots' => $useTimeSlots,
  ], 'request', null, $uid, $actorRole);

  flash('Настройки сохранены', 'ok');
} catch (Throwable $e) {
  audit_log('requests', 'settings', 'error', [
    'error' => $e->getMessage(),
  ], 'request', null, $uid, $actorRole);

  flash('Ошибка настроек', 'danger', 1);
}

redirect_return('/adm/index.php?m=requests');
