<?php
/**
 * FILE: /adm/modules/services/assets/php/services_modal_category_add.php
 * ROLE: modal_category_add - форма создания категории
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/services_lib.php';
require_once __DIR__ . '/services_i18n.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('services'));

/**
 * $uid - пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);

/**
 * $roles - роли
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

if (!services_is_admin($roles) && !services_is_manager($roles)) {
  json_err(services_t('services.error_forbidden'), 403);
}

/**
 * $csrf - токен
 */
$csrf = csrf_token();

ob_start();
?>
<form method="post" action="<?= h(url('/adm/index.php?m=services&do=category_add')) ?>">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__body" style="display:grid; gap:12px;">
      <input class="select" style="height:40px;" name="name" placeholder="<?= h(services_t('services.placeholder_category_name')) ?>" required>
      <button class="btn btn--accent" type="submit"><?= h(services_t('services.btn_create')) ?></button>
    </div>
  </div>
</form>
<?php
$html = (string)ob_get_clean();

json_ok([
  'title' => services_t('services.modal_category_add_title'),
  'html'  => $html,
]);