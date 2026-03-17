<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_lib.php
 * ROLE: Вспомогательные функции модуля requests
 * NOTES:
 *  - Только функции (процедурный стиль)
 *  - Префикс requests_
 */

declare(strict_types=1);

/**
 * requests_norm_phone()
 * Нормализует телефон к формату 7XXXXXXXXXX (если возможно).
 *
 * @param string $phone
 * @return string
 */
function requests_norm_phone(string $phone): string
{
  // $digits — только цифры
  $digits = preg_replace('~\D+~', '', $phone) ?? '';

  // Если 11 цифр и начинается с 8 — заменяем на 7
  if (strlen($digits) === 11 && $digits[0] === '8') {
    $digits = '7' . substr($digits, 1);
  }

  // Если 10 цифр — дописываем 7 в начало
  if (strlen($digits) === 10) {
    $digits = '7' . $digits;
  }

  return $digits;
}

/**
 * requests_settings_get()
 * Возвращает настройки модуля из БД.
 *
 * @param PDO $pdo
 * @return array{use_specialists:int,use_time_slots:int}
 */
function requests_settings_get(PDO $pdo): array
{
  // $row — строка настроек
  $row = null;

  try {
    // $st — запрос настроек
    $st = $pdo->query("SELECT use_specialists, use_time_slots FROM " . REQUESTS_SETTINGS_TABLE . " WHERE id = 1 LIMIT 1");
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
  } catch (Throwable $e) {
    // Игнорируем — вернём дефолт
  }

  if (!$row) {
    // Дефолт и попытка создать строку
    try {
      $pdo->prepare("INSERT INTO " . REQUESTS_SETTINGS_TABLE . " (id, use_specialists, use_time_slots) VALUES (1, 0, 0)")
          ->execute();
    } catch (Throwable $e) {
      // Игнор
    }

    return ['use_specialists' => 0, 'use_time_slots' => 0];
  }

  // $useSpecialists — флаг режима специалистов
  $useSpecialists = (int)($row['use_specialists'] ?? 0);
  // $useTimeSlots — флаг интервального режима
  $useTimeSlots = (int)($row['use_time_slots'] ?? 0);

  return ['use_specialists' => $useSpecialists, 'use_time_slots' => $useTimeSlots];
}

/**
 * requests_service_duration()
 * Возвращает длительность услуги (мин). Если нет данных — 30.
 *
 * @param PDO $pdo
 * @param int $serviceId
 * @return int
 */
function requests_service_duration(PDO $pdo, int $serviceId): int
{
  if ($serviceId <= 0) return 30;

  try {
    $st = $pdo->prepare("SELECT duration_min FROM " . REQUESTS_SERVICES_TABLE . " WHERE id = :id LIMIT 1");
    $st->execute([':id' => $serviceId]);
    $dur = (int)($st->fetchColumn() ?: 0);
    if ($dur > 0) return $dur;
  } catch (Throwable $e) {
    // игнор
  }

  return 30;
}

/**
 * requests_service_duration_map()
 * Возвращает карту длительностей по id услуг.
 *
 * @param PDO $pdo
 * @param array<int,int> $serviceIds
 * @return array<int,int>
 */
function requests_service_duration_map(PDO $pdo, array $serviceIds): array
{
  $ids = [];
  foreach ($serviceIds as $sid) {
    $sid = (int)$sid;
    if ($sid > 0) $ids[] = $sid;
  }
  $ids = array_values(array_unique($ids));
  if (!$ids) return [];

  $map = [];
  try {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, duration_min FROM " . REQUESTS_SERVICES_TABLE . " WHERE id IN ($in)");
    $st->execute($ids);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $id = (int)($row['id'] ?? 0);
      if ($id <= 0) continue;
      $dur = (int)($row['duration_min'] ?? 0);
      if ($dur <= 0) $dur = 30;
      $map[$id] = $dur;
    }
  } catch (Throwable $e) {
    // игнор
  }

  return $map;
}

/**
 * requests_items_duration()
 * Считает суммарную длительность по позициям (по длительности услуги).
 *
 * @param PDO $pdo
 * @param array<int,array<string,mixed>> $items
 * @return int
 */
function requests_items_duration(PDO $pdo, array $items): int
{
  if (!$items) return 30;

  $ids = [];
  foreach ($items as $it) {
    $sid = (int)($it['service_id'] ?? 0);
    if ($sid > 0) $ids[] = $sid;
  }
  $map = requests_service_duration_map($pdo, $ids);

  $total = 0;
  foreach ($items as $it) {
    $qty = (int)($it['qty'] ?? 1);
    if ($qty <= 0) $qty = 1;
    $sid = (int)($it['service_id'] ?? 0);
    $dur = $sid > 0 ? (int)($map[$sid] ?? 30) : 30;
    if ($dur <= 0) $dur = 30;
    $total += $dur * $qty;
  }

  if ($total <= 0) $total = 30;
  return $total;
}

/**
 * requests_time_to_minutes()
 * Переводит время HH:MM в минуты от начала суток.
 *
 * @param string $time
 * @return int
 */
function requests_time_to_minutes(string $time): int
{
  /**
   * $time — очищенная строка времени
   */
  $time = trim($time);
  if ($time === '' || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time)) {
    return 0;
  }

  /**
   * $parts — части времени
   */
  $parts = explode(':', $time);
  /**
   * $h — часы
   */
  $h = (int)($parts[0] ?? 0);
  /**
   * $m — минуты
   */
  $m = (int)($parts[1] ?? 0);

  if ($h < 0) $h = 0;
  if ($h > 23) $h = 23;
  if ($m < 0) $m = 0;
  if ($m > 59) $m = 59;

  return ($h * 60) + $m;
}

/**
 * requests_minutes_intersect()
 * Проверяет пересечение двух интервалов минут.
 *
 * @param int $fromA
 * @param int $toA
 * @param int $fromB
 * @param int $toB
 * @return bool
 */
function requests_minutes_intersect(int $fromA, int $toA, int $fromB, int $toB): bool
{
  return ($fromA < $toB && $toA > $fromB);
}

/**
 * requests_schedule_get_day()
 * Возвращает расписание специалиста на дату.
 *
 * @param PDO $pdo
 * @param int $specialistId
 * @param string $date
 * @return array<string,mixed>
 */
function requests_schedule_get_day(PDO $pdo, int $specialistId, string $date): array
{
  if ($specialistId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    return [];
  }

  /**
   * $weekday — номер дня недели (1..7)
   */
  $weekday = (int)date('N', strtotime($date));
  if ($weekday < 1 || $weekday > 7) {
    return [];
  }

  try {
    /**
     * $st — запрос расписания
     */
    $st = $pdo->prepare("
      SELECT
        time_start,
        time_end,
        break_start,
        break_end,
        lead_minutes,
        is_day_off
      FROM " . REQUESTS_SCHEDULE_TABLE . "
      WHERE user_id = :uid AND weekday = :wd
      LIMIT 1
    ");
    $st->execute([
      ':uid' => $specialistId,
      ':wd' => $weekday,
    ]);

    /**
     * $row — строка расписания
     */
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      return [];
    }

    /**
     * $isDayOff — флаг выходного
     */
    $isDayOff = (int)($row['is_day_off'] ?? 0);
    if ($isDayOff === 1) {
      return [];
    }

    /**
     * $timeStart — начало работы
     */
    $timeStart = trim((string)($row['time_start'] ?? ''));
    /**
     * $timeEnd — конец работы
     */
    $timeEnd = trim((string)($row['time_end'] ?? ''));
    if ($timeStart === '' || $timeEnd === '') {
      return [];
    }

    /**
     * $leadMinutes — перерыв между приёмами
     */
    $leadMinutes = (int)($row['lead_minutes'] ?? 0);
    if ($leadMinutes < 0) $leadMinutes = 0;

    /**
     * $breaks — массив обеденных интервалов
     */
    $breaks = [];

    /**
     * $breakStart — начало обеда
     */
    $breakStart = trim((string)($row['break_start'] ?? ''));
    /**
     * $breakEnd — конец обеда
     */
    $breakEnd = trim((string)($row['break_end'] ?? ''));

    if ($breakStart !== '' && $breakEnd !== '') {
      /**
       * $bs — начало обеда в минутах
       */
      $bs = requests_time_to_minutes($breakStart);
      /**
       * $be — конец обеда в минутах
       */
      $be = requests_time_to_minutes($breakEnd);
      if ($be > $bs) {
        $breaks[] = [
          'from' => $bs,
          'to' => $be,
        ];
      }
    }

    return [
      'time_start' => $timeStart,
      'time_end' => $timeEnd,
      'lead_minutes' => $leadMinutes,
      'breaks' => $breaks,
    ];
  } catch (Throwable $e) {
    return [];
  }
}

/**
 * requests_bookings_get_day()
 * Возвращает интервалы занятости специалиста на дату.
 *
 * @param PDO $pdo
 * @param int $specialistId
 * @param string $date
 * @param int|null $excludeRequestId
 * @return array<int,array<string,int>>
 */
function requests_bookings_get_day(PDO $pdo, int $specialistId, string $date, ?int $excludeRequestId = null): array
{
  if ($specialistId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    return [];
  }

  /**
   * $sql — базовый SQL заявок дня
   */
  $sql = "
    SELECT
      id,
      visit_at,
      duration_min
    FROM " . REQUESTS_TABLE . "
    WHERE specialist_user_id = :uid
      AND visit_at IS NOT NULL
      AND DATE(visit_at) = :d
      AND archived_at IS NULL
  ";

  /**
   * $params — параметры запроса
   */
  $params = [
    ':uid' => $specialistId,
    ':d' => $date,
  ];

  /**
   * $excludeId — id заявки, которую игнорируем
   */
  $excludeId = (int)($excludeRequestId ?? 0);
  if ($excludeId > 0) {
    $sql .= " AND id <> :exclude_id";
    $params[':exclude_id'] = $excludeId;
  }

  $sql .= " ORDER BY visit_at ASC, id ASC";

  try {
    /**
     * $st — запрос заявок дня
     */
    $st = $pdo->prepare($sql);
    $st->execute($params);

    /**
     * $rows — сырые строки
     */
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    /**
     * $intervals — интервалы занятости
     */
    $intervals = [];

    foreach ($rows as $row) {
      /**
       * $visitAt — дата/время записи
       */
      $visitAt = trim((string)($row['visit_at'] ?? ''));
      if ($visitAt === '') continue;

      /**
       * $start — время начала в минутах
       */
      $start = requests_time_to_minutes((string)date('H:i', strtotime($visitAt)));
      /**
       * $dur — длительность записи
       */
      $dur = (int)($row['duration_min'] ?? 0);
      if ($dur <= 0) $dur = 30;

      $intervals[] = [
        'id' => (int)($row['id'] ?? 0),
        'from' => $start,
        'to' => $start + $dur,
      ];
    }

    return $intervals;
  } catch (Throwable $e) {
    return [];
  }
}

/**
 * requests_slot_validate()
 * Проверяет доступность интервала для записи.
 *
 * @param PDO $pdo
 * @param int $specialistId
 * @param string $date
 * @param string $time
 * @param int $durationMin
 * @param int|null $excludeRequestId
 * @return array<string,mixed>
 */
function requests_slot_validate(
  PDO $pdo,
  int $specialistId,
  string $date,
  string $time,
  int $durationMin = 0,
  ?int $excludeRequestId = null
): array {
  if ($specialistId <= 0) {
    return [
      'ok' => false,
      'reason' => 'bad_specialist',
      'message' => 'Некорректный специалист',
    ];
  }

  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
    return [
      'ok' => false,
      'reason' => 'bad_datetime',
      'message' => 'Некорректная дата или время',
    ];
  }

  /**
   * $duration — длительность новой записи
   */
  if ($date < date('Y-m-d')) {
    return [
      'ok' => false,
      'reason' => 'past_date',
      'message' => 'Запись на прошедшую дату недоступна',
    ];
  }

  $duration = (int)$durationMin;
  if ($duration <= 0) $duration = 30;

  /**
   * $schedule — расписание специалиста на дату
   */
  $schedule = requests_schedule_get_day($pdo, $specialistId, $date);
  if (!$schedule) {
    return [
      'ok' => false,
      'reason' => 'no_schedule',
      'message' => 'У специалиста нет рабочего интервала на эту дату',
    ];
  }

  /**
   * $workFrom — старт рабочего дня
   */
  $workFrom = requests_time_to_minutes((string)($schedule['time_start'] ?? ''));
  /**
   * $workTo — конец рабочего дня
   */
  $workTo = requests_time_to_minutes((string)($schedule['time_end'] ?? ''));
  if ($workTo <= $workFrom) {
    return [
      'ok' => false,
      'reason' => 'bad_schedule',
      'message' => 'Некорректное расписание специалиста',
    ];
  }

  /**
   * $slotFrom — начало новой записи
   */
  $slotFrom = requests_time_to_minutes($time);
  /**
   * $slotTo — конец новой записи
   */
  $slotTo = $slotFrom + $duration;

  if ($slotFrom < $workFrom || $slotTo > $workTo) {
    return [
      'ok' => false,
      'reason' => 'out_of_work_time',
      'message' => 'Время вне рабочего интервала специалиста',
    ];
  }

  /**
   * $lead — перерыв между приёмами
   */
  $lead = (int)($schedule['lead_minutes'] ?? 0);
  if ($lead < 0) $lead = 0;

  if ($date === date('Y-m-d')) {
    /**
     * $nowCut — минимально допустимое время старта сегодня
     */
    $nowCut = ((int)date('H') * 60) + (int)date('i') + $lead;
    if ($slotFrom < $nowCut) {
      return [
        'ok' => false,
        'reason' => 'past_time',
        'message' => 'Слот уже недоступен по времени',
      ];
    }
  }

  /**
   * $breaks — обеденные перерывы
   */
  $breaks = (array)($schedule['breaks'] ?? []);
  foreach ($breaks as $br) {
    /**
     * $brFrom — начало обеда
     */
    $brFrom = (int)($br['from'] ?? 0);
    /**
     * $brTo — конец обеда
     */
    $brTo = (int)($br['to'] ?? 0);
    if ($brTo <= $brFrom) continue;

    if (requests_minutes_intersect($slotFrom, $slotTo, $brFrom, $brTo)) {
      return [
        'ok' => false,
        'reason' => 'break_time',
        'message' => 'Интервал пересекается с обеденным перерывом',
      ];
    }
  }

  /**
   * $bookings — интервалы существующих записей
   */
  $bookings = requests_bookings_get_day($pdo, $specialistId, $date, $excludeRequestId);
  foreach ($bookings as $b) {
    /**
     * $busyFrom — занятость с учётом буфера до записи
     */
    $busyFrom = (int)($b['from'] ?? 0) - $lead;
    if ($busyFrom < 0) $busyFrom = 0;
    /**
     * $busyTo — занятость с учётом буфера после записи
     */
    $busyTo = (int)($b['to'] ?? 0) + $lead;

    if (requests_minutes_intersect($slotFrom, $slotTo, $busyFrom, $busyTo)) {
      return [
        'ok' => false,
        'reason' => 'busy',
        'message' => 'Это время занято или нарушает перерыв между приёмами',
      ];
    }
  }

  return [
    'ok' => true,
    'reason' => 'ok',
    'message' => '',
  ];
}

/**
 * requests_slot_key()
 * Собирает уникальный ключ слота по специалисту и времени.
 *
 * @param int $specialistId
 * @param string|null $visitAt
 * @return string|null
 */
function requests_slot_key(int $specialistId, ?string $visitAt): ?string
{
  if ($specialistId <= 0 || $visitAt === null || trim($visitAt) === '') {
    return null;
  }

  $ts = strtotime($visitAt);
  if ($ts === false) {
    return null;
  }

  $date = date('Y-m-d', $ts);
  $time = date('H:i', $ts);

  return $specialistId . '|' . $date . '|' . $time;
}

/**
 * requests_is_admin()
 * Проверяет роль admin в массиве ролей.
 *
 * @param array<int,string> $roles
 * @return bool
 */
function requests_is_admin(array $roles): bool
{
  return in_array('admin', $roles, true);
}

/**
 * requests_is_manager()
 * Проверяет роль manager в массиве ролей.
 *
 * @param array<int,string> $roles
 * @return bool
 */
function requests_is_manager(array $roles): bool
{
  return in_array('manager', $roles, true);
}

/**
 * requests_is_specialist()
 * Проверяет роль specialist в массиве ролей.
 *
 * @param array<int,string> $roles
 * @return bool
 */
function requests_is_specialist(array $roles): bool
{
  return in_array('specialist', $roles, true);
}

/**
 * requests_add_history()
 * Добавляет запись в историю заявки.
 *
 * @param PDO $pdo
 * @param int $requestId
 * @param int|null $userId
 * @param string $action
 * @param string|null $fromStatus
 * @param string|null $toStatus
 * @param array $payload
 * @return void
 */
function requests_add_history(
  PDO $pdo,
  int $requestId,
  ?int $userId,
  string $action,
  ?string $fromStatus,
  ?string $toStatus,
  array $payload = []
): void {
  try {
    $pdo->prepare("\n      INSERT INTO " . REQUESTS_HISTORY_TABLE . "\n        (request_id, user_id, action, from_status, to_status, payload, created_at)\n      VALUES\n        (:rid, :uid, :action, :from_s, :to_s, :payload, NOW())\n    ")->execute([
      ':rid' => $requestId,
      ':uid' => $userId,
      ':action' => $action,
      ':from_s' => $fromStatus,
      ':to_s' => $toStatus,
      ':payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
  } catch (Throwable $e) {
    // Игнор
  }
}

/**
 * requests_add_comment()
 * Добавляет комментарий по заявке.
 *
 * @param PDO $pdo
 * @param int $requestId
 * @param int|null $userId
 * @param int|null $clientId
 * @param string $authorType
 * @param string $text
 * @return void
 */
function requests_add_comment(
  PDO $pdo,
  int $requestId,
  ?int $userId,
  ?int $clientId,
  string $authorType,
  string $text
): void {
  try {
    $pdo->prepare("\n      INSERT INTO " . REQUESTS_COMMENTS_TABLE . "\n        (request_id, user_id, client_id, author_type, comment, created_at)\n      VALUES\n        (:rid, :uid, :cid, :atype, :comment, NOW())\n    ")->execute([
      ':rid' => $requestId,
      ':uid' => $userId,
      ':cid' => $clientId,
      ':atype' => $authorType,
      ':comment' => $text,
    ]);
  } catch (Throwable $e) {
    // Игнор
  }
}

/**
 * requests_find_or_create_client()
 * Ищет клиента по телефону, при отсутствии — создаёт.
 *
 * @param PDO $pdo
 * @param string $name
 * @param string $phone
 * @param string $email
 * @param string|null $tmpPass (out)
 * @return int ID клиента
 */
function requests_find_or_create_client(
  PDO $pdo,
  string $name,
  string $phone,
  string $email,
  ?string &$tmpPass = null
): int {
  // $tmpPass — сбрасываем
  $tmpPass = null;

  // $st — ищем клиента
  $st = $pdo->prepare("SELECT id FROM clients WHERE phone = ? LIMIT 1");
  $st->execute([$phone]);

  // $id — найденный клиент
  $id = (int)($st->fetchColumn() ?: 0);

  if ($id > 0) {
    return $id;
  }

  // $tmpPass — временный пароль
  $tmpPass = 'Crm' . bin2hex(random_bytes(4));

  // $passHash — хеш пароля
  $passHash = password_hash($tmpPass, PASSWORD_DEFAULT);

  // $firstName — имя клиента
  $firstName = trim($name);

  $pdo->prepare("\n    INSERT INTO clients\n      (first_name, last_name, middle_name, phone, email, pass_hash, pass_is_temp, status, created_at, updated_at)\n    VALUES\n      (:first_name, NULL, NULL, :phone, :email, :pass_hash, 1, 'active', NOW(), NOW())\n  ")->execute([
    ':first_name' => $firstName !== '' ? $firstName : 'Клиент',
    ':phone' => $phone,
    ':email' => ($email !== '' ? $email : null),
    ':pass_hash' => $passHash,
  ]);

  return (int)$pdo->lastInsertId();
}

/**
 * requests_tg_escape_html()
 * Экранирует текст для безопасной отправки в Telegram (parse_mode=HTML).
 *
 * @param string $value
 * @return string
 */
function requests_tg_escape_html(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * requests_tg_unique_ids()
 * Нормализует и дедуплицирует список ID.
 *
 * @param array<int,mixed> $ids
 * @return array<int,int>
 */
function requests_tg_unique_ids(array $ids): array
{
  /**
   * $out — итоговый список.
   */
  $out = [];
  foreach ($ids as $id) {
    $id = (int)$id;
    if ($id > 0) $out[] = $id;
  }
  $out = array_values(array_unique($out));
  return $out;
}

/**
 * requests_tg_format_visit_at()
 * Форматирует дату/время визита для текста уведомления.
 *
 * @param string|null $visitAt
 * @return string
 */
function requests_tg_format_visit_at(?string $visitAt): string
{
  $visitAt = trim((string)$visitAt);
  if ($visitAt === '') return '—';

  /**
   * $ts — timestamp визита.
   */
  $ts = strtotime($visitAt);
  if ($ts === false) return $visitAt;

  return date('d.m.Y H:i', $ts);
}

/**
 * requests_tg_status_label()
 * Возвращает человекочитаемую подпись статуса для уведомления.
 *
 * @param string $status
 * @return string
 */
function requests_tg_status_label(string $status): string
{
  $status = strtolower(trim($status));
  $map = [
    REQUESTS_STATUS_NEW => 'новая',
    REQUESTS_STATUS_CONFIRMED => 'подтверждена',
    REQUESTS_STATUS_IN_WORK => 'в работе',
    REQUESTS_STATUS_DONE => 'выполнена',
    REQUESTS_NOTIFY_STATUS_CHANGED => 'изменена',
    REQUESTS_NOTIFY_STATUS_RESCHEDULED => 'перенесена',
    REQUESTS_NOTIFY_STATUS_CANCELED => 'отменена',
  ];
  return (string)($map[$status] ?? $status);
}

/**
 * requests_tg_event_for_manager()
 * Возвращает event_code для менеджера/админа по статусу.
 *
 * @param string $statusTo
 * @return string|null
 */
function requests_tg_event_for_manager(string $statusTo): ?string
{
  $statusTo = strtolower(trim($statusTo));
  if ($statusTo === REQUESTS_STATUS_NEW) return REQUESTS_TG_EVENT_MGR_NEW;
  if ($statusTo === REQUESTS_STATUS_CONFIRMED) return REQUESTS_TG_EVENT_MGR_CONFIRMED;
  if ($statusTo === REQUESTS_STATUS_IN_WORK) return REQUESTS_TG_EVENT_MGR_ACCEPTED;
  if ($statusTo === REQUESTS_STATUS_DONE) return REQUESTS_TG_EVENT_MGR_DONE;
  if ($statusTo === REQUESTS_NOTIFY_STATUS_CHANGED) return REQUESTS_TG_EVENT_MGR_CHANGED;
  if ($statusTo === REQUESTS_NOTIFY_STATUS_RESCHEDULED) return REQUESTS_TG_EVENT_MGR_CHANGED;
  if ($statusTo === REQUESTS_NOTIFY_STATUS_CANCELED) return REQUESTS_TG_EVENT_MGR_CHANGED;
  return null;
}

/**
 * requests_tg_event_for_specialist()
 * Возвращает event_code для специалиста по статусу.
 *
 * @param string $statusTo
 * @return string|null
 */
function requests_tg_event_for_specialist(string $statusTo): ?string
{
  $statusTo = strtolower(trim($statusTo));
  if ($statusTo === REQUESTS_STATUS_NEW) return REQUESTS_TG_EVENT_SPEC_NEW;
  if ($statusTo === REQUESTS_STATUS_CONFIRMED) return REQUESTS_TG_EVENT_SPEC_CONFIRMED;
  if ($statusTo === REQUESTS_NOTIFY_STATUS_CHANGED) return REQUESTS_TG_EVENT_SPEC_CHANGED;
  if ($statusTo === REQUESTS_NOTIFY_STATUS_RESCHEDULED) return REQUESTS_TG_EVENT_SPEC_CHANGED;
  if ($statusTo === REQUESTS_NOTIFY_STATUS_CANCELED) return REQUESTS_TG_EVENT_SPEC_CHANGED;
  return null;
}

/**
 * requests_tg_event_for_client()
 * Возвращает event_code для клиента по статусу.
 *
 * @param string $statusTo
 * @return string|null
 */
function requests_tg_event_for_client(string $statusTo): ?string
{
  $statusTo = strtolower(trim($statusTo));
  if ($statusTo === REQUESTS_STATUS_CONFIRMED) return REQUESTS_TG_EVENT_CLIENT_CONFIRMED;
  if ($statusTo === REQUESTS_STATUS_IN_WORK) return REQUESTS_TG_EVENT_CLIENT_ACCEPTED;
  if ($statusTo === REQUESTS_STATUS_DONE) return REQUESTS_TG_EVENT_CLIENT_THANKS;
  if ($statusTo === REQUESTS_NOTIFY_STATUS_CHANGED) return REQUESTS_TG_EVENT_CLIENT_CHANGED;
  if ($statusTo === REQUESTS_NOTIFY_STATUS_RESCHEDULED) return REQUESTS_TG_EVENT_CLIENT_CHANGED;
  if ($statusTo === REQUESTS_NOTIFY_STATUS_CANCELED) return REQUESTS_TG_EVENT_CLIENT_CHANGED;
  return null;
}

/**
 * requests_tg_fetch_request_context()
 * Возвращает контекст заявки для построения текста уведомлений.
 *
 * @param PDO $pdo
 * @param int $requestId
 * @return array<string,mixed>
 */
function requests_tg_fetch_request_context(PDO $pdo, int $requestId): array
{
  if ($requestId <= 0) return [];

  /**
   * $st — запрос карточки заявки.
   */
  $st = $pdo->prepare("
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
      su.name AS specialist_name,
      r.visit_at,
      r.duration_min,
      r.price_total,
      r.taken_by,
      tu.name AS taken_by_name,
      r.done_by,
      du.name AS done_by_name,
      r.created_at,
      r.updated_at
    FROM " . REQUESTS_TABLE . " r
    LEFT JOIN " . REQUESTS_SERVICES_TABLE . " s ON s.id = r.service_id
    LEFT JOIN users su ON su.id = r.specialist_user_id
    LEFT JOIN users tu ON tu.id = r.taken_by
    LEFT JOIN users du ON du.id = r.done_by
    WHERE r.id = :id
    LIMIT 1
  ");
  $st->execute([':id' => $requestId]);

  /**
   * $row — данные заявки.
   */
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : [];
}

/**
 * requests_tg_users_by_roles()
 * Возвращает список активных пользователей по кодам ролей.
 *
 * @param PDO $pdo
 * @param array<int,string> $roleCodes
 * @return array<int,int>
 */
function requests_tg_users_by_roles(PDO $pdo, array $roleCodes): array
{
  $roleCodes = array_values(array_unique(array_filter(array_map('trim', $roleCodes), static function ($v) {
    return $v !== '';
  })));
  if (!$roleCodes) return [];

  /**
   * $in — placeholder для IN.
   */
  $in = implode(',', array_fill(0, count($roleCodes), '?'));
  /**
   * $st — запрос пользователей.
   */
  $st = $pdo->prepare("
    SELECT DISTINCT u.id
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id
    WHERE u.status = 'active'
      AND r.code IN ($in)
  ");
  $st->execute($roleCodes);

  /**
   * $ids — список user_id.
   */
  $ids = $st->fetchAll(PDO::FETCH_COLUMN, 0);
  if (!is_array($ids)) $ids = [];
  return requests_tg_unique_ids($ids);
}

/**
 * requests_tg_filter_targets()
 * Фильтрует target user_id: исключает actor и уже уведомленных.
 *
 * @param array<int,int> $targetIds
 * @param int $actorUserId
 * @param bool $skipActor
 * @param array<int,int> $alreadyNotified
 * @return array<int,int>
 */
function requests_tg_filter_targets(array $targetIds, int $actorUserId, bool $skipActor, array $alreadyNotified = []): array
{
  $targetIds = requests_tg_unique_ids($targetIds);
  $already = array_fill_keys(requests_tg_unique_ids($alreadyNotified), true);

  /**
   * $out — итоговый список получателей.
   */
  $out = [];
  foreach ($targetIds as $targetId) {
    if ($skipActor && $actorUserId > 0 && $targetId === $actorUserId) {
      continue;
    }
    if (isset($already[$targetId])) {
      continue;
    }
    $out[] = $targetId;
  }

  return requests_tg_unique_ids($out);
}

/**
 * requests_tg_build_message()
 * Формирует текст уведомления в HTML-режиме Telegram.
 *
 * @param array<string,mixed> $context
 * @param string $title
 * @param string $statusTo
 * @param string $extra
 * @return string
 */
function requests_tg_build_message(array $context, string $title, string $statusTo, string $extra = ''): string
{
  /**
   * $requestId — номер заявки.
   */
  $requestId = (int)($context['id'] ?? 0);
  /**
   * $clientName — имя клиента.
   */
  $clientName = trim((string)($context['client_name'] ?? ''));
  /**
   * $clientPhone — телефон клиента.
   */
  $clientPhone = trim((string)($context['client_phone'] ?? ''));
  /**
   * $serviceName — услуга.
   */
  $serviceName = trim((string)($context['service_name'] ?? ''));
  /**
   * $specialistName — специалист.
   */
  $specialistName = trim((string)($context['specialist_name'] ?? ''));
  /**
   * $visitText — время визита.
   */
  $visitText = requests_tg_format_visit_at((string)($context['visit_at'] ?? ''));
  /**
   * $statusText — подпись статуса.
   */
  $statusText = requests_tg_status_label($statusTo);

  /**
   * $lines — строки сообщения.
   */
  $lines = [];
  $lines[] = '<b>' . requests_tg_escape_html($title) . '</b>';
  $lines[] = 'Заявка: #' . (string)$requestId;
  if ($clientName !== '') {
    $clientLine = 'Клиент: ' . requests_tg_escape_html($clientName);
    if ($clientPhone !== '') {
      $clientLine .= ' (' . requests_tg_escape_html($clientPhone) . ')';
    }
    $lines[] = $clientLine;
  }
  if ($serviceName !== '') {
    $lines[] = 'Услуга: ' . requests_tg_escape_html($serviceName);
  }
  if ($specialistName !== '') {
    $lines[] = 'Специалист: ' . requests_tg_escape_html($specialistName);
  }
  if ($visitText !== '' && $visitText !== '—') {
    $lines[] = 'Визит: ' . requests_tg_escape_html($visitText);
  }
  if (trim($extra) !== '') {
    $lines[] = requests_tg_escape_html(trim($extra));
  }
  $lines[] = 'Статус: ' . requests_tg_escape_html($statusText);

  return implode("\n", $lines);
}

/**
 * requests_tg_build_client_message()
 * Формирует сообщение для клиента.
 *
 * @param array<string,mixed> $context
 * @param string $title
 * @param string $statusTo
 * @param string $discountText
 * @param string $extra
 * @return string
 */
function requests_tg_build_client_message(
  array $context,
  string $title,
  string $statusTo,
  string $discountText = '',
  string $extra = ''
): string {
  $requestId = (int)($context['id'] ?? 0);
  $serviceName = trim((string)($context['service_name'] ?? ''));
  $specialistName = trim((string)($context['specialist_name'] ?? ''));
  $visitAt = trim((string)($context['visit_at'] ?? ''));
  $statusText = requests_tg_status_label($statusTo);
  $total = (int)($context['price_total'] ?? 0);

  $totalText = ($total > 0)
    ? (number_format($total, 0, '.', ' ') . ' руб.')
    : 'не указана';

  $discountText = trim($discountText);
  if ($discountText === '') {
    $discountText = 'нет';
  }

  $visitText = '—';
  if ($visitAt !== '' && $visitAt !== '0000-00-00 00:00:00') {
    try {
      $visitText = date('d.m.Y H:i', strtotime($visitAt));
    } catch (Throwable $e) {
      $visitText = $visitAt;
    }
  }

  $lines = [];
  $lines[] = '<b>' . requests_tg_escape_html($title) . '</b>';
  if ($requestId > 0) {
    $lines[] = 'Заявка #' . $requestId;
  }
  if ($serviceName !== '') {
    $lines[] = 'Услуга: ' . requests_tg_escape_html($serviceName);
  }
  if ($specialistName !== '') {
    $lines[] = 'Специалист: ' . requests_tg_escape_html($specialistName);
  }
  if ($visitText !== '' && $visitText !== '—') {
    $lines[] = 'Визит: ' . requests_tg_escape_html($visitText);
  }
  $lines[] = 'Стоимость: ' . requests_tg_escape_html($totalText);
  $lines[] = 'Скидка: ' . requests_tg_escape_html($discountText);
  if (trim($extra) !== '') {
    $lines[] = requests_tg_escape_html(trim($extra));
  }
  $lines[] = 'Статус: ' . requests_tg_escape_html($statusText);

  return implode("\n", $lines);
}

/**
 * requests_tg_dispatch()
 * Отправляет уведомление через TG модуль и пишет аудит отправки.
 *
 * @param int $requestId
 * @param string $eventCode
 * @param string $message
 * @param array<int,int> $userIds
 * @param string $audience
 * @param int|null $actorUserId
 * @param string|null $actorRole
 * @return array<string,mixed>
 */
function requests_tg_dispatch(
  int $requestId,
  string $eventCode,
  string $message,
  array $userIds,
  string $audience,
  ?int $actorUserId = null,
  ?string $actorRole = null
): array {
  $userIds = requests_tg_unique_ids($userIds);
  if (!$userIds) {
    return [
      'ok' => true,
      'audience' => $audience,
      'event_code' => $eventCode,
      'used_event_code' => $eventCode,
      'targets' => 0,
      'sent' => 0,
      'failed' => 0,
      'reason' => 'no_targets',
    ];
  }

  if (!function_exists('sendSystemTG')) {
    return [
      'ok' => false,
      'audience' => $audience,
      'event_code' => $eventCode,
      'used_event_code' => $eventCode,
      'targets' => count($userIds),
      'sent' => 0,
      'failed' => count($userIds),
      'reason' => 'send_function_missing',
      'message' => 'sendSystemTG() недоступна',
    ];
  }

  /**
   * $usedEventCode — фактически использованный код события.
   */
  $usedEventCode = $eventCode;
  /**
   * $fallbackToGeneral — признак fallback в event_code=general.
   */
  $fallbackToGeneral = 0;

  /**
   * $result — результат отправки.
   */
  $result = sendSystemTG($message, $eventCode, [
    'user_ids' => $userIds,
    'parse_mode' => 'HTML',
  ]);

  if (($result['ok'] ?? false) !== true && (string)($result['reason'] ?? '') === 'event_not_found') {
    $fallbackToGeneral = 1;
    $usedEventCode = 'general';
    $result = sendSystemTG($message, 'general', [
      'user_ids' => $userIds,
      'parse_mode' => 'HTML',
    ]);
  }

  $ok = (($result['ok'] ?? false) === true);
  $targets = (int)($result['targets'] ?? count($userIds));
  $sent = (int)($result['sent'] ?? 0);
  $failed = (int)($result['failed'] ?? 0);
  $reason = trim((string)($result['reason'] ?? ''));
  $errorMessage = trim((string)($result['message'] ?? ''));
  if ($errorMessage === '') {
    $errorMessage = trim((string)($result['description'] ?? ''));
  }

  audit_log('requests', 'tg_notify', ($ok ? 'info' : 'warn'), [
    'request_id' => $requestId,
    'audience' => $audience,
    'event_code' => $eventCode,
    'used_event_code' => $usedEventCode,
    'fallback_to_general' => $fallbackToGeneral,
    'targets' => $targets,
    'sent' => $sent,
    'failed' => $failed,
    'reason' => ($reason !== '' ? $reason : null),
    'message' => ($errorMessage !== '' ? $errorMessage : null),
  ], 'request', $requestId, ($actorUserId ?: null), ($actorRole !== '' ? $actorRole : null));

  return [
    'ok' => $ok,
    'audience' => $audience,
    'event_code' => $eventCode,
    'used_event_code' => $usedEventCode,
    'fallback_to_general' => $fallbackToGeneral,
    'targets' => $targets,
    'sent' => $sent,
    'failed' => $failed,
    'reason' => $reason,
    'message' => $errorMessage,
  ];
}

/**
 * requests_tg_dispatch_clients()
 * Отправляет уведомление клиентам через TG-модуль tg_system_clients.
 *
 * @param int $requestId
 * @param string $eventCode
 * @param string $message
 * @param array<int,int> $clientIds
 * @param string $audience
 * @param int|null $actorUserId
 * @param string|null $actorRole
 * @return array<string,mixed>
 */
function requests_tg_dispatch_clients(
  int $requestId,
  string $eventCode,
  string $message,
  array $clientIds,
  string $audience,
  ?int $actorUserId = null,
  ?string $actorRole = null
): array {
  $clientIds = requests_tg_unique_ids($clientIds);
  if (!$clientIds) {
    return [
      'ok' => true,
      'audience' => $audience,
      'event_code' => $eventCode,
      'used_event_code' => $eventCode,
      'targets' => 0,
      'sent' => 0,
      'failed' => 0,
      'reason' => 'no_targets',
    ];
  }

  if (!function_exists('sendClientTG')) {
    return [
      'ok' => false,
      'audience' => $audience,
      'event_code' => $eventCode,
      'used_event_code' => $eventCode,
      'targets' => count($clientIds),
      'sent' => 0,
      'failed' => count($clientIds),
      'reason' => 'send_function_missing',
      'message' => 'sendClientTG() недоступна',
    ];
  }

  $usedEventCode = $eventCode;
  $fallbackToGeneral = 0;

  $result = sendClientTG($message, $eventCode, [
    'client_ids' => $clientIds,
    'parse_mode' => 'HTML',
  ]);

  if (($result['ok'] ?? false) !== true && (string)($result['reason'] ?? '') === 'event_not_found') {
    $fallbackToGeneral = 1;
    $usedEventCode = 'general';
    $result = sendClientTG($message, 'general', [
      'client_ids' => $clientIds,
      'parse_mode' => 'HTML',
    ]);
  }

  $ok = (($result['ok'] ?? false) === true);
  $targets = (int)($result['targets'] ?? count($clientIds));
  $sent = (int)($result['sent'] ?? 0);
  $failed = (int)($result['failed'] ?? 0);
  $reason = trim((string)($result['reason'] ?? ''));
  $errorMessage = trim((string)($result['message'] ?? ''));
  if ($errorMessage === '') {
    $errorMessage = trim((string)($result['description'] ?? ''));
  }

  audit_log('requests', 'tg_notify', ($ok ? 'info' : 'warn'), [
    'request_id' => $requestId,
    'audience' => $audience,
    'event_code' => $eventCode,
    'used_event_code' => $usedEventCode,
    'fallback_to_general' => $fallbackToGeneral,
    'targets' => $targets,
    'sent' => $sent,
    'failed' => $failed,
    'reason' => ($reason !== '' ? $reason : null),
    'message' => ($errorMessage !== '' ? $errorMessage : null),
  ], 'request', $requestId, ($actorUserId ?: null), ($actorRole !== '' ? $actorRole : null));

  return [
    'ok' => $ok,
    'audience' => $audience,
    'event_code' => $eventCode,
    'used_event_code' => $usedEventCode,
    'fallback_to_general' => $fallbackToGeneral,
    'targets' => $targets,
    'sent' => $sent,
    'failed' => $failed,
    'reason' => $reason,
    'message' => $errorMessage,
  ];
}

/**
 * requests_tg_notify_status()
 * Единая отправка TG-уведомлений по статусному событию заявки.
 *
 * @param PDO $pdo
 * @param int $requestId
 * @param string $statusTo
 * @param array<string,mixed> $options
 * @return array<string,mixed>
 */
function requests_tg_notify_status(PDO $pdo, int $requestId, string $statusTo, array $options = []): array
{
  $statusTo = strtolower(trim($statusTo));
  $allowed = [
    REQUESTS_STATUS_NEW,
    REQUESTS_STATUS_CONFIRMED,
    REQUESTS_STATUS_IN_WORK,
    REQUESTS_STATUS_DONE,
    REQUESTS_NOTIFY_STATUS_CHANGED,
    REQUESTS_NOTIFY_STATUS_RESCHEDULED,
    REQUESTS_NOTIFY_STATUS_CANCELED,
  ];

  if ($requestId <= 0) {
    return ['ok' => false, 'reason' => 'request_invalid'];
  }
  if (!in_array($statusTo, $allowed, true)) {
    return ['ok' => false, 'reason' => 'status_invalid', 'status_to' => $statusTo];
  }

  /**
   * $actorUserId — инициатор действия.
   */
  $actorUserId = (int)($options['actor_user_id'] ?? 0);
  /**
   * $actorRole — роль инициатора.
   */
  $actorRole = trim((string)($options['actor_role'] ?? ''));
  /**
   * $skipActor — исключать инициатора из получателей.
   */
  $skipActor = ((int)($options['skip_actor'] ?? 0) === 1);
  /**
   * $prevSpecialistId — предыдущий специалист (для переназначения/изменения).
   */
  $prevSpecialistId = (int)($options['prev_specialist_id'] ?? 0);
  /**
   * $snapshot — снимок заявки до изменения (опционально).
   */
  $snapshot = (isset($options['snapshot']) && is_array($options['snapshot'])) ? (array)$options['snapshot'] : [];

  /**
   * $context — текущий контекст заявки из БД.
   */
  $context = requests_tg_fetch_request_context($pdo, $requestId);
  if (!$context) {
    return ['ok' => false, 'reason' => 'request_not_found', 'request_id' => $requestId];
  }

  foreach (['client_name', 'client_phone', 'service_name', 'specialist_name', 'visit_at'] as $field) {
    if (trim((string)($context[$field] ?? '')) === '' && trim((string)($snapshot[$field] ?? '')) !== '') {
      $context[$field] = (string)$snapshot[$field];
    }
  }
  foreach (['specialist_user_id', 'service_id'] as $field) {
    if ((int)($context[$field] ?? 0) <= 0 && (int)($snapshot[$field] ?? 0) > 0) {
      $context[$field] = (int)$snapshot[$field];
    }
  }
  if ($prevSpecialistId <= 0) {
    $prevSpecialistId = (int)($snapshot['specialist_user_id'] ?? 0);
  }

  /**
   * $dispatches — список отправок.
   */
  $dispatches = [];
  /**
   * $alreadyNotified — защита от дублей по user_id в рамках одного события.
   */
  $alreadyNotified = [];

  /**
   * $managerEvent — event_code для менеджеров/админов.
   */
  $managerEvent = requests_tg_event_for_manager($statusTo);
  if ($managerEvent !== null) {
    /**
     * $managerTargets — получатели manager/admin.
     */
    $managerTargets = requests_tg_users_by_roles($pdo, ['admin', 'manager']);
    $managerTargets = requests_tg_filter_targets($managerTargets, $actorUserId, $skipActor, $alreadyNotified);

    /**
     * $managerTitle — заголовок уведомления для manager/admin.
     */
    $managerTitle = 'Обновление заявки';
    if ($statusTo === REQUESTS_STATUS_NEW) $managerTitle = 'Новая заявка';
    if ($statusTo === REQUESTS_STATUS_CONFIRMED) $managerTitle = 'Заявка подтверждена';
    if ($statusTo === REQUESTS_STATUS_IN_WORK) $managerTitle = 'Заявка принята в работу';
    if ($statusTo === REQUESTS_STATUS_DONE) $managerTitle = 'Заявка выполнена';
    if (in_array($statusTo, [REQUESTS_NOTIFY_STATUS_CHANGED, REQUESTS_NOTIFY_STATUS_RESCHEDULED, REQUESTS_NOTIFY_STATUS_CANCELED], true)) {
      $managerTitle = 'Заявка изменена';
    }

    $managerMessage = requests_tg_build_message($context, $managerTitle, $statusTo);
    $managerDispatch = requests_tg_dispatch(
      $requestId,
      $managerEvent,
      $managerMessage,
      $managerTargets,
      'manager',
      ($actorUserId > 0 ? $actorUserId : null),
      ($actorRole !== '' ? $actorRole : null)
    );
    $dispatches[] = $managerDispatch;
    $alreadyNotified = requests_tg_unique_ids(array_merge($alreadyNotified, $managerTargets));
  }

  /**
   * $specialistEvent — event_code для специалиста.
   */
  $specialistEvent = requests_tg_event_for_specialist($statusTo);
  if ($specialistEvent !== null) {
    /**
     * $currentSpecialistId — текущий специалист в заявке.
     */
    $currentSpecialistId = (int)($context['specialist_user_id'] ?? 0);

    /**
     * $specialistTargets — текущие специалисты-получатели.
     */
    $specialistTargets = [];
    if ($currentSpecialistId > 0) $specialistTargets[] = $currentSpecialistId;
    $specialistTargets = requests_tg_filter_targets($specialistTargets, $actorUserId, $skipActor, $alreadyNotified);

    if ($specialistTargets) {
      $specialistTitle = ($statusTo === REQUESTS_STATUS_NEW) ? 'Новая заявка на вас' : 'Обновление вашей заявки';
      if ($statusTo === REQUESTS_STATUS_CONFIRMED) $specialistTitle = 'Ваша заявка подтверждена';
      if (in_array($statusTo, [REQUESTS_NOTIFY_STATUS_CHANGED, REQUESTS_NOTIFY_STATUS_RESCHEDULED, REQUESTS_NOTIFY_STATUS_CANCELED], true)) {
        $specialistTitle = 'Заявка изменена';
      }

      $specialistMessage = requests_tg_build_message($context, $specialistTitle, $statusTo);
      $specialistDispatch = requests_tg_dispatch(
        $requestId,
        $specialistEvent,
        $specialistMessage,
        $specialistTargets,
        'specialist',
        ($actorUserId > 0 ? $actorUserId : null),
        ($actorRole !== '' ? $actorRole : null)
      );
      $dispatches[] = $specialistDispatch;
      $alreadyNotified = requests_tg_unique_ids(array_merge($alreadyNotified, $specialistTargets));
    }

    /**
     * $prevTargets — прошлый специалист, если отличается от текущего.
     */
    $prevTargets = [];
    if ($prevSpecialistId > 0 && $prevSpecialistId !== $currentSpecialistId) {
      $prevTargets[] = $prevSpecialistId;
    }
    $prevTargets = requests_tg_filter_targets($prevTargets, $actorUserId, $skipActor, $alreadyNotified);

    if ($prevTargets && in_array($statusTo, [REQUESTS_NOTIFY_STATUS_CHANGED, REQUESTS_NOTIFY_STATUS_RESCHEDULED, REQUESTS_NOTIFY_STATUS_CANCELED], true)) {
      $prevMessage = requests_tg_build_message($context, 'Заявка снята с вас', $statusTo, 'Эта заявка больше не назначена на вас.');
      $prevDispatch = requests_tg_dispatch(
        $requestId,
        $specialistEvent,
        $prevMessage,
        $prevTargets,
        'specialist_prev',
        ($actorUserId > 0 ? $actorUserId : null),
        ($actorRole !== '' ? $actorRole : null)
      );
      $dispatches[] = $prevDispatch;
      $alreadyNotified = requests_tg_unique_ids(array_merge($alreadyNotified, $prevTargets));
    }
  }

  /**
   * $targetsTotal — всего таргетов по всем отправкам.
   */
  $clientEvent = requests_tg_event_for_client($statusTo);
  if ($clientEvent !== null) {
    $clientId = (int)($context['client_id'] ?? 0);
    if ($clientId <= 0) {
      $clientId = (int)($snapshot['client_id'] ?? 0);
    }

    if ($clientId > 0) {
      $clientTitle = 'Обновление заявки';
      if ($statusTo === REQUESTS_STATUS_IN_WORK) $clientTitle = 'Заявка принята';
      if ($statusTo === REQUESTS_STATUS_CONFIRMED) $clientTitle = 'Заявка подтверждена';
      if ($statusTo === REQUESTS_STATUS_DONE) $clientTitle = 'Спасибо, что выбрали нас';
      if (in_array($statusTo, [REQUESTS_NOTIFY_STATUS_CHANGED, REQUESTS_NOTIFY_STATUS_RESCHEDULED, REQUESTS_NOTIFY_STATUS_CANCELED], true)) {
        $clientTitle = 'Заявка изменена';
      }

      $discountText = trim((string)($options['discount_text'] ?? ''));
      $clientExtra = trim((string)($options['client_extra_text'] ?? ''));
      $clientMessage = requests_tg_build_client_message($context, $clientTitle, $statusTo, $discountText, $clientExtra);

      $clientDispatch = requests_tg_dispatch_clients(
        $requestId,
        $clientEvent,
        $clientMessage,
        [$clientId],
        'client',
        ($actorUserId > 0 ? $actorUserId : null),
        ($actorRole !== '' ? $actorRole : null)
      );
      $dispatches[] = $clientDispatch;
    }
  }

  $targetsTotal = 0;
  /**
   * $sentTotal — всего успешно отправлено.
   */
  $sentTotal = 0;
  /**
   * $failedTotal — всего ошибок отправки.
   */
  $failedTotal = 0;
  foreach ($dispatches as $d) {
    $targetsTotal += (int)($d['targets'] ?? 0);
    $sentTotal += (int)($d['sent'] ?? 0);
    $failedTotal += (int)($d['failed'] ?? 0);
  }

  return [
    'ok' => true,
    'request_id' => $requestId,
    'status_to' => $statusTo,
    'dispatches' => $dispatches,
    'targets_total' => $targetsTotal,
    'sent_total' => $sentTotal,
    'failed_total' => $failedTotal,
  ];
}
