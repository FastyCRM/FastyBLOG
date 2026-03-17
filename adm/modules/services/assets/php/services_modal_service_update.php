<?php
/**
 * FILE: /adm/modules/services/assets/php/services_modal_service_update.php
 * ROLE: modal_service_update - форма редактирования услуги
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
 * $pdo - БД
 */
$pdo = db();

/**
 * $id - услуга
 */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_404(services_t('services.error_bad_id'));
}

/**
 * Загружаем услугу
 */
$st = $pdo->prepare("SELECT id, name, price, duration_min, status FROM " . SERVICES_TABLE . " WHERE id = ? LIMIT 1");
$st->execute([$id]);
$service = $st->fetch(PDO::FETCH_ASSOC);

if (!$service) {
  http_404(services_t('services.error_service_not_found'));
}

/**
 * Текущая категория услуги (одна)
 */
$st = $pdo->prepare("SELECT specialty_id FROM " . SERVICES_SPECIALTY_SERVICES_TABLE . " WHERE service_id = ? LIMIT 1");
$st->execute([$id]);
$currentCategoryId = (int)($st->fetchColumn() ?: 0);

/**
 * Текущие специалисты услуги
 */
$st = $pdo->prepare("SELECT user_id FROM " . SERVICES_USER_SERVICES_TABLE . " WHERE service_id = ?");
$st->execute([$id]);
$currentSpecialists = [];
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
  $currentSpecialists[] = (int)($row['user_id'] ?? 0);
}

/**
 * $settings - настройки
 */
$settings = services_settings_get($pdo);

/**
 * $useSpecialists - режим специалистов
 */
$useSpecialists = ((int)($settings['use_specialists'] ?? 0) === 1);
/**
 * $useTimeSlots - интервальный режим
 */
$useTimeSlots = ($useSpecialists && ((int)($settings['use_time_slots'] ?? 0) === 1));

/**
 * $categories - список категорий
 */
$st = $pdo->query("
  SELECT id, name, status
  FROM " . SERVICES_SPECIALTIES_TABLE . "
  WHERE status = 'active'
  ORDER BY name ASC, id DESC
");
$categories = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

/**
 * $specialists - список специалистов
 */
$specialists = [];
if ($useSpecialists) {
  $st = $pdo->prepare("
    SELECT u.id, u.name
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'specialist' AND u.status = 'active'
    ORDER BY u.name ASC
  ");
  $st->execute();
  $specialists = $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * $csrf - токен
 */
$csrf = csrf_token();

$name = (string)($service['name'] ?? '');
$price = (int)($service['price'] ?? 0);
$duration = (int)($service['duration_min'] ?? 0);

ob_start();
?>
<form method="post" action="<?= h(url('/adm/index.php?m=services&do=service_update')) ?>">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="id" value="<?= (int)$id ?>">

  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__head">
      <div class="card__title"><?= h(services_t('services.modal_service_update_card_title', ['id' => $id])) ?></div>
      <div class="card__hint muted"><?= h(services_t('services.modal_service_update_hint')) ?></div>
    </div>
    <div class="card__body" style="display:grid; gap:12px;">

      <label class="field field--stack">
        <span class="field__label"><?= h(services_t('services.field_service_name')) ?></span>
        <input class="select" style="height:40px;" name="name" value="<?= h($name) ?>" placeholder="<?= h(services_t('services.placeholder_service_name')) ?>" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(services_t('services.field_price')) ?></span>
        <input class="select" style="height:40px;" name="price" type="number" min="0" step="1" placeholder="<?= h(services_t('services.placeholder_price')) ?>"
               value="<?= $price > 0 ? (int)$price : '' ?>">
      </label>

      <?php if ($useTimeSlots): ?>
        <label class="field field--stack">
          <span class="field__label"><?= h(services_t('services.field_duration_min')) ?></span>
          <input class="select" style="height:40px;" name="duration_min" type="number" min="1" step="1" placeholder="<?= h(services_t('services.placeholder_duration_min')) ?>"
                 value="<?= $duration > 0 ? (int)$duration : '' ?>">
        </label>
      <?php endif; ?>

      <label class="field field--stack">
        <span class="field__label"><?= h(services_t('services.field_category')) ?></span>
        <select class="select" name="category_id" data-ui-select="1">
          <option value=""><?= h(services_t('services.option_uncategorized')) ?></option>
          <?php foreach ($categories as $c): ?>
            <?php
              $cid = (int)($c['id'] ?? 0);
              $cname = (string)($c['name'] ?? '');
              $label = $cname !== '' ? $cname : services_t('services.dash');
            ?>
            <option value="<?= (int)$cid ?>" <?= $cid === $currentCategoryId ? 'selected' : '' ?>>
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <?php if ($useSpecialists): ?>
        <div class="field field--stack" data-services-spec-wrap="1">
          <span class="field__label"><?= h(services_t('services.field_specialists')) ?></span>
          <input class="select services-spec-search"
                 type="text"
                 placeholder="<?= h(services_t('services.placeholder_specialist_search')) ?>"
                 data-services-spec-search="1">
          <div class="services-checklist scroll-thin" data-services-spec-list="1">
            <?php if (!$specialists): ?>
              <div class="muted" style="padding:6px 4px;"><?= h(services_t('services.no_specialists')) ?></div>
            <?php else: ?>
              <?php foreach ($specialists as $sp): ?>
                <?php
                  $sid = (int)($sp['id'] ?? 0);
                  $sname = (string)($sp['name'] ?? '');
                  $checked = in_array($sid, $currentSpecialists, true);
                ?>
                <label class="services-check" data-services-spec-item="1" data-name="<?= h($sname) ?>">
                  <input type="checkbox" name="specialist_ids[]" value="<?= (int)$sid ?>" <?= $checked ? 'checked' : '' ?>>
                  <span><?= h($sname !== '' ? $sname : ('#' . $sid)) ?></span>
                </label>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

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
  'title' => services_t('services.modal_service_update_title'),
  'html'  => $html,
]);