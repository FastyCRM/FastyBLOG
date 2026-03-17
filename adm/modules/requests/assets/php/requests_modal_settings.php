<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_modal_settings.php
 * ROLE: modal_settings — настройки модуля requests
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

if (!$isAdmin) {
  json_err('Forbidden', 403);
}

/**
 * $pdo — БД
 */
$pdo = db();

/**
 * $settings — настройки
 */
$settings = requests_settings_get($pdo);

/**
 * $useSpecialists — режим специалистов
 */
$useSpecialists = ((int)($settings['use_specialists'] ?? 0) === 1);

/**
 * $useTimeSlots — интервальный режим
 */
$useTimeSlots = ($useSpecialists && ((int)($settings['use_time_slots'] ?? 0) === 1));

/**
 * $csrf — токен
 */
$csrf = csrf_token();

ob_start();
?>
<div class="card" style="box-shadow:none; border-color: var(--border-soft);">
  <div class="card__body" style="display:flex; flex-direction:column; gap:12px;">
    <form method="post" action="<?= h(url('/adm/index.php?m=requests&do=settings_update')) ?>">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

      <label class="field" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" name="use_specialists" value="1" <?= $useSpecialists ? 'checked' : '' ?>>
        <span>Режим со специалистами</span>
      </label>

      <label class="field" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" name="use_time_slots" value="1" <?= $useTimeSlots ? 'checked' : '' ?>>
        <span>Интервалы (слоты времени)</span>
      </label>

      <div class="muted" style="font-size:12px;">
        Интервалы работают только при включённых специалистах.
      </div>

      <button class="btn btn--accent" type="submit">Сохранить</button>
    </form>
  </div>
</div>
<?php
/**
 * $html — HTML настроек
 */
$html = ob_get_clean();

json_ok([
  'title' => 'Настройки заявок',
  'html' => $html,
]);
