<?php
/**
 * FILE: /adm/modules/dashboard/assets/php/dashboard_profile_update.php
 * ROLE: profile_update — сохранение профиля текущего пользователя
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/dashboard_lib.php';

acl_guard(module_allowed_roles('dashboard'));

/**
 * $csrf — CSRF токен из POST
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

/**
 * $pdo — соединение с БД
 */
$pdo = db();

/**
 * $uid — текущий пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);

/**
 * $actorRole — роль текущего пользователя
 */
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

/**
 * $fallback — резервный редирект
 */
$fallback = '/adm/index.php?m=dashboard';

if ($uid <= 0) {
  audit_log('dashboard', 'profile_update', 'warn', [
    'reason' => 'no_auth_user',
  ], 'user', null, 0, $actorRole);

  flash(dashboard_t('dashboard.flash_user_not_defined'), 'warn');

  if (function_exists('redirect_return')) {
    redirect_return($fallback);
  }
  redirect($fallback);
}

/**
 * Профильные поля
 */
$name = trim((string)($_POST['name'] ?? ''));
$lastName = trim((string)($_POST['last_name'] ?? ''));
$middleName = trim((string)($_POST['middle_name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$uiTheme = trim((string)($_POST['ui_theme'] ?? 'dark'));

if ($name === '' || $phone === '' || $email === '') {
  audit_log('dashboard', 'profile_update', 'warn', [
    'reason' => 'validation',
    'name' => ($name !== ''),
    'phone' => ($phone !== '' ? $phone : null),
    'email' => ($email !== '' ? $email : null),
  ], 'user', $uid, $uid, $actorRole);

  flash(dashboard_t('dashboard.flash_profile_required'), 'warn');

  if (function_exists('redirect_return')) {
    redirect_return($fallback);
  }
  redirect($fallback);
}

if (!in_array($uiTheme, ['dark', 'light', 'color'], true)) {
  $uiTheme = 'dark';
}

/**
 * $nameColumns — наличие полей фамилии/отчества в users
 */
$nameColumns = dashboard_users_name_columns($pdo);

/**
 * $isSpecialist — текущий пользователь имеет роль specialist
 */
$isSpecialist = dashboard_user_is_specialist($pdo, $uid);

/**
 * $leadRaw — исходное значение перерыва между приёмами
 */
$leadRaw = trim((string)($_POST['lead_minutes'] ?? ''));

/**
 * $leadMinutes — нормализованный перерыв между приёмами
 */
$leadMinutes = ($leadRaw === '') ? 10 : (int)$leadRaw;
if ($leadMinutes < 0) $leadMinutes = 0;
if ($leadMinutes > 240) $leadMinutes = 240;

/**
 * $schedule — расписание из формы
 */
$schedule = $_POST['schedule'] ?? [];
if (!is_array($schedule)) {
  $schedule = [];
}

/**
 * $tgNotifyInput — входные персональные настройки TG-событий.
 */
$tgNotifyInput = $_POST['tg_notify'] ?? [];
if (!is_array($tgNotifyInput)) {
  $tgNotifyInput = [];
}
/**
 * $tgNotifyLoaded — флаг, что секция TG-настроек присутствовала в форме.
 */
$tgNotifyLoaded = ((int)($_POST['tg_notify_loaded'] ?? 0) === 1);

/**
 * $tgSettingsFile — settings.php модуля tg_system_users.
 */
$tgSettingsFile = ROOT_PATH . '/adm/modules/tg_system_users/settings.php';
/**
 * $tgLibFile — библиотека модуля tg_system_users.
 */
$tgLibFile = ROOT_PATH . '/adm/modules/tg_system_users/assets/php/tg_system_users_lib.php';

try {
  $pdo->beginTransaction();

  /**
   * $fields — SQL поля для UPDATE users
   */
  $fields = [
    'name = :name',
    'phone = :phone',
    'email = :email',
    'ui_theme = :ui_theme',
    'updated_at = NOW()',
  ];

  /**
   * $params — параметры для UPDATE users
   */
  $params = [
    ':name' => $name,
    ':phone' => $phone,
    ':email' => $email,
    ':ui_theme' => $uiTheme,
    ':id' => $uid,
  ];

  if (!empty($nameColumns['last_name'])) {
    $fields[] = 'last_name = :last_name';
    $params[':last_name'] = ($lastName !== '' ? $lastName : null);
  }

  if (!empty($nameColumns['middle_name'])) {
    $fields[] = 'middle_name = :middle_name';
    $params[':middle_name'] = ($middleName !== '' ? $middleName : null);
  }

  $sqlUser = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id LIMIT 1';

  $stUser = $pdo->prepare($sqlUser);
  $stUser->execute($params);

  if ($isSpecialist) {
    /**
     * $weekdays — список дней недели
     */
    $weekdays = dashboard_schedule_weekdays();

    $stSched = $pdo->prepare('
      INSERT INTO specialist_schedule
      (user_id, weekday, time_start, time_end, break_start, break_end, is_day_off, lead_minutes)
      VALUES
      (:uid, :wd, :ts, :te, :bs, :be, :day_off, :lead)
      ON DUPLICATE KEY UPDATE
        time_start = VALUES(time_start),
        time_end = VALUES(time_end),
        break_start = VALUES(break_start),
        break_end = VALUES(break_end),
        is_day_off = VALUES(is_day_off),
        lead_minutes = VALUES(lead_minutes)
    ');

    foreach ($weekdays as $wd => $label) {
      /**
       * $row — данные дня недели из формы
       */
      $row = $schedule[$wd] ?? [];
      if (!is_array($row)) {
        $row = [];
      }

      /**
       * $isDayOff — флаг выходного
       */
      $isDayOff = isset($row['day_off']) ? 1 : 0;

      /**
       * Времена работы и перерыва
       */
      $timeStart = dashboard_time_hm((string)($row['time_start'] ?? ''), '09:00');
      $timeEnd = dashboard_time_hm((string)($row['time_end'] ?? ''), '18:00');
      $breakStart = dashboard_time_hm((string)($row['break_start'] ?? ''), '13:00');
      $breakEnd = dashboard_time_hm((string)($row['break_end'] ?? ''), '14:00');

      $stSched->execute([
        ':uid' => $uid,
        ':wd' => (int)$wd,
        ':ts' => $timeStart,
        ':te' => $timeEnd,
        ':bs' => $breakStart,
        ':be' => $breakEnd,
        ':day_off' => $isDayOff,
        ':lead' => $leadMinutes,
      ]);
    }
  }

  if ($tgNotifyLoaded
      && function_exists('module_is_enabled')
      && module_is_enabled('tg_system_users')
      && is_file($tgSettingsFile)
      && is_file($tgLibFile)) {
    require_once $tgSettingsFile;
    require_once $tgLibFile;

    if (function_exists('tg_system_users_user_preferences_save')) {
      $tgMap = [];
      foreach ($tgNotifyInput as $eventCode => $enabledVal) {
        $code = trim((string)$eventCode);
        if ($code === '') continue;
        $tgMap[$code] = ((int)$enabledVal === 1 || (string)$enabledVal === '1') ? 1 : 0;
      }

      tg_system_users_user_preferences_save($pdo, $uid, $tgMap);
    }
  }

  $pdo->commit();

  audit_log('dashboard', 'profile_update', 'info', [
    'user_id' => $uid,
    'email' => $email,
    'phone' => $phone,
    'ui_theme' => $uiTheme,
    'is_specialist' => $isSpecialist,
    'lead_minutes' => ($isSpecialist ? $leadMinutes : null),
    'tg_notify_items' => count($tgNotifyInput),
  ], 'user', $uid, $uid, $actorRole);

  flash(dashboard_t('dashboard.flash_profile_saved'), 'ok');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  audit_log('dashboard', 'profile_update', 'error', [
    'user_id' => $uid,
    'error' => $e->getMessage(),
  ], 'user', $uid, $uid, $actorRole);

  flash(dashboard_t('dashboard.flash_profile_save_error'), 'danger', 1);
}

if (function_exists('redirect_return')) {
  redirect_return($fallback);
}
redirect($fallback);
