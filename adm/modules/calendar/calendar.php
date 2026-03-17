<?php
/**
 * FILE: /adm/modules/calendar/calendar.php
 * ROLE: VIEW — календарь заявок (UI only)
 * CONNECTIONS:
 *  - db()
 *  - csrf_token()
 *  - module_allowed_roles(), acl_guard()
 *  - auth_user_id(), auth_user_roles()
 *  - url(), h()
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/calendar_lib.php';

/**
 * ACL: доступ к модулю calendar
 */
acl_guard(module_allowed_roles('calendar'));

/**
 * $pdo — БД
 */
$pdo = db();

/**
 * $csrf — токен
 */
$csrf = csrf_token();

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
$isAdmin = in_array('admin', $roles, true);
/**
 * $isManager — менеджер
 */
$isManager = in_array('manager', $roles, true);
/**
 * $isSpecialist — специалист
 */
$isSpecialist = in_array('specialist', $roles, true);

/**
 * $settingsRow — настройки заявок
 */
$settingsRow = null;
try {
$st = $pdo->query("SELECT use_specialists, use_time_slots FROM " . CALENDAR_REQUESTS_SETTINGS_TABLE . " WHERE id = 1 LIMIT 1");
  $settingsRow = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
} catch (Throwable $e) {
  $settingsRow = null;
}
if (!$settingsRow) {
  try {
    $pdo->prepare("INSERT INTO " . CALENDAR_REQUESTS_SETTINGS_TABLE . " (id, use_specialists, use_time_slots) VALUES (1, 0, 0)")
        ->execute();
  } catch (Throwable $e) {
    // игнор
  }
  $settingsRow = [
    'use_specialists' => 0,
    'use_time_slots' => 0,
  ];
}

/**
 * $useSpecialists — режим специалистов
 */
$useSpecialists = ((int)($settingsRow['use_specialists'] ?? 0) === 1);
/**
 * $useTimeSlots — режим интервалов
 */
$useTimeSlots = ((int)($settingsRow['use_time_slots'] ?? 0) === 1);

/**
 * $userSettings — персональные настройки календаря
 */
$userSettings = [];
try {
  $st = $pdo->prepare("SELECT mode, manager_spec_ids FROM " . CALENDAR_USER_SETTINGS_TABLE . " WHERE user_id = :uid LIMIT 1");
  $st->execute([':uid' => $uid]);
  $userSettings = $st ? (array)$st->fetch(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
  $userSettings = [];
}

/**
 * $mode — режим календаря
 */
$mode = (string)($userSettings['mode'] ?? '');
if ($mode === '') {
  if (($isAdmin || $isManager) && !$isSpecialist) {
    $mode = 'manager';
  } else {
    $mode = 'user';
  }
}
if (!in_array($mode, ['user', 'manager'], true)) {
  $mode = 'user';
}
if ($mode === 'manager' && (!$isAdmin && !$isManager)) {
  $mode = 'user';
}
if ($mode === 'user' && !$isSpecialist && ($isAdmin || $isManager)) {
  $mode = 'manager';
}

/**
 * $today — текущая дата
 */
$today = date('Y-m-d');
/**
 * $baseDate — выбранная дата (не хранится)
 */
$baseDate = (string)($_GET['date'] ?? '');
if ($baseDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $baseDate)) {
  $baseDate = $today;
}

/**
 * $days — окно дней
 */
$days = (int)($_GET['days'] ?? CALENDAR_DEFAULT_DAYS);
if ($days < 1) $days = CALENDAR_DEFAULT_DAYS;
if ($days > 14) $days = 14;
/**
 * $baseUrl — базовый URL календаря (без даты)
 */
$daysParam = (int)($_GET['days'] ?? 0);
$baseUrl = '/adm/index.php?m=calendar';
if ($daysParam > 0) {
  $baseUrl .= '&days=' . $daysParam;
}

/**
 * $specialists — список специалистов
 */
$specialists = [];
$stSpec = $pdo->prepare("
  SELECT u.id, u.name
  FROM " . CALENDAR_USERS_TABLE . " u
  JOIN " . CALENDAR_USER_ROLES_TABLE . " ur ON ur.user_id = u.id
  JOIN " . CALENDAR_ROLES_TABLE . " r ON r.id = ur.role_id
  WHERE r.code = 'specialist' AND u.status = 'active'
  ORDER BY u.name ASC
");
$stSpec->execute();
$specialists = $stSpec->fetchAll(PDO::FETCH_ASSOC);

/**
 * $selectedSpecIds — выбранные специалисты (manager mode)
 */
$selectedSpecIds = [];
/**
 * $managerDate — дата для manager-mode
 */
/**
 * $managerDate — дата в менеджерском режиме (только из виджета)
 */
$managerDate = $baseDate;
/**
 * $rawSpecIds — список id из настроек
 */
$rawSpecIds = (string)($userSettings['manager_spec_ids'] ?? '');
if ($rawSpecIds !== '') {
  foreach (explode(',', $rawSpecIds) as $sid) {
    $sid = (int)trim($sid);
    if ($sid > 0) $selectedSpecIds[] = $sid;
  }
}
$selectedSpecIds = array_values(array_unique($selectedSpecIds));

$specIdsAll = array_map('intval', array_column($specialists, 'id'));
$selectedSpecIds = array_values(array_intersect($selectedSpecIds, $specIdsAll));

if ($mode === 'manager') {
  if (!$selectedSpecIds) {
    $selectedSpecIds = array_slice($specIdsAll, 0, 4);
  }
} else {
  $selectedSpecIds = $isSpecialist ? [$uid] : [];
}

/**
 * $bookingsBySpec — заявки по специалистам (manager)
 * $bookingsByDate — заявки по датам (user)
 */
$bookingsBySpec = [];
$bookingsByDate = [];

if ($mode === 'manager') {
  if ($selectedSpecIds) {
    $in = implode(',', array_fill(0, count($selectedSpecIds), '?'));
    $stB = $pdo->prepare("
      SELECT id, status, source, client_id, client_name, visit_at, duration_min, specialist_user_id
      FROM " . CALENDAR_REQUESTS_TABLE . "
      WHERE specialist_user_id IN ($in)
        AND visit_at IS NOT NULL
        AND DATE(visit_at) = ?
        AND archived_at IS NULL
      ORDER BY visit_at ASC, id ASC
    ");
    $params = $selectedSpecIds;
    $params[] = $managerDate;
    $stB->execute($params);
    $rows = $stB->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
      $sid = (int)($r['specialist_user_id'] ?? 0);
      if (!isset($bookingsBySpec[$sid])) $bookingsBySpec[$sid] = [];
      $bookingsBySpec[$sid][] = $r;
    }
  }
} else {
  if ($isSpecialist) {
    for ($i = 0; $i < $days; $i++) {
      $date = date('Y-m-d', strtotime($baseDate . ' +' . $i . ' day'));
      $bookingsByDate[$date] = calendar_get_bookings_day($pdo, $uid, $date);
    }
  }
}

/**
 * $slotHeight — высота одного интервала
 */
$slotHeight = CALENDAR_SLOT_HEIGHT_PX;
?>

<link rel="stylesheet" href="<?= h(url('/adm/modules/calendar/assets/css/main.css')) ?>">
<link rel="stylesheet" href="<?= h(url('/adm/modules/requests/assets/css/main.css')) ?>">

<h1>Календарь</h1>

<div class="card" style="box-shadow:none; border-color: var(--border-soft); margin-bottom:12px;">
  <div class="card__body calendar-toolbar">
    <div class="calendar-toolbar__left">
      <div class="calendar-hint">Режим: <strong><?= $mode === 'manager' ? 'Менеджер' : 'Специалист' ?></strong></div>
    </div>

    <div class="calendar-toolbar__right">
      <?php if ($isAdmin || $isManager): ?>
        <button class="iconbtn iconbtn--sm"
                type="button"
                data-calendar-open-modal="1"
                data-calendar-modal="<?= h(url('/adm/index.php?m=calendar&do=modal_settings')) ?>"
                aria-label="Настройки"
                title="Настройки">
          <i class="bi bi-gear"></i>
        </button>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="calendar-layout">
  <aside class="calendar-side">
    <div class="card calendar-mini"
         data-calendar-mini="1"
         data-calendar-date="<?= h($baseDate) ?>"
         data-calendar-today="<?= h($today) ?>"
         data-calendar-base="<?= h(url($baseUrl)) ?>">
      <div class="calendar-mini__head">
        <button class="iconbtn iconbtn--sm calendar-mini__nav" type="button" data-cal-nav="prev" aria-label="Предыдущий месяц">
          <i class="bi bi-chevron-left"></i>
        </button>
        <div class="calendar-mini__title" data-cal-title></div>
        <button class="iconbtn iconbtn--sm calendar-mini__nav" type="button" data-cal-nav="next" aria-label="Следующий месяц">
          <i class="bi bi-chevron-right"></i>
        </button>
      </div>
      <div class="calendar-mini__week">
        <span>Пн</span><span>Вт</span><span>Ср</span><span>Чт</span><span>Пт</span><span>Сб</span><span>Вс</span>
      </div>
      <div class="calendar-mini__grid" data-cal-grid></div>
      <div class="calendar-mini__actions">
        <button class="btn calendar-mini__reset" type="button" data-cal-reset>Сброс</button>
      </div>
    </div>
  </aside>
  <div class="calendar-main">
<?php if ($mode === 'manager'): ?>
  <div class="calendar-board calendar-board--specs">
    <?php foreach ($selectedSpecIds as $sid): ?>
      <?php
        $specName = '';
        foreach ($specialists as $sp) {
          if ((int)($sp['id'] ?? 0) === $sid) { $specName = (string)($sp['name'] ?? ''); break; }
        }
        $schedule = calendar_get_schedule_day($pdo, $sid, $managerDate);
        $bookings = $bookingsBySpec[$sid] ?? [];

        $gridHeight = 140;
        $interval = 30;
        $startMin = 0;
        $endMin = 0;
        $timeLabels = [];
        if ($schedule) {
          $startMin = calendar_time_to_minutes((string)$schedule['time_start']);
          $endMin = calendar_time_to_minutes((string)$schedule['time_end']);
          $totalSlots = (int)floor(($endMin - $startMin) / $interval);
          if ($totalSlots < 1) $totalSlots = 1;
          $gridHeight = $totalSlots * $slotHeight;
          for ($t = $startMin; $t < $endMin; $t += $interval) {
            $top = (int)round((($t - $startMin) / $interval) * $slotHeight);
            $timeLabels[] = [
              'top' => $top,
              'label' => calendar_minutes_to_time($t),
              'major' => ((int)($t % 60) === 0),
            ];
          }
        }
      ?>
      <div class="calendar-col">
        <div class="calendar-col__head">
          <div class="calendar-col__title"><?= h($specName !== '' ? $specName : ('#' . $sid)) ?></div>
          <div class="calendar-col__sub"><?= h($managerDate) ?></div>
        </div>
        <div class="calendar-col__body">
          <?php if (!$schedule): ?>
            <div class="calendar-grid__empty">Нет графика</div>
          <?php else: ?>
            <div class="calendar-grid calendar-grid--times" style="height: <?= (int)$gridHeight ?>px; --slot-h: <?= (int)$slotHeight ?>px;">
              <div class="calendar-grid__times">
                <?php foreach ($timeLabels as $tl): ?>
                <div class="calendar-grid__time <?= ($tl['major'] ?? false) ? 'calendar-grid__time--major' : 'calendar-grid__time--minor' ?>" style="top: <?= (int)$tl['top'] ?>px;"><?= h((string)$tl['label']) ?></div>
              <?php endforeach; ?>
            </div>
              <?php foreach ($bookings as $b): ?>
                <?php
                  $visitAt = (string)($b['visit_at'] ?? '');
                  $visitTime = $visitAt !== '' ? date('H:i', strtotime($visitAt)) : '';
                  $bStart = calendar_time_to_minutes($visitTime);
                  $dur = (int)($b['duration_min'] ?? 0);
                  if ($dur <= 0) $dur = $interval;
                  $top = (int)round((($bStart - $startMin) / $interval) * $slotHeight);
                  if ($top < 0) $top = 0;
                  $height = (int)round(($dur / $interval) * $slotHeight);
                  if ($height < 20) $height = 20;
                  $status = (string)($b['status'] ?? '');
                  $source = strtolower(trim((string)($b['source'] ?? '')));
                  $isAdvBooking = ($source === 'adv_bot');
                  $clientId = (int)($b['client_id'] ?? 0);
                  $isPfStatus = in_array($status, ['confirmed', 'in_work'], true);
                  $canOpenPersonal = ($clientId > 0 && $isPfStatus && ($isAdmin || $isManager || $sid === $uid));
                ?>
                <div class="calendar-booking calendar-booking--<?= h($status) ?>"
                     style="top: <?= (int)$top ?>px; height: <?= (int)$height ?>px;"
                     data-calendar-request-id="<?= (int)($b['id'] ?? 0) ?>"
                     data-calendar-modal="<?= h(url('/adm/index.php?m=requests&do=modal_view&id=' . (int)$b['id'] . '&return_url=' . urlencode((string)($_SERVER['REQUEST_URI'] ?? '')))) ?>">
                  <div class="calendar-booking__line">
                    <span class="calendar-booking__name"><?= h((string)($b['client_name'] ?? '')) ?></span>
                    <?php if ($isAdvBooking): ?>
                      <span class="calendar-booking__source" title="Бронь рекламы">AD</span>
                    <?php endif; ?>
                    <?php if ($visitTime !== ''): ?>
                      <span class="calendar-booking__time"><?= h($visitTime) ?></span>
                    <?php endif; ?>
                    <?php if ($canOpenPersonal): ?>
                      <a class="iconbtn iconbtn--sm calendar-booking__doc"
                         href="<?= h(url('/adm/index.php?m=personal_file&client_id=' . $clientId)) ?>"
                         data-calendar-client-link="1"
                         aria-label="Личное дело"
                         title="Личное дело">
                        <i class="bi bi-file-earmark-person"></i>
                      </a>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <?php if (!$isSpecialist): ?>
    <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
      <div class="card__body">
        <div class="calendar-hint">Режим специалиста доступен только для роли specialist.</div>
      </div>
    </div>
  <?php else: ?>
    <div class="calendar-board calendar-board--days">
      <?php for ($i = 0; $i < $days; $i++): ?>
        <?php
          $date = date('Y-m-d', strtotime($baseDate . ' +' . $i . ' day'));
          $schedule = calendar_get_schedule_day($pdo, $uid, $date);
          $bookings = $bookingsByDate[$date] ?? [];

          $gridHeight = 140;
          $interval = 30;
          $startMin = 0;
          $endMin = 0;
          $timeLabels = [];
          if ($schedule) {
            $startMin = calendar_time_to_minutes((string)$schedule['time_start']);
            $endMin = calendar_time_to_minutes((string)$schedule['time_end']);
            $totalSlots = (int)floor(($endMin - $startMin) / $interval);
            if ($totalSlots < 1) $totalSlots = 1;
            $gridHeight = $totalSlots * $slotHeight;
            for ($t = $startMin; $t < $endMin; $t += $interval) {
              $top = (int)round((($t - $startMin) / $interval) * $slotHeight);
              $timeLabels[] = [
                'top' => $top,
                'label' => calendar_minutes_to_time($t),
                'major' => ((int)($t % 60) === 0),
              ];
            }
          } elseif ($bookings) {
            $interval = 30;
            $minTime = null;
            $maxTime = null;
            foreach ($bookings as $b) {
              $visitAt = (string)($b['visit_at'] ?? '');
              if ($visitAt === '') continue;
              $t = calendar_time_to_minutes(date('H:i', strtotime($visitAt)));
              $dur = (int)($b['duration_min'] ?? 0);
              if ($dur <= 0) $dur = $interval;
              $from = $t;
              $to = $t + $dur;
              if ($minTime === null || $from < $minTime) $minTime = $from;
              if ($maxTime === null || $to > $maxTime) $maxTime = $to;
            }
            if ($minTime === null || $maxTime === null || $maxTime <= $minTime) {
              $minTime = 9 * 60;
              $maxTime = 18 * 60;
            }
            $startMin = $minTime;
            $endMin = $maxTime;
            $totalSlots = (int)floor(($endMin - $startMin) / $interval);
            if ($totalSlots < 1) $totalSlots = 1;
            $gridHeight = $totalSlots * $slotHeight;
            for ($t = $startMin; $t < $endMin; $t += $interval) {
              $top = (int)round((($t - $startMin) / $interval) * $slotHeight);
              $timeLabels[] = [
                'top' => $top,
                'label' => calendar_minutes_to_time($t),
                'major' => ((int)($t % 60) === 0),
              ];
            }
          }
        ?>
        <div class="calendar-col">
          <div class="calendar-col__head">
            <div class="calendar-col__title"><?= h($date) ?></div>
            <div class="calendar-col__sub"><?= $date === $today ? 'Сегодня' : '' ?></div>
          </div>
          <div class="calendar-col__body">
            <?php if (!$schedule && !$bookings): ?>
              <div class="calendar-grid__empty">Нет графика</div>
            <?php else: ?>
              <div class="calendar-grid calendar-grid--times" style="height: <?= (int)$gridHeight ?>px; --slot-h: <?= (int)$slotHeight ?>px;">
                <div class="calendar-grid__times">
                  <?php foreach ($timeLabels as $tl): ?>
                    <div class="calendar-grid__time <?= ($tl['major'] ?? false) ? 'calendar-grid__time--major' : 'calendar-grid__time--minor' ?>" style="top: <?= (int)$tl['top'] ?>px;"><?= h((string)$tl['label']) ?></div>
                  <?php endforeach; ?>
                </div>
                <?php foreach ($bookings as $b): ?>
                  <?php
                    $visitAt = (string)($b['visit_at'] ?? '');
                    $visitTime = $visitAt !== '' ? date('H:i', strtotime($visitAt)) : '';
                    $bStart = calendar_time_to_minutes($visitTime);
                    $dur = (int)($b['duration_min'] ?? 0);
                    if ($dur <= 0) $dur = $interval;
                    $top = (int)round((($bStart - $startMin) / $interval) * $slotHeight);
                    if ($top < 0) $top = 0;
                    $height = (int)round(($dur / $interval) * $slotHeight);
                    if ($height < 20) $height = 20;
                    $status = (string)($b['status'] ?? '');
                    $source = strtolower(trim((string)($b['source'] ?? '')));
                    $isAdvBooking = ($source === 'adv_bot');
                    $clientId = (int)($b['client_id'] ?? 0);
                    $isPfStatus = in_array($status, ['confirmed', 'in_work'], true);
                    $canOpenPersonal = ($clientId > 0 && $isPfStatus && ($isAdmin || $isManager || $isSpecialist));
                  ?>
                  <div class="calendar-booking calendar-booking--<?= h($status) ?>"
                       style="top: <?= (int)$top ?>px; height: <?= (int)$height ?>px;"
                       data-calendar-request-id="<?= (int)($b['id'] ?? 0) ?>"
                      data-calendar-modal="<?= h(url('/adm/index.php?m=requests&do=modal_view&id=' . (int)$b['id'] . '&return_url=' . urlencode((string)($_SERVER['REQUEST_URI'] ?? '')))) ?>">
                    <div class="calendar-booking__line">
                      <span class="calendar-booking__name"><?= h((string)($b['client_name'] ?? '')) ?></span>
                      <?php if ($isAdvBooking): ?>
                        <span class="calendar-booking__source" title="Бронь рекламы">AD</span>
                      <?php endif; ?>
                      <?php if ($visitTime !== ''): ?>
                        <span class="calendar-booking__time"><?= h($visitTime) ?></span>
                      <?php endif; ?>
                      <?php if ($canOpenPersonal): ?>
                        <a class="iconbtn iconbtn--sm calendar-booking__doc"
                           href="<?= h(url('/adm/index.php?m=personal_file&client_id=' . $clientId)) ?>"
                           data-calendar-client-link="1"
                           aria-label="Личное дело"
                           title="Личное дело">
                          <i class="bi bi-file-earmark-person"></i>
                        </a>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
  </div>
</div>

<script src="<?= h(url('/adm/modules/requests/assets/js/main.js')) ?>"></script>
<script src="<?= h(url('/adm/modules/calendar/assets/js/main.js')) ?>"></script>
