<?php
/**
 * FILE: /adm/modules/calendar/assets/php/calendar_settings_update.php
 * ROLE: settings_update — обновление настроек календаря
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/calendar_lib.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('calendar'));

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
$isAdmin = in_array('admin', $roles, true);
/**
 * $isManager — менеджер
 */
$isManager = in_array('manager', $roles, true);
/**
 * $isSpecialist — специалист
 */
$isSpecialist = in_array('specialist', $roles, true);

/**
 * $actorRole — роль
 */
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if (!$isAdmin && !$isManager) {
  audit_log('calendar', 'settings', 'warn', [
    'reason' => 'deny',
  ], 'calendar', null, $uid, $actorRole);
  flash('Доступ запрещён', 'danger', 1);
  redirect('/adm/index.php?m=calendar');
}

/**
 * $useTimeSlots — режим интервалов (глобально)
 */
$useTimeSlots = (int)($_POST['use_time_slots'] ?? 0);
$useTimeSlots = $useTimeSlots === 1 ? 1 : 0;

if (!$isAdmin) {
  try {
    $st = $pdo->query("SELECT use_time_slots FROM " . CALENDAR_REQUESTS_SETTINGS_TABLE . " WHERE id = 1 LIMIT 1");
    $useTimeSlots = $st ? (int)$st->fetchColumn() : 0;
  } catch (Throwable $e) {
    $useTimeSlots = 0;
  }
}

/**
 * $calendarMode — режим календаря (персонально)
 */
$calendarMode = (string)($_POST['calendar_mode'] ?? 'user');
if (!in_array($calendarMode, ['user', 'manager'], true)) {
  $calendarMode = 'user';
}
if ($calendarMode === 'user' && !$isSpecialist && ($isAdmin || $isManager)) {
  $calendarMode = 'manager';
}

/**
 * $managerSpecIds — выбранные специалисты
 */
$managerSpecIdsInput = $_POST['calendar_manager_spec_ids'] ?? null;
$managerSpecIds = '';
if (is_array($managerSpecIdsInput)) {
  $ids = [];
  foreach ($managerSpecIdsInput as $sid) {
    $sid = (int)$sid;
    if ($sid > 0) $ids[] = $sid;
  }
  $ids = array_values(array_unique($ids));
  $managerSpecIds = $ids ? implode(',', $ids) : '';
}

try {
  $pdo->prepare("
    INSERT INTO " . CALENDAR_REQUESTS_SETTINGS_TABLE . " (id, use_time_slots)
    VALUES (1, :use_time_slots)
    ON DUPLICATE KEY UPDATE use_time_slots = :use_time_slots_u
  ")->execute([
    ':use_time_slots' => $useTimeSlots,
    ':use_time_slots_u' => $useTimeSlots,
  ]);
} catch (Throwable $e) {
  // ignore
}

try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS " . CALENDAR_USER_SETTINGS_TABLE . " (
      user_id INT NOT NULL,
      mode ENUM('user','manager') NOT NULL DEFAULT 'user',
      manager_spec_ids VARCHAR(255) NOT NULL DEFAULT '',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
  ");
} catch (Throwable $e) {
  // ignore
}

try {
  $pdo->prepare("
    INSERT INTO " . CALENDAR_USER_SETTINGS_TABLE . " (user_id, mode, manager_spec_ids)
    VALUES (:uid, :mode, :specs)
    ON DUPLICATE KEY UPDATE
      mode = :mode_u,
      manager_spec_ids = :specs_u
  ")->execute([
    ':uid' => $uid,
    ':mode' => $calendarMode,
    ':specs' => $managerSpecIds,
    ':mode_u' => $calendarMode,
    ':specs_u' => $managerSpecIds,
  ]);

  audit_log('calendar', 'settings', 'info', [
    'use_time_slots' => $useTimeSlots,
    'calendar_mode' => $calendarMode,
    'calendar_manager_spec_ids' => $managerSpecIds,
    'user_id' => $uid,
  ], 'calendar', null, $uid, $actorRole);

  flash('Настройки календаря сохранены', 'ok');
} catch (Throwable $e) {
  audit_log('calendar', 'settings', 'error', [
    'error' => $e->getMessage(),
  ], 'calendar', null, $uid, $actorRole);

  flash('Ошибка настроек календаря', 'danger', 1);
}

redirect('/adm/index.php?m=calendar');