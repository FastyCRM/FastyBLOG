<?php
/**
 * FILE: /adm/modules/calendar/assets/php/api/calendar_api_slots_day.php
 * ROLE: api_slots_day — свободные слоты на дату
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
 * $date — дата (Y-m-d)
 */
$date = trim((string)($_GET['date'] ?? ''));
/**
 * $durationMin — длительность записи (мин)
 */
$durationMin = (int)($_GET['duration_min'] ?? 0);
/**
 * $excludeRequestId — заявка, которую исключаем из занятости (при редактировании)
 */
$excludeRequestId = (int)($_GET['exclude_request_id'] ?? 0);

if ($specialistId <= 0 || $date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  json_err('Bad params', 400);
}

if ($date < date('Y-m-d')) {
  json_ok([
    'specialist_id' => $specialistId,
    'date' => $date,
    'slots' => [],
    'has_schedule' => false,
    'reason' => 'past_date',
    'message' => 'Запись на прошедшую дату недоступна',
  ]);
}

/**
 * $schedule — расписание дня
 */
$schedule = calendar_get_schedule_day($pdo, $specialistId, $date);
if (!$schedule) {
  $state = calendar_get_schedule_day_state($pdo, $specialistId, $date);
  $reason = 'no_schedule';
  $message = 'Нет рабочего расписания на выбранную дату';
  if (!empty($state['is_day_off'])) {
    $reason = 'day_off';
    $message = 'У специалиста выходной в выбранную дату';
  }

  json_ok([
    'specialist_id' => $specialistId,
    'date' => $date,
    'slots' => [],
    'has_schedule' => false,
    'reason' => $reason,
    'message' => $message,
  ]);
}

/**
 * $bookings — заявки дня
 */
$bookings = calendar_get_bookings_day($pdo, $specialistId, $date, $excludeRequestId > 0 ? $excludeRequestId : null);

/**
 * $slots — свободные слоты
 */
$slots = calendar_slots_build($schedule, $bookings, $date, $durationMin);
$durationOut = $durationMin > 0 ? $durationMin : 30;

json_ok([
  'specialist_id' => $specialistId,
  'date' => $date,
  'slots' => $slots,
  'has_schedule' => true,
  'reason' => ($slots ? 'ok' : 'no_slots'),
  'message' => ($slots ? '' : 'Нет свободных слотов на выбранную дату'),
  'duration_min' => $durationOut,
  'time_start' => (string)$schedule['time_start'],
  'time_end' => (string)$schedule['time_end'],
  'lead_minutes' => (int)$schedule['lead_minutes'],
]);
