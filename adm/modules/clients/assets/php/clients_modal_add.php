<?php
/**
 * FILE: /adm/modules/clients/assets/php/clients_modal_add.php
 * ROLE: modal_add - контент модалки "Добавить клиента"
 * CONNECTIONS:
 *  - csrf_token()
 *  - module_allowed_roles(), acl_guard()
 *  - json_ok()
 *  - url(), h()
 *  - clients_t(), clients_status_label()
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/clients_i18n.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('clients'));

/**
 * $csrf - CSRF токен
 */
$csrf = csrf_token();

/**
 * $returnUrl - куда вернуться после сохранения
 */
$returnUrl = (string)($_GET['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));

/**
 * $html - тело модалки
 */
ob_start();
?>

<form method="post" enctype="multipart/form-data" action="<?= h(url('/adm/index.php?m=clients&do=add')) ?>" class="form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <?php if ($returnUrl !== ''): ?>
    <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
  <?php endif; ?>

  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__head">
      <div class="card__title"><?= h(clients_t('clients.modal_add_card_title')) ?></div>
      <div class="card__hint muted"><?= h(clients_t('clients.modal_add_hint')) ?></div>
    </div>

    <div class="card__body" style="display:grid; gap:12px;">

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_first_name_required')) ?></span>
        <input class="select" style="height:40px;" name="first_name" value="" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_last_name')) ?></span>
        <input class="select" style="height:40px;" name="last_name" value="">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_middle_name')) ?></span>
        <input class="select" style="height:40px;" name="middle_name" value="">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_phone_login_required')) ?></span>
        <input class="select" style="height:40px;" name="phone" value="" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_email')) ?></span>
        <input class="select" style="height:40px;" name="email" value="">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_inn')) ?></span>
        <input class="select" style="height:40px;" name="inn" value="">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_birth_date')) ?></span>
        <input class="select" style="height:40px;" type="date" name="birth_date" value="">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_photo')) ?></span>
        <input class="select" style="height:40px;" type="file" name="photo">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_status')) ?></span>
        <select class="select" name="status">
          <option value="active"><?= h(clients_status_label('active')) ?></option>
          <option value="blocked"><?= h(clients_status_label('blocked')) ?></option>
        </select>
      </label>

      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <button class="btn" type="submit"><?= h(clients_t('clients.btn_save')) ?></button>
        <button class="btn btn--ghost" type="button" data-modal-close="1"><?= h(clients_t('clients.btn_cancel')) ?></button>
      </div>

    </div>
  </div>
</form>

<?php
$html = (string)ob_get_clean();

json_ok([
  'title' => clients_t('clients.modal_add_title'),
  'html'  => $html,
]);
