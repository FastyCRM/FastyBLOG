<?php
/**
 * FILE: /adm/modules/bot_adv_calendar/assets/php/bot_adv_calendar_modal_generate.php
 * ROLE: modal_generate модуля bot_adv_calendar
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/bot_adv_calendar_lib.php';

acl_guard(module_allowed_roles('bot_adv_calendar'));

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!bot_adv_calendar_is_manage_role($roles)) {
  json_err('Forbidden', 403);
}

$users = [];

try {
  $pdo = db();
  $users = bot_adv_calendar_users_attach_candidates($pdo, 500);
} catch (Throwable $e) {
  $users = [];
}

$csrf = csrf_token();

ob_start();
?>
<form method="post" action="<?= h(url('/adm/index.php?m=bot_adv_calendar&do=user_attach')) ?>" class="form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

  <div class="card" style="box-shadow:none; border-color:var(--border-soft);">
    <div class="card__head">
      <div class="card__title">Подключение пользователя к модулю</div>
      <div class="card__hint muted">Сначала подключите пользователя в CRM к bot_adv_calendar. Telegram-привязка выполняется отдельно по коду.</div>
    </div>
    <div class="card__body" style="display:grid; gap:12px;">
      <label class="field field--stack">
        <span class="field__label">Пользователь CRM</span>
        <select class="select" name="user_id" required>
          <option value="">Выберите пользователя…</option>
          <?php foreach ($users as $u): ?>
            <?php
              $id = (int)($u['id'] ?? 0);
              if ($id <= 0) continue;
              $name = trim((string)($u['name'] ?? ''));
              $status = trim((string)($u['status'] ?? ''));
              $roles = trim((string)($u['role_codes'] ?? ''));
              $label = '#' . $id;
              if ($name !== '') $label .= ' - ' . $name;
              if ($status !== '') $label .= ' [' . $status . ']';
              if ($roles !== '') $label .= ' (' . $roles . ')';
            ?>
            <option value="<?= $id ?>"><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if (!$users): ?>
        <div class="muted">Нет доступных пользователей для подключения. Все пользователи уже подключены или отсутствуют в CRM.</div>
      <?php endif; ?>
    </div>
    <div class="card__foot" style="display:flex; gap:10px; justify-content:flex-end;">
      <button class="btn btn--accent" type="submit" <?= $users ? '' : 'disabled' ?>>Подключить</button>
      <button class="btn" type="button" data-modal-close="1">Закрыть</button>
    </div>
  </div>
</form>
<?php
$html = (string)ob_get_clean();

json_ok([
  'title' => 'Подключить пользователя CRM',
  'html' => $html,
]);
