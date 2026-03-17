<?php
/**
 * FILE: /adm/modules/dashboard/assets/php/dashboard_lib.php
 * ROLE: Функции модуля dashboard (подготовка данных для VIEW и action)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

/**
 * dashboard_t()
 * Возвращает локализованную строку модуля dashboard.
 *
 * @param string $key
 * @param array<string,string|int|float> $replace
 * @return string
 */
function dashboard_t(string $key, array $replace = []): string
{
  $text = function_exists('t') ? t($key) : $key;
  if (!$replace) {
    return $text;
  }

  /**
   * $map — карта подстановок вида ['{id}' => '123'].
   */
  $map = [];
  foreach ($replace as $k => $v) {
    $map['{' . $k . '}'] = (string)$v;
  }

  return strtr($text, $map);
}

/**
 * dashboard_time_hm()
 * Нормализует время к формату HH:MM.
 *
 * @param string $raw
 * @param string $fallback
 * @return string
 */
function dashboard_time_hm(string $raw, string $fallback = '00:00'): string {
  $raw = trim($raw);

  if (preg_match('/^\d{2}:\d{2}$/', $raw)) {
    return $raw;
  }

  if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $raw)) {
    return substr($raw, 0, 5);
  }

  return $fallback;
}

/**
 * dashboard_schedule_weekdays()
 * Возвращает список дней недели.
 *
 * @return array<int,string>
 */
function dashboard_schedule_weekdays(): array {
  return [
    1 => dashboard_t('dashboard.weekday_1'),
    2 => dashboard_t('dashboard.weekday_2'),
    3 => dashboard_t('dashboard.weekday_3'),
    4 => dashboard_t('dashboard.weekday_4'),
    5 => dashboard_t('dashboard.weekday_5'),
    6 => dashboard_t('dashboard.weekday_6'),
    7 => dashboard_t('dashboard.weekday_7'),
  ];
}

/**
 * dashboard_users_name_columns()
 * Проверяет, есть ли в users поля last_name/middle_name.
 *
 * @param PDO $pdo
 * @return array<string,bool>
 */
function dashboard_users_name_columns(PDO $pdo): array {
  /**
   * $cache — кеш проверки полей
   */
  static $cache = null;

  if (is_array($cache)) {
    return $cache;
  }

  $cache = [
    'last_name' => false,
    'middle_name' => false,
  ];

  try {
    $stLast = $pdo->query("SHOW COLUMNS FROM users LIKE 'last_name'");
    $cache['last_name'] = ($stLast && $stLast->fetch(PDO::FETCH_ASSOC) !== false);

    $stMiddle = $pdo->query("SHOW COLUMNS FROM users LIKE 'middle_name'");
    $cache['middle_name'] = ($stMiddle && $stMiddle->fetch(PDO::FETCH_ASSOC) !== false);
  } catch (Throwable $e) {
    $cache['last_name'] = false;
    $cache['middle_name'] = false;
  }

  return $cache;
}

/**
 * dashboard_user_is_specialist()
 * Проверяет, есть ли у пользователя роль specialist.
 *
 * @param PDO $pdo
 * @param int $uid
 * @return bool
 */
function dashboard_user_is_specialist(PDO $pdo, int $uid): bool {
  if ($uid <= 0) {
    return false;
  }

  try {
    $st = $pdo->prepare("SELECT id FROM roles WHERE code='specialist' LIMIT 1");
    $st->execute();

    /**
     * $specialistRoleId — id роли specialist
     */
    $specialistRoleId = (int)($st->fetchColumn() ?: 0);
    if ($specialistRoleId <= 0) {
      return false;
    }

    $st = $pdo->prepare('SELECT 1 FROM user_roles WHERE user_id = :uid AND role_id = :rid LIMIT 1');
    $st->execute([
      ':uid' => $uid,
      ':rid' => $specialistRoleId,
    ]);

    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * dashboard_schedule_get()
 * Возвращает расписание специалиста и перерыв между приёмами.
 *
 * @param PDO $pdo
 * @param int $uid
 * @return array<string,mixed>
 */
function dashboard_schedule_get(PDO $pdo, int $uid): array {
  /**
   * $rows — расписание по дням недели
   */
  $rows = [];

  foreach (dashboard_schedule_weekdays() as $wd => $label) {
    $rows[$wd] = [
      'time_start' => '09:00',
      'time_end' => '18:00',
      'break_start' => '13:00',
      'break_end' => '14:00',
      'is_day_off' => in_array($wd, [6, 7], true) ? 1 : 0,
    ];
  }

  /**
   * $leadMinutes — перерыв между приёмами (по умолчанию 10 минут)
   */
  $leadMinutes = 10;
  /**
   * $leadWasRead — признак, что lead_minutes прочитан из БД
   */
  $leadWasRead = false;

  if ($uid <= 0) {
    return [
      'lead_minutes' => $leadMinutes,
      'rows' => $rows,
    ];
  }

  try {
    $st = $pdo->prepare('
      SELECT
        weekday,
        time_start,
        time_end,
        break_start,
        break_end,
        is_day_off,
        lead_minutes
      FROM specialist_schedule
      WHERE user_id = :uid
      ORDER BY weekday ASC
    ');
    $st->execute([':uid' => $uid]);

    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
      $wd = (int)($row['weekday'] ?? 0);
      if ($wd < 1 || $wd > 7) continue;

      $rows[$wd] = [
        'time_start' => dashboard_time_hm((string)($row['time_start'] ?? ''), '09:00'),
        'time_end' => dashboard_time_hm((string)($row['time_end'] ?? ''), '18:00'),
        'break_start' => dashboard_time_hm((string)($row['break_start'] ?? ''), '13:00'),
        'break_end' => dashboard_time_hm((string)($row['break_end'] ?? ''), '14:00'),
        'is_day_off' => ((int)($row['is_day_off'] ?? 0) === 1) ? 1 : 0,
      ];

      $rowLead = (int)($row['lead_minutes'] ?? 0);
      if (!$leadWasRead) {
        $leadMinutes = $rowLead;
        $leadWasRead = true;
      }
    }
  } catch (Throwable $e) {
    // Оставляем значения по умолчанию.
  }

  return [
    'lead_minutes' => $leadMinutes,
    'rows' => $rows,
  ];
}

/**
 * dashboard_user_profile()
 * Возвращает профиль текущего пользователя для дашборда.
 *
 * @param PDO $pdo
 * @param int $uid
 * @return array<string,mixed>
 */
function dashboard_user_profile(PDO $pdo, int $uid): array {
  $nameColumns = dashboard_users_name_columns($pdo);

  $result = [
    'id' => $uid,
    'name' => '',
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'ui_theme' => '',
    'last_name' => '',
    'middle_name' => '',
    'name_columns' => $nameColumns,
  ];

  if ($uid <= 0) {
    $result['full_name'] = dashboard_t('dashboard.user_not_defined');
    return $result;
  }

  $fields = ['id', 'name', 'email', 'phone', 'ui_theme'];
  if ($nameColumns['last_name']) {
    $fields[] = 'last_name';
  }
  if ($nameColumns['middle_name']) {
    $fields[] = 'middle_name';
  }

  $sql = 'SELECT ' . implode(', ', $fields) . ' FROM users WHERE id = :uid LIMIT 1';

  try {
    $st = $pdo->prepare($sql);
    $st->execute([':uid' => $uid]);

    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
      $result['full_name'] = dashboard_t('dashboard.user_with_id', ['id' => $uid]);
      return $result;
    }

    $name = trim((string)($row['name'] ?? ''));
    $lastName = $nameColumns['last_name'] ? trim((string)($row['last_name'] ?? '')) : '';
    $middleName = $nameColumns['middle_name'] ? trim((string)($row['middle_name'] ?? '')) : '';

    $fullName = trim($name . ' ' . $lastName . ' ' . $middleName);
    if ($fullName === '') {
      $fallbackEmail = trim((string)($row['email'] ?? ''));
      $fallbackPhone = trim((string)($row['phone'] ?? ''));
      $fullName = ($fallbackEmail !== '')
        ? $fallbackEmail
        : ($fallbackPhone !== '' ? $fallbackPhone : dashboard_t('dashboard.user_with_id', ['id' => $uid]));
    }

    $result['id'] = (int)($row['id'] ?? $uid);
    $result['name'] = $name;
    $result['full_name'] = $fullName;
    $result['email'] = trim((string)($row['email'] ?? ''));
    $result['phone'] = trim((string)($row['phone'] ?? ''));
    $result['ui_theme'] = trim((string)($row['ui_theme'] ?? ''));
    $result['last_name'] = $lastName;
    $result['middle_name'] = $middleName;
  } catch (Throwable $e) {
    $result['full_name'] = dashboard_t('dashboard.user_with_id', ['id' => $uid]);
  }

  return $result;
}

/**
 * dashboard_unread_notifications_stub()
 * Заглушка непросмотренных оповещений.
 *
 * @return array<int,array<string,string>>
 */
function dashboard_unread_notifications_stub(): array {
  return [
    [
      'title' => dashboard_t('dashboard.stub_notify_title_1'),
      'text' => dashboard_t('dashboard.stub_notify_text_1'),
      'time' => dashboard_t('dashboard.stub_notify_time_1'),
    ],
    [
      'title' => dashboard_t('dashboard.stub_notify_title_2'),
      'text' => dashboard_t('dashboard.stub_notify_text_2'),
      'time' => dashboard_t('dashboard.stub_notify_time_2'),
    ],
    [
      'title' => dashboard_t('dashboard.stub_notify_title_3'),
      'text' => dashboard_t('dashboard.stub_notify_text_3'),
      'time' => dashboard_t('dashboard.stub_notify_time_3'),
    ],
  ];
}

/**
 * dashboard_system_cards_stub()
 * Заглушки для блока системных карточек.
 *
 * @return array<int,array<string,string>>
 */
function dashboard_system_cards_stub(): array {
  $dashboardUrl = function_exists('url') ? (string)url('/adm/index.php?m=dashboard') : '/adm/index.php?m=dashboard';

  return [
    [
      'title' => dashboard_t('dashboard.stub_system_title_1'),
      'text' => dashboard_t('dashboard.stub_system_text_1'),
      'action_label' => dashboard_t('dashboard.stub_system_action_1'),
      'action_url' => $dashboardUrl,
    ],
    [
      'title' => dashboard_t('dashboard.stub_system_title_2'),
      'text' => dashboard_t('dashboard.stub_system_text_2'),
      'action_label' => dashboard_t('dashboard.stub_system_action_2'),
      'action_url' => $dashboardUrl,
    ],
    [
      'title' => dashboard_t('dashboard.stub_system_title_3'),
      'text' => dashboard_t('dashboard.stub_system_text_3'),
      'action_label' => dashboard_t('dashboard.stub_system_action_3'),
      'action_url' => $dashboardUrl,
    ],
  ];
}

/**
 * dashboard_company_piecework_settings()
 * Пытается прочитать сдельную оплату из company_settings, иначе возвращает заглушку.
 *
 * @param PDO $pdo
 * @return array<string,mixed>
 */
function dashboard_company_piecework_settings(PDO $pdo): array {
  $settings = [
    'enabled' => true,
    'percent' => 40.0,
    'source' => 'stub_40_percent',
  ];

  try {
    $stTable = $pdo->query("SHOW TABLES LIKE 'company_settings'");
    $tableExists = ($stTable && $stTable->fetch(PDO::FETCH_NUM) !== false);
    if (!$tableExists) {
      return $settings;
    }

    $stEnabled = $pdo->query("SHOW COLUMNS FROM company_settings LIKE 'piecework_enabled'");
    $hasEnabled = ($stEnabled && $stEnabled->fetch(PDO::FETCH_ASSOC) !== false);

    $stPercent = $pdo->query("SHOW COLUMNS FROM company_settings LIKE 'piecework_percent'");
    $hasPercent = ($stPercent && $stPercent->fetch(PDO::FETCH_ASSOC) !== false);

    if (!$hasEnabled && !$hasPercent) {
      return $settings;
    }

    $fields = [];
    if ($hasEnabled) $fields[] = 'piecework_enabled';
    if ($hasPercent) $fields[] = 'piecework_percent';

    $sql = 'SELECT ' . implode(', ', $fields) . ' FROM company_settings LIMIT 1';
    $st = $pdo->query($sql);
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;

    if (is_array($row)) {
      if ($hasEnabled) {
        $settings['enabled'] = ((int)($row['piecework_enabled'] ?? 0) === 1);
      }

      if ($hasPercent) {
        $percent = (float)($row['piecework_percent'] ?? 0);
        if ($percent > 0) {
          $settings['percent'] = $percent;
        }
      }

      $settings['source'] = 'company_settings';
    }
  } catch (Throwable $e) {
    return $settings;
  }

  return $settings;
}

/**
 * dashboard_recent_done_requests()
 * Возвращает последние выполненные заявки пользователя.
 *
 * @param PDO $pdo
 * @param int $uid
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function dashboard_recent_done_requests(PDO $pdo, int $uid, int $limit = 4): array {
  if ($uid <= 0) {
    return [];
  }

  $safeLimit = max(1, min(20, $limit));

  $sql = "
    SELECT
      r.id,
      r.client_name,
      COALESCE(s.name, '—') AS service_name,
      COALESCE(NULLIF(r.price_total, 0), inv.total, 0) AS total,
      COALESCE(r.done_at, r.updated_at, r.created_at) AS done_at
    FROM requests r
    LEFT JOIN services s ON s.id = r.service_id
    LEFT JOIN (
      SELECT i.request_id, i.total
      FROM request_invoices i
      INNER JOIN (
        SELECT request_id, MAX(id) AS max_id
        FROM request_invoices
        GROUP BY request_id
      ) li ON li.max_id = i.id
    ) inv ON inv.request_id = r.id
    WHERE
      r.status = 'done'
      AND (r.specialist_user_id = :uid_spec OR r.done_by = :uid_done)
    ORDER BY COALESCE(r.done_at, r.updated_at, r.created_at) DESC, r.id DESC
    LIMIT {$safeLimit}
  ";

  try {
    $st = $pdo->prepare($sql);
    $st->execute([
      ':uid_spec' => $uid,
      ':uid_done' => $uid,
    ]);

    return (array)$st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return [];
  }
}

/**
 * dashboard_salary_summary()
 * Считает сумму выполненных заявок за 30 дней и расчёт ЗП.
 *
 * @param PDO $pdo
 * @param int $uid
 * @return array<string,mixed>
 */
function dashboard_salary_summary(PDO $pdo, int $uid): array {
  $piecework = dashboard_company_piecework_settings($pdo);

  $summary = [
    'period_days' => 30,
    'done_count' => 0,
    'done_sum' => 0.0,
    'enabled' => (bool)($piecework['enabled'] ?? true),
    'percent' => (float)($piecework['percent'] ?? 40),
    'source' => (string)($piecework['source'] ?? 'stub_40_percent'),
    'salary' => 0.0,
  ];

  if ($uid <= 0) {
    return $summary;
  }

  $sql = "
    SELECT
      COUNT(*) AS done_count,
      COALESCE(SUM(COALESCE(NULLIF(r.price_total, 0), inv.total, 0)), 0) AS done_sum
    FROM requests r
    LEFT JOIN (
      SELECT i.request_id, i.total
      FROM request_invoices i
      INNER JOIN (
        SELECT request_id, MAX(id) AS max_id
        FROM request_invoices
        GROUP BY request_id
      ) li ON li.max_id = i.id
    ) inv ON inv.request_id = r.id
    WHERE
      r.status = 'done'
      AND (r.specialist_user_id = :uid_spec OR r.done_by = :uid_done)
      AND COALESCE(r.done_at, r.updated_at, r.created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)
  ";

  try {
    $st = $pdo->prepare($sql);
    $st->execute([
      ':uid_spec' => $uid,
      ':uid_done' => $uid,
    ]);

    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
      $summary['done_count'] = (int)($row['done_count'] ?? 0);
      $summary['done_sum'] = (float)($row['done_sum'] ?? 0);
    }
  } catch (Throwable $e) {
    return $summary;
  }

  if ($summary['enabled']) {
    $summary['salary'] = round($summary['done_sum'] * ($summary['percent'] / 100), 2);
  }

  return $summary;
}

/**
 * dashboard_money()
 * Форматирует сумму для UI.
 *
 * @param float $amount
 * @return string
 */
function dashboard_money(float $amount): string {
  $rounded = round($amount, 2);
  $fraction = abs($rounded - round($rounded)) > 0.0001 ? 2 : 0;
  return number_format($rounded, $fraction, ',', ' ');
}
