<?php
/**
 * FILE: /adm/modules/calendar/assets/php/calendar_modal_manager_settings.php
 * ROLE: modal_manager_settings — блок настроек менеджерского режима
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

if (!$isAdmin && !$isManager) {
  json_err('Forbidden', 403);
}

/**
 * $pdo — БД
 */
$pdo = db();

/**
 * $userSettings — персональные настройки
 */
$userSettings = [];
try {
  $st = $pdo->prepare("SELECT manager_spec_ids FROM " . CALENDAR_USER_SETTINGS_TABLE . " WHERE user_id = :uid LIMIT 1");
  $st->execute([':uid' => $uid]);
  $userSettings = $st ? (array)$st->fetch(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
  $userSettings = [];
}

/**
 * $specialists — список специалистов
 */
$specialists = [];
$stSpec = $pdo->prepare("
  SELECT u.id, u.name
  FROM " . CALENDAR_USERS_TABLE . " u
  JOIN " . CALENDAR_USER_ROLES_TABLE . " ur ON ur.user_id = u.id
  JOIN " . CALENDAR_ROLES_TABLE . " r ON r.id = ur.role_id
  WHERE r.code = 'specialist' AND u.status = 'active'
  ORDER BY u.name ASC
");
$stSpec->execute();
$specialists = $stSpec->fetchAll(PDO::FETCH_ASSOC);

/**
 * $selectedSpecIds — выбранные специалисты
 */
$selectedSpecIds = [];
$rawSpecIds = (string)($userSettings['manager_spec_ids'] ?? '');
if ($rawSpecIds !== '') {
  foreach (explode(',', $rawSpecIds) as $sid) {
    $sid = (int)trim($sid);
    if ($sid > 0) $selectedSpecIds[] = $sid;
  }
}
$selectedSpecIds = array_values(array_unique($selectedSpecIds));
if (!$selectedSpecIds && $specialists) {
  $selectedSpecIds = array_slice(array_map('intval', array_column($specialists, 'id')), 0, 4);
}

ob_start();
?>
<div class="field field--stack">
  <span class="field__label">Специалисты (колонки)</span>
  <div class="calendar-specs" style="max-height:200px; overflow:auto; padding:6px; border:1px solid var(--border-soft); border-radius:10px;">
    <?php foreach ($specialists as $sp): ?>
      <?php
        $sid = (int)($sp['id'] ?? 0);
        $sname = (string)($sp['name'] ?? '');
        $checked = in_array($sid, $selectedSpecIds, true);
      ?>
      <label style="display:flex; gap:8px; align-items:center; margin:4px 0;">
        <input type="checkbox" name="calendar_manager_spec_ids[]" value="<?= (int)$sid ?>" <?= $checked ? 'checked' : '' ?>>
        <span><?= h($sname !== '' ? $sname : ('#' . $sid)) ?></span>
      </label>
    <?php endforeach; ?>
  </div>
  <div class="muted" style="font-size:12px; margin-top:6px;">
    Если никого не выбрать — будут показаны первые 4 специалиста.
  </div>
</div>
<?php
$html = ob_get_clean();

json_ok([
  'html' => $html,
]);
