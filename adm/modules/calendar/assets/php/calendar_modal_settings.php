<?php
/**
 * FILE: /adm/modules/calendar/assets/php/calendar_modal_settings.php
 * ROLE: modal_settings — настройки модуля calendar
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

if (!$isAdmin && !$isManager) {
  json_err('Forbidden', 403);
}

/**
 * $pdo — БД
 */
$pdo = db();

/**
 * $settingsRow — настройки заявок
 */
$settingsRow = null;
try {
  $st = $pdo->query("SELECT use_time_slots FROM " . CALENDAR_REQUESTS_SETTINGS_TABLE . " WHERE id = 1 LIMIT 1");
  $settingsRow = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
} catch (Throwable $e) {
  $settingsRow = null;
}
if (!$settingsRow) {
  $settingsRow = [
    'use_time_slots' => 0,
  ];
}

/**
 * $useTimeSlots — интервальный режим
 */
$useTimeSlots = ((int)($settingsRow['use_time_slots'] ?? 0) === 1);

/**
 * $calendarMode — режим календаря (персонально)
 */
$calendarMode = '';
try {
  $stUser = $pdo->prepare("SELECT mode FROM " . CALENDAR_USER_SETTINGS_TABLE . " WHERE user_id = :uid LIMIT 1");
  $stUser->execute([':uid' => $uid]);
  $calendarMode = (string)($stUser->fetchColumn() ?: '');
} catch (Throwable $e) {
  $calendarMode = '';
}
if ($calendarMode === '') {
  $calendarMode = ($isAdmin || $isManager) && !$isSpecialist ? 'manager' : 'user';
}
if (!in_array($calendarMode, ['user', 'manager'], true)) {
  $calendarMode = 'user';
}
if ($calendarMode === 'user' && !$isSpecialist && ($isAdmin || $isManager)) {
  $calendarMode = 'manager';
}

/**
 * $csrf — токен
 */
$csrf = csrf_token();

ob_start();
?>
<div class="card" data-calendar-settings-modal="1" style="box-shadow:none; border-color: var(--border-soft);">
  <div class="card__body" style="display:flex; flex-direction:column; gap:12px;">
    <form method="post" action="<?= h(url('/adm/index.php?m=calendar&do=settings_update')) ?>">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

      <div class="field" style="display:flex; flex-direction:column; gap:6px;">
        <div class="field__label">Режим календаря</div>
        <label style="display:flex; gap:8px; align-items:center; <?= $isSpecialist ? '' : 'opacity:.6;' ?>">
          <input type="radio" name="calendar_mode" value="user" <?= $calendarMode === 'user' ? 'checked' : '' ?> <?= $isSpecialist ? '' : 'disabled' ?>>
          <span>Пользовательский (специалист)</span>
        </label>
        <label style="display:flex; gap:8px; align-items:center;">
          <input type="radio" name="calendar_mode" value="manager" <?= $calendarMode === 'manager' ? 'checked' : '' ?>>
          <span>Менеджерский</span>
        </label>
        <?php if (!$isSpecialist): ?>
          <div class="muted" style="font-size:12px;">Пользовательский режим доступен только для роли specialist.</div>
        <?php endif; ?>
      </div>

      <div class="card"
           data-calendar-manager-wrap="1"
           data-calendar-manager-url="<?= h(url('/adm/index.php?m=calendar&do=modal_manager_settings')) ?>"
           style="box-shadow:none; border-color: var(--border-soft); padding:10px; <?= $calendarMode === 'manager' ? '' : 'display:none;' ?>">
        <div class="muted" style="font-size:12px;">Загрузка…</div>
      </div>

      <label class="field" style="display:flex; gap:8px; align-items:center; <?= $isAdmin ? '' : 'opacity:.6;' ?>">
        <input type="checkbox" name="use_time_slots" value="1" <?= $useTimeSlots ? 'checked' : '' ?> <?= $isAdmin ? '' : 'disabled' ?>>
        <span>Интервалы (слоты времени)</span>
      </label>

      <?php if (!$isAdmin): ?>
        <div class="muted" style="font-size:12px;">Флаг интервалов меняется только админом.</div>
      <?php endif; ?>

      <div class="muted" style="font-size:12px;">
        Настройка влияет на выбор времени в заявках.
      </div>

      <button class="btn btn--accent" type="submit">Сохранить</button>
    </form>
  </div>
</div>
<?php
$html = ob_get_clean();

json_ok([
  'title' => 'Настройки календаря',
  'html' => $html,
]);