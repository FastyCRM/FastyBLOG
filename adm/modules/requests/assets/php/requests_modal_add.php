<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_modal_add.php
 * ROLE: modal_add — форма создания заявки
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/requests_lib.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('requests'));

/**
 * $uid — пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);

/**
 * $roles — роли
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

/**
 * $isAdmin — админ
 */
$isAdmin = requests_is_admin($roles);

/**
 * $isManager — менеджер
 */
$isManager = requests_is_manager($roles);

if (!$isAdmin && !$isManager) {
  json_err('Forbidden', 403);
}

/**
 * $pdo — БД
 */
$pdo = db();

/**
 * $settings — настройки
 */
$settings = requests_settings_get($pdo);

/**
 * $useSpecialists — режим специалистов
 */
$useSpecialists = ((int)($settings['use_specialists'] ?? 0) === 1);
/**
 * $useTimeSlots — интервальный режим (только при специалистах)
 */
$useTimeSlots = ($useSpecialists && ((int)($settings['use_time_slots'] ?? 0) === 1));
/**
 * $slotsApi — API слотов календаря
 */
$slotsApi = url('/core/internal_api.php?m=calendar&do=api_slots_day');
$clientsLookupApi = url('/adm/index.php?m=clients&do=api_search');

/**
 * $services — список услуг
 */
$services = [];

/**
 * $specialists — список специалистов
 */
$specialists = [];
/**
 * $specMap — карта специалистов по услугам
 */
$specMap = [];
/**
 * $specMapJson — JSON карты специалистов
 */
$specMapJson = '{}';

if ($useSpecialists) {
  /**
   * $st — запрос услуг
   */
  $st = $pdo->query("SELECT id, name, duration_min FROM " . REQUESTS_SERVICES_TABLE . " WHERE status='active' ORDER BY name ASC");
  $services = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

  /**
   * $st2 — запрос специалистов
   */
  $st2 = $pdo->prepare("\n    SELECT u.id, u.name\n    FROM users u\n    JOIN user_roles ur ON ur.user_id = u.id\n    JOIN roles r ON r.id = ur.role_id\n    WHERE r.code = 'specialist' AND u.status = 'active'\n    ORDER BY u.name ASC\n  ");
  $st2->execute();
  $specialists = $st2->fetchAll(PDO::FETCH_ASSOC);

  /**
   * $st3 — запрос связей специалист-услуга
   */
  $st3 = $pdo->prepare("\n    SELECT us.service_id, u.id, u.name
    FROM " . REQUESTS_USER_SERVICES_TABLE . " us
    JOIN users u ON u.id = us.user_id
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'specialist' AND u.status = 'active'
    ORDER BY u.name ASC
  ");
  $st3->execute();
  /**
   * $rows — строки связей
   */
  $rows = $st3->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as $r) {
    /**
     * $sid — id услуги
     */
    $sid = (int)($r['service_id'] ?? 0);
    if ($sid <= 0) continue;
    if (!isset($specMap[$sid])) $specMap[$sid] = [];
    $specMap[$sid][] = [
      'id' => (int)($r['id'] ?? 0),
      'name' => (string)($r['name'] ?? ''),
    ];
  }

  $specMapJson = json_encode($specMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($specMapJson === false) $specMapJson = '{}';
}

/**
 * $csrf — токен
 */
$csrf = csrf_token();

/**
 * $returnUrl — куда вернуться после создания
 */
$returnUrl = (string)($_GET['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));

ob_start();
?>
<div class="req-modal-scroll req-modal-scroll--add">
<form method="post" action="<?= h(url('/adm/index.php?m=requests&do=add')) ?>">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <?php if ($returnUrl !== ''): ?>
    <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
  <?php endif; ?>

  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__body" style="display:flex; flex-direction:column; gap:10px;">
      <div class="req-client-picker" data-requests-client-picker="1" data-requests-clients-api="<?= h($clientsLookupApi) ?>">
        <input type="hidden" name="client_id" value="" data-requests-client-id="1">

        <div class="req-search">
          <input class="select"
                 style="height:40px;"
                 type="text"
                 placeholder="Поиск клиента: ИНН / телефон / имя / фамилия"
                 data-requests-client-query="1"
                 autocomplete="off">
          <div class="req-search__suggest scroll-thin" data-requests-client-suggest="1"></div>
        </div>

        <div class="req-client-picker__actions">
          <button class="btn btn--ghost" type="button" data-requests-client-toggle-new="1">Новый клиент</button>
          <button class="btn is-hidden" type="button" data-requests-client-clear="1">Сброс клиента</button>
        </div>

        <div class="muted is-hidden" data-requests-client-selected="1"></div>
      </div>

      <div class="req-client-new is-hidden" data-requests-client-new-wrap="1">
        <input class="select"
               style="height:40px;"
               name="client_name"
               placeholder="Имя клиента"
               data-requests-client-name="1">

        <input class="select"
               style="height:40px;"
               name="client_phone"
               placeholder="Телефон"
               data-requests-client-phone="1">

        <input class="select"
               style="height:40px;"
               name="client_email"
               type="email"
               placeholder="Email (необязательно)"
               data-requests-client-email="1">
      </div>
      <div class="req-form-grid req-form-grid--single">
        <?php if ($useSpecialists): ?>
          <div class="req-form-right" data-requests-specmap="<?= h($specMapJson) ?>">
            <div class="req-search" data-requests-service-search-wrap="1" data-requests-service-wrap="1">
              <input class="select" type="text" placeholder="Поиск услуги" data-requests-service-search="1" autocomplete="off">
              <div class="req-search__suggest scroll-thin" data-requests-service-suggest="1"></div>
              <select class="select" name="service_id" data-requests-service-select="1" style="display:none;">
                <option value="">Услуга</option>
                <?php foreach ($services as $srv): ?>
                  <?php
                    $dur = (int)($srv['duration_min'] ?? 0);
                    if ($dur <= 0) $dur = 30;
                  ?>
                  <option value="<?= (int)$srv['id'] ?>" data-duration="<?= (int)$dur ?>"><?= h((string)$srv['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="req-search is-hidden" data-requests-specialist-search-wrap="1" data-requests-specialist-wrap="1">
              <input class="select" type="text" placeholder="Поиск специалиста" data-requests-specialist-search="1" autocomplete="off">
              <div class="req-search__suggest scroll-thin" data-requests-specialist-suggest="1"></div>
              <select class="select" name="specialist_user_id" data-requests-specialist-select="1" style="display:none;">
                <option value="">Специалист</option>
              </select>
            </div>
          </div>
        <?php endif; ?>

        <div class="req-form-left<?= $useSpecialists ? ' is-hidden' : '' ?>"
             data-requests-slot-wrap="1"
             data-requests-slots-api="<?= h($slotsApi) ?>"
             data-requests-use-slots="<?= $useTimeSlots ? '1' : '0' ?>">
          <input class="select" name="visit_date" type="date" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" placeholder="Дата" data-requests-slot-date="1">
          <?php if ($useTimeSlots): ?>
              <select class="select" name="visit_time" data-requests-slot-select="1" data-ui-select="1" data-placeholder="Время">
              <option value="">Время</option>
            </select>
            <div class="muted req-slot-note is-hidden" data-requests-slot-note="1"></div>
          <?php else: ?>
            <input class="select" name="visit_time" type="time" placeholder="Время">
          <?php endif; ?>
        </div>
      </div>

      <button class="btn btn--accent" type="submit">Создать</button>
    </div>
  </div>
</form>
</div>
<?php
/**
 * $html — HTML формы
 */
$html = ob_get_clean();

json_ok([
  'title' => 'Новая заявка',
  'html' => $html,
]);
