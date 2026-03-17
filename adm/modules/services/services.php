<?php
/**
 * FILE: /adm/modules/services/services.php
 * ROLE: VIEW - категории и услуги (UI only)
 * CONNECTIONS:
 *  - db()
 *  - csrf_token()
 *  - module_allowed_roles(), acl_guard()
 *  - url(), h()
 *  - services_t()
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/services_lib.php';
require_once __DIR__ . '/assets/php/services_i18n.php';

/**
 * ACL: доступ к модулю services
 */
acl_guard(module_allowed_roles('services'));

/**
 * $pdo - БД
 */
$pdo = db();

/**
 * $csrf - CSRF токен
 */
$csrf = csrf_token();

/**
 * $uid - пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);

/**
 * $roles - роли пользователя
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

/**
 * $isAdmin - админ
 */
$isAdmin = services_is_admin($roles);

/**
 * $isManager - менеджер
 */
$isManager = services_is_manager($roles);

/**
 * $settings - настройки (use_specialists)
 */
$settings = services_settings_get($pdo);

/**
 * $useSpecialists - режим специалистов
 */
$useSpecialists = ((int)($settings['use_specialists'] ?? 0) === 1);

/**
 * $categories - список категорий
 */
$stCat = $pdo->query("
  SELECT
    sp.id,
    sp.name,
    sp.status,
    COUNT(DISTINCT ss.service_id) AS service_count,
    GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ' ') AS service_names
  FROM " . SERVICES_SPECIALTIES_TABLE . " sp
  LEFT JOIN " . SERVICES_SPECIALTY_SERVICES_TABLE . " ss ON ss.specialty_id = sp.id
  LEFT JOIN " . SERVICES_TABLE . " s ON s.id = ss.service_id AND s.status = 'active'
  WHERE sp.status = 'active'
  GROUP BY sp.id
  ORDER BY sp.name ASC, sp.id DESC
");
$categories = $stCat ? $stCat->fetchAll(PDO::FETCH_ASSOC) : [];

/**
 * $services - список услуг
 */
$stSrv = $pdo->query("
  SELECT
    s.id,
    s.name,
    s.price,
    s.duration_min,
    s.status,
    GROUP_CONCAT(DISTINCT sp.id ORDER BY sp.id SEPARATOR ',') AS category_ids,
    GROUP_CONCAT(DISTINCT sp.name ORDER BY sp.name SEPARATOR ', ') AS category_names,
    COUNT(DISTINCT us.user_id) AS specialist_count
  FROM " . SERVICES_TABLE . " s
  LEFT JOIN " . SERVICES_SPECIALTY_SERVICES_TABLE . " ss ON ss.service_id = s.id
  LEFT JOIN " . SERVICES_SPECIALTIES_TABLE . " sp ON sp.id = ss.specialty_id
  LEFT JOIN " . SERVICES_USER_SERVICES_TABLE . " us ON us.service_id = s.id
  WHERE s.status = 'active'
  GROUP BY s.id
  ORDER BY s.name ASC, s.id DESC
");
$services = $stSrv ? $stSrv->fetchAll(PDO::FETCH_ASSOC) : [];

/**
 * $suggest - подсказки для поиска (категории + услуги)
 */
$suggest = [];
$seen = [];

/**
 * $lower - функция приведения к lower (mb, если доступно)
 */
$lower = function (string $val): string {
  return function_exists('mb_strtolower') ? mb_strtolower($val) : strtolower($val);
};

foreach ($categories as $c) {
  $name = trim((string)($c['name'] ?? ''));
  if ($name === '') continue;
  $key = 'category:' . $lower($name);
  if (isset($seen[$key])) continue;
  $seen[$key] = true;
  $suggest[] = ['type' => 'category', 'name' => $name];
}

foreach ($services as $s) {
  $name = trim((string)($s['name'] ?? ''));
  if ($name === '') continue;
  $key = 'service:' . $lower($name);
  if (isset($seen[$key])) continue;
  $seen[$key] = true;
  $suggest[] = [
    'type' => 'service',
    'name' => $name,
    'category' => (string)($s['category_names'] ?? ''),
  ];
}

$suggestJson = json_encode($suggest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($suggestJson === false) $suggestJson = '[]';

$servicesJsI18n = [
  'modal_title' => services_t('services.modal_default_title'),
  'empty_services' => services_t('services.empty_services'),
  'empty_services_in_category' => services_t('services.empty_services_in_category'),
  'suggest_type_category' => services_t('services.suggest_type_category'),
  'suggest_type_service' => services_t('services.suggest_type_service'),
];
?>

<link rel="stylesheet" href="<?= h(url('/adm/modules/services/assets/css/main.css')) ?>">

<h1><?= h(services_t('services.page_title')) ?></h1>

<div class="card services-toolbar" style="box-shadow:none; border-color: var(--border-soft); margin-bottom:12px;">
  <div class="card__body services-toolbar__body">
    <div class="services-search" data-services-search-wrap="1">
      <input class="input services-search__input"
             type="text"
             placeholder="<?= h(services_t('services.search_placeholder')) ?>"
             data-services-search="1"
             data-services-suggest="<?= h($suggestJson) ?>">
      <div class="services-search__list scroll-thin" data-services-search-list="1"></div>
    </div>

    <div class="services-toolbar__actions">
      <?php if ($isAdmin || $isManager): ?>
        <button class="iconbtn iconbtn--sm"
                type="button"
                data-services-reset-filter="1"
                aria-label="<?= h(services_t('services.action_reset_filter')) ?>"
                title="<?= h(services_t('services.action_reset_filter')) ?>">
          <i class="bi bi-x-circle"></i>
        </button>
        <button class="iconbtn iconbtn--sm"
                type="button"
                data-services-open-modal="1"
                data-services-modal="<?= h(url('/adm/index.php?m=services&do=modal_category_add')) ?>"
                aria-label="<?= h(services_t('services.action_add_category')) ?>"
                title="<?= h(services_t('services.action_add_category')) ?>">
          <i class="bi bi-folder-plus"></i>
        </button>
        <button class="iconbtn iconbtn--sm"
                type="button"
                data-services-open-modal="1"
                data-services-modal="<?= h(url('/adm/index.php?m=services&do=modal_service_add')) ?>"
                aria-label="<?= h(services_t('services.action_add_service')) ?>"
                title="<?= h(services_t('services.action_add_service')) ?>">
          <i class="bi bi-plus-lg"></i>
        </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card-grid-2 services-grid">
  <div class="card">
    <div class="card__head">
      <div class="card__title"><?= h(services_t('services.section_categories_title')) ?></div>
      <div class="card__hint muted"><?= h(services_t('services.section_categories_hint')) ?></div>
    </div>
    <div class="card__body">
      <div class="tablewrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:44px"></th>
              <th><?= h(services_t('services.col_category')) ?></th>
              <th style="width:110px"><?= h(services_t('services.col_services_count')) ?></th>
              <th class="t-right" style="width:140px"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($categories as $c): ?>
            <?php
              $id = (int)($c['id'] ?? 0);
              $name = (string)($c['name'] ?? '');
              $serviceCount = (int)($c['service_count'] ?? 0);
              $serviceNames = (string)($c['service_names'] ?? '');
              $search = trim($name . ' ' . $serviceNames);
            ?>
            <tr class="services-row"
                data-services-search-item="1"
                data-services-type="category"
                data-category-id="<?= (int)$id ?>"
                data-search="<?= h($search) ?>">
              <td><i class="bi bi-folder"></i></td>
              <td><strong><?= h($name !== '' ? $name : services_t('services.dash')) ?></strong></td>
              <td><?= (int)$serviceCount ?></td>
              <td class="t-right">
                <div class="table__actions">
                  <button class="iconbtn iconbtn--sm"
                          type="button"
                          data-services-open-modal="1"
                          data-services-modal="<?= h(url('/adm/index.php?m=services&do=modal_category_update&id=' . $id)) ?>"
                          aria-label="<?= h(services_t('services.action_edit_category')) ?>"
                          title="<?= h(services_t('services.action_edit_category')) ?>">
                    <i class="bi bi-pencil"></i>
                  </button>

                  <form method="post"
                        action="<?= h(url('/adm/index.php?m=services&do=category_delete')) ?>"
                        class="table__actionform"
                        onsubmit="return confirm('<?= h(services_t('services.confirm_delete_category_with_services')) ?>');">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <button class="iconbtn iconbtn--sm"
                            type="submit"
                            aria-label="<?= h(services_t('services.action_delete')) ?>"
                            title="<?= h(services_t('services.action_delete')) ?>">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <tr data-services-empty="1" style="<?= $categories ? 'display:none;' : '' ?>">
            <td colspan="4" class="muted" style="padding:14px;"><?= h(services_t('services.empty_categories')) ?></td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card__head">
      <div class="card__title"><?= h(services_t('services.section_services_title')) ?></div>
      <div class="card__hint muted"><?= h(services_t('services.section_services_hint')) ?></div>
    </div>
    <div class="card__body">
      <div class="tablewrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:44px"></th>
              <th><?= h(services_t('services.col_service')) ?></th>
              <th style="width:180px"><?= h(services_t('services.col_category')) ?></th>
              <th style="width:110px"><?= h(services_t('services.col_price')) ?></th>
              <th style="width:120px"><?= h(services_t('services.col_duration')) ?></th>
              <?php if ($useSpecialists): ?>
                <th style="width:110px"><?= h(services_t('services.col_specialists')) ?></th>
              <?php endif; ?>
              <th class="t-right" style="width:140px"></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($services as $s): ?>
            <?php
              $id = (int)($s['id'] ?? 0);
              $name = (string)($s['name'] ?? '');
              $price = (int)($s['price'] ?? 0);
              $duration = (int)($s['duration_min'] ?? 0);
              $catNames = (string)($s['category_names'] ?? '');
              $catIdStr = (string)($s['category_ids'] ?? '');
              $catId = (int)($catIdStr !== '' ? $catIdStr : 0);
              $specCount = (int)($s['specialist_count'] ?? 0);
              $search = trim($name . ' ' . $catNames);
            ?>
            <tr class="services-row"
                data-services-search-item="1"
                data-services-type="service"
                data-category-id="<?= (int)$catId ?>"
                data-search="<?= h($search) ?>">
              <td><i class="bi bi-box"></i></td>
              <td><strong><?= h($name !== '' ? $name : services_t('services.dash')) ?></strong></td>
              <td><?= h($catNames !== '' ? $catNames : services_t('services.dash')) ?></td>
              <td><?= $price > 0 ? (int)$price : services_t('services.dash') ?></td>
              <td><?= $duration > 0 ? ((int)$duration . ' ' . services_t('services.duration_suffix_min')) : services_t('services.dash') ?></td>
              <?php if ($useSpecialists): ?>
                <td><?= (int)$specCount ?></td>
              <?php endif; ?>
              <td class="t-right">
                <div class="table__actions">
                  <button class="iconbtn iconbtn--sm"
                          type="button"
                          data-services-open-modal="1"
                          data-services-modal="<?= h(url('/adm/index.php?m=services&do=modal_service_update&id=' . $id)) ?>"
                          aria-label="<?= h(services_t('services.action_edit_service')) ?>"
                          title="<?= h(services_t('services.action_edit_service')) ?>">
                    <i class="bi bi-pencil"></i>
                  </button>

                  <form method="post"
                        action="<?= h(url('/adm/index.php?m=services&do=service_delete')) ?>"
                        class="table__actionform"
                        onsubmit="return confirm('<?= h(services_t('services.confirm_delete_service_forever')) ?>');">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <button class="iconbtn iconbtn--sm"
                            type="submit"
                            aria-label="<?= h(services_t('services.action_delete')) ?>"
                            title="<?= h(services_t('services.action_delete')) ?>">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <tr data-services-empty="1" data-services-empty-type="services" style="<?= $services ? 'display:none;' : '' ?>">
            <td colspan="<?= $useSpecialists ? '7' : '6' ?>" class="muted" style="padding:14px;">
              <span data-services-empty-text="1"><?= h(services_t('services.empty_services')) ?></span>
            </td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
window.CRM_SERVICES_I18N = <?= json_encode($servicesJsI18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= h(url('/adm/modules/services/assets/js/main.js')) ?>"></script>