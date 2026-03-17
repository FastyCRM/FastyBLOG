<?php
/**
 * FILE: /adm/modules/requests/requests.php
 * ROLE: VIEW — канбан заявок (UI only)
 * CONNECTIONS:
 *  - db()
 *  - csrf_token()
 *  - module_allowed_roles(), acl_guard()
 *  - url(), h()
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/requests_lib.php';

/**
 * ACL: доступ к модулю requests
 */
acl_guard(module_allowed_roles('requests'));

/**
 * $pdo — соединение с БД
 */
$pdo = db();

/**
 * $csrf — CSRF токен
 */
$csrf = csrf_token();

/**
 * $uid — текущий пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);

/**
 * $roles — роли пользователя
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
 * $settings — настройки модуля
 */
$settings = requests_settings_get($pdo);

/**
 * $useSpecialists — режим специалистов
 */
$useSpecialists = ((int)($settings['use_specialists'] ?? 0) === 1);
/**
 * $useTimeSlots — интервальный режим
 */
$useTimeSlots = ($useSpecialists && ((int)($settings['use_time_slots'] ?? 0) === 1));

/**
 * $where — фильтр
 */
$where = "1=1";

/**
 * $params — параметры запроса
 */
$params = [];

if (!$isAdmin && !$isManager) {
  if ($useSpecialists) {
    $where .= " AND (r.specialist_user_id = :uid1 OR r.taken_by = :uid2 OR r.done_by = :uid3)";
    $params[':uid1'] = $uid;
    $params[':uid2'] = $uid;
    $params[':uid3'] = $uid;
  } else {
    $where .= " AND (r.status = :confirmed OR r.taken_by = :uid1 OR r.done_by = :uid2)";
    $params[':confirmed'] = REQUESTS_STATUS_CONFIRMED;
    $params[':uid1'] = $uid;
    $params[':uid2'] = $uid;
  }
}

/**
 * $sql — запрос
 */
$sql = "
  SELECT
    r.id,
    r.status,
    r.source,
    r.client_id,
    r.client_name,
    r.client_phone,
    r.client_email,
    r.service_id,
    s.name AS service_name,
    r.specialist_user_id,
    us.name AS specialist_name,
    r.visit_at,
    r.created_at
  FROM " . REQUESTS_TABLE . " r
  LEFT JOIN " . REQUESTS_SERVICES_TABLE . " s ON s.id = r.service_id
  LEFT JOIN users us ON us.id = r.specialist_user_id
  WHERE {$where}
  ORDER BY r.created_at DESC
  LIMIT 500
";

/**
 * $st — подготовленный запрос
 */
$st = $pdo->prepare($sql);
$st->execute($params);
/**
 * $rows — строки заявок
 */
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/**
 * $cols — группировка по статусам
 */
$cols = [
  REQUESTS_STATUS_NEW => [],
  REQUESTS_STATUS_CONFIRMED => [],
  REQUESTS_STATUS_IN_WORK => [],
  REQUESTS_STATUS_DONE => [],
];

foreach ($rows as $r) {
  /**
   * $stt — статус
   */
  $stt = (string)($r['status'] ?? '');
  if (!isset($cols[$stt])) continue;
  $cols[$stt][] = $r;
}
?>

<link rel="stylesheet" href="<?= h(url('/adm/modules/requests/assets/css/main.css')) ?>">

<h1>Заявки</h1>

<div class="card req-toolbar" style="box-shadow:none; border-color: var(--border-soft); margin-bottom:12px;">
  <div class="card__body req-toolbar__body">

    <div class="req-toolbar__meta">
      <span class="req-pill"><?= $useSpecialists ? 'со специалистами' : 'без специалистов' ?></span>
      <?php if ($useSpecialists): ?>
        <span class="req-pill"><?= $useTimeSlots ? 'интервалы' : 'ручное время' ?></span>
      <?php endif; ?>
    </div>

    <div class="req-toolbar__actions">
      <?php if ($isAdmin): ?>
        <button class="iconbtn iconbtn--sm"
                type="button"
                data-requests-open-modal="1"
                data-requests-modal="<?= h(url('/adm/index.php?m=requests&do=modal_settings')) ?>"
                aria-label="Настройки"
                title="Настройки">
          <i class="bi bi-gear"></i>
        </button>
      <?php endif; ?>

      <?php if ($isAdmin || $isManager): ?>
        <button class="iconbtn iconbtn--sm"
                type="button"
                data-requests-open-modal="1"
                data-requests-modal="<?= h(url('/adm/index.php?m=requests&do=modal_add')) ?>"
                aria-label="Новая заявка"
                title="Новая заявка">
          <i class="bi bi-plus-lg"></i>
        </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="req-board">
  <?php
    /**
     * $colTitles — заголовки колонок
     */
    $colTitles = [
      REQUESTS_STATUS_NEW => 'Новые',
      REQUESTS_STATUS_CONFIRMED => 'Подтвержденные',
      REQUESTS_STATUS_IN_WORK => 'В работе',
      REQUESTS_STATUS_DONE => 'Выполненные',
    ];
  ?>

  <?php foreach ($cols as $status => $list): ?>
    <div class="req-col" data-status="<?= h($status) ?>">
      <div class="req-col__head">
        <div class="req-col__title"><?= h($colTitles[$status] ?? $status) ?></div>
        <div class="req-col__count"><?= count($list) ?></div>
      </div>

      <div class="req-col__body">
        <?php if (!$list): ?>
          <div class="muted" style="font-size:12px;">Пусто</div>
        <?php endif; ?>

        <?php foreach ($list as $r): ?>
          <?php
            /**
             * $rid — id заявки
             */
            $rid = (int)($r['id'] ?? 0);
            /**
             * $clientIdRow — id клиента
             */
            $clientIdRow = (int)($r['client_id'] ?? 0);
            /**
             * $name — имя клиента
             */
            $name = (string)($r['client_name'] ?? '');
            $sourceCode = strtolower(trim((string)($r['source'] ?? 'crm')));
            $sourceLabel = ($sourceCode === 'adv_bot')
              ? 'Бронь рекламы'
              : (($sourceCode === 'landing') ? 'Сайт' : 'CRM');
            $sourceClass = ($sourceCode === 'adv_bot') ? ' req-card__source--adv' : '';
            /**
             * $phone — телефон
             */
            $phone = (string)($r['client_phone'] ?? '');
            /**
             * $serviceName — услуга
             */
            $serviceName = (string)($r['service_name'] ?? '');
            /**
             * $specName — специалист
             */
            $specName = (string)($r['specialist_name'] ?? '');
            /**
             * $visitAt — дата/время
             */
            $visitAt = (string)($r['visit_at'] ?? '');
            /**
             * $createdAt — дата создания
             */
            $createdAt = (string)($r['created_at'] ?? '');
            /**
             * $isPfStatus — статус, в котором доступна ссылка в личное дело
             */
            $isPfStatus = in_array($status, [REQUESTS_STATUS_CONFIRMED, REQUESTS_STATUS_IN_WORK], true);
            /**
             * $canOpenPersonal — можно открыть личное дело
             */
            $canOpenPersonal = false;
            if ($clientIdRow > 0 && $isPfStatus) {
              if ($isAdmin || $isManager) {
                $canOpenPersonal = true;
              } elseif ($isSpecialist && (int)($r['specialist_user_id'] ?? 0) === $uid) {
                $canOpenPersonal = true;
              }
            }
          ?>

          <div class="req-card"
               data-requests-open-modal="1"
               data-requests-modal="<?= h(url('/adm/index.php?m=requests&do=modal_view&id=' . $rid)) ?>">
            <div class="req-card__row">
              <div class="req-card__title"><?= h($name !== '' ? $name : '—') ?></div>
              <div class="req-card__source<?= h($sourceClass) ?>"><?= h($sourceLabel) ?></div>
              <div class="req-card__item"><?= h($phone !== '' ? $phone : '—') ?></div>
              <?php if ($serviceName !== ''): ?>
                <div class="req-card__item"><?= h($serviceName) ?></div>
              <?php endif; ?>
              <?php if ($useSpecialists && $specName !== ''): ?>
                <div class="req-card__item">Спец: <?= h($specName) ?></div>
              <?php endif; ?>
              <?php if ($visitAt !== ''): ?>
                <div class="req-card__item"><?= h($visitAt) ?></div>
              <?php else: ?>
                <div class="req-card__item"><?= h($createdAt) ?></div>
              <?php endif; ?>

              <?php if ($canOpenPersonal): ?>
                <a class="iconbtn iconbtn--sm req-card__doc"
                   href="<?= h(url('/adm/index.php?m=personal_file&client_id=' . $clientIdRow)) ?>"
                   data-requests-client-link="1"
                   aria-label="Личное дело"
                   title="Личное дело">
                  <i class="bi bi-file-earmark-person"></i>
                </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<script src="<?= h(url('/adm/modules/requests/assets/js/main.js')) ?>"></script>
