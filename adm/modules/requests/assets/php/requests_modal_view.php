<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_modal_view.php
 * ROLE: modal_view — карточка заявки
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
 * $pdo — БД
 */
$pdo = db();

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

/**
 * $isSpecialist — специалист
 */
$isSpecialist = requests_is_specialist($roles);

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

/**
 * $id — заявка
 */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  json_err('Bad id', 400);
}

/**
 * $st — запрос заявки
 */
$st = $pdo->prepare("\n  SELECT
    r.*, 
    s.name AS service_name,
    us.name AS specialist_name,
    cb.name AS confirmed_by_name,
    tb.name AS taken_by_name,
    dbu.name AS done_by_name
  FROM " . REQUESTS_TABLE . " r
  LEFT JOIN " . REQUESTS_SERVICES_TABLE . " s ON s.id = r.service_id
  LEFT JOIN users us ON us.id = r.specialist_user_id
  LEFT JOIN users cb ON cb.id = r.confirmed_by
  LEFT JOIN users tb ON tb.id = r.taken_by
  LEFT JOIN users dbu ON dbu.id = r.done_by
  WHERE r.id = :id
  LIMIT 1
");
/**
 * $row — заявка
 */
$st->execute([':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  json_err('Not found', 404);
}

/**
 * Проверка доступа
 */
/**
 * $allow — флаг доступа
 */
$allow = false;

if ($isAdmin || $isManager) {
  $allow = true;
} else {
  /**
   * $status — статус
   */
  $status = (string)($row['status'] ?? '');
  /**
   * $specId — специалист
   */
  $specId = (int)($row['specialist_user_id'] ?? 0);
  /**
   * $takenBy — кто принял
   */
  $takenBy = (int)($row['taken_by'] ?? 0);
  /**
   * $doneBy — кто завершил
   */
  $doneBy = (int)($row['done_by'] ?? 0);

  if ($useSpecialists) {
    if ($specId === $uid || $takenBy === $uid || $doneBy === $uid) {
      $allow = true;
    }
  } else {
    if ($status === REQUESTS_STATUS_CONFIRMED || $takenBy === $uid || $doneBy === $uid) {
      $allow = true;
    }
  }
}

if (!$allow) {
  json_err('Forbidden', 403);
}

/**
 * $csrf — CSRF токен
 */
$csrf = csrf_token();

/**
 * $returnUrl — куда вернуться после действий из модалки
 */
$returnUrl = (string)($_GET['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));

/**
 * $status — статус
 */
$status = (string)($row['status'] ?? '');
$sourceCode = strtolower(trim((string)($row['source'] ?? 'crm')));
$sourceLabel = ($sourceCode === 'adv_bot')
  ? 'Бронь рекламы'
  : (($sourceCode === 'landing') ? 'Сайт' : 'CRM');

/**
 * $specialistUserId — id назначенного специалиста
 */
$specialistUserId = (int)($row['specialist_user_id'] ?? 0);


/**
 * $clientName — имя
 */
$clientName = (string)($row['client_name'] ?? '');

/**
 * $clientPhone — телефон
 */
$clientPhone = (string)($row['client_phone'] ?? '');

/**
 * $clientEmail — email
 */
$clientEmail = (string)($row['client_email'] ?? '');

/**
 * $serviceName — услуга
 */
$serviceName = (string)($row['service_name'] ?? '');
/**
 * $serviceId — id услуги
 */
$serviceId = (int)($row['service_id'] ?? 0);

/**
 * $specialistName — специалист
 */
$specialistName = (string)($row['specialist_name'] ?? '');

/**
 * $visitAt — дата/время
 */
$visitAt = (string)($row['visit_at'] ?? '');
/**
 * $visitDate — дата визита
 */
$visitDate = $visitAt !== '' ? date('Y-m-d', strtotime($visitAt)) : date('Y-m-d');
/**
 * $visitTime — время визита
 */
$visitTime = $visitAt !== '' ? date('H:i', strtotime($visitAt)) : '';

/**
 * $durationMin — длительность
 */
$durationMin = (int)($row['duration_min'] ?? 0);
/**
 * $priceTotal — стоимость
 */
$priceTotal = (int)($row['price_total'] ?? 0);
/**
 * $takenById — кто принял в работу
 */
$takenById = (int)($row['taken_by'] ?? 0);

$invoiceServices = [];
$invoiceServicesJson = "[]";
$invoiceInitialItems = [];
$invoiceInitialItemsJson = "[]";
$invoiceInitialTotal = 0;

$needInvoiceServices = ($status === REQUESTS_STATUS_NEW || $status === REQUESTS_STATUS_IN_WORK);
if ($needInvoiceServices) {
  try {
    $stInv = $pdo->query("
      SELECT id, name, price, duration_min
      FROM " . REQUESTS_SERVICES_TABLE . " 
      WHERE status = 'active'
      ORDER BY name ASC
    ");
    $invoiceServices = $stInv ? $stInv->fetchAll(PDO::FETCH_ASSOC) : [];
  } catch (Throwable $e) {
    try {
      $stInv = $pdo->query("
        SELECT id, name, 0 AS price, 30 AS duration_min
        FROM " . REQUESTS_SERVICES_TABLE . " 
        WHERE status = 'active'
        ORDER BY name ASC
      ");
      $invoiceServices = $stInv ? $stInv->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e2) {
      $invoiceServices = [];
    }
  }
  $invoiceServicesJson = json_encode($invoiceServices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($invoiceServicesJson === false) $invoiceServicesJson = "[]";
}

if ($status === REQUESTS_STATUS_NEW || $status === REQUESTS_STATUS_IN_WORK) {
  try {
    $stDraft = $pdo->prepare("
      SELECT id, total
      FROM " . REQUESTS_INVOICES_TABLE . "
      WHERE request_id = :rid
      ORDER BY created_at DESC, id DESC
      LIMIT 1
    ");
    $stDraft->execute([':rid' => $id]);
    $draft = $stDraft->fetch(PDO::FETCH_ASSOC);
    $draftId = $draft ? (int)($draft['id'] ?? 0) : 0;
    if ($draftId > 0) {
      $stDraftItems = $pdo->prepare("
        SELECT service_id, service_name, qty, price, total
        FROM " . REQUESTS_INVOICE_ITEMS_TABLE . "
        WHERE invoice_id = :iid
        ORDER BY id ASC
      ");
      $stDraftItems->execute([':iid' => $draftId]);
      $invoiceInitialItems = $stDraftItems->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $invoiceInitialTotal = (int)($draft['total'] ?? 0);
    }
  } catch (Throwable $e) {
    $invoiceInitialItems = [];
  }
}

if (!$invoiceInitialItems && $status === REQUESTS_STATUS_NEW && $serviceId > 0 && $serviceName !== '') {
  $priceVal = 0;
  foreach ($invoiceServices as $srv) {
    if ((int)$srv['id'] === $serviceId) {
      $priceVal = (int)($srv['price'] ?? 0);
      break;
    }
  }
  $invoiceInitialItems = [[
    'service_id' => $serviceId,
    'service_name' => $serviceName,
    'qty' => 1,
    'price' => $priceVal,
    'total' => $priceVal,
  ]];
  $invoiceInitialTotal = $priceVal;
}

if ($invoiceInitialItems) {
  $invoiceInitialItemsJson = json_encode($invoiceInitialItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($invoiceInitialItemsJson === false) $invoiceInitialItemsJson = "[]";
}
$invoiceInitialTotalDisplay = $invoiceInitialTotal > 0 ? $invoiceInitialTotal : ($priceTotal > 0 ? $priceTotal : 0);
$displayPriceTotal = $priceTotal;
if ($displayPriceTotal <= 0 && $invoiceInitialTotalDisplay > 0) {
  $displayPriceTotal = $invoiceInitialTotalDisplay;
}

$autoStamp = date('Ymd');
$autoInvoiceNumber = 'INV-' . $id . '-' . $autoStamp;
$autoActNumber = 'ACT-' . $id . '-' . $autoStamp;
$autoContractNumber = 'CTR-' . $id . '-' . $autoStamp;
$autoInvoiceDate = date('Y-m-d');
$stc = $pdo->prepare("\n  SELECT c.*, u.name AS user_name, cl.first_name AS client_name
  FROM " . REQUESTS_COMMENTS_TABLE . " c
  LEFT JOIN users u ON u.id = c.user_id
  LEFT JOIN clients cl ON cl.id = c.client_id
  WHERE c.request_id = :rid
  ORDER BY c.created_at ASC
");
/**
 * $comments — список комментариев
 */
$stc->execute([':rid' => $id]);
$comments = $stc->fetchAll(PDO::FETCH_ASSOC);

$invoice = null;
$invoiceItems = [];
if ($status === REQUESTS_STATUS_DONE) {
  try {
    $stInv = $pdo->prepare("
      SELECT *
      FROM " . REQUESTS_INVOICES_TABLE . "
      WHERE request_id = :rid
      ORDER BY created_at DESC, id DESC
      LIMIT 1
    ");
    $stInv->execute([':rid' => $id]);
    $invoice = $stInv->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($invoice) {
      $stItems = $pdo->prepare("
        SELECT *
        FROM " . REQUESTS_INVOICE_ITEMS_TABLE . "
        WHERE invoice_id = :iid
        ORDER BY id ASC
      ");
      $stItems->execute([':iid' => (int)$invoice['id']]);
      $invoiceItems = $stItems->fetchAll(PDO::FETCH_ASSOC);
    }
  } catch (Throwable $e) {
    $invoice = null;
    $invoiceItems = [];
  }
}

/**
 * Данные для confirm формы
 */
/**
 * $services — услуги
 */
$services = [];
/**
 * $specialists — специалисты
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
   * $st1 — запрос услуг
   */
  $st1 = $pdo->query("SELECT id, name, duration_min FROM " . REQUESTS_SERVICES_TABLE . " WHERE status='active' ORDER BY name ASC");
  $services = $st1 ? $st1->fetchAll(PDO::FETCH_ASSOC) : [];

  /**
   * $st2 — запрос специалистов
   */
  $st2 = $pdo->prepare("\n    SELECT u.id, u.name
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE r.code = 'specialist' AND u.status = 'active'
    ORDER BY u.name ASC
  ");
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

  $specMapJson = json_encode($specMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($specMapJson === false) $specMapJson = '{}';
}

ob_start();
?>
<div class="req-modal-scroll scroll-thin">
<div class="card-grid-2" style="margin-bottom:12px;">
  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__body" style="display:flex; flex-direction:column; gap:6px;">
      <div><strong><?= h($clientName !== '' ? $clientName : '—') ?></strong></div>
      <div class="muted">Телефон: <?= h($clientPhone !== '' ? $clientPhone : '—') ?></div>
      <div class="muted">Email: <?= h($clientEmail !== '' ? $clientEmail : '—') ?></div>
    </div>
  </div>

  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__body" style="display:flex; flex-direction:column; gap:6px;">
      <div class="muted">Статус: <strong><?= h($status) ?></strong></div>
      <div class="muted">Источник: <?= h($sourceLabel) ?> (<?= h($sourceCode) ?>)</div>
      <div class="muted">Услуга: <?= h($serviceName !== '' ? $serviceName : '—') ?></div>
      <div class="muted">Специалист: <?= h($specialistName !== '' ? $specialistName : '—') ?></div>
      <div class="muted">Дата/время: <?= h($visitAt !== '' ? $visitAt : '—') ?></div>
      <div class="muted">Длительность: <?= $durationMin > 0 ? (int)$durationMin . ' мин' : '—' ?></div>
      <div class="muted">Стоимость: <?= $displayPriceTotal > 0 ? (int)$displayPriceTotal : '—' ?></div>

    </div>
  </div>
</div>

<?php
  /**
   * $canConfirm — право подтверждения
   */
  $canConfirm = false;
  if ($status === REQUESTS_STATUS_NEW) {
    if ($isAdmin || $isManager) {
      $canConfirm = true;
    } elseif ($useSpecialists && $specialistUserId > 0 && $specialistUserId === $uid) {
      $canConfirm = true;
    }
  }
?>

<?php if ($canConfirm): ?>
  <div class="card" style="box-shadow:none; border-color: var(--border-soft); margin-bottom:12px;">
    <div class="card__head">
      <div class="card__title">Подтверждение заявки</div>
      <div class="card__hint muted">Менеджер фиксирует время и детали</div>
    </div>
    <div class="card__body">
      <div style="display:flex; justify-content:flex-end; margin-bottom:8px;">
        <button class="btn" type="button" data-requests-reset-chain="1">Сброс</button>
      </div>
      <form method="post" action="<?= h(url('/adm/index.php?m=requests&do=confirm')) ?>" style="display:flex; flex-direction:column; gap:10px;">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <?php if ($returnUrl !== ''): ?>
          <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
        <?php endif; ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="req-form-grid req-form-grid--single">
          <?php if ($useSpecialists): ?>
          <div class="req-form-right"
               data-requests-specmap="<?= h($specMapJson) ?>"
               data-requests-service-current="<?= (int)$serviceId ?>"
               data-requests-specialist-current="<?= (int)$specialistUserId ?>"
               data-requests-chain-root="1">
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
                    <option value="<?= (int)$srv['id'] ?>" data-duration="<?= (int)$dur ?>"<?= (int)$srv['id'] === $serviceId ? ' selected' : '' ?>><?= h((string)$srv['name']) ?></option>
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
            <input class="select" name="visit_date" type="date" value="<?= h($visitDate) ?>" min="<?= date('Y-m-d') ?>" placeholder="Дата" data-requests-slot-date="1">
            <div data-requests-slot-time-wrap="1">
              <?php if ($useTimeSlots): ?>
                <select class="select" name="visit_time" data-requests-slot-select="1" data-ui-select="1" data-placeholder="Время" data-requests-slot-current="<?= h($visitTime) ?>">
                  <option value="">Время</option>
                </select>
              <?php else: ?>
                <input class="select" name="visit_time" type="time" value="<?= h($visitTime) ?>" placeholder="Время">
              <?php endif; ?>
            </div>
            <div class="muted req-slot-note is-hidden" data-requests-slot-note="1"></div>
          </div>
        </div>

        <?php if ($invoiceServicesJson !== "[]"): ?>
          <div class="req-invoice"
               data-requests-invoice="1"
               data-requests-services="<?= h($invoiceServicesJson) ?>"
               data-requests-initial-items="<?= h($invoiceInitialItemsJson) ?>">
            <div class="req-invoice__search">
              <input class="select" type="text" placeholder="Поиск услуги" data-requests-service-search="1" autocomplete="off">
              <button class="btn" type="button" data-requests-add-service="1">Добавить услугу</button>
              <div class="req-invoice__suggest scroll-thin" data-requests-service-suggest="1"></div>
            </div>

            <div class="req-invoice__list" data-requests-invoice-items="1"></div>

            <div class="req-invoice__total">
              <span>Итого:</span>
              <strong data-requests-total="1"><?= (int)$invoiceInitialTotalDisplay ?></strong>
            </div>
            <input type="hidden" name="price_total" data-requests-total-input="1" value="<?= (int)$invoiceInitialTotalDisplay ?>">
          </div>
        <?php endif; ?>

        <textarea class="select" style="min-height:60px;" name="comment" placeholder="Комментарий"></textarea>

        <button class="btn btn--accent" type="submit">Подтвердить</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php if ($status === REQUESTS_STATUS_CONFIRMED && (!$useSpecialists || $specialistUserId <= 0 || $specialistUserId === $uid)): ?>
  <div class="card" style="box-shadow:none; border-color: var(--border-soft); margin-bottom:12px;">
    <div class="card__body">
      <form method="post" action="<?= h(url('/adm/index.php?m=requests&do=take')) ?>">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <?php if ($returnUrl !== ''): ?>
          <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
        <?php endif; ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <button class="btn btn--accent" type="submit">Принять в работу</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php if ($status === REQUESTS_STATUS_CONFIRMED && ($isAdmin || $isManager)): ?>
  <div class="card" style="box-shadow:none; border-color: var(--border-soft); margin-bottom:12px;">
    <div class="card__body">
      <form method="post" action="<?= h(url('/adm/index.php?m=requests&do=reassign')) ?>">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <?php if ($returnUrl !== ''): ?>
          <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
        <?php endif; ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <button class="btn btn--danger" type="submit">Переназначить</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php if ($status === REQUESTS_STATUS_IN_WORK): ?>
  <?php
    /**
     * $canDone — право завершить
     */
    $canDone = ($isAdmin || $isManager || $takenById === $uid);
  ?>
  <?php if ($canDone): ?>
  <div class="card" style="box-shadow:none; border-color: var(--border-soft); margin-bottom:12px;">
    <div class="card__body">
      <form method="post" action="<?= h(url('/adm/index.php?m=requests&do=done')) ?>" style="display:flex; flex-direction:column; gap:10px;">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <?php if ($returnUrl !== ''): ?>
          <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
        <?php endif; ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <div class="req-invoice"
             data-requests-invoice="1"
             data-requests-services="<?= h($invoiceServicesJson) ?>"
             data-requests-initial-items="<?= h($invoiceInitialItemsJson) ?>">
          <div class="req-invoice__search">
            <input class="select" type="text" placeholder="Поиск услуги" data-requests-service-search="1" autocomplete="off">
            <button class="btn" type="button" data-requests-add-service="1">Добавить услугу</button>
            <div class="req-invoice__suggest scroll-thin" data-requests-service-suggest="1"></div>
          </div>

          <div class="req-invoice__list" data-requests-invoice-items="1"></div>

          <div class="req-invoice__total">
            <span>Итого:</span>
            <strong data-requests-total="1"><?= (int)$invoiceInitialTotalDisplay ?></strong>
          </div>
          <input type="hidden" name="price_total" data-requests-total-input="1"
                 value="<?= (int)$invoiceInitialTotalDisplay ?>">

          <div class="req-invoice__meta">
            <input class="select" name="invoice_date" type="date" value="<?= h($autoInvoiceDate) ?>">
            <input class="select" name="invoice_number" type="text" placeholder="№ счета" value="<?= h($autoInvoiceNumber) ?>">
            <input class="select" name="act_number" type="text" placeholder="№ акта" value="<?= h($autoActNumber) ?>">
            <input class="select" name="contract_number" type="text" placeholder="№ договора" value="<?= h($autoContractNumber) ?>">
          </div>
        </div>
        <button class="btn btn--accent" type="submit">Выполнена</button>
      </form>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>

<?php if ($status === REQUESTS_STATUS_DONE && $invoice): ?>
  <div class="card" style="box-shadow:none; border-color: var(--border-soft); margin-bottom:12px;">
    <div class="card__head">
      <div class="card__title">Счет</div>
    </div>
    <div class="card__body req-invoice-view">
      <div class="req-invoice-view__meta">
        <div>№ счета: <strong><?= h((string)($invoice['invoice_number'] ?? '—')) ?></strong></div>
        <div>№ акта: <strong><?= h((string)($invoice['act_number'] ?? '—')) ?></strong></div>
        <div>№ договора: <strong><?= h((string)($invoice['contract_number'] ?? '—')) ?></strong></div>
        <div>Дата: <strong><?= h((string)($invoice['invoice_date'] ?? '—')) ?></strong></div>
      </div>

      <?php if ($invoiceItems): ?>
        <div class="req-invoice-view__list">
          <?php foreach ($invoiceItems as $it): ?>
            <div class="req-invoice-view__row">
              <div class="req-invoice-view__name"><?= h((string)($it['service_name'] ?? '')) ?></div>
              <div class="req-invoice-view__qty"><?= (int)($it['qty'] ?? 0) ?></div>
              <div class="req-invoice-view__price"><?= (int)($it['price'] ?? 0) ?></div>
              <div class="req-invoice-view__sum"><?= (int)($it['total'] ?? 0) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="req-invoice-view__total">
        Итого: <strong><?= (int)($invoice['total'] ?? 0) ?></strong>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="card" style="box-shadow:none; border-color: var(--border-soft);">
  <div class="card__head">
    <div class="card__title">Комментарии</div>
  </div>
  <div class="card__body req-comments">
    <?php if (!$comments): ?>
      <div class="muted">Комментариев нет.</div>
    <?php endif; ?>

    <?php foreach ($comments as $c): ?>
      <?php
        /**
         * $cText — текст комментария
         */
        $cText = (string)($c['comment'] ?? '');
        /**
         * $cAt — дата комментария
         */
        $cAt = (string)($c['created_at'] ?? '');
        /**
         * $cAuthorType — тип автора
         */
        $cAuthorType = (string)($c['author_type'] ?? 'user');
        /**
         * $cUserName — имя сотрудника
         */
        $cUserName = (string)($c['user_name'] ?? '');
        /**
         * $cClientName — имя клиента
         */
        $cClientName = (string)($c['client_name'] ?? '');
        /**
         * $cAuthor — отображаемый автор
         */
        $cAuthor = $cAuthorType === 'client' ? ($cClientName !== '' ? $cClientName : 'Клиент') : ($cUserName !== '' ? $cUserName : 'Сотрудник');
        /**
         * $cTag — короткое имя
         */
        $cTag = $cAuthor;
        if ($cTag !== '') {
          $parts = preg_split('/\s+/', $cTag);
          if ($parts && isset($parts[0]) && $parts[0] !== '') $cTag = (string)$parts[0];
        }
        if (function_exists('mb_substr')) {
          $cTag = mb_substr($cTag, 0, 8);
        } else {
          $cTag = substr($cTag, 0, 8);
        }
        if ($cTag === '') $cTag = '•';
      ?>
      <div class="req-comment">
        <div class="req-comment__avatar"><?= h($cTag) ?></div>
        <div class="req-comment__body">
          <div class="req-comment__text"><?= h($cText) ?></div>
          <div class="req-comment__time"><?= h($cAt) ?></div>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($status !== REQUESTS_STATUS_NEW): ?>
      <form method="post" action="<?= h(url('/adm/index.php?m=requests&do=comment_add')) ?>" class="req-comment-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <?php if ($returnUrl !== ''): ?>
          <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
        <?php endif; ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <textarea class="select" name="comment" placeholder="Новый комментарий" required></textarea>
        <button class="btn btn--accent" type="submit">Добавить</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</div>
<?php
/**
 * $html — HTML карточки
 */
$html = ob_get_clean();

json_ok([
  'title' => 'Заявка № ' . $id,
  'html' => $html,
]);
