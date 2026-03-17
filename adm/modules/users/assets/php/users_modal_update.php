<?php
/**
 * FILE: /adm/modules/users/assets/php/users_modal_update.php
 * ROLE: modal_update - контент модалки "Редактировать пользователя"
 * CONNECTIONS:
 *  - db()
 *  - csrf_token()
 *  - auth_user_role()
 *  - module_allowed_roles(), acl_guard()
 *  - json_ok()
 *  - users_t(), users_status_label()
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/users_i18n.php';

/**
 * ACL: доступ к модулю users
 */
acl_guard(module_allowed_roles('users'));

/**
 * $pdo - соединение с БД
 */
$pdo = db();

/**
 * $csrf - CSRF токен
 */
$csrf = csrf_token();

/**
 * $role - роль актёра
 */
$role = (string)auth_user_role();

/**
 * $isAdmin - админ
 */
$isAdmin = ($role === 'admin');

/**
 * $id - редактируемый пользователь
 */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_404(users_t('users.error_bad_id'));
}

/**
 * $st - загрузка пользователя
 */
$st = $pdo->prepare("SELECT id, email, phone, pass_hash, name, status, ui_theme FROM users WHERE id = ? LIMIT 1");
$st->execute([$id]);

/**
 * $user - данные пользователя
 */
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!$user) {
  http_404(users_t('users.error_user_not_found'));
}

/**
 * $userRoleIds - текущие роли пользователя
 */
$st = $pdo->prepare("SELECT role_id FROM user_roles WHERE user_id = ?");
$st->execute([$id]);

$userRoleIds = [];
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
  $userRoleIds[] = (int)($row['role_id'] ?? 0);
}

/**
 * $roles - справочник ролей (только admin)
 */
$roles = [];
if ($isAdmin) {
  $rs = $pdo->query("SELECT id, code, name FROM roles ORDER BY sort ASC, id ASC");
  $roles = $rs ? $rs->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * $specialistRoleId - id роли specialist
 */
$specialistRoleId = 0;
try {
  $st = $pdo->prepare("SELECT id FROM roles WHERE code='specialist' LIMIT 1");
  $st->execute();
  $specialistRoleId = (int)($st->fetchColumn() ?: 0);
} catch (Throwable $e) {
  $specialistRoleId = 0;
}

/**
 * $isUserSpecialist - пользователь специалист
 */
$isUserSpecialist = ($specialistRoleId > 0 && in_array($specialistRoleId, $userRoleIds, true));

/**
 * $scheduleMap - расписание специалиста
 */
$scheduleMap = [];
if ($isUserSpecialist) {
  $st = $pdo->prepare("
    SELECT weekday, time_start, time_end, break_start, break_end, is_day_off
    FROM specialist_schedule
    WHERE user_id = :uid
  ");
  $st->execute([':uid' => $id]);
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $wd = (int)($row['weekday'] ?? 0);
    if ($wd < 1 || $wd > 7) continue;
    $scheduleMap[$wd] = [
      'time_start' => (string)($row['time_start'] ?? '09:00'),
      'time_end' => (string)($row['time_end'] ?? '18:00'),
      'break_start' => (string)($row['break_start'] ?? '13:00'),
      'break_end' => (string)($row['break_end'] ?? '14:00'),
      'is_day_off' => (int)($row['is_day_off'] ?? 0),
    ];
  }
}

/**
 * Значения
 */
$name = (string)($user['name'] ?? '');
$phone = (string)($user['phone'] ?? '');
$email = (string)($user['email'] ?? '');
$status = (string)($user['status'] ?? 'active');
$uiTheme = (string)($user['ui_theme'] ?? 'dark');

/**
 * $html - тело модалки
 */
ob_start();
?>

<form method="post" action="<?= h(url('/adm/index.php?m=users&do=update')) ?>" class="form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="id" value="<?= (int)$id ?>">

  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__head">
      <div class="card__title"><?= h(users_t('users.modal_user_title', ['id' => $id])) ?></div>
      <div class="card__hint muted"><?= h($isAdmin ? users_t('users.modal_update_hint_admin') : users_t('users.modal_update_hint_manager')) ?></div>
    </div>

    <div class="card__body" style="display:grid; gap:12px;">

      <label class="field field--stack">
        <span class="field__label"><?= h(users_t('users.field_name')) ?></span>
        <input class="select" style="height:40px;" name="name" value="<?= h($name) ?>" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(users_t('users.field_phone')) ?></span>
        <input class="select" style="height:40px;" name="phone" value="<?= h($phone) ?>" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(users_t('users.field_email')) ?></span>
        <input class="select" style="height:40px;" name="email" type="email" value="<?= h($email) ?>" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(users_t('users.field_status')) ?></span>
        <select class="select" name="status">
          <option value="active" <?= $status === 'active' ? 'selected' : '' ?>><?= h(users_status_label('active')) ?></option>
          <option value="blocked" <?= $status === 'blocked' ? 'selected' : '' ?>><?= h(users_status_label('blocked')) ?></option>
        </select>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(users_t('users.field_ui_theme')) ?></span>
        <select class="select" name="ui_theme">
          <option value="dark" <?= $uiTheme === 'dark' ? 'selected' : '' ?>>dark</option>
          <option value="light" <?= $uiTheme === 'light' ? 'selected' : '' ?>>light</option>
          <option value="color" <?= $uiTheme === 'color' ? 'selected' : '' ?>>color</option>
        </select>
      </label>

      <?php if ($isAdmin): ?>
        <div class="field field--stack">
          <span class="field__label"><?= h(users_t('users.field_roles')) ?></span>
          <div class="card" style="padding:10px; box-shadow:none; border-color: var(--border-soft);">
            <?php foreach ($roles as $r): ?>
              <?php
                $rid = (int)($r['id'] ?? 0);
                $rcode = (string)($r['code'] ?? '');
                $rname = (string)($r['name'] ?? $rcode);
                if ($rid <= 0 || $rcode === '') continue;

                $checked = in_array($rid, $userRoleIds, true);
              ?>
              <label style="display:flex; gap:10px; align-items:center; padding:6px 2px;">
                <input type="checkbox" name="role_ids[]" value="<?= (int)$rid ?>" <?= $checked ? 'checked' : '' ?>>
                <span><?= h($rname) ?> <span class="muted" style="font-size:12px;">(<?= h($rcode) ?>)</span></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php else: ?>
        <!-- manager: принудительно держим user -->
        <input type="hidden" name="role_force" value="user">
      <?php endif; ?>

      <?php if ($isUserSpecialist): ?>
        <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
          <div class="card__head">
            <div class="card__title"><?= h(users_t('users.work_mode_title')) ?></div>
            <div class="card__hint muted"><?= h(users_t('users.work_mode_hint')) ?></div>
          </div>
          <div class="card__body">
            <div class="tablewrap">
              <table class="table">
                <thead>
                  <tr>
                    <th style="width:160px;"><?= h(users_t('users.table_day')) ?></th>
                    <th style="width:120px;"><?= h(users_t('users.table_day_off')) ?></th>
                    <th style="width:140px;"><?= h(users_t('users.table_from')) ?></th>
                    <th style="width:140px;"><?= h(users_t('users.table_to')) ?></th>
                    <th style="width:140px;"><?= h(users_t('users.table_break_from')) ?></th>
                    <th style="width:140px;"><?= h(users_t('users.table_break_to')) ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                    $weekdays = [
                      1 => users_t('users.weekday_1'),
                      2 => users_t('users.weekday_2'),
                      3 => users_t('users.weekday_3'),
                      4 => users_t('users.weekday_4'),
                      5 => users_t('users.weekday_5'),
                      6 => users_t('users.weekday_6'),
                      7 => users_t('users.weekday_7'),
                    ];
                  ?>
                  <?php foreach ($weekdays as $wd => $wlabel): ?>
                    <?php
                      $row = $scheduleMap[$wd] ?? [
                        'time_start' => '09:00',
                        'time_end' => '18:00',
                        'break_start' => '13:00',
                        'break_end' => '14:00',
                        'is_day_off' => 0,
                      ];
                      $timeStart = (string)$row['time_start'];
                      $timeEnd = (string)$row['time_end'];
                      $breakStart = (string)$row['break_start'];
                      $breakEnd = (string)$row['break_end'];
                      $isDayOff = ((int)$row['is_day_off'] === 1);
                    ?>
                    <tr>
                      <td><?= h($wlabel) ?></td>
                      <td>
                        <label style="display:flex; align-items:center; gap:6px;">
                          <input type="checkbox" name="schedule[<?= (int)$wd ?>][day_off]" value="1" <?= $isDayOff ? 'checked' : '' ?>>
                          <span class="muted" style="font-size:12px;"><?= h(users_t('users.day_off')) ?></span>
                        </label>
                      </td>
                      <td>
                        <input class="select" type="time" name="schedule[<?= (int)$wd ?>][time_start]" value="<?= h($timeStart) ?>">
                      </td>
                      <td>
                        <input class="select" type="time" name="schedule[<?= (int)$wd ?>][time_end]" value="<?= h($timeEnd) ?>">
                      </td>
                      <td>
                        <input class="select" type="time" name="schedule[<?= (int)$wd ?>][break_start]" value="<?= h($breakStart) ?>">
                      </td>
                      <td>
                        <input class="select" type="time" name="schedule[<?= (int)$wd ?>][break_end]" value="<?= h($breakEnd) ?>">
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <button class="btn" type="submit"><?= h(users_t('users.btn_save')) ?></button>
        <button class="btn btn--ghost" type="button" data-modal-close="1"><?= h(users_t('users.btn_close')) ?></button>
      </div>

    </div>
  </div>
</form>

<?php
$html = (string)ob_get_clean();

json_ok([
  'title' => users_t('users.modal_update_title'),
  'html'  => $html,
]);
