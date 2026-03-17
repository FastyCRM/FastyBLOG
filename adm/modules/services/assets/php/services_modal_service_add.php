<?php
/**
 * FILE: /adm/modules/services/assets/php/services_modal_service_add.php
 * ROLE: modal_service_add - форма создания услуги
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
 * $pdo - БД
 */
$pdo = db();

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

ob_start();
?>
<form method="post" action="<?= h(url('/adm/index.php?m=services&do=service_add')) ?>">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__body" style="display:grid; gap:12px;">

      <label class="field field--stack">
        <span class="field__label"><?= h(services_t('services.field_service_name')) ?></span>
        <input class="select" style="height:40px;" name="name" placeholder="<?= h(services_t('services.placeholder_service_name_example')) ?>" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(services_t('services.field_price')) ?></span>
        <input class="select" style="height:40px;" name="price" type="number" min="0" step="1" placeholder="<?= h(services_t('services.placeholder_price')) ?>">
      </label>

      <?php if ($useTimeSlots): ?>
        <label class="field field--stack">
          <span class="field__label"><?= h(services_t('services.field_duration_min')) ?></span>
          <input class="select" style="height:40px;" name="duration_min" type="number" min="1" step="1" placeholder="<?= h(services_t('services.placeholder_duration_min')) ?>">
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
            <option value="<?= (int)$cid ?>"><?= h($label) ?></option>
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
                ?>
                <label class="services-check" data-services-spec-item="1" data-name="<?= h($sname) ?>">
                  <input type="checkbox" name="specialist_ids[]" value="<?= (int)$sid ?>">
                  <span><?= h($sname !== '' ? $sname : ('#' . $sid)) ?></span>
                </label>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <button class="btn btn--accent" type="submit"><?= h(services_t('services.btn_create')) ?></button>
    </div>
  </div>
</form>
<?php
$html = (string)ob_get_clean();

json_ok([
  'title' => services_t('services.modal_service_add_title'),
  'html'  => $html,
]);