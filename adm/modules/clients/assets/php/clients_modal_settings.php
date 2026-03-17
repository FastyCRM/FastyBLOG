<?php
/**
 * FILE: /adm/modules/clients/assets/php/clients_modal_settings.php
 * ROLE: modal_settings модуля clients
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/clients_i18n.php';

acl_guard(module_allowed_roles('clients'));

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$isAdmin = in_array('admin', $roles, true);

if (!$isAdmin) {
  json_err(clients_t('clients.error_access_denied'), 403);
}

$csrf = csrf_token();

ob_start();
?>
<div class="card" style="box-shadow:none; border-color: var(--border-soft);">
  <div class="card__head">
    <div class="card__title"><?= h(clients_t('clients.modal_settings_card_title')) ?></div>
    <div class="card__hint muted"><?= h(clients_t('clients.modal_settings_hint')) ?></div>
  </div>

  <div class="card__body" style="display:grid; gap:12px;">
    <div class="muted">
      <?= h(clients_t('clients.modal_settings_text')) ?>
    </div>

    <form method="post"
          action="<?= h(url('/adm/index.php?m=clients&do=clear')) ?>"
          onsubmit="return confirm('<?= h(clients_t('clients.modal_settings_confirm')) ?>');">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="confirm_clear" value="yes">
      <button class="btn btn--danger" type="submit"><?= h(clients_t('clients.modal_settings_clear_button')) ?></button>
    </form>
  </div>
</div>
<?php
$html = (string)ob_get_clean();

json_ok([
  'title' => clients_t('clients.modal_settings_title'),
  'html' => $html,
]);
