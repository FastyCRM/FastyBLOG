<?php
/**
 * FILE: /adm/modules/services/assets/php/services_modal_category_update.php
 * ROLE: modal_category_update - форма редактирования категории
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/services_i18n.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('services'));

/**
 * $pdo - БД
 */
$pdo = db();

/**
 * $id - категория
 */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_404(services_t('services.error_bad_id'));
}

/**
 * Загружаем категорию
 */
$st = $pdo->prepare("SELECT id, name, status FROM " . SERVICES_SPECIALTIES_TABLE . " WHERE id = ? LIMIT 1");
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  http_404(services_t('services.error_category_not_found'));
}

/**
 * $csrf - токен
 */
$csrf = csrf_token();

$name = (string)($row['name'] ?? '');

ob_start();
?>
<form method="post" action="<?= h(url('/adm/index.php?m=services&do=category_update')) ?>">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="id" value="<?= (int)$id ?>">

  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__head">
      <div class="card__title"><?= h(services_t('services.modal_category_update_card_title', ['id' => $id])) ?></div>
      <div class="card__hint muted"><?= h(services_t('services.modal_category_update_hint')) ?></div>
    </div>
    <div class="card__body" style="display:grid; gap:12px;">
      <input class="select" style="height:40px;" name="name" value="<?= h($name) ?>" placeholder="<?= h(services_t('services.placeholder_category_name')) ?>" required>
      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <button class="btn" type="submit"><?= h(services_t('services.btn_save')) ?></button>
        <button class="btn" type="button" data-modal-close="1"><?= h(services_t('services.btn_close')) ?></button>
      </div>
    </div>
  </div>
</form>
<?php
$html = (string)ob_get_clean();

json_ok([
  'title' => services_t('services.modal_category_update_title'),
  'html'  => $html,
]);