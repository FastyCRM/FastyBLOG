<?php
/**
 * FILE: /adm/modules/calendar/assets/php/api/calendar_api_slots_nearest.php
 * ROLE: api_slots_nearest — ближайшая дата со слотами
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../calendar_lib.php';

/**
 * $pdo — БД
 */
$pdo = db();

/**
 * $specialistId — специалист
 */
$specialistId = (int)($_GET['specialist_id'] ?? 0);
/**
 * $startDate — дата старта (Y-m-d)
 */
$startDate = trim((string)($_GET['date'] ?? ''));
/**
 * $durationMin — длительность записи (мин)
 */
$durationMin = (int)($_GET['duration_min'] ?? 0);
/**
 * $excludeRequestId — заявка, которую исключаем из занятости (при редактировании)
 */
$excludeRequestId = (int)($_GET['exclude_request_id'] ?? 0);
/**
 * $horizon — сколько дней вперёд искать
 */
$horizon = (int)($_GET['horizon'] ?? 14);
if ($horizon <= 0) $horizon = 14;
if ($horizon > 60) $horizon = 60;

if ($specialistId <= 0 || $startDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
  json_err('Bad params', 400);
}

$today = date('Y-m-d');
if ($startDate < $today) {
  $startDate = $today;
}

/**
 * $nearestDate — ближайшая дата
 */
$nearestDate = null;
/**
 * $nearestSlots — слоты
 */
$nearestSlots = [];
/**
 * $lastReason — последняя причина отсутствия слотов
 */
$lastReason = 'no_slots';
/**
 * $lastMessage — последняя причина в человекочитаемом виде
 */
$lastMessage = 'Нет свободных слотов';

for ($i = 0; $i <= $horizon; $i++) {
  $date = date('Y-m-d', strtotime($startDate . ' +' . $i . ' day'));
  $schedule = calendar_get_schedule_day($pdo, $specialistId, $date);
  if (!$schedule) {
    $state = calendar_get_schedule_day_state($pdo, $specialistId, $date);
    if (!empty($state['is_day_off'])) {
      $lastReason = 'day_off';
      $lastMessage = 'У специалиста выходной в выбранную дату';
    } else {
      $lastReason = 'no_schedule';
      $lastMessage = 'Нет рабочего расписания на выбранную дату';
    }
    continue;
  }

  $bookings = calendar_get_bookings_day($pdo, $specialistId, $date, $excludeRequestId > 0 ? $excludeRequestId : null);
  $slots = calendar_slots_build($schedule, $bookings, $date, $durationMin);
  if ($slots) {
    $nearestDate = $date;
    $nearestSlots = $slots;
    $lastReason = 'ok';
    $lastMessage = '';
    break;
  }

  $lastReason = 'no_slots';
  $lastMessage = 'Нет свободных слотов на выбранную дату';
}

json_ok([
  'specialist_id' => $specialistId,
  'start_date' => $startDate,
  'nearest_date' => $nearestDate,
  'duration_min' => ($durationMin > 0 ? $durationMin : 30),
  'slots' => $nearestSlots,
  'reason' => $lastReason,
  'message' => $lastMessage,
]);
