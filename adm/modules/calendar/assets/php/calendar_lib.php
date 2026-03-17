<?php
/**
 * FILE: /adm/modules/calendar/assets/php/calendar_lib.php
 * ROLE: Вспомогательные функции модуля calendar
 * NOTES:
 *  - Только функции (процедурный стиль)
 *  - Префикс calendar_
 */

declare(strict_types=1);

/**
 * calendar_time_to_minutes()
 * Переводит время HH:MM в минуты от начала суток.
 *
 * @param string $time
 * @return int
 */
function calendar_time_to_minutes(string $time): int
{
  $time = trim($time);
  if ($time === '' || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) {
    return 0;
  }

  $parts = explode(':', $time);
  $h = (int)($parts[0] ?? 0);
  $m = (int)($parts[1] ?? 0);
  if ($h < 0) $h = 0;
  if ($m < 0) $m = 0;

  return ($h * 60) + $m;
}

/**
 * calendar_minutes_to_time()
 * Переводит минуты от начала суток в строку HH:MM.
 *
 * @param int $minutes
 * @return string
 */
function calendar_minutes_to_time(int $minutes): string
{
  if ($minutes < 0) $minutes = 0;
  $h = (int)floor($minutes / 60);
  $m = $minutes % 60;

  return str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT);
}

/**
 * calendar_weekday()
 * Возвращает номер дня недели (1..7, где 1 = понедельник).
 *
 * @param string $date Y-m-d
 * @return int
 */
function calendar_weekday(string $date): int
{
  $ts = strtotime($date);
  if ($ts === false) return 0;
  return (int)date('N', $ts);
}

/**
 * calendar_parse_breaks()
 * Парсит JSON перерывов в массив интервалов.
 *
 * Формат:
 * [
 *   {"from":"13:00","to":"14:00"},
 *   ...
 * ]
 *
 * @param string|null $raw
 * @return array<int,array{from:int,to:int}>
 */
function calendar_parse_breaks(?string $raw): array
{
  if ($raw === null || trim($raw) === '') return [];

  $arr = json_decode($raw, true);
  if (!is_array($arr)) return [];

  $out = [];
  foreach ($arr as $row) {
    $from = calendar_time_to_minutes((string)($row['from'] ?? ''));
    $to = calendar_time_to_minutes((string)($row['to'] ?? ''));
    if ($to <= $from) continue;
    $out[] = ['from' => $from, 'to' => $to];
  }

  return $out;
}

/**
 * calendar_get_schedule_day()
 * Возвращает расписание специалиста на конкретную дату.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param string $date Y-m-d
 * @return array<string,mixed>
 */
function calendar_get_schedule_day(PDO $pdo, int $userId, string $date): array
{
  $weekday = calendar_weekday($date);
  if ($weekday <= 0 || $userId <= 0) {
    return [];
  }

  $row = null;
  try {
    $st = $pdo->prepare("
      SELECT
        time_start,
        time_end,
        break_start,
        break_end,
        lead_minutes,
        is_day_off
      FROM " . CALENDAR_SCHEDULE_TABLE . "
      WHERE user_id = :uid AND weekday = :wd
      LIMIT 1
    ");
    $st->execute([
      ':uid' => $userId,
      ':wd' => $weekday,
    ]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $st = $pdo->prepare("
      SELECT
        time_start,
        time_end,
        lead_minutes,
        breaks_json,
        is_day_off
      FROM " . CALENDAR_SCHEDULE_TABLE . "
      WHERE user_id = :uid AND weekday = :wd
      LIMIT 1
    ");
    $st->execute([
      ':uid' => $userId,
      ':wd' => $weekday,
    ]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  }
  if (!$row) return [];

  $isDayOff = (int)($row['is_day_off'] ?? 0);
  if ($isDayOff === 1) return [];

  $timeStart = (string)($row['time_start'] ?? '');
  $timeEnd = (string)($row['time_end'] ?? '');
  if ($timeStart === '' || $timeEnd === '') return [];

  $leadMinutes = (int)($row['lead_minutes'] ?? 0);
  if ($leadMinutes < 0) $leadMinutes = 0;

  $breaks = [];
  $breakStart = (string)($row['break_start'] ?? '');
  $breakEnd = (string)($row['break_end'] ?? '');
  if ($breakStart !== '' && $breakEnd !== '') {
    $bs = calendar_time_to_minutes($breakStart);
    $be = calendar_time_to_minutes($breakEnd);
    if ($be > $bs) {
      $breaks[] = ['from' => $bs, 'to' => $be];
    }
  }

  if (isset($row['breaks_json'])) {
    $breaksJson = calendar_parse_breaks((string)($row['breaks_json'] ?? ''));
    if ($breaksJson) {
      $breaks = array_merge($breaks, $breaksJson);
    }
  }

  return [
    'time_start' => $timeStart,
    'time_end' => $timeEnd,
    'lead_minutes' => $leadMinutes,
    'breaks' => $breaks,
  ];
}

/**
 * calendar_get_schedule_day_state()
 * Возвращает признак наличия строки расписания и выходного на дату.
 *
 * @param PDO $pdo
 * @param int $userId
 * @param string $date Y-m-d
 * @return array{has_row:bool,is_day_off:bool}
 */
function calendar_get_schedule_day_state(PDO $pdo, int $userId, string $date): array
{
  $weekday = calendar_weekday($date);
  if ($weekday <= 0 || $userId <= 0) {
    return [
      'has_row' => false,
      'is_day_off' => false,
    ];
  }

  $row = null;
  try {
    $st = $pdo->prepare("
      SELECT is_day_off
      FROM " . CALENDAR_SCHEDULE_TABLE . "
      WHERE user_id = :uid AND weekday = :wd
      LIMIT 1
    ");
    $st->execute([
      ':uid' => $userId,
      ':wd' => $weekday,
    ]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    try {
      $st = $pdo->prepare("
        SELECT 0 AS is_day_off
        FROM " . CALENDAR_SCHEDULE_TABLE . "
        WHERE user_id = :uid AND weekday = :wd
        LIMIT 1
      ");
      $st->execute([
        ':uid' => $userId,
        ':wd' => $weekday,
      ]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
      $row = null;
    }
  }

  if (!$row) {
    return [
      'has_row' => false,
      'is_day_off' => false,
    ];
  }

  return [
    'has_row' => true,
    'is_day_off' => ((int)($row['is_day_off'] ?? 0) === 1),
  ];
}

/**
 * calendar_get_bookings_day()
 * Загружает заявки специалиста на дату (только с visit_at).
 *
 * @param PDO $pdo
 * @param int $userId
 * @param string $date Y-m-d
 * @param int|null $excludeRequestId
 * @return array<int,array<string,mixed>>
 */
function calendar_get_bookings_day(PDO $pdo, int $userId, string $date, ?int $excludeRequestId = null): array
{
  if ($userId <= 0 || $date === '') return [];

  $sql = "
    SELECT
      id,
      status,
      source,
      client_id,
      client_name,
      visit_at,
      duration_min
    FROM " . CALENDAR_REQUESTS_TABLE . "
    WHERE specialist_user_id = :uid
      AND visit_at IS NOT NULL
      AND DATE(visit_at) = :d
      AND archived_at IS NULL
  ";
  $params = [
    ':uid' => $userId,
    ':d' => $date,
  ];

  $excludeId = (int)($excludeRequestId ?? 0);
  if ($excludeId > 0) {
    $sql .= " AND id <> :exclude_id";
    $params[':exclude_id'] = $excludeId;
  }

  $sql .= " ORDER BY visit_at ASC, id ASC";

  $st = $pdo->prepare($sql);
  $st->execute($params);

  return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * calendar_slots_build()
 * Строит список свободных слотов по расписанию и заявкам.
 *
 * @param array<string,mixed> $schedule
 * @param array<int,array<string,mixed>> $bookings
 * @param string $date Y-m-d
 * @param int $durationMin
 * @return array<int,string>
 */
function calendar_slots_build(array $schedule, array $bookings, string $date, int $durationMin = 0): array
{
  if (!$schedule) return [];

  $startMin = calendar_time_to_minutes((string)$schedule['time_start']);
  $endMin = calendar_time_to_minutes((string)$schedule['time_end']);
  $interval = 15;
  $duration = $durationMin > 0 ? $durationMin : 30;
  $lead = (int)($schedule['lead_minutes'] ?? 0);
  $breaks = (array)($schedule['breaks'] ?? []);

  if ($interval <= 0) $interval = 15;
  if ($duration <= 0) $duration = 30;
  if ($endMin <= $startMin) return [];

  $busy = [];
  foreach ($bookings as $b) {
    $visitAt = (string)($b['visit_at'] ?? '');
    if ($visitAt === '') continue;
    $t = date('H:i', strtotime($visitAt));
    $bStart = calendar_time_to_minutes($t);
    $bDur = (int)($b['duration_min'] ?? 0);
    if ($bDur <= 0) $bDur = 30;
    $busyFrom = $bStart - max(0, $lead);
    if ($busyFrom < 0) $busyFrom = 0;
    $busyTo = $bStart + $bDur + max(0, $lead);
    $busy[] = [
      'from' => $busyFrom,
      'to' => $busyTo,
    ];
  }

  $nowCut = null;
  if ($date === date('Y-m-d')) {
    $nowCut = (int)date('H') * 60 + (int)date('i') + max(0, $lead);
  }

  $slots = [];
  for ($t = $startMin; $t + $duration <= $endMin; $t += $interval) {
    $slotFrom = $t;
    $slotTo = $t + $duration;

    if ($nowCut !== null && $slotFrom < $nowCut) {
      continue;
    }

    $skip = false;
    foreach ($breaks as $br) {
      if ($slotFrom < $br['to'] && $slotTo > $br['from']) {
        $skip = true;
        break;
      }
    }
    if ($skip) continue;

    foreach ($busy as $b) {
      if ($slotFrom < $b['to'] && $slotTo > $b['from']) {
        $skip = true;
        break;
      }
    }
    if ($skip) continue;

    $slots[] = calendar_minutes_to_time($slotFrom);
  }

  return $slots;
}
