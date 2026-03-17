<?php
/**
 * FILE: /adm/modules/clients/assets/php/clients_modal_update.php
 * ROLE: modal_update - контент модалки "Редактировать клиента"
 * CONNECTIONS:
 *  - db()
 *  - csrf_token()
 *  - module_allowed_roles(), acl_guard()
 *  - json_ok(), json_err()
 *  - url(), h()
 *  - clients_t(), clients_status_label()
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/clients_i18n.php';

acl_guard(module_allowed_roles('clients'));

$pdo  = db();
$csrf = csrf_token();
/**
 * $returnUrl - куда вернуться после сохранения
 */
$returnUrl = (string)($_GET['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));

/**
 * $id - id клиента
 */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  json_err(clients_t('clients.error_bad_id'), 400);
}

/**
 * $st - читаем клиента
 */
$st = $pdo->prepare("
  SELECT id, first_name, last_name, middle_name, phone, email, status, inn, photo_path, birth_date
  FROM " . CLIENTS_TABLE . "
  WHERE id = ?
  LIMIT 1
");
$st->execute([$id]);

/**
 * $c - клиент
 */
$c = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
if (!$c) {
  json_err(clients_t('clients.error_client_not_found'), 404);
}

/**
 * Поля
 */
$firstName  = (string)($c['first_name'] ?? '');
$lastName   = (string)($c['last_name'] ?? '');
$middleName = (string)($c['middle_name'] ?? '');
$phone      = (string)($c['phone'] ?? '');
$email      = (string)($c['email'] ?? '');
$status     = (string)($c['status'] ?? 'active');
$inn        = (string)($c['inn'] ?? '');
$photoPath  = (string)($c['photo_path'] ?? '');
$birthDate  = (string)($c['birth_date'] ?? '');

ob_start();
?>

<form method="post" enctype="multipart/form-data" action="<?= h(url('/adm/index.php?m=clients&do=update')) ?>" class="form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="id" value="<?= (int)$id ?>">
  <?php if ($returnUrl !== ''): ?>
    <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
  <?php endif; ?>

  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__head">
      <div class="card__title"><?= h(clients_t('clients.modal_update_card_title', ['id' => $id])) ?></div>
      <div class="card__hint muted"><?= h(clients_t('clients.modal_update_hint')) ?></div>
    </div>

    <div class="card__body" style="display:grid; gap:12px;">

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_first_name_required')) ?></span>
        <input class="select" style="height:40px;" name="first_name" value="<?= h($firstName) ?>" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_last_name')) ?></span>
        <input class="select" style="height:40px;" name="last_name" value="<?= h($lastName) ?>">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_middle_name')) ?></span>
        <input class="select" style="height:40px;" name="middle_name" value="<?= h($middleName) ?>">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_phone_login_required')) ?></span>
        <input class="select" style="height:40px;" name="phone" value="<?= h($phone) ?>" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_email')) ?></span>
        <input class="select" style="height:40px;" name="email" value="<?= h($email) ?>">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_inn')) ?></span>
        <input class="select" style="height:40px;" name="inn" value="<?= h($inn) ?>">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_birth_date')) ?></span>
        <input class="select" style="height:40px;" type="date" name="birth_date" value="<?= h($birthDate) ?>">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_photo')) ?></span>
        <input class="select" style="height:40px;" type="file" name="photo">
      </label>
      <?php if ($photoPath !== ''): ?>
        <div class="muted" style="font-size:12px;"><?= h(clients_t('clients.photo_uploaded')) ?></div>
      <?php endif; ?>

      <label class="field field--stack">
        <span class="field__label"><?= h(clients_t('clients.field_status')) ?></span>
        <select class="select" name="status">
          <option value="active" <?= $status === 'active' ? 'selected' : '' ?>><?= h(clients_status_label('active')) ?></option>
          <option value="blocked" <?= $status === 'blocked' ? 'selected' : '' ?>><?= h(clients_status_label('blocked')) ?></option>
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
  'title' => clients_t('clients.modal_update_title'),
  'html'  => $html,
]);
