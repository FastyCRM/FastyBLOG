<?php
/**
 * FILE: /adm/modules/users/assets/php/users_update.php
 * ROLE: update - обновление пользователя
 * CONNECTIONS:
 *  - db()
 *  - csrf_check()
 *  - flash()
 *  - redirect()
 *  - auth_user_role(), auth_user_id()
 *  - module_allowed_roles(), acl_guard()
 *  - users_t()
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/users_i18n.php';

/**
 * ACL: доступ к модулю users
 */
acl_guard(module_allowed_roles('users'));

/**
 * CSRF
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

/**
 * $pdo - соединение с БД
 */
$pdo = db();

/**
 * $actorRole - роль актёра
 */
$actorRole = (string)auth_user_role();

/**
 * $actorId - кто выполняет действие
 */
$actorId = (int)auth_user_id();

/**
 * $isAdmin - админ
 */
$isAdmin = ($actorRole === 'admin');

/**
 * $id - редактируемый пользователь
 */
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  audit_log('users', 'update', 'warn', [
    'reason' => 'invalid_id',
    'id' => $id,
  ], 'user', null, $actorId, $actorRole);
  flash(users_t('users.flash_invalid_user'), 'warn');
  redirect('/adm/index.php?m=users');
}

/**
 * Поля
 */
$name = trim((string)($_POST['name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$status = (string)($_POST['status'] ?? 'active');
$uiTheme = (string)($_POST['ui_theme'] ?? 'dark');

/**
 * Валидация
 */
if ($name === '' || $phone === '' || $email === '') {
  audit_log('users', 'update', 'warn', [
    'reason' => 'validation',
    'id' => $id,
    'name' => ($name !== ''),
    'phone' => ($phone !== '' ? $phone : null),
    'email' => ($email !== '' ? $email : null),
  ], 'user', $id, $actorId, $actorRole);
  flash(users_t('users.flash_required_fields'), 'warn');
  redirect('/adm/index.php?m=users');
}

if (!in_array($status, ['active', 'blocked'], true)) $status = 'active';
if (!in_array($uiTheme, ['dark', 'light', 'color'], true)) $uiTheme = 'dark';

/**
 * $roleIds - роли для назначения
 */
$roleIds = [];
/**
 * $specialistRoleId - id роли specialist
 */
$specialistRoleId = 0;
try {
  $stSpec = $pdo->prepare("SELECT id FROM roles WHERE code='specialist' LIMIT 1");
  $stSpec->execute();
  $specialistRoleId = (int)($stSpec->fetchColumn() ?: 0);
} catch (Throwable $e) {
  $specialistRoleId = 0;
}

if ($isAdmin) {
  /**
   * Admin: роли из формы
   */
  $in = $_POST['role_ids'] ?? [];
  if (is_array($in)) {
    foreach ($in as $rid) {
      $rid = (int)$rid;
      if ($rid > 0) $roleIds[] = $rid;
    }
  }
} else {
  /**
   * Manager: принудительно роль user
   */
  $st = $pdo->prepare("SELECT id FROM roles WHERE code='user' LIMIT 1");
  $st->execute();
  $roleIds[] = (int)($st->fetchColumn() ?: 0);
}

$roleIds = array_values(array_unique(array_filter($roleIds)));
if (!$roleIds) {
  audit_log('users', 'update', 'error', [
    'reason' => 'no_roles',
    'id' => $id,
  ], 'user', $id, $actorId, $actorRole);
  flash(users_t('users.flash_role_assign_failed'), 'danger', 1);
  redirect('/adm/index.php?m=users');
}

/**
 * $isUserSpecialist - итоговая роль specialist
 */
$isUserSpecialist = ($specialistRoleId > 0 && in_array($specialistRoleId, $roleIds, true));

try {
  $pdo->beginTransaction();

  /**
   * Обновляем пользователя
   */
  $st = $pdo->prepare("
    UPDATE users
    SET email=:email, phone=:phone, name=:name, status=:status, ui_theme=:ui_theme, updated_at=NOW()
    WHERE id=:id
    LIMIT 1
  ");

  $st->execute([
    ':email' => ($email !== '' ? $email : null),
    ':phone' => $phone,
    ':name' => $name,
    ':status' => $status,
    ':ui_theme' => $uiTheme,
    ':id' => $id,
  ]);

  /**
   * Переназначаем роли (простая модель)
   */
  $st = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ?");
  $st->execute([$id]);

  $st = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
  foreach ($roleIds as $rid) {
    $st->execute([$id, (int)$rid]);
  }

  /**
   * Обновляем расписание специалиста (если есть и роль specialist)
   */
  $schedule = $_POST['schedule'] ?? null;
  if ($isUserSpecialist && is_array($schedule)) {
    $weekdays = [1, 2, 3, 4, 5, 6, 7];
    $stSched = $pdo->prepare("
      INSERT INTO specialist_schedule (user_id, weekday, time_start, time_end, break_start, break_end, is_day_off)
      VALUES (:uid, :wd, :ts, :te, :bs, :be, :day_off)
      ON DUPLICATE KEY UPDATE
        time_start = VALUES(time_start),
        time_end = VALUES(time_end),
        break_start = VALUES(break_start),
        break_end = VALUES(break_end),
        is_day_off = VALUES(is_day_off)
    ");

    foreach ($weekdays as $wd) {
      $row = $schedule[$wd] ?? [];
      if (!is_array($row)) $row = [];

      $isDayOff = isset($row['day_off']) ? 1 : 0;

      $timeStart = trim((string)($row['time_start'] ?? ''));
      $timeEnd = trim((string)($row['time_end'] ?? ''));
      if (!preg_match('/^\d{2}:\d{2}$/', $timeStart)) $timeStart = '09:00';
      if (!preg_match('/^\d{2}:\d{2}$/', $timeEnd)) $timeEnd = '18:00';

      $breakStart = trim((string)($row['break_start'] ?? ''));
      $breakEnd = trim((string)($row['break_end'] ?? ''));
      if (!preg_match('/^\d{2}:\d{2}$/', $breakStart)) $breakStart = '13:00';
      if (!preg_match('/^\d{2}:\d{2}$/', $breakEnd)) $breakEnd = '14:00';

      $stSched->execute([
        ':uid' => $id,
        ':wd' => $wd,
        ':ts' => $timeStart,
        ':te' => $timeEnd,
        ':bs' => $breakStart,
        ':be' => $breakEnd,
        ':day_off' => $isDayOff,
      ]);
    }
  }

  $pdo->commit();

  audit_log('users', 'update', 'info', [
    'id' => $id,
    'phone' => $phone,
    'email' => ($email !== '' ? $email : null),
    'status' => $status,
    'roles' => $roleIds,
  ], 'user', $id, $actorId, $actorRole);

  flash(users_t('users.flash_user_updated'), 'ok');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  audit_log('users', 'update', 'error', [
    'id' => $id,
    'phone' => $phone,
    'error' => $e->getMessage(),
  ], 'user', $id, $actorId, $actorRole);
  flash(users_t('users.flash_user_update_error'), 'danger', 1);
}

redirect('/adm/index.php?m=users');
