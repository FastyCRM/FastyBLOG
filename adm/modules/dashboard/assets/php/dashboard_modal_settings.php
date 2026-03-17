<?php
/**
 * FILE: /adm/modules/dashboard/assets/php/dashboard_modal_settings.php
 * ROLE: modal_settings — модалка редактирования профиля на dashboard
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/dashboard_lib.php';

acl_guard(module_allowed_roles('dashboard'));

/**
 * $uid — текущий пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);

/**
 * $pdo — соединение с БД
 */
$pdo = db();

/**
 * $csrf — CSRF токен
 */
$csrf = csrf_token();

/**
 * $profile — профиль пользователя
 */
$profile = dashboard_user_profile($pdo, $uid);

/**
 * $nameColumns — наличие полей ФИО в users
 */
$nameColumns = (array)($profile['name_columns'] ?? []);

/**
 * $hasLast — флаг last_name
 */
$hasLast = !empty($nameColumns['last_name']);

/**
 * $hasMiddle — флаг middle_name
 */
$hasMiddle = !empty($nameColumns['middle_name']);

/**
 * $isSpecialist — пользователь имеет роль specialist
 */
$isSpecialist = dashboard_user_is_specialist($pdo, $uid);

/**
 * $scheduleData — расписание пользователя
 */
$scheduleData = dashboard_schedule_get($pdo, $uid);

/**
 * $scheduleRows — строки расписания по дням
 */
$scheduleRows = (array)($scheduleData['rows'] ?? []);

/**
 * $leadMinutes — перерыв между приёмами
 */
$leadMinutes = (int)($scheduleData['lead_minutes'] ?? 10);
if ($leadMinutes < 0) $leadMinutes = 10;

/**
 * $tgPrefs — персональные настройки TG-событий.
 */
$tgPrefs = [];
/**
 * $tgPrefsAvailable — доступность интеграции tg_system_users.
 */
$tgPrefsAvailable = false;

$tgSettingsFile = ROOT_PATH . '/adm/modules/tg_system_users/settings.php';
$tgLibFile = ROOT_PATH . '/adm/modules/tg_system_users/assets/php/tg_system_users_lib.php';
if (function_exists('module_is_enabled')
    && module_is_enabled('tg_system_users')
    && is_file($tgSettingsFile)
    && is_file($tgLibFile)) {
  require_once $tgSettingsFile;
  require_once $tgLibFile;

  if (function_exists('tg_system_users_user_preferences')) {
    try {
      $tgPrefs = (array)tg_system_users_user_preferences($pdo, $uid);
      $tgPrefsAvailable = true;
    } catch (Throwable $e) {
      $tgPrefs = [];
      $tgPrefsAvailable = false;
    }
  }
}

/**
 * $weekdays — список дней недели
 */
$weekdays = dashboard_schedule_weekdays();

/**
 * $returnUrl — куда вернуться после сохранения
 */
$returnUrl = (string)($_SERVER['HTTP_REFERER'] ?? '/adm/index.php?m=dashboard');
if ($returnUrl === '') {
  $returnUrl = '/adm/index.php?m=dashboard';
}

ob_start();
?>
<form method="post" action="<?= h(url('/adm/index.php?m=dashboard&do=profile_update')) ?>" class="dash-profile-form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">

  <div class="card dash-modal-card">
    <div class="card__head">
      <div class="card__title"><?= h(dashboard_t('dashboard.modal_profile_edit_title')) ?></div>
      <div class="card__hint muted"><?= h(dashboard_t('dashboard.modal_profile_edit_hint')) ?></div>
    </div>

    <div class="card__body">
      <div class="dash-form-grid">
        <?php if ($hasLast): ?>
          <label class="field field--stack">
            <span class="field__label"><?= h(dashboard_t('dashboard.field_last_name')) ?></span>
            <input class="input" name="last_name" value="<?= h((string)($profile['last_name'] ?? '')) ?>" maxlength="80">
          </label>
        <?php endif; ?>

        <label class="field field--stack">
          <span class="field__label"><?= h(dashboard_t('dashboard.field_name')) ?></span>
          <input class="input" name="name" value="<?= h((string)($profile['name'] ?? '')) ?>" required maxlength="190">
        </label>

        <?php if ($hasMiddle): ?>
          <label class="field field--stack">
            <span class="field__label"><?= h(dashboard_t('dashboard.field_middle_name')) ?></span>
            <input class="input" name="middle_name" value="<?= h((string)($profile['middle_name'] ?? '')) ?>" maxlength="80">
          </label>
        <?php endif; ?>

        <label class="field field--stack">
          <span class="field__label"><?= h(dashboard_t('dashboard.field_phone')) ?></span>
          <input class="input" name="phone" value="<?= h((string)($profile['phone'] ?? '')) ?>" required maxlength="32">
        </label>

        <label class="field field--stack">
          <span class="field__label"><?= h(dashboard_t('dashboard.field_email')) ?></span>
          <input class="input" type="email" name="email" value="<?= h((string)($profile['email'] ?? '')) ?>" required maxlength="190">
        </label>

        <label class="field field--stack">
          <span class="field__label"><?= h(dashboard_t('dashboard.field_theme')) ?></span>
          <?php $theme = (string)($profile['ui_theme'] ?? 'dark'); ?>
          <select class="select" name="ui_theme" data-ui-select="1">
            <option value="dark" <?= $theme === 'dark' ? 'selected' : '' ?>>dark</option>
            <option value="light" <?= $theme === 'light' ? 'selected' : '' ?>>light</option>
            <option value="color" <?= $theme === 'color' ? 'selected' : '' ?>>color</option>
          </select>
        </label>
      </div>

      <?php if ($isSpecialist): ?>
        <div class="card dash-schedule-card">
          <div class="card__head">
            <div class="card__title"><?= h(dashboard_t('dashboard.work_time_title')) ?></div>
            <div class="card__hint muted"><?= h(dashboard_t('dashboard.work_time_hint')) ?></div>
          </div>

          <div class="card__body">
            <label class="field field--stack dash-lead-field">
              <span class="field__label"><?= h(dashboard_t('dashboard.work_lead_minutes')) ?></span>
              <input class="input" type="number" name="lead_minutes" min="0" max="240" step="1" value="<?= (int)$leadMinutes ?>">
            </label>

            <div class="table-wrap">
              <table class="table dash-schedule-table">
                <thead>
                  <tr>
                    <th><?= h(dashboard_t('dashboard.table_day')) ?></th>
                    <th><?= h(dashboard_t('dashboard.table_day_off')) ?></th>
                    <th><?= h(dashboard_t('dashboard.table_from')) ?></th>
                    <th><?= h(dashboard_t('dashboard.table_to')) ?></th>
                    <th><?= h(dashboard_t('dashboard.table_break_from')) ?></th>
                    <th><?= h(dashboard_t('dashboard.table_break_to')) ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($weekdays as $wd => $label): ?>
                    <?php
                      $row = $scheduleRows[$wd] ?? [
                        'time_start' => '09:00',
                        'time_end' => '18:00',
                        'break_start' => '13:00',
                        'break_end' => '14:00',
                        'is_day_off' => in_array($wd, [6, 7], true) ? 1 : 0,
                      ];

                      $timeStart = dashboard_time_hm((string)($row['time_start'] ?? ''), '09:00');
                      $timeEnd = dashboard_time_hm((string)($row['time_end'] ?? ''), '18:00');
                      $breakStart = dashboard_time_hm((string)($row['break_start'] ?? ''), '13:00');
                      $breakEnd = dashboard_time_hm((string)($row['break_end'] ?? ''), '14:00');
                      $isDayOff = ((int)($row['is_day_off'] ?? 0) === 1);
                    ?>
                    <tr>
                      <td><?= h($label) ?></td>
                      <td>
                        <label class="dash-dayoff">
                          <input type="checkbox" name="schedule[<?= (int)$wd ?>][day_off]" value="1" <?= $isDayOff ? 'checked' : '' ?>>
                          <span class="muted"><?= h(dashboard_t('dashboard.day_off')) ?></span>
                        </label>
                      </td>
                      <td>
                        <input class="input" type="time" name="schedule[<?= (int)$wd ?>][time_start]" value="<?= h($timeStart) ?>">
                      </td>
                      <td>
                        <input class="input" type="time" name="schedule[<?= (int)$wd ?>][time_end]" value="<?= h($timeEnd) ?>">
                      </td>
                      <td>
                        <input class="input" type="time" name="schedule[<?= (int)$wd ?>][break_start]" value="<?= h($breakStart) ?>">
                      </td>
                      <td>
                        <input class="input" type="time" name="schedule[<?= (int)$wd ?>][break_end]" value="<?= h($breakEnd) ?>">
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($tgPrefsAvailable): ?>
        <div class="card dash-schedule-card">
          <div class="card__head">
            <div class="card__title"><?= h(dashboard_t('dashboard.tg_title')) ?></div>
            <div class="card__hint muted"><?= h(dashboard_t('dashboard.tg_hint')) ?></div>
          </div>

          <div class="card__body">
            <input type="hidden" name="tg_notify_loaded" value="1">
            <?php if (!$tgPrefs): ?>
              <div class="muted"><?= h(dashboard_t('dashboard.tg_events_empty')) ?></div>
            <?php else: ?>
              <div style="display:grid; gap:10px;">
                <?php foreach ($tgPrefs as $tgEvent): ?>
                  <?php
                    $tgCode = (string)($tgEvent['event_code'] ?? '');
                    $tgTitle = (string)($tgEvent['title'] ?? $tgCode);
                    $tgDesc = (string)($tgEvent['description'] ?? '');
                    $tgGlobal = ((int)($tgEvent['global_enabled'] ?? 0) === 1);
                    $tgUserEnabled = ((int)($tgEvent['user_enabled'] ?? 0) === 1);
                  ?>
                  <label class="field" style="display:flex; align-items:flex-start; gap:8px;">
                    <input type="checkbox"
                           name="tg_notify[<?= h($tgCode) ?>]"
                           value="1"
                           <?= $tgUserEnabled ? 'checked' : '' ?>
                           <?= $tgGlobal ? '' : 'disabled' ?>>
                    <span>
                      <strong><?= h($tgTitle) ?></strong>
                      <?php if ($tgDesc !== ''): ?>
                        <span class="muted"> — <?= h($tgDesc) ?></span>
                      <?php endif; ?>
                      <?php if (!$tgGlobal): ?>
                        <span class="muted"> <?= h(dashboard_t('dashboard.tg_globally_disabled')) ?></span>
                      <?php endif; ?>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="card__foot dash-modal-foot">
      <button class="btn btn--accent" type="submit"><?= h(dashboard_t('dashboard.save')) ?></button>
      <button class="btn" type="button" data-modal-close="1"><?= h(dashboard_t('dashboard.close')) ?></button>
    </div>
  </div>
</form>
<?php
$html = (string)ob_get_clean();

json_ok([
  'title' => dashboard_t('dashboard.modal_settings_title'),
  'html' => $html,
]);
