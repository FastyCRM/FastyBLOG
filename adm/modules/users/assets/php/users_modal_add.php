<?php
/**
 * FILE: /adm/modules/users/assets/php/users_modal_add.php
 * ROLE: modal_add - контент модалки "Добавить пользователя"
 * CONNECTIONS:
 *  - db()
 *  - csrf_token()
 *  - auth_user_role()
 *  - module_allowed_roles(), acl_guard()
 *  - json_ok()
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
 * $roles - список ролей для админа
 */
$roles = [];
if ($isAdmin) {
  $st = $pdo->query("SELECT id, code, name FROM roles ORDER BY sort ASC, id ASC");
  $roles = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * $html - тело модалки
 */
ob_start();
?>

<form method="post" action="<?= h(url('/adm/index.php?m=users&do=add')) ?>" class="form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__head">
      <div class="card__title"><?= h(users_t('users.modal_add_card_title')) ?></div>
      <div class="card__hint muted"><?= h(users_t('users.modal_add_hint')) ?></div>
    </div>

    <div class="card__body" style="display:grid; gap:12px;">

      <label class="field field--stack">
        <span class="field__label"><?= h(users_t('users.field_name')) ?></span>
        <input class="select" style="height:40px;" name="name" value="" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(users_t('users.field_phone')) ?></span>
        <input class="select" style="height:40px;" name="phone" value="" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(users_t('users.field_email_for_reset')) ?></span>
        <input class="select" style="height:40px;" name="email" type="email" value="" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(users_t('users.field_status')) ?></span>
        <select class="select" name="status">
          <option value="active"><?= h(users_status_label('active')) ?></option>
          <option value="blocked"><?= h(users_status_label('blocked')) ?></option>
        </select>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(users_t('users.field_ui_theme')) ?></span>
        <select class="select" name="ui_theme">
          <option value="dark">dark</option>
          <option value="light">light</option>
          <option value="color">color</option>
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

                $checked = ($rcode === 'user');
              ?>
              <label style="display:flex; gap:10px; align-items:center; padding:6px 2px;">
                <input type="checkbox" name="role_ids[]" value="<?= (int)$rid ?>" <?= $checked ? 'checked' : '' ?>>
                <span><?= h($rname) ?> <span class="muted" style="font-size:12px;">(<?= h($rcode) ?>)</span></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php else: ?>
        <!-- manager: принудительно роль user -->
        <input type="hidden" name="role_force" value="user">
      <?php endif; ?>

      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <button class="btn" type="submit"><?= h(users_t('users.btn_save')) ?></button>
        <button class="btn btn--ghost" type="button" data-modal-close="1"><?= h(users_t('users.btn_cancel')) ?></button>
      </div>

    </div>
  </div>
</form>

<?php
$html = (string)ob_get_clean();

json_ok([
  'title' => users_t('users.modal_add_title'),
  'html'  => $html,
]);
