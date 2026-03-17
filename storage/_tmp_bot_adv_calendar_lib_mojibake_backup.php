<?php
/**
 * FILE: /adm/modules/bot_adv_calendar/assets/php/bot_adv_calendar_lib.php
 * ROLE: Р вЂР С‘Р В·Р Р…Р ВµРЎРѓ-Р В»Р С•Р С–Р С‘Р С”Р В° Р СР С•Р Т‘РЎС“Р В»РЎРЏ bot_adv_calendar
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once ROOT_PATH . '/core/telegram.php';
require_once ROOT_PATH . '/adm/modules/calendar/settings.php';
require_once ROOT_PATH . '/adm/modules/calendar/assets/php/calendar_lib.php';
require_once ROOT_PATH . '/adm/modules/requests/settings.php';
require_once ROOT_PATH . '/adm/modules/requests/assets/php/requests_lib.php';

if (!function_exists('bot_adv_calendar_now')) {

  function bot_adv_calendar_now(): string
  {
    return date('Y-m-d H:i:s');
  }

  function bot_adv_calendar_is_manage_role(array $roles): bool
  {
    foreach (BOT_ADV_CALENDAR_MANAGE_ROLES as $role) {
      if (in_array($role, $roles, true)) return true;
    }
    return false;
  }

  function bot_adv_calendar_settings_defaults(): array
  {
    return [
      'enabled' => 0,
      'bot_token' => '',
      'webhook_secret' => '',
      'webhook_url' => '',
      'default_parse_mode' => 'HTML',
      'token_ttl_minutes' => 15,
      'retention_days' => 7,
    ];
  }

  function bot_adv_calendar_schema_required_tables(): array
  {
    return [
      BOT_ADV_CALENDAR_TABLE_SETTINGS,
      BOT_ADV_CALENDAR_TABLE_USER_ACCESS,
      BOT_ADV_CALENDAR_TABLE_USER_OPTIONS,
      BOT_ADV_CALENDAR_TABLE_USER_WINDOWS,
      BOT_ADV_CALENDAR_TABLE_LINKS,
      BOT_ADV_CALENDAR_TABLE_LINK_TOKENS,
      BOT_ADV_CALENDAR_TABLE_CLIENT_ONBOARDING,
      BOT_ADV_CALENDAR_TABLE_DISPATCH_LOG,
    ];
  }

  function bot_adv_calendar_schema_db_name(PDO $pdo): string
  {
    $cfg = function_exists('app_config') ? (array)app_config() : [];
    $dbCfg = (array)($cfg['db'] ?? []);
    $dbName = trim((string)($dbCfg['name'] ?? ''));
    if ($dbName !== '') return $dbName;

    $st = $pdo->query("SELECT DATABASE()");
    $name = $st ? trim((string)$st->fetchColumn()) : '';
    return $name;
  }

  function bot_adv_calendar_schema_missing_tables(PDO $pdo): array
  {
    $dbName = bot_adv_calendar_schema_db_name($pdo);
    if ($dbName === '') {
      return bot_adv_calendar_schema_required_tables();
    }

    $required = bot_adv_calendar_schema_required_tables();
    $required = array_values(array_unique(array_filter($required, static function ($t) {
      return trim((string)$t) !== '';
    })));
    if (!$required) return [];

    $in = implode(',', array_fill(0, count($required), '?'));
    $params = array_merge([$dbName], $required);

    $st = $pdo->prepare("
      SELECT TABLE_NAME
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = ?
        AND TABLE_NAME IN ($in)
    ");
    $st->execute($params);

    $exists = $st->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!is_array($exists)) $exists = [];
    $existsMap = array_fill_keys(array_map('strval', $exists), true);

    $missing = [];
    foreach ($required as $table) {
      if (!isset($existsMap[$table])) $missing[] = $table;
    }

    return $missing;
  }

  function bot_adv_calendar_require_schema(PDO $pdo): void
  {
    static $checked = false;
    if ($checked) return;

    $missing = bot_adv_calendar_schema_missing_tables($pdo);
    if ($missing) {
      throw new RuntimeException(
        'Р СњР Вµ Р С—РЎР‚Р С‘Р СР ВµР Р…Р ВµР Р… SQL Р СР С•Р Т‘РЎС“Р В»РЎРЏ bot_adv_calendar. Р С›РЎвЂљРЎРѓРЎС“РЎвЂљРЎРѓРЎвЂљР Р†РЎС“РЎР‹РЎвЂљ РЎвЂљР В°Р В±Р В»Р С‘РЎвЂ РЎвЂ№: ' . implode(', ', $missing)
      );
    }

    $checked = true;
  }

  function bot_adv_calendar_settings_get(PDO $pdo): array
  {
    bot_adv_calendar_require_schema($pdo);

    $defaults = bot_adv_calendar_settings_defaults();
    $st = $pdo->query("
      SELECT enabled, bot_token, webhook_secret, webhook_url, default_parse_mode, token_ttl_minutes, retention_days
      FROM " . BOT_ADV_CALENDAR_TABLE_SETTINGS . "
      WHERE id = 1
      LIMIT 1
    ");

    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
    if (!is_array($row)) return $defaults;

    $settings = [
      'enabled' => ((int)($row['enabled'] ?? 0) === 1) ? 1 : 0,
      'bot_token' => trim((string)($row['bot_token'] ?? '')),
      'webhook_secret' => trim((string)($row['webhook_secret'] ?? '')),
      'webhook_url' => trim((string)($row['webhook_url'] ?? '')),
      'default_parse_mode' => trim((string)($row['default_parse_mode'] ?? 'HTML')),
      'token_ttl_minutes' => (int)($row['token_ttl_minutes'] ?? 15),
      'retention_days' => (int)($row['retention_days'] ?? 7),
    ];

    if (!in_array($settings['default_parse_mode'], ['HTML', 'Markdown', 'MarkdownV2'], true)) {
      $settings['default_parse_mode'] = 'HTML';
    }
    if ($settings['token_ttl_minutes'] < 1) $settings['token_ttl_minutes'] = 15;
    if ($settings['token_ttl_minutes'] > 1440) $settings['token_ttl_minutes'] = 1440;
    if ($settings['retention_days'] < 1) $settings['retention_days'] = 7;
    if ($settings['retention_days'] > 30) $settings['retention_days'] = 30;

    return $settings;
  }

  function bot_adv_calendar_settings_save(PDO $pdo, array $input): array
  {
    bot_adv_calendar_require_schema($pdo);

    $enabled = ((int)($input['enabled'] ?? 0) === 1) ? 1 : 0;
    $botToken = trim((string)($input['bot_token'] ?? ''));
    $webhookSecret = trim((string)($input['webhook_secret'] ?? ''));
    $webhookUrl = trim((string)($input['webhook_url'] ?? ''));
    $defaultParseMode = trim((string)($input['default_parse_mode'] ?? 'HTML'));
    $tokenTtl = (int)($input['token_ttl_minutes'] ?? 15);
    $retentionDays = (int)($input['retention_days'] ?? 7);

    if (!in_array($defaultParseMode, ['HTML', 'Markdown', 'MarkdownV2'], true)) {
      $defaultParseMode = 'HTML';
    }
    if ($tokenTtl < 1) $tokenTtl = 15;
    if ($tokenTtl > 1440) $tokenTtl = 1440;
    if ($retentionDays < 1) $retentionDays = 7;
    if ($retentionDays > 30) $retentionDays = 30;

    $pdo->prepare("
      INSERT INTO " . BOT_ADV_CALENDAR_TABLE_SETTINGS . "
      (id, enabled, bot_token, webhook_secret, webhook_url, default_parse_mode, token_ttl_minutes, retention_days)
      VALUES (1, :enabled, :bot_token, :webhook_secret, :webhook_url, :default_parse_mode, :token_ttl_minutes, :retention_days)
      ON DUPLICATE KEY UPDATE
        enabled = VALUES(enabled),
        bot_token = VALUES(bot_token),
        webhook_secret = VALUES(webhook_secret),
        webhook_url = VALUES(webhook_url),
        default_parse_mode = VALUES(default_parse_mode),
        token_ttl_minutes = VALUES(token_ttl_minutes),
        retention_days = VALUES(retention_days)
    ")->execute([
      ':enabled' => $enabled,
      ':bot_token' => $botToken,
      ':webhook_secret' => $webhookSecret,
      ':webhook_url' => $webhookUrl,
      ':default_parse_mode' => $defaultParseMode,
      ':token_ttl_minutes' => $tokenTtl,
      ':retention_days' => $retentionDays,
    ]);

    return bot_adv_calendar_settings_get($pdo);
  }

  function bot_adv_calendar_actor_types(): array
  {
    return BOT_ADV_CALENDAR_ACTOR_TYPES;
  }

  function bot_adv_calendar_link_token_actor_types(): array
  {
    return BOT_ADV_CALENDAR_LINK_TOKEN_ACTOR_TYPES;
  }

  function bot_adv_calendar_is_actor_type(string $actorType): bool
  {
    return in_array($actorType, bot_adv_calendar_actor_types(), true);
  }

  function bot_adv_calendar_is_link_token_actor_type(string $actorType): bool
  {
    return in_array($actorType, bot_adv_calendar_link_token_actor_types(), true);
  }

  function bot_adv_calendar_actor_row(PDO $pdo, string $actorType, int $actorId): array
  {
    if ($actorId <= 0) return [];
    if (!bot_adv_calendar_is_actor_type($actorType)) return [];

    if ($actorType === 'user') {
      $st = $pdo->prepare("
        SELECT id, name, status
        FROM " . BOT_ADV_CALENDAR_USERS_TABLE . "
        WHERE id = :id
        LIMIT 1
      ");
      $st->execute([':id' => $actorId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!is_array($row)) return [];

      return [
        'id' => (int)($row['id'] ?? 0),
        'name' => trim((string)($row['name'] ?? '')),
        'status' => trim((string)($row['status'] ?? '')),
      ];
    }

    $st = $pdo->prepare("
      SELECT
        id,
        TRIM(CONCAT_WS(' ', last_name, first_name, middle_name)) AS name,
        status
      FROM " . BOT_ADV_CALENDAR_CLIENTS_TABLE . "
      WHERE id = :id
      LIMIT 1
    ");
    $st->execute([':id' => $actorId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return [];

    return [
      'id' => (int)($row['id'] ?? 0),
      'name' => trim((string)($row['name'] ?? '')),
      'status' => trim((string)($row['status'] ?? '')),
    ];
  }

  function bot_adv_calendar_user_is_attached(PDO $pdo, int $userId): bool
  {
    if ($userId <= 0) return false;

    $st = $pdo->prepare("
      SELECT user_id
      FROM " . BOT_ADV_CALENDAR_TABLE_USER_ACCESS . "
      WHERE user_id = :user_id
      LIMIT 1
    ");
    $st->execute([':user_id' => $userId]);
    return ((int)$st->fetchColumn() > 0);
  }

  function bot_adv_calendar_user_attach(PDO $pdo, int $userId, int $createdBy = 0): bool
  {
    if ($userId <= 0) return false;

    $actor = bot_adv_calendar_actor_row($pdo, 'user', $userId);
    if (!$actor) return false;

    $st = $pdo->prepare("
      INSERT INTO " . BOT_ADV_CALENDAR_TABLE_USER_ACCESS . "
      (user_id, created_by)
      VALUES (:user_id, :created_by)
      ON DUPLICATE KEY UPDATE
        created_by = VALUES(created_by),
        updated_at = CURRENT_TIMESTAMP
    ");

    $st->execute([
      ':user_id' => $userId,
      ':created_by' => ($createdBy > 0 ? $createdBy : 0),
    ]);

    return true;
  }

  function bot_adv_calendar_user_detach(PDO $pdo, int $userId): bool
  {
    if ($userId <= 0) return false;

    $pdo->beginTransaction();
    try {
      $pdo->prepare("
        DELETE FROM " . BOT_ADV_CALENDAR_TABLE_USER_ACCESS . "
        WHERE user_id = :user_id
        LIMIT 1
      ")->execute([':user_id' => $userId]);

      $pdo->prepare("
        UPDATE " . BOT_ADV_CALENDAR_TABLE_LINKS . "
        SET is_active = 0,
            last_seen_at = :now
        WHERE actor_type = 'user'
          AND actor_id = :user_id
      ")->execute([
        ':now' => bot_adv_calendar_now(),
        ':user_id' => $userId,
      ]);

      $pdo->prepare("
        UPDATE " . BOT_ADV_CALENDAR_TABLE_LINK_TOKENS . "
        SET used_at = :now
        WHERE actor_type = 'user'
          AND actor_id = :user_id
          AND used_at IS NULL
      ")->execute([
        ':now' => bot_adv_calendar_now(),
        ':user_id' => $userId,
      ]);

      $pdo->commit();
      return true;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      return false;
    }
  }

  function bot_adv_calendar_users_attach_candidates(PDO $pdo, int $limit = 500): array
  {
    bot_adv_calendar_require_schema($pdo);

    if ($limit < 1) $limit = 1;
    if ($limit > 2000) $limit = 2000;

    $sql = "
      SELECT
        u.id,
        u.name,
        u.status,
        COALESCE(GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ', '), '') AS role_codes
      FROM " . BOT_ADV_CALENDAR_USERS_TABLE . " u
      LEFT JOIN user_roles ur ON ur.user_id = u.id
      LEFT JOIN roles r ON r.id = ur.role_id
      LEFT JOIN " . BOT_ADV_CALENDAR_TABLE_USER_ACCESS . " ua ON ua.user_id = u.id
      WHERE ua.user_id IS NULL
      GROUP BY u.id, u.name, u.status
      ORDER BY (u.status = 'active') DESC, u.name ASC, u.id ASC
      LIMIT :lim
    ";

    $st = $pdo->prepare($sql);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  function bot_adv_calendar_user_has_crm_access(PDO $pdo, int $userId, array $roles = []): bool
  {
    if ($userId <= 0) return false;
    if (bot_adv_calendar_is_manage_role($roles)) return true;
    return bot_adv_calendar_user_is_attached($pdo, $userId);
  }

  function bot_adv_calendar_parse_hm(string $value): ?string
  {
    $value = trim($value);
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $value, $m)) {
      return null;
    }
    $h = (int)($m[1] ?? 0);
    $i = (int)($m[2] ?? 0);
    return str_pad((string)$h, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
  }

  function bot_adv_calendar_time_hm(string $value, string $fallback): string
  {
    $parsed = bot_adv_calendar_parse_hm($value);
    if ($parsed !== null) return $parsed;

    $fallbackParsed = bot_adv_calendar_parse_hm($fallback);
    return $fallbackParsed ?? '09:00';
  }

  function bot_adv_calendar_dayoff_mask_normalize(string $mask): string
  {
    $mask = trim($mask);
    if (!preg_match('/^[01]{7}$/', $mask)) {
      return '0000011';
    }
    return $mask;
  }

  function bot_adv_calendar_dayoff_mask_from_weekdays(array $weekdays): string
  {
    $bits = ['0', '0', '0', '0', '0', '0', '0'];
    foreach ($weekdays as $wd) {
      $i = (int)$wd;
      if ($i < 1 || $i > 7) continue;
      $bits[$i - 1] = '1';
    }
    return implode('', $bits);
  }

  function bot_adv_calendar_weekday_map(): array
  {
    return [
      1 => 'Р СџР С•Р Р…Р ВµР Т‘Р ВµР В»РЎРЉР Р…Р С‘Р С”',
      2 => 'Р вЂ™РЎвЂљР С•РЎР‚Р Р…Р С‘Р С”',
      3 => 'Р РЋРЎР‚Р ВµР Т‘Р В°',
      4 => 'Р В§Р ВµРЎвЂљР Р†Р ВµРЎР‚Р С–',
      5 => 'Р СџРЎРЏРЎвЂљР Р…Р С‘РЎвЂ Р В°',
      6 => 'Р РЋРЎС“Р В±Р В±Р С•РЎвЂљР В°',
      7 => 'Р вЂ™Р С•РЎРѓР С”РЎР‚Р ВµРЎРѓР ВµР Р…РЎРЉР Вµ',
    ];
  }

  function bot_adv_calendar_weekday_label(int $weekday): string
  {
    if ($weekday === 0) return 'Р С™Р В°Р В¶Р Т‘РЎвЂ№Р в„– Р Т‘Р ВµР Р…РЎРЉ';
    $map = bot_adv_calendar_weekday_map();
    return (string)($map[$weekday] ?? ('Р вЂќР ВµР Р…РЎРЉ #' . $weekday));
  }

  function bot_adv_calendar_user_options_defaults(): array
  {
    return [
      'booking_mode' => 'fixed',
      'slot_interval_minutes' => 60,
      'work_start' => '09:00',
      'work_end' => '18:00',
      'dayoff_mask' => '0000011',
    ];
  }

  function bot_adv_calendar_user_options_get(PDO $pdo, int $userId): array
  {
    bot_adv_calendar_require_schema($pdo);

    $defaults = bot_adv_calendar_user_options_defaults();
    if ($userId <= 0) return $defaults;

    $st = $pdo->prepare("
      SELECT booking_mode, slot_interval_minutes, work_start, work_end, dayoff_mask
      FROM " . BOT_ADV_CALENDAR_TABLE_USER_OPTIONS . "
      WHERE user_id = :user_id
      LIMIT 1
    ");
    $st->execute([':user_id' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
      return $defaults;
    }

    $mode = trim((string)($row['booking_mode'] ?? 'fixed'));
    if (!in_array($mode, ['fixed', 'range'], true)) $mode = 'fixed';

    $interval = (int)($row['slot_interval_minutes'] ?? 60);
    if ($interval < 5) $interval = 5;
    if ($interval > 720) $interval = 720;

    $workStart = bot_adv_calendar_time_hm((string)($row['work_start'] ?? ''), $defaults['work_start']);
    $workEnd = bot_adv_calendar_time_hm((string)($row['work_end'] ?? ''), $defaults['work_end']);

    $startMinutes = (int)substr($workStart, 0, 2) * 60 + (int)substr($workStart, 3, 2);
    $endMinutes = (int)substr($workEnd, 0, 2) * 60 + (int)substr($workEnd, 3, 2);
    if ($endMinutes <= $startMinutes) {
      $workStart = $defaults['work_start'];
      $workEnd = $defaults['work_end'];
    }

    $dayoffMask = bot_adv_calendar_dayoff_mask_normalize((string)($row['dayoff_mask'] ?? ''));

    return [
      'booking_mode' => $mode,
      'slot_interval_minutes' => $interval,
      'work_start' => $workStart,
      'work_end' => $workEnd,
      'dayoff_mask' => $dayoffMask,
    ];
  }

  function bot_adv_calendar_user_options_save(PDO $pdo, int $userId, array $input, int $updatedBy = 0): array
  {
    bot_adv_calendar_require_schema($pdo);

    if ($userId <= 0) {
      throw new RuntimeException('Р СњР ВµР С”Р С•РЎР‚РЎР‚Р ВµР С”РЎвЂљР Р…РЎвЂ№Р в„– user_id');
    }

    $mode = trim((string)($input['booking_mode'] ?? 'fixed'));
    if (!in_array($mode, ['fixed', 'range'], true)) $mode = 'fixed';

    $interval = (int)($input['slot_interval_minutes'] ?? 60);
    if ($interval < 5) $interval = 5;
    if ($interval > 720) $interval = 720;

    $workStart = bot_adv_calendar_time_hm((string)($input['work_start'] ?? ''), '09:00');
    $workEnd = bot_adv_calendar_time_hm((string)($input['work_end'] ?? ''), '18:00');

    $startMinutes = (int)substr($workStart, 0, 2) * 60 + (int)substr($workStart, 3, 2);
    $endMinutes = (int)substr($workEnd, 0, 2) * 60 + (int)substr($workEnd, 3, 2);
    if ($endMinutes <= $startMinutes) {
      throw new RuntimeException('Р С›Р С”Р С•Р Р…РЎвЂЎР В°Р Р…Р С‘Р Вµ РЎР‚Р В°Р В±Р С•РЎвЂЎР ВµР С–Р С• Р Т‘Р Р…РЎРЏ Р Т‘Р С•Р В»Р В¶Р Р…Р С• Р В±РЎвЂ№РЎвЂљРЎРЉ Р С—Р С•Р В·Р В¶Р Вµ Р Р…Р В°РЎвЂЎР В°Р В»Р В°');
    }

    $dayoffRaw = $input['day_off_weekdays'] ?? [];
    if (!is_array($dayoffRaw)) $dayoffRaw = [];
    $dayoffMask = bot_adv_calendar_dayoff_mask_from_weekdays($dayoffRaw);

    $pdo->prepare("
      INSERT INTO " . BOT_ADV_CALENDAR_TABLE_USER_OPTIONS . "
      (user_id, booking_mode, slot_interval_minutes, work_start, work_end, dayoff_mask, updated_by)
      VALUES (:user_id, :booking_mode, :slot_interval_minutes, :work_start, :work_end, :dayoff_mask, :updated_by)
      ON DUPLICATE KEY UPDATE
        booking_mode = VALUES(booking_mode),
        slot_interval_minutes = VALUES(slot_interval_minutes),
        work_start = VALUES(work_start),
        work_end = VALUES(work_end),
        dayoff_mask = VALUES(dayoff_mask),
        updated_by = VALUES(updated_by),
        updated_at = CURRENT_TIMESTAMP
    ")->execute([
      ':user_id' => $userId,
      ':booking_mode' => $mode,
      ':slot_interval_minutes' => $interval,
      ':work_start' => $workStart,
      ':work_end' => $workEnd,
      ':dayoff_mask' => $dayoffMask,
      ':updated_by' => ($updatedBy > 0 ? $updatedBy : 0),
    ]);

    return bot_adv_calendar_user_options_get($pdo, $userId);
  }

  function bot_adv_calendar_user_windows_list(PDO $pdo, int $userId): array
  {
    bot_adv_calendar_require_schema($pdo);
    if ($userId <= 0) return [];

    $st = $pdo->prepare("
      SELECT id, user_id, weekday, window_type, time_from, time_to, price, is_active, sort
      FROM " . BOT_ADV_CALENDAR_TABLE_USER_WINDOWS . "
      WHERE user_id = :user_id
      ORDER BY weekday ASC, sort ASC, id ASC
    ");
    $st->execute([':user_id' => $userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  function bot_adv_calendar_user_window_add(PDO $pdo, int $userId, array $input): array
  {
    bot_adv_calendar_require_schema($pdo);

    if ($userId <= 0) {
      return ['ok' => false, 'error' => 'Р СњР ВµР С”Р С•РЎР‚РЎР‚Р ВµР С”РЎвЂљР Р…РЎвЂ№Р в„– Р С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»РЎРЉ'];
    }

    $weekday = (int)($input['weekday'] ?? 0);
    if ($weekday < 0 || $weekday > 7) {
      return ['ok' => false, 'error' => 'Р СњР ВµР С”Р С•РЎР‚РЎР‚Р ВµР С”РЎвЂљР Р…РЎвЂ№Р в„– Р Т‘Р ВµР Р…РЎРЉ Р Р…Р ВµР Т‘Р ВµР В»Р С‘'];
    }

    $windowType = trim((string)($input['window_type'] ?? 'fixed'));
    if (!in_array($windowType, ['fixed', 'range'], true)) {
      $windowType = 'fixed';
    }

    $timeFrom = bot_adv_calendar_parse_hm((string)($input['time_from'] ?? ''));
    if ($timeFrom === null) {
      return ['ok' => false, 'error' => 'Р Р€Р С”Р В°Р В¶Р С‘РЎвЂљР Вµ Р С”Р С•РЎР‚РЎР‚Р ВµР С”РЎвЂљР Р…Р С•Р Вµ Р Р†РЎР‚Р ВµР СРЎРЏ Р Р…Р В°РЎвЂЎР В°Р В»Р В°'];
    }

    $timeTo = null;
    if ($windowType === 'range') {
      $timeTo = bot_adv_calendar_parse_hm((string)($input['time_to'] ?? ''));
      if ($timeTo === null) {
        return ['ok' => false, 'error' => 'Р Р€Р С”Р В°Р В¶Р С‘РЎвЂљР Вµ Р С”Р С•РЎР‚РЎР‚Р ВµР С”РЎвЂљР Р…Р С•Р Вµ Р Р†РЎР‚Р ВµР СРЎРЏ Р С•Р С”Р С•Р Р…РЎвЂЎР В°Р Р…Р С‘РЎРЏ'];
      }

      $fromMinutes = (int)substr($timeFrom, 0, 2) * 60 + (int)substr($timeFrom, 3, 2);
      $toMinutes = (int)substr($timeTo, 0, 2) * 60 + (int)substr($timeTo, 3, 2);
      if ($toMinutes <= $fromMinutes) {
        return ['ok' => false, 'error' => 'Р С›Р С”Р С•Р Р…РЎвЂЎР В°Р Р…Р С‘Р Вµ Р С•Р С”Р Р…Р В° Р Т‘Р С•Р В»Р В¶Р Р…Р С• Р В±РЎвЂ№РЎвЂљРЎРЉ Р С—Р С•Р В·Р В¶Р Вµ Р Р…Р В°РЎвЂЎР В°Р В»Р В°'];
      }
    }

    $price = (int)($input['price'] ?? 0);
    if ($price < 0) $price = 0;
    if ($price > 1000000000) $price = 1000000000;

    $sort = (int)($input['sort'] ?? 100);
    if ($sort < -100000) $sort = -100000;
    if ($sort > 100000) $sort = 100000;

    $pdo->prepare("
      INSERT INTO " . BOT_ADV_CALENDAR_TABLE_USER_WINDOWS . "
      (user_id, weekday, window_type, time_from, time_to, price, is_active, sort)
      VALUES (:user_id, :weekday, :window_type, :time_from, :time_to, :price, 1, :sort)
    ")->execute([
      ':user_id' => $userId,
      ':weekday' => $weekday,
      ':window_type' => $windowType,
      ':time_from' => $timeFrom,
      ':time_to' => $timeTo,
      ':price' => $price,
      ':sort' => $sort,
    ]);

    return ['ok' => true];
  }

  function bot_adv_calendar_user_window_delete(PDO $pdo, int $userId, int $windowId): bool
  {
    bot_adv_calendar_require_schema($pdo);

    if ($userId <= 0 || $windowId <= 0) return false;

    $st = $pdo->prepare("
      DELETE FROM " . BOT_ADV_CALENDAR_TABLE_USER_WINDOWS . "
      WHERE id = :id
        AND user_id = :user_id
      LIMIT 1
    ");
    $st->execute([
      ':id' => $windowId,
      ':user_id' => $userId,
    ]);

    return ((int)$st->rowCount() > 0);
  }

  function bot_adv_calendar_date_weekday(string $date): int
  {
    $date = trim($date);
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return 0;
    $ts = strtotime($date);
    if ($ts === false) return 0;
    return (int)date('N', $ts); // 1..7
  }

  function bot_adv_calendar_dayoff_mask_is_off(string $mask, int $weekday): bool
  {
    $mask = bot_adv_calendar_dayoff_mask_normalize($mask);
    if ($weekday < 1 || $weekday > 7) return false;
    return (substr($mask, $weekday - 1, 1) === '1');
  }

  function bot_adv_calendar_client_adv_slots(PDO $pdo, string $date): array
  {
    $weekday = bot_adv_calendar_date_weekday($date);
    if ($weekday <= 0) return [];
    if ($date < date('Y-m-d')) return [];
    $todayNowMin = null;
    if ($date === date('Y-m-d')) {
      $todayNowMin = ((int)date('H') * 60) + (int)date('i');
    }

    $sql = "
      SELECT
        w.user_id,
        w.window_type,
        TIME_FORMAT(w.time_from, '%H:%i') AS time_from_hm,
        TIME_FORMAT(w.time_to, '%H:%i') AS time_to_hm,
        w.price,
        w.sort,
        COALESCE(NULLIF(TRIM(u.name), ''), CONCAT('User #', w.user_id)) AS user_name,
        COALESCE(uo.booking_mode, 'fixed') AS booking_mode,
        COALESCE(uo.slot_interval_minutes, 60) AS slot_interval_minutes,
        COALESCE(uo.dayoff_mask, '0000011') AS dayoff_mask,
        COALESCE(TIME_FORMAT(uo.work_start, '%H:%i'), '09:00') AS work_start_hm,
        COALESCE(TIME_FORMAT(uo.work_end, '%H:%i'), '18:00') AS work_end_hm
      FROM " . BOT_ADV_CALENDAR_TABLE_USER_WINDOWS . " w
      JOIN " . BOT_ADV_CALENDAR_TABLE_USER_ACCESS . " ua
        ON ua.user_id = w.user_id
      LEFT JOIN " . BOT_ADV_CALENDAR_TABLE_USER_OPTIONS . " uo
        ON uo.user_id = w.user_id
      LEFT JOIN " . BOT_ADV_CALENDAR_USERS_TABLE . " u
        ON u.id = w.user_id
      WHERE w.is_active = 1
        AND (w.weekday = 0 OR w.weekday = :weekday)
      ORDER BY w.user_id ASC, w.weekday ASC, w.sort ASC, w.id ASC
    ";

    $st = $pdo->prepare($sql);
    $st->execute([':weekday' => $weekday]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows) || !$rows) return [];

    $out = [];
    $seen = [];
    $bookingsByUser = [];

    foreach ($rows as $row) {
      $userId = (int)($row['user_id'] ?? 0);
      if ($userId <= 0) continue;

      $dayoffMask = (string)($row['dayoff_mask'] ?? '0000011');
      if (bot_adv_calendar_dayoff_mask_is_off($dayoffMask, $weekday)) {
        continue;
      }

      $bookingMode = trim((string)($row['booking_mode'] ?? 'fixed'));
      if (!in_array($bookingMode, ['fixed', 'range'], true)) {
        $bookingMode = 'fixed';
      }

      $windowType = trim((string)($row['window_type'] ?? 'fixed'));
      if (!in_array($windowType, ['fixed', 'range'], true)) {
        $windowType = 'fixed';
      }

      if ($windowType !== $bookingMode) {
        continue;
      }

      $interval = (int)($row['slot_interval_minutes'] ?? 60);
      if ($interval < 5) $interval = 5;
      if ($interval > 720) $interval = 720;

      $workStartHm = bot_adv_calendar_time_hm((string)($row['work_start_hm'] ?? ''), '09:00');
      $workEndHm = bot_adv_calendar_time_hm((string)($row['work_end_hm'] ?? ''), '18:00');
      $workStartMin = calendar_time_to_minutes($workStartHm);
      $workEndMin = calendar_time_to_minutes($workEndHm);
      if ($workEndMin <= $workStartMin) {
        $workStartMin = calendar_time_to_minutes('09:00');
        $workEndMin = calendar_time_to_minutes('18:00');
      }

      $timeFromHm = bot_adv_calendar_time_hm((string)($row['time_from_hm'] ?? ''), '09:00');
      $timeFromMin = calendar_time_to_minutes($timeFromHm);
      $price = (int)($row['price'] ?? 0);
      if ($price < 0) $price = 0;
      $userName = trim((string)($row['user_name'] ?? ('User #' . $userId)));

      if (!isset($bookingsByUser[$userId])) {
        $bookingsByUser[$userId] = [];
        $bookings = calendar_get_bookings_day($pdo, $userId, $date);
        if (is_array($bookings)) {
          foreach ($bookings as $b) {
            $visitAt = trim((string)($b['visit_at'] ?? ''));
            if ($visitAt === '') continue;
            $hm = date('H:i', strtotime($visitAt));
            $bStart = calendar_time_to_minutes($hm);
            $bDur = (int)($b['duration_min'] ?? 0);
            if ($bDur <= 0) $bDur = 30;
            $bookingsByUser[$userId][] = [
              'from' => $bStart,
              'to' => ($bStart + $bDur),
            ];
          }
        }
      }

      $bookingIntervals = (array)($bookingsByUser[$userId] ?? []);

      if ($windowType === 'fixed') {
        if ($timeFromMin < $workStartMin || $timeFromMin >= $workEndMin) {
          continue;
        }
        if ($todayNowMin !== null && $timeFromMin < $todayNowMin) {
          continue;
        }

        $slotTime = calendar_minutes_to_time($timeFromMin);
        $key = $userId . '|fixed|' . $slotTime;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $slotToMin = $timeFromMin + $interval;
        $isBusy = false;
        foreach ($bookingIntervals as $bi) {
          $biFrom = (int)($bi['from'] ?? 0);
          $biTo = (int)($bi['to'] ?? 0);
          if ($slotToMin > $biFrom && $timeFromMin < $biTo) {
            $isBusy = true;
            break;
          }
        }

        $out[] = [
          'user_id' => $userId,
          'user_name' => $userName,
          'window_type' => 'fixed',
          'time' => $slotTime,
          'price' => $price,
          'is_busy' => $isBusy ? 1 : 0,
        ];
        continue;
      }

      $timeToHmRaw = trim((string)($row['time_to_hm'] ?? ''));
      if ($timeToHmRaw === '') continue;
      $timeToHm = bot_adv_calendar_time_hm($timeToHmRaw, $timeFromHm);
      $timeToMin = calendar_time_to_minutes($timeToHm);
      if ($timeToMin <= $timeFromMin) continue;

      $fromMin = max($timeFromMin, $workStartMin);
      $toMin = min($timeToMin, $workEndMin);
      if ($toMin <= $fromMin) continue;

      for ($t = $fromMin; $t + $interval <= $toMin; $t += $interval) {
        if ($todayNowMin !== null && $t < $todayNowMin) {
          continue;
        }
        $slotTime = calendar_minutes_to_time($t);
        $key = $userId . '|range|' . $slotTime;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $slotToMin = $t + $interval;
        $isBusy = false;
        foreach ($bookingIntervals as $bi) {
          $biFrom = (int)($bi['from'] ?? 0);
          $biTo = (int)($bi['to'] ?? 0);
          if ($slotToMin > $biFrom && $t < $biTo) {
            $isBusy = true;
            break;
          }
        }

        $out[] = [
          'user_id' => $userId,
          'user_name' => $userName,
          'window_type' => 'range',
          'time' => $slotTime,
          'price' => $price,
          'is_busy' => $isBusy ? 1 : 0,
        ];
      }
    }

    usort($out, static function (array $a, array $b): int {
      $ta = (string)($a['time'] ?? '');
      $tb = (string)($b['time'] ?? '');
      if ($ta === $tb) {
        $ua = (int)($a['user_id'] ?? 0);
        $ub = (int)($b['user_id'] ?? 0);
        if ($ua === $ub) {
          $ba = (int)($a['is_busy'] ?? 0);
          $bb = (int)($b['is_busy'] ?? 0);
          if ($ba !== $bb) return $ba <=> $bb;
          return ((int)($a['price'] ?? 0)) <=> ((int)($b['price'] ?? 0));
        }
        return $ua <=> $ub;
      }
      return strcmp($ta, $tb);
    });

    return $out;
  }

  function bot_adv_calendar_client_free_slots(array $slots): array
  {
    if (!$slots) return [];
    $free = [];
    foreach ($slots as $slot) {
      if (!is_array($slot)) continue;
      if ((int)($slot['is_busy'] ?? 0) === 1) continue;
      $free[] = $slot;
    }
    return $free;
  }

  function bot_adv_calendar_client_adv_slots_text(array $slots, int $limit = 12): string
  {
    $slots = bot_adv_calendar_client_free_slots($slots);
    $showAll = ($limit <= 0);
    if (!$showAll) {
      if ($limit < 1) $limit = 1;
      if ($limit > 100) $limit = 100;
    }

    if (!$slots) return 'Р вЂќР С•РЎРѓРЎвЂљРЎС“Р С—Р Р…РЎвЂ№РЎвЂ¦ РЎР‚Р ВµР С”Р В»Р В°Р СР Р…РЎвЂ№РЎвЂ¦ Р С•Р С”Р С•Р Р… Р Р…Р ВµРЎвЂљ.';

    $total = count($slots);
    $lines = [];
    $n = 0;
    foreach ($slots as $slot) {
      if (!$showAll && $n >= $limit) break;
      $time = trim((string)($slot['time'] ?? ''));
      if ($time === '') continue;
      $price = (int)($slot['price'] ?? 0);
      $isBusy = ((int)($slot['is_busy'] ?? 0) === 1);
      $userName = trim((string)($slot['user_name'] ?? ''));
      $priceText = number_format($price, 0, '.', ' ') . ' РІвЂљР…';
      $line = 'РІР‚Сћ ' . $time . ' ' . ($isBusy ? '+' : '-') . ' ' . $priceText;
      if ($userName !== '') $line .= ' (' . $userName . ')';
      $lines[] = $line;
      $n++;
    }

    if (!$lines) return 'Р вЂќР С•РЎРѓРЎвЂљРЎС“Р С—Р Р…РЎвЂ№РЎвЂ¦ РЎР‚Р ВµР С”Р В»Р В°Р СР Р…РЎвЂ№РЎвЂ¦ Р С•Р С”Р С•Р Р… Р Р…Р ВµРЎвЂљ.';
    if (!$showAll && $total > $n) {
      $lines[] = 'РІР‚В¦ Р С‘ Р ВµРЎвЂ°Р Вµ ' . ($total - $n) . ' Р С•Р С”Р С•Р Р…';
    }

    return implode("\n", $lines);
  }

  function bot_adv_calendar_client_slots_preview(array $slots, int $limit = 3): string
  {
    $slots = bot_adv_calendar_client_free_slots($slots);
    if ($limit < 1) $limit = 1;
    if ($limit > 6) $limit = 6;
    if (!$slots) return '';

    $parts = [];
    $n = 0;
    foreach ($slots as $slot) {
      if ($n >= $limit) break;
      $time = trim((string)($slot['time'] ?? ''));
      if ($time === '') continue;
      $parts[] = bot_adv_calendar_time_compact($time);
      $n++;
    }

    return implode(' ', $parts);
  }

  function bot_adv_calendar_time_compact(string $hm): string
  {
    $hm = bot_adv_calendar_time_hm($hm, '');
    if ($hm === '') return '';
    if (substr($hm, 3, 2) === '00') {
      return (string)((int)substr($hm, 0, 2));
    }
    return ((int)substr($hm, 0, 2)) . ':' . substr($hm, 3, 2);
  }

  function bot_adv_calendar_client_slot_find(array $slots, int $userId, string $time): array
  {
    $time = bot_adv_calendar_time_hm($time, '');
    if ($userId <= 0 || $time === '') return [];

    foreach ($slots as $slot) {
      if ((int)($slot['user_id'] ?? 0) !== $userId) continue;
      if (bot_adv_calendar_time_hm((string)($slot['time'] ?? ''), '') !== $time) continue;
      return is_array($slot) ? $slot : [];
    }
    return [];
  }

  function bot_adv_calendar_client_slot_callback_data(string $date, array $slot): string
  {
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return 'calendar_noop';
    $userId = (int)($slot['user_id'] ?? 0);
    $time = bot_adv_calendar_time_hm((string)($slot['time'] ?? ''), '');
    if ($userId <= 0 || $time === '') return 'calendar_noop';
    $dateKey = str_replace('-', '', $date);
    $timeKey = str_replace(':', '', $time);
    return 'calendar_book:' . $dateKey . ':' . $userId . ':' . $timeKey;
  }

  function bot_adv_calendar_client_slot_callback_parse(string $callbackData): array
  {
    $callbackData = trim($callbackData);
    if (!preg_match('~^calendar_book:(\d{8}):(\d+):(\d{4})$~', $callbackData, $m)) {
      return ['ok' => false, 'reason' => 'format'];
    }

    $date = substr((string)$m[1], 0, 4) . '-' . substr((string)$m[1], 4, 2) . '-' . substr((string)$m[1], 6, 2);
    $userId = (int)$m[2];
    $time = substr((string)$m[3], 0, 2) . ':' . substr((string)$m[3], 2, 2);

    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return ['ok' => false, 'reason' => 'date'];
    if ($userId <= 0) return ['ok' => false, 'reason' => 'user'];
    if (!preg_match('~^\d{2}:\d{2}$~', $time)) return ['ok' => false, 'reason' => 'time'];

    $mins = calendar_time_to_minutes($time);
    if ($mins < 0 || $mins >= 1440) return ['ok' => false, 'reason' => 'time'];

    return [
      'ok' => true,
      'date' => $date,
      'user_id' => $userId,
      'time' => $time,
    ];
  }

  function bot_adv_calendar_client_day_slots_keyboard(string $date, array $slots): array
  {
    $slots = bot_adv_calendar_client_free_slots($slots);
    $month = substr($date, 0, 7);
    $kb = [];
    $shown = 0;
    $maxRows = 40;

    foreach ($slots as $slot) {
      if ($shown >= $maxRows) break;

      $time = bot_adv_calendar_time_hm((string)($slot['time'] ?? ''), '');
      if ($time === '') continue;

      $price = (int)($slot['price'] ?? 0);
      if ($price < 0) $price = 0;
      $isBusy = false;
      $busyMark = '-';
      $priceText = number_format($price, 0, '.', ' ') . ' РІвЂљР…';
      $userName = trim((string)($slot['user_name'] ?? ''));

      $text = $time . ' ' . $busyMark . ' ' . $priceText;
      if ($userName !== '') $text .= ' РІР‚Сћ ' . $userName;

      $kb[] = [[
        'text' => $text,
        'callback_data' => bot_adv_calendar_client_slot_callback_data($date, $slot),
      ]];
      $shown++;
    }

    if (count($slots) > $shown) {
      $kb[] = [[
        'text' => 'РІР‚В¦ Р ВµРЎвЂ°Р Вµ ' . (count($slots) - $shown) . ' Р С•Р С”Р С•Р Р…',
        'callback_data' => 'calendar_noop',
      ]];
    }

    $kb[] = [
      ['text' => 'Р С›Р В±Р Р…Р С•Р Р†Р С‘РЎвЂљРЎРЉ Р Т‘Р ВµР Р…РЎРЉ', 'callback_data' => 'calendar_day:' . $date],
      ['text' => 'Р С™Р В°Р В»Р ВµР Р…Р Т‘Р В°РЎР‚РЎРЉ', 'callback_data' => 'calendar_month:' . $month],
    ];

    return ['inline_keyboard' => $kb];
  }

  function bot_adv_calendar_user_windows_enabled(PDO $pdo, int $userId): bool
  {
    if ($userId <= 0) return false;

    static $cache = [];
    if (array_key_exists($userId, $cache)) {
      return (bool)$cache[$userId];
    }

    $st = $pdo->prepare("
      SELECT 1
      FROM " . BOT_ADV_CALENDAR_TABLE_USER_WINDOWS . "
      WHERE user_id = :uid
        AND is_active = 1
      LIMIT 1
    ");
    $st->execute([':uid' => $userId]);
    $row = $st->fetch(PDO::FETCH_NUM);
    $cache[$userId] = (is_array($row) && !empty($row));
    return (bool)$cache[$userId];
  }

  function bot_adv_calendar_booking_client_label(array $booking): string
  {
    $name = trim((string)($booking['client_name'] ?? ''));
    return ($name !== '') ? $name : 'busy';
  }

  function bot_adv_calendar_user_adv_slots(PDO $pdo, string $date, int $userId): array
  {
    $weekday = bot_adv_calendar_date_weekday($date);
    if ($weekday <= 0 || $userId <= 0) return [];
    if ($date < date('Y-m-d')) return [];

    $todayNowMin = null;
    if ($date === date('Y-m-d')) {
      $todayNowMin = ((int)date('H') * 60) + (int)date('i');
    }

    $sql = "
      SELECT
        w.user_id,
        w.window_type,
        TIME_FORMAT(w.time_from, '%H:%i') AS time_from_hm,
        TIME_FORMAT(w.time_to, '%H:%i') AS time_to_hm,
        w.price,
        w.sort,
        COALESCE(NULLIF(TRIM(u.name), ''), CONCAT('User #', w.user_id)) AS user_name,
        COALESCE(uo.booking_mode, 'fixed') AS booking_mode,
        COALESCE(uo.slot_interval_minutes, 60) AS slot_interval_minutes,
        COALESCE(uo.dayoff_mask, '0000011') AS dayoff_mask,
        COALESCE(TIME_FORMAT(uo.work_start, '%H:%i'), '09:00') AS work_start_hm,
        COALESCE(TIME_FORMAT(uo.work_end, '%H:%i'), '18:00') AS work_end_hm
      FROM " . BOT_ADV_CALENDAR_TABLE_USER_WINDOWS . " w
      JOIN " . BOT_ADV_CALENDAR_TABLE_USER_ACCESS . " ua
        ON ua.user_id = w.user_id
      LEFT JOIN " . BOT_ADV_CALENDAR_TABLE_USER_OPTIONS . " uo
        ON uo.user_id = w.user_id
      LEFT JOIN " . BOT_ADV_CALENDAR_USERS_TABLE . " u
        ON u.id = w.user_id
      WHERE w.user_id = :user_id
        AND w.is_active = 1
        AND (w.weekday = 0 OR w.weekday = :weekday)
      ORDER BY w.weekday ASC, w.sort ASC, w.id ASC
    ";

    $st = $pdo->prepare($sql);
    $st->execute([
      ':user_id' => $userId,
      ':weekday' => $weekday,
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows) || !$rows) return [];

    $dayoffMask = (string)($rows[0]['dayoff_mask'] ?? '0000011');
    if (bot_adv_calendar_dayoff_mask_is_off($dayoffMask, $weekday)) {
      return [];
    }

    $bookings = calendar_get_bookings_day($pdo, $userId, $date);
    $bookingIntervals = [];
    if (is_array($bookings)) {
      foreach ($bookings as $b) {
        $visitAt = trim((string)($b['visit_at'] ?? ''));
        if ($visitAt === '') continue;
        $hm = date('H:i', strtotime($visitAt));
        $start = calendar_time_to_minutes($hm);
        $dur = (int)($b['duration_min'] ?? 0);
        if ($dur <= 0) $dur = 30;
        $bookingIntervals[] = [
          'from' => $start,
          'to' => ($start + $dur),
          'client_name' => bot_adv_calendar_booking_client_label($b),
        ];
      }
    }

    $outMap = [];
    foreach ($rows as $row) {
      $bookingMode = trim((string)($row['booking_mode'] ?? 'fixed'));
      if (!in_array($bookingMode, ['fixed', 'range'], true)) $bookingMode = 'fixed';

      $windowType = trim((string)($row['window_type'] ?? 'fixed'));
      if (!in_array($windowType, ['fixed', 'range'], true)) $windowType = 'fixed';
      if ($windowType !== $bookingMode) continue;

      $interval = (int)($row['slot_interval_minutes'] ?? 60);
      if ($interval < 5) $interval = 5;
      if ($interval > 720) $interval = 720;

      $workStartHm = bot_adv_calendar_time_hm((string)($row['work_start_hm'] ?? ''), '09:00');
      $workEndHm = bot_adv_calendar_time_hm((string)($row['work_end_hm'] ?? ''), '18:00');
      $workStartMin = calendar_time_to_minutes($workStartHm);
      $workEndMin = calendar_time_to_minutes($workEndHm);
      if ($workEndMin <= $workStartMin) {
        $workStartMin = calendar_time_to_minutes('09:00');
        $workEndMin = calendar_time_to_minutes('18:00');
      }

      $timeFromHm = bot_adv_calendar_time_hm((string)($row['time_from_hm'] ?? ''), '09:00');
      $timeFromMin = calendar_time_to_minutes($timeFromHm);
      $price = (int)($row['price'] ?? 0);
      if ($price < 0) $price = 0;
      $userName = trim((string)($row['user_name'] ?? ('User #' . $userId)));

      if ($windowType === 'fixed') {
        if ($timeFromMin < $workStartMin || $timeFromMin >= $workEndMin) {
          continue;
        }
        if ($todayNowMin !== null && $timeFromMin < $todayNowMin) {
          continue;
        }

        $slotTime = calendar_minutes_to_time($timeFromMin);
        $slotToMin = $timeFromMin + $interval;
        $isBusy = false;
        $busyClientName = '';
        foreach ($bookingIntervals as $bi) {
          $biFrom = (int)($bi['from'] ?? 0);
          $biTo = (int)($bi['to'] ?? 0);
          if ($slotToMin > $biFrom && $timeFromMin < $biTo) {
            $isBusy = true;
            $busyClientName = trim((string)($bi['client_name'] ?? ''));
            break;
          }
        }

        if (!isset($outMap[$slotTime])) {
          $outMap[$slotTime] = [
            'user_id' => $userId,
            'user_name' => $userName,
            'window_type' => 'fixed',
            'time' => $slotTime,
            'price' => $price,
            'is_busy' => $isBusy ? 1 : 0,
            'busy_client_name' => $busyClientName,
          ];
        } else if ((int)($outMap[$slotTime]['is_busy'] ?? 0) !== 1 && $isBusy) {
          $outMap[$slotTime]['is_busy'] = 1;
          $outMap[$slotTime]['busy_client_name'] = $busyClientName;
        }
        continue;
      }

      $timeToHmRaw = trim((string)($row['time_to_hm'] ?? ''));
      if ($timeToHmRaw === '') continue;
      $timeToHm = bot_adv_calendar_time_hm($timeToHmRaw, $timeFromHm);
      $timeToMin = calendar_time_to_minutes($timeToHm);
      if ($timeToMin <= $timeFromMin) continue;

      $fromMin = max($timeFromMin, $workStartMin);
      $toMin = min($timeToMin, $workEndMin);
      if ($toMin <= $fromMin) continue;

      for ($t = $fromMin; $t + $interval <= $toMin; $t += $interval) {
        if ($todayNowMin !== null && $t < $todayNowMin) {
          continue;
        }

        $slotTime = calendar_minutes_to_time($t);
        $slotToMin = $t + $interval;
        $isBusy = false;
        $busyClientName = '';
        foreach ($bookingIntervals as $bi) {
          $biFrom = (int)($bi['from'] ?? 0);
          $biTo = (int)($bi['to'] ?? 0);
          if ($slotToMin > $biFrom && $t < $biTo) {
            $isBusy = true;
            $busyClientName = trim((string)($bi['client_name'] ?? ''));
            break;
          }
        }

        if (!isset($outMap[$slotTime])) {
          $outMap[$slotTime] = [
            'user_id' => $userId,
            'user_name' => $userName,
            'window_type' => 'range',
            'time' => $slotTime,
            'price' => $price,
            'is_busy' => $isBusy ? 1 : 0,
            'busy_client_name' => $busyClientName,
          ];
        } else if ((int)($outMap[$slotTime]['is_busy'] ?? 0) !== 1 && $isBusy) {
          $outMap[$slotTime]['is_busy'] = 1;
          $outMap[$slotTime]['busy_client_name'] = $busyClientName;
        }
      }
    }

    ksort($outMap);
    return array_values($outMap);
  }

  function bot_adv_calendar_user_schedule_slots(PDO $pdo, string $date, int $userId): array
  {
    if ($userId <= 0) return [];
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return [];
    if ($date < date('Y-m-d')) return [];

    $schedule = calendar_get_schedule_day($pdo, $userId, $date);
    if (!$schedule) return [];

    $bookings = calendar_get_bookings_day($pdo, $userId, $date);
    $durationMin = bot_adv_calendar_user_slot_interval_minutes($pdo, $userId);
    $free = calendar_slots_build($schedule, is_array($bookings) ? $bookings : [], $date, $durationMin);

    $map = [];
    foreach ($free as $time) {
      $time = bot_adv_calendar_time_hm((string)$time, '');
      if ($time === '') continue;
      $map[$time] = [
        'user_id' => $userId,
        'user_name' => '',
        'window_type' => 'range',
        'time' => $time,
        'price' => 0,
        'is_busy' => 0,
        'busy_client_name' => '',
      ];
    }

    if (is_array($bookings)) {
      foreach ($bookings as $b) {
        $visitAt = trim((string)($b['visit_at'] ?? ''));
        if ($visitAt === '') continue;
        $time = date('H:i', strtotime($visitAt));
        $time = bot_adv_calendar_time_hm($time, '');
        if ($time === '') continue;

        $name = bot_adv_calendar_booking_client_label($b);
        if (!isset($map[$time])) {
          $map[$time] = [
            'user_id' => $userId,
            'user_name' => '',
            'window_type' => 'range',
            'time' => $time,
            'price' => 0,
            'is_busy' => 1,
            'busy_client_name' => $name,
          ];
          continue;
        }
        $map[$time]['is_busy'] = 1;
        $map[$time]['busy_client_name'] = $name;
      }
    }

    ksort($map);
    return array_values($map);
  }

  function bot_adv_calendar_user_day_slots(PDO $pdo, string $date, int $userId): array
  {
    if ($userId <= 0) return [];
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return [];
    if ($date < date('Y-m-d')) return [];

    if (bot_adv_calendar_user_windows_enabled($pdo, $userId)) {
      return bot_adv_calendar_user_adv_slots($pdo, $date, $userId);
    }

    return bot_adv_calendar_user_schedule_slots($pdo, $date, $userId);
  }

  function bot_adv_calendar_user_free_slots(array $slots): array
  {
    $out = [];
    foreach ($slots as $slot) {
      if ((int)($slot['is_busy'] ?? 0) === 1) continue;
      $time = bot_adv_calendar_time_hm((string)($slot['time'] ?? ''), '');
      if ($time === '') continue;
      $slot['time'] = $time;
      $out[] = $slot;
    }
    return $out;
  }

  function bot_adv_calendar_user_slot_find(array $slots, string $time): array
  {
    $time = bot_adv_calendar_time_hm($time, '');
    if ($time === '') return [];

    foreach ($slots as $slot) {
      if (bot_adv_calendar_time_hm((string)($slot['time'] ?? ''), '') !== $time) continue;
      return is_array($slot) ? $slot : [];
    }
    return [];
  }

  function bot_adv_calendar_user_slot_callback_data(string $date, string $time): string
  {
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return 'calendar_noop';
    $time = bot_adv_calendar_time_hm($time, '');
    if ($time === '') return 'calendar_noop';
    $dateKey = str_replace('-', '', $date);
    $timeKey = str_replace(':', '', $time);
    return 'calendar_user_book:' . $dateKey . ':' . $timeKey;
  }

  function bot_adv_calendar_user_slot_callback_parse(string $callbackData): array
  {
    $callbackData = trim($callbackData);
    if (!preg_match('~^calendar_user_book:(\d{8}):(\d{4})$~', $callbackData, $m)) {
      return ['ok' => false, 'reason' => 'format'];
    }

    $date = substr((string)$m[1], 0, 4) . '-' . substr((string)$m[1], 4, 2) . '-' . substr((string)$m[1], 6, 2);
    $time = substr((string)$m[2], 0, 2) . ':' . substr((string)$m[2], 2, 2);
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return ['ok' => false, 'reason' => 'date'];
    if (!preg_match('~^\d{2}:\d{2}$~', $time)) return ['ok' => false, 'reason' => 'time'];
    $mins = calendar_time_to_minutes($time);
    if ($mins < 0 || $mins >= 1440) return ['ok' => false, 'reason' => 'time'];

    return [
      'ok' => true,
      'date' => $date,
      'time' => $time,
    ];
  }

  function bot_adv_calendar_user_booking_token(string $date, string $time): string
  {
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return '';
    $time = bot_adv_calendar_time_hm($time, '');
    if ($time === '') return '';
    return 'UBOOK:' . str_replace('-', '', $date) . ':' . str_replace(':', '', $time);
  }

  function bot_adv_calendar_user_booking_token_parse(string $text): array
  {
    $text = trim($text);
    if ($text === '') return ['ok' => false, 'reason' => 'empty'];
    if (!preg_match('~UBOOK:(\d{8}):(\d{4})~', $text, $m)) {
      return ['ok' => false, 'reason' => 'format'];
    }

    $date = substr((string)$m[1], 0, 4) . '-' . substr((string)$m[1], 4, 2) . '-' . substr((string)$m[1], 6, 2);
    $time = substr((string)$m[2], 0, 2) . ':' . substr((string)$m[2], 2, 2);
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return ['ok' => false, 'reason' => 'date'];
    if (!preg_match('~^\d{2}:\d{2}$~', $time)) return ['ok' => false, 'reason' => 'time'];

    return [
      'ok' => true,
      'date' => $date,
      'time' => $time,
    ];
  }

  function bot_adv_calendar_tg_username_normalize(string $value): string
  {
    $value = trim($value);
    if ($value === '') return '';
    $value = ltrim($value, '@');
    if ($value === '') return '';
    if (!preg_match('~^[A-Za-z0-9_]{3,64}$~', $value)) return '';
    return '@' . strtolower($value);
  }

  function bot_adv_calendar_find_client_by_tg_username(PDO $pdo, string $tgUsername): array
  {
    $tgUsername = bot_adv_calendar_tg_username_normalize($tgUsername);
    if ($tgUsername === '') return [];

    $st = $pdo->prepare("
      SELECT id, first_name, last_name, middle_name, phone, status
      FROM " . BOT_ADV_CALENDAR_CLIENTS_TABLE . "
      WHERE phone = :tg
      LIMIT 1
    ");
    $st->execute([':tg' => $tgUsername]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) return $row;

    $st = $pdo->prepare("
      SELECT id, first_name, last_name, middle_name, phone, status
      FROM " . BOT_ADV_CALENDAR_CLIENTS_TABLE . "
      WHERE first_name = :tg
      ORDER BY id ASC
      LIMIT 1
    ");
    $st->execute([':tg' => $tgUsername]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
  }

  function bot_adv_calendar_create_client_by_tg_username(PDO $pdo, string $tgUsername): int
  {
    $tgUsername = bot_adv_calendar_tg_username_normalize($tgUsername);
    if ($tgUsername === '') return 0;

    $tmpPass = 'Crm' . bin2hex(random_bytes(4));
    $passHash = password_hash($tmpPass, PASSWORD_DEFAULT);

    $st = $pdo->prepare("
      INSERT INTO " . BOT_ADV_CALENDAR_CLIENTS_TABLE . "
      (first_name, last_name, middle_name, phone, email, pass_hash, pass_is_temp, status, created_at, updated_at)
      VALUES (:first_name, NULL, NULL, :phone, NULL, :pass_hash, 1, 'active', NOW(), NOW())
    ");
    $st->execute([
      ':first_name' => $tgUsername,
      ':phone' => $tgUsername,
      ':pass_hash' => $passHash,
    ]);
    return (int)$pdo->lastInsertId();
  }

  function bot_adv_calendar_find_or_create_client_by_tg_username(PDO $pdo, string $tgUsername): array
  {
    $tgUsername = bot_adv_calendar_tg_username_normalize($tgUsername);
    if ($tgUsername === '') {
      return ['id' => 0, 'created' => false, 'tg_username' => ''];
    }

    $row = bot_adv_calendar_find_client_by_tg_username($pdo, $tgUsername);
    if ($row) {
      return [
        'id' => (int)($row['id'] ?? 0),
        'created' => false,
        'tg_username' => $tgUsername,
      ];
    }

    try {
      $newId = bot_adv_calendar_create_client_by_tg_username($pdo, $tgUsername);
      return [
        'id' => $newId,
        'created' => true,
        'tg_username' => $tgUsername,
      ];
    } catch (Throwable $e) {
      $retry = bot_adv_calendar_find_client_by_tg_username($pdo, $tgUsername);
      if ($retry) {
        return [
          'id' => (int)($retry['id'] ?? 0),
          'created' => false,
          'tg_username' => $tgUsername,
        ];
      }
      throw $e;
    }
  }

  function bot_adv_calendar_user_slots_text(array $slots, int $limit = 30): string
  {
    if ($limit < 1) $limit = 1;
    if ($limit > 80) $limit = 80;
    if (!$slots) return '';

    usort($slots, static function (array $a, array $b): int {
      $ta = (string)($a['time'] ?? '');
      $tb = (string)($b['time'] ?? '');
      return strcmp($ta, $tb);
    });

    $lines = [];
    $i = 0;
    foreach ($slots as $slot) {
      if ($i >= $limit) break;
      $time = bot_adv_calendar_time_hm((string)($slot['time'] ?? ''), '');
      if ($time === '') continue;
      $isBusy = ((int)($slot['is_busy'] ?? 0) === 1);
      $price = (int)($slot['price'] ?? 0);
      if ($price < 0) $price = 0;
      $priceText = ($price > 0) ? (' | ' . number_format($price, 0, '.', ' ') . ' RUB') : '';

      if ($isBusy) {
        $busyName = trim((string)($slot['busy_client_name'] ?? ''));
        $label = ($busyName !== '') ? ('busy - ' . $busyName) : 'busy';
        $lines[] = $time . ' | ' . $label . $priceText;
      } else {
        $lines[] = $time . ' | free' . $priceText;
      }
      $i++;
    }

    if (count($slots) > $i) {
      $lines[] = '... more ' . (count($slots) - $i) . ' slots';
    }

    return implode("\n", $lines);
  }

  function bot_adv_calendar_user_day_slots_keyboard(string $date, array $slots): array
  {
    $month = substr($date, 0, 7);
    $freeSlots = bot_adv_calendar_user_free_slots($slots);
    $kb = [];
    $shown = 0;
    $maxRows = 40;

    foreach ($freeSlots as $slot) {
      if ($shown >= $maxRows) break;
      $time = bot_adv_calendar_time_hm((string)($slot['time'] ?? ''), '');
      if ($time === '') continue;

      $price = (int)($slot['price'] ?? 0);
      if ($price < 0) $price = 0;
      $text = $time;
      if ($price > 0) {
        $text .= ' | ' . number_format($price, 0, '.', ' ') . ' RUB';
      }

      $kb[] = [[
        'text' => $text,
        'callback_data' => bot_adv_calendar_user_slot_callback_data($date, $time),
      ]];
      $shown++;
    }

    if (count($freeSlots) > $shown) {
      $kb[] = [[
        'text' => '... more ' . (count($freeSlots) - $shown) . ' slots',
        'callback_data' => 'calendar_noop',
      ]];
    }

    $kb[] = [
      ['text' => 'Refresh day', 'callback_data' => 'calendar_day:' . $date],
      ['text' => 'Calendar', 'callback_data' => 'calendar_month:' . $month],
    ];

    return ['inline_keyboard' => $kb];
  }

  function bot_adv_calendar_user_calendar_keyboard(PDO $pdo, string $monthStart, int $userId): array
  {
    $monthStart = bot_adv_calendar_client_month_start($monthStart);
    $ts = strtotime($monthStart);
    if ($ts === false) $ts = time();

    $year = (int)date('Y', $ts);
    $month = (int)date('n', $ts);
    $daysInMonth = (int)date('t', $ts);
    $firstWeekday = (int)date('N', $ts);

    $prevMonth = bot_adv_calendar_client_month_shift($monthStart, -1);
    $nextMonth = bot_adv_calendar_client_month_shift($monthStart, 1);

    $kb = [];
    $kb[] = [
      ['text' => '<', 'callback_data' => 'calendar_month:' . substr($prevMonth, 0, 7)],
      ['text' => bot_adv_calendar_client_month_label($monthStart), 'callback_data' => 'calendar_noop'],
      ['text' => '>', 'callback_data' => 'calendar_month:' . substr($nextMonth, 0, 7)],
    ];

    $weekdayRow = [];
    foreach (['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as $wd) {
      $weekdayRow[] = ['text' => $wd, 'callback_data' => 'calendar_noop'];
    }
    $kb[] = $weekdayRow;

    $row = [];
    for ($i = 1; $i < $firstWeekday; $i++) {
      $row[] = ['text' => '.', 'callback_data' => 'calendar_noop'];
    }

    for ($day = 1; $day <= $daysInMonth; $day++) {
      $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
      $isPast = ($date < date('Y-m-d'));
      $slots = $isPast ? [] : bot_adv_calendar_user_day_slots($pdo, $date, $userId);
      $freeSlots = bot_adv_calendar_user_free_slots($slots);
      $slotsTotal = count($freeSlots);
      $state = bot_adv_calendar_client_day_state($slotsTotal);
      $color = $isPast ? bot_adv_calendar_utf8_icon('black') : bot_adv_calendar_client_day_state_emoji($state);
      $label = $color . (string)$day;
      $cb = $isPast ? 'calendar_noop' : ('calendar_day:' . $date);
      $row[] = ['text' => $label, 'callback_data' => $cb];

      if (count($row) >= 7) {
        $kb[] = $row;
        $row = [];
      }
    }

    if ($row) {
      while (count($row) < 7) {
        $row[] = ['text' => '.', 'callback_data' => 'calendar_noop'];
      }
      $kb[] = $row;
    }

    return ['inline_keyboard' => $kb];
  }

  function bot_adv_calendar_user_calendar_text(string $monthStart): string
  {
    $g = bot_adv_calendar_utf8_icon('green');
    $y = bot_adv_calendar_utf8_icon('yellow');
    $r = bot_adv_calendar_utf8_icon('red');
    return "Ad calendar (your slots): " . bot_adv_calendar_client_month_label($monthStart) . "\n"
      . "Choose a day.\n"
      . "{$g} free  {$y} few  {$r} no slots\n"
      . "Cell: color + day number.";
  }

  function bot_adv_calendar_send_user_calendar(PDO $pdo, string $botToken, string $chatId, int $userId, string $monthStart = ''): void
  {
    $monthStart = bot_adv_calendar_client_month_start($monthStart);
    tg_send_message($botToken, $chatId, bot_adv_calendar_user_calendar_text($monthStart), [
      'parse_mode' => 'HTML',
      'reply_markup' => bot_adv_calendar_user_calendar_keyboard($pdo, $monthStart, $userId),
    ]);
  }

  function bot_adv_calendar_edit_user_calendar(PDO $pdo, string $botToken, string $chatId, int $messageId, int $userId, string $monthStart = ''): void
  {
    $monthStart = bot_adv_calendar_client_month_start($monthStart);
    tg_edit_message_text($botToken, [
      'chat_id' => $chatId,
      'message_id' => $messageId,
      'text' => bot_adv_calendar_user_calendar_text($monthStart),
      'parse_mode' => 'HTML',
      'reply_markup' => bot_adv_calendar_user_calendar_keyboard($pdo, $monthStart, $userId),
    ]);
  }

  function bot_adv_calendar_process_user_booking_input(PDO $pdo, array $meta, array $link, array $settings): array
  {
    $botToken = trim((string)($settings['bot_token'] ?? ''));
    $chatId = trim((string)($meta['chat_id'] ?? ''));
    $text = trim((string)($meta['text'] ?? ''));
    $replyText = trim((string)($meta['reply_text'] ?? ''));

    if ($chatId === '' || $text === '') {
      return ['ok' => true, 'handled' => false, 'reason' => 'user_booking_empty'];
    }
    if (strpos($text, '/') === 0) {
      return ['ok' => true, 'handled' => false, 'reason' => 'user_booking_command'];
    }

    $tgUsername = bot_adv_calendar_tg_username_normalize($text);
    if ($tgUsername === '') {
      return ['ok' => true, 'handled' => false, 'reason' => 'user_booking_not_username'];
    }

    $token = bot_adv_calendar_user_booking_token_parse($replyText);
    if (($token['ok'] ?? false) !== true) {
      $token = bot_adv_calendar_user_booking_token_parse($text);
    }
    if (($token['ok'] ?? false) !== true) {
      if ($botToken !== '') {
        tg_send_message(
          $botToken,
          $chatId,
          'Reply to the selected slot message with @user_nameTG.'
        );
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'user_booking_no_token'];
    }

    $date = (string)($token['date'] ?? '');
    $time = (string)($token['time'] ?? '');
    if ($date < date('Y-m-d')) {
      if ($botToken !== '') {
        tg_send_message($botToken, $chatId, 'Past dates are not available for booking.');
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'user_booking_past'];
    }

    $userId = (int)($link['actor_id'] ?? 0);
    if ($userId <= 0) {
      if ($botToken !== '') {
        tg_send_message($botToken, $chatId, 'Failed to detect CRM user for booking.');
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'user_booking_user_missing'];
    }

    $slots = bot_adv_calendar_user_day_slots($pdo, $date, $userId);
    $slot = bot_adv_calendar_user_slot_find($slots, $time);

    if (!$slot) {
      if ($botToken !== '') {
        tg_send_message(
          $botToken,
          $chatId,
          "Slot {$time} on {$date} is no longer available.\nRefresh the day and choose another one.",
          ['reply_markup' => bot_adv_calendar_user_day_slots_keyboard($date, $slots)]
        );
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'user_booking_slot_missing'];
    }
    if ((int)($slot['is_busy'] ?? 0) === 1) {
      if ($botToken !== '') {
        tg_send_message(
          $botToken,
          $chatId,
          "Slot {$time} is already busy. Choose another one.",
          ['reply_markup' => bot_adv_calendar_user_day_slots_keyboard($date, $slots)]
        );
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'user_booking_slot_busy'];
    }

    try {
      $clientBind = bot_adv_calendar_find_or_create_client_by_tg_username($pdo, $tgUsername);
      $clientId = (int)($clientBind['id'] ?? 0);
      if ($clientId <= 0) {
        throw new RuntimeException('Failed to resolve client.');
      }

      $create = bot_adv_calendar_create_adv_booking_request($pdo, $clientId, $date, $slot);
      if (($create['ok'] ?? false) !== true) {
        $reason = (string)($create['reason'] ?? 'error');
        if ($botToken !== '') {
          $msg = ($reason === 'slot_taken')
            ? 'This slot is already busy. Choose another one.'
            : 'Failed to create booking. Try again.';
          tg_send_message(
            $botToken,
            $chatId,
            $msg,
            ['reply_markup' => bot_adv_calendar_user_day_slots_keyboard($date, $slots)]
          );
        }
        return ['ok' => true, 'handled' => true, 'reason' => 'user_booking_create_' . $reason];
      }

      if ($botToken !== '') {
        $requestId = (int)($create['request_id'] ?? 0);
        $price = (int)($create['price'] ?? 0);
        $dayLabel = date('d.m.Y', strtotime($date));
        $priceText = number_format($price, 0, '.', ' ') . ' RUB';
        $msg = "Booking created.\n"
          . "Request #{$requestId}\n"
          . "Client: {$tgUsername}\n"
          . "Date/time: {$dayLabel} {$time}\n"
          . "Price: {$priceText}\n"
          . "Source: adv_bot";
        tg_send_message($botToken, $chatId, $msg);
        bot_adv_calendar_send_user_calendar($pdo, $botToken, $chatId, $userId, substr($date, 0, 7));
      }

      return ['ok' => true, 'handled' => true, 'reason' => 'user_booking_created'];
    } catch (Throwable $e) {
      if ($botToken !== '') {
        tg_send_message($botToken, $chatId, 'Booking error. Try again.');
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'user_booking_error'];
    }
  }

  function bot_adv_calendar_user_slot_interval_minutes(PDO $pdo, int $userId): int
  {
    if ($userId <= 0) return 60;

    $st = $pdo->prepare("
      SELECT slot_interval_minutes
      FROM " . BOT_ADV_CALENDAR_TABLE_USER_OPTIONS . "
      WHERE user_id = :uid
      LIMIT 1
    ");
    $st->execute([':uid' => $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $interval = (int)($row['slot_interval_minutes'] ?? 60);
    if ($interval < 5) $interval = 5;
    if ($interval > 720) $interval = 720;
    return $interval;
  }

  function bot_adv_calendar_client_contact(PDO $pdo, int $clientId): array
  {
    if ($clientId <= 0) return [];

    $st = $pdo->prepare("
      SELECT id, first_name, last_name, middle_name, phone, email, status
      FROM " . BOT_ADV_CALENDAR_CLIENTS_TABLE . "
      WHERE id = :id
      LIMIT 1
    ");
    $st->execute([':id' => $clientId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
  }

  function bot_adv_calendar_client_name_from_row(array $row): string
  {
    $parts = [
      trim((string)($row['last_name'] ?? '')),
      trim((string)($row['first_name'] ?? '')),
      trim((string)($row['middle_name'] ?? '')),
    ];
    $parts = array_values(array_filter($parts, static function (string $v): bool {
      return $v !== '';
    }));
    $name = trim(implode(' ', $parts));
    if ($name === '') {
      $name = trim((string)($row['first_name'] ?? ''));
    }
    return $name;
  }

  function bot_adv_calendar_create_adv_booking_request(PDO $pdo, int $clientId, string $date, array $slot): array
  {
    if ($clientId <= 0) return ['ok' => false, 'reason' => 'client_invalid'];
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return ['ok' => false, 'reason' => 'date_invalid'];

    $specialistId = (int)($slot['user_id'] ?? 0);
    $time = bot_adv_calendar_time_hm((string)($slot['time'] ?? ''), '');
    if ($specialistId <= 0 || $time === '') {
      return ['ok' => false, 'reason' => 'slot_invalid'];
    }

    $price = (int)($slot['price'] ?? 0);
    if ($price < 0) $price = 0;

    $client = bot_adv_calendar_client_contact($pdo, $clientId);
    if (!$client) return ['ok' => false, 'reason' => 'client_not_found'];

    $clientName = bot_adv_calendar_client_name_from_row($client);
    $clientPhone = trim((string)($client['phone'] ?? ''));
    $clientEmail = trim((string)($client['email'] ?? ''));
    if ($clientName === '') $clientName = 'Р С™Р В»Р С‘Р ВµР Р…РЎвЂљ #' . $clientId;
    if ($clientPhone === '') return ['ok' => false, 'reason' => 'client_phone_missing'];

    $durationMin = bot_adv_calendar_user_slot_interval_minutes($pdo, $specialistId);
    $visitAt = $date . ' ' . $time . ':00';
    $slotKey = requests_slot_key($specialistId, $visitAt);
    if ($slotKey === null) return ['ok' => false, 'reason' => 'slot_key_invalid'];

    try {
      $pdo->beginTransaction();

      $pdo->prepare("
        INSERT INTO " . REQUESTS_TABLE . "
          (status, source, client_id, client_name, client_phone, client_email,
           service_id, specialist_user_id, visit_at, slot_key, duration_min, price_total, created_by, created_at, updated_at)
        VALUES
          (:status, :source, :client_id, :client_name, :client_phone, :client_email,
           :service_id, :specialist_user_id, :visit_at, :slot_key, :duration_min, :price_total, :created_by, NOW(), NOW())
      ")->execute([
        ':status' => REQUESTS_STATUS_NEW,
        ':source' => 'adv_bot',
        ':client_id' => $clientId,
        ':client_name' => $clientName,
        ':client_phone' => $clientPhone,
        ':client_email' => ($clientEmail !== '' ? $clientEmail : null),
        ':service_id' => null,
        ':specialist_user_id' => $specialistId,
        ':visit_at' => $visitAt,
        ':slot_key' => $slotKey,
        ':duration_min' => $durationMin,
        ':price_total' => ($price > 0 ? $price : null),
        ':created_by' => null,
      ]);

      $requestId = (int)$pdo->lastInsertId();
      if ($requestId <= 0) throw new RuntimeException('request_insert_failed');

      requests_add_history($pdo, $requestId, null, 'create', null, REQUESTS_STATUS_NEW, [
        'source' => 'adv_bot',
        'channel' => 'telegram',
        'kind' => 'adv_booking',
      ]);
      requests_add_comment($pdo, $requestId, null, $clientId, 'client', 'Р вЂРЎР‚Р С•Р Р…РЎРЉ РЎР‚Р ВµР С”Р В»Р В°Р СРЎвЂ№ РЎвЂЎР ВµРЎР‚Р ВµР В· Telegram-Р В±Р С•РЎвЂљ.');

      $pdo->commit();

      $notify = requests_tg_notify_status($pdo, $requestId, REQUESTS_STATUS_NEW, [
        'actor_user_id' => 0,
        'actor_role' => 'client',
        'action' => 'create_adv_bot',
      ]);

      return [
        'ok' => true,
        'request_id' => $requestId,
        'visit_at' => $visitAt,
        'price' => $price,
        'notify_ok' => (($notify['ok'] ?? false) === true) ? 1 : 0,
      ];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      if ($e instanceof PDOException && isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) {
        return ['ok' => false, 'reason' => 'slot_taken'];
      }
      return [
        'ok' => false,
        'reason' => 'db_error',
        'error' => $e->getMessage(),
      ];
    }
  }

  function bot_adv_calendar_cleanup_tokens(PDO $pdo): void
  {
    $pdo->exec("
      DELETE FROM " . BOT_ADV_CALENDAR_TABLE_LINK_TOKENS . "
      WHERE (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
         OR (expires_at < DATE_SUB(NOW(), INTERVAL 2 DAY))
    ");
  }

  function bot_adv_calendar_generate_raw_token(): string
  {
    return (string)random_int(100000, 999999);
  }

  function bot_adv_calendar_generate_link_token(PDO $pdo, string $actorType, int $actorId, int $createdBy = 0): string
  {
    bot_adv_calendar_require_schema($pdo);

    $actorType = trim($actorType);
    if (!bot_adv_calendar_is_link_token_actor_type($actorType)) {
      throw new RuntimeException('Р СњР ВµР С”Р С•РЎР‚РЎР‚Р ВµР С”РЎвЂљР Р…РЎвЂ№Р в„– actor_type');
    }
    if ($actorId <= 0) {
      throw new RuntimeException('Р СњР ВµР С”Р С•РЎР‚РЎР‚Р ВµР С”РЎвЂљР Р…РЎвЂ№Р в„– actor_id');
    }
    if ($actorType === 'user' && !bot_adv_calendar_user_is_attached($pdo, $actorId)) {
      throw new RuntimeException('Р СџР С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»РЎРЉ Р Р…Р Вµ Р С—Р С•Р Т‘Р С”Р В»РЎР‹РЎвЂЎР ВµР Р… Р С” bot_adv_calendar');
    }

    $actor = bot_adv_calendar_actor_row($pdo, $actorType, $actorId);
    if (!$actor) {
      throw new RuntimeException('Р РЋРЎС“РЎвЂ°Р Р…Р С•РЎРѓРЎвЂљРЎРЉ Р Т‘Р В»РЎРЏ Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р С”Р С‘ Р Р…Р Вµ Р Р…Р В°Р в„–Р Т‘Р ВµР Р…Р В°');
    }

    $settings = bot_adv_calendar_settings_get($pdo);
    $ttlMinutes = (int)($settings['token_ttl_minutes'] ?? 15);
    if ($ttlMinutes < 1) $ttlMinutes = 15;
    if ($ttlMinutes > 1440) $ttlMinutes = 1440;

    bot_adv_calendar_cleanup_tokens($pdo);

    $now = bot_adv_calendar_now();
    $rawToken = '';
    $hash = '';
    $attempt = 0;

    while ($attempt < 60) {
      $candidate = bot_adv_calendar_generate_raw_token();
      $candidateHash = hash('sha256', $candidate);

      $stCheck = $pdo->prepare("
        SELECT id
        FROM " . BOT_ADV_CALENDAR_TABLE_LINK_TOKENS . "
        WHERE token_hash = :token_hash
          AND used_at IS NULL
          AND expires_at >= :now
        LIMIT 1
      ");
      $stCheck->execute([
        ':token_hash' => $candidateHash,
        ':now' => $now,
      ]);

      if (!(int)$stCheck->fetchColumn()) {
        $rawToken = $candidate;
        $hash = $candidateHash;
        break;
      }

      $attempt++;
    }

    if ($rawToken === '' || $hash === '') {
      throw new RuntimeException('Р СњР Вµ РЎС“Р Т‘Р В°Р В»Р С•РЎРѓРЎРЉ РЎРѓР С–Р ВµР Р…Р ВµРЎР‚Р С‘РЎР‚Р С•Р Р†Р В°РЎвЂљРЎРЉ РЎС“Р Р…Р С‘Р С”Р В°Р В»РЎРЉР Р…РЎвЂ№Р в„– Р С”Р С•Р Т‘ Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р С”Р С‘');
    }

    $expiresAt = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

    $pdo->prepare("
      UPDATE " . BOT_ADV_CALENDAR_TABLE_LINK_TOKENS . "
      SET used_at = :used_at
      WHERE actor_type = :actor_type
        AND actor_id = :actor_id
        AND used_at IS NULL
    ")->execute([
      ':used_at' => $now,
      ':actor_type' => $actorType,
      ':actor_id' => $actorId,
    ]);

    $pdo->prepare("
      INSERT INTO " . BOT_ADV_CALENDAR_TABLE_LINK_TOKENS . "
      (actor_type, actor_id, token_hash, expires_at, created_by)
      VALUES (:actor_type, :actor_id, :token_hash, :expires_at, :created_by)
    ")->execute([
      ':actor_type' => $actorType,
      ':actor_id' => $actorId,
      ':token_hash' => $hash,
      ':expires_at' => $expiresAt,
      ':created_by' => $createdBy,
    ]);

    return $rawToken;
  }

  function bot_adv_calendar_touch_link_by_chat(PDO $pdo, string $chatId): void
  {
    $chatId = trim($chatId);
    if ($chatId === '') return;

    $pdo->prepare("
      UPDATE " . BOT_ADV_CALENDAR_TABLE_LINKS . "
      SET last_seen_at = :now
      WHERE chat_id = :chat_id
      LIMIT 1
    ")->execute([
      ':now' => bot_adv_calendar_now(),
      ':chat_id' => $chatId,
    ]);
  }

  function bot_adv_calendar_find_link_by_chat(PDO $pdo, string $chatId): ?array
  {
    $chatId = trim($chatId);
    if ($chatId === '') return null;

    $st = $pdo->prepare("
      SELECT actor_type, actor_id, chat_id, is_active
      FROM " . BOT_ADV_CALENDAR_TABLE_LINKS . "
      WHERE chat_id = :chat_id
      LIMIT 1
    ");
    $st->execute([':chat_id' => $chatId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;

    return [
      'actor_type' => trim((string)($row['actor_type'] ?? '')),
      'actor_id' => (int)($row['actor_id'] ?? 0),
      'chat_id' => trim((string)($row['chat_id'] ?? '')),
      'is_active' => ((int)($row['is_active'] ?? 0) === 1) ? 1 : 0,
    ];
  }

  function bot_adv_calendar_str_limit(string $value, int $max): string
  {
    $value = trim($value);
    if ($max <= 0 || $value === '') return $value;

    if (function_exists('mb_substr')) {
      return (string)mb_substr($value, 0, $max, 'UTF-8');
    }

    return substr($value, 0, $max);
  }

  function bot_adv_calendar_link_chat_actor(PDO $pdo, string $actorType, int $actorId, array $chatMeta): array
  {
    if (!bot_adv_calendar_is_actor_type($actorType) || $actorId <= 0) {
      return ['ok' => false, 'reason' => 'bad_actor'];
    }

    $chatId = trim((string)($chatMeta['chat_id'] ?? ''));
    if ($chatId === '') {
      return ['ok' => false, 'reason' => 'chat_missing'];
    }

    $chatType = trim((string)($chatMeta['chat_type'] ?? ''));
    $username = bot_adv_calendar_str_limit((string)($chatMeta['username'] ?? ''), 128);
    $firstName = bot_adv_calendar_str_limit((string)($chatMeta['first_name'] ?? ''), 120);
    $lastName = bot_adv_calendar_str_limit((string)($chatMeta['last_name'] ?? ''), 120);
    $now = bot_adv_calendar_now();

    $pdo->prepare("
      DELETE FROM " . BOT_ADV_CALENDAR_TABLE_LINKS . "
      WHERE chat_id = :chat_id
        AND NOT (actor_type = :actor_type AND actor_id = :actor_id)
    ")->execute([
      ':chat_id' => $chatId,
      ':actor_type' => $actorType,
      ':actor_id' => $actorId,
    ]);

    $pdo->prepare("
      INSERT INTO " . BOT_ADV_CALENDAR_TABLE_LINKS . "
      (actor_type, actor_id, chat_id, chat_type, username, first_name, last_name, is_active, linked_at, last_seen_at)
      VALUES (:actor_type, :actor_id, :chat_id, :chat_type, :username, :first_name, :last_name, 1, :linked_at, :last_seen_at)
      ON DUPLICATE KEY UPDATE
        chat_id = VALUES(chat_id),
        chat_type = VALUES(chat_type),
        username = VALUES(username),
        first_name = VALUES(first_name),
        last_name = VALUES(last_name),
        is_active = 1,
        linked_at = VALUES(linked_at),
        last_seen_at = VALUES(last_seen_at)
    ")->execute([
      ':actor_type' => $actorType,
      ':actor_id' => $actorId,
      ':chat_id' => $chatId,
      ':chat_type' => $chatType,
      ':username' => $username,
      ':first_name' => $firstName,
      ':last_name' => $lastName,
      ':linked_at' => $now,
      ':last_seen_at' => $now,
    ]);

    return ['ok' => true, 'chat_id' => $chatId];
  }

  function bot_adv_calendar_onboarding_steps(): array
  {
    return ['await_name', 'await_phone'];
  }

  function bot_adv_calendar_onboarding_cleanup(PDO $pdo): void
  {
    $pdo->exec("
      DELETE FROM " . BOT_ADV_CALENDAR_TABLE_CLIENT_ONBOARDING . "
      WHERE COALESCE(updated_at, created_at) < DATE_SUB(NOW(), INTERVAL 2 DAY)
    ");
  }

  function bot_adv_calendar_onboarding_get(PDO $pdo, string $chatId): ?array
  {
    $chatId = trim($chatId);
    if ($chatId === '') return null;

    $st = $pdo->prepare("
      SELECT step, client_name, phone_raw
      FROM " . BOT_ADV_CALENDAR_TABLE_CLIENT_ONBOARDING . "
      WHERE chat_id = :chat_id
      LIMIT 1
    ");
    $st->execute([':chat_id' => $chatId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) return null;

    $step = trim((string)($row['step'] ?? ''));
    if (!in_array($step, bot_adv_calendar_onboarding_steps(), true)) {
      $step = 'await_name';
    }

    return [
      'step' => $step,
      'client_name' => trim((string)($row['client_name'] ?? '')),
      'phone_raw' => trim((string)($row['phone_raw'] ?? '')),
    ];
  }

  function bot_adv_calendar_onboarding_upsert(PDO $pdo, string $chatId, string $step, string $clientName = '', string $phoneRaw = ''): void
  {
    $chatId = trim($chatId);
    if ($chatId === '') return;

    if (!in_array($step, bot_adv_calendar_onboarding_steps(), true)) {
      $step = 'await_name';
    }

    $clientName = bot_adv_calendar_str_limit($clientName, 160);
    $phoneRaw = bot_adv_calendar_str_limit($phoneRaw, 32);

    $pdo->prepare("
      INSERT INTO " . BOT_ADV_CALENDAR_TABLE_CLIENT_ONBOARDING . "
      (chat_id, step, client_name, phone_raw)
      VALUES (:chat_id, :step, :client_name, :phone_raw)
      ON DUPLICATE KEY UPDATE
        step = VALUES(step),
        client_name = VALUES(client_name),
        phone_raw = VALUES(phone_raw),
        updated_at = CURRENT_TIMESTAMP
    ")->execute([
      ':chat_id' => $chatId,
      ':step' => $step,
      ':client_name' => $clientName,
      ':phone_raw' => $phoneRaw,
    ]);
  }

  function bot_adv_calendar_onboarding_clear(PDO $pdo, string $chatId): void
  {
    $chatId = trim($chatId);
    if ($chatId === '') return;

    $pdo->prepare("
      DELETE FROM " . BOT_ADV_CALENDAR_TABLE_CLIENT_ONBOARDING . "
      WHERE chat_id = :chat_id
      LIMIT 1
    ")->execute([
      ':chat_id' => $chatId,
    ]);
  }

  function bot_adv_calendar_norm_phone(string $phone): string
  {
    $digits = preg_replace('~\D+~', '', $phone) ?? '';

    if (strlen($digits) === 11 && $digits[0] === '8') {
      $digits = '7' . substr($digits, 1);
    }
    if (strlen($digits) === 10) {
      $digits = '7' . $digits;
    }

    return $digits;
  }

  function bot_adv_calendar_phone_variants(string $phoneNorm): array
  {
    $phoneNorm = trim($phoneNorm);
    if ($phoneNorm === '') return [];

    $variants = [$phoneNorm];
    if (strlen($phoneNorm) === 11) {
      if ($phoneNorm[0] === '7') {
        $variants[] = '8' . substr($phoneNorm, 1);
      } elseif ($phoneNorm[0] === '8') {
        $variants[] = '7' . substr($phoneNorm, 1);
      }
    }

    $out = [];
    foreach ($variants as $v) {
      $v = preg_replace('~\D+~', '', (string)$v) ?? '';
      if ($v !== '') $out[] = $v;
    }
    return array_values(array_unique($out));
  }

  function bot_adv_calendar_find_client_by_phone(PDO $pdo, string $phoneNorm): array
  {
    $variants = bot_adv_calendar_phone_variants($phoneNorm);
    if (!$variants) return [];

    $phoneExpr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";
    $in = implode(',', array_fill(0, count($variants), '?'));

    $sql = "
      SELECT
        id,
        first_name,
        last_name,
        middle_name,
        phone,
        status
      FROM " . BOT_ADV_CALENDAR_CLIENTS_TABLE . "
      WHERE {$phoneExpr} IN ({$in})
      ORDER BY (status = 'active') DESC, id ASC
      LIMIT 1
    ";

    $st = $pdo->prepare($sql);
    $st->execute($variants);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
  }

  function bot_adv_calendar_create_client(PDO $pdo, string $clientName, string $phoneNorm): int
  {
    $firstName = bot_adv_calendar_str_limit(trim($clientName), 80);
    if ($firstName === '') $firstName = 'Р С™Р В»Р С‘Р ВµР Р…РЎвЂљ';

    $tmpPass = 'Crm' . bin2hex(random_bytes(4));
    $passHash = password_hash($tmpPass, PASSWORD_DEFAULT);

    $st = $pdo->prepare("
      INSERT INTO " . BOT_ADV_CALENDAR_CLIENTS_TABLE . "
      (first_name, last_name, middle_name, phone, email, pass_hash, pass_is_temp, status, created_at, updated_at)
      VALUES (:first_name, NULL, NULL, :phone, NULL, :pass_hash, 1, 'active', NOW(), NOW())
    ");
    $st->execute([
      ':first_name' => $firstName,
      ':phone' => $phoneNorm,
      ':pass_hash' => $passHash,
    ]);

    return (int)$pdo->lastInsertId();
  }

  function bot_adv_calendar_find_or_create_client(PDO $pdo, string $clientName, string $phoneNorm): array
  {
    $row = bot_adv_calendar_find_client_by_phone($pdo, $phoneNorm);
    if ($row) {
      return [
        'id' => (int)($row['id'] ?? 0),
        'created' => false,
      ];
    }

    try {
      $newId = bot_adv_calendar_create_client($pdo, $clientName, $phoneNorm);
      return [
        'id' => $newId,
        'created' => true,
      ];
    } catch (Throwable $e) {
      // Р вЂ™Р ВµРЎР‚Р С•РЎРЏРЎвЂљР Р…Р С• Р С–Р С•Р Р…Р С”Р В° Р С—Р С• unique(phone): Р С—РЎР‚Р С•Р В±РЎС“Р ВµР С Р С—Р С•Р Р†РЎвЂљР С•РЎР‚Р Р…Р С• Р Р…Р В°Р в„–РЎвЂљР С‘ Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В°.
      $retry = bot_adv_calendar_find_client_by_phone($pdo, $phoneNorm);
      if ($retry) {
        return [
          'id' => (int)($retry['id'] ?? 0),
          'created' => false,
        ];
      }
      throw $e;
    }
  }

  function bot_adv_calendar_bind_client_from_onboarding(PDO $pdo, array $chatMeta, string $clientName, string $phoneRaw): array
  {
    $chatId = trim((string)($chatMeta['chat_id'] ?? ''));
    if ($chatId === '') {
      return ['ok' => false, 'reason' => 'chat_missing', 'message' => 'Р СњР Вµ РЎС“Р Т‘Р В°Р В»Р С•РЎРѓРЎРЉ Р С•Р С—РЎР‚Р ВµР Т‘Р ВµР В»Р С‘РЎвЂљРЎРЉ chat_id.'];
    }

    $phoneNorm = bot_adv_calendar_norm_phone($phoneRaw);
    if (strlen($phoneNorm) !== 11 || $phoneNorm[0] !== '7') {
      return ['ok' => false, 'reason' => 'phone_invalid', 'message' => 'Р СћР ВµР В»Р ВµРЎвЂћР С•Р Р… Р Р…Р ВµР С”Р С•РЎР‚РЎР‚Р ВµР С”РЎвЂљР ВµР Р…. Р В¤Р С•РЎР‚Р СР В°РЎвЂљ: 79XXXXXXXXX.'];
    }

    try {
      $pdo->beginTransaction();

      $found = bot_adv_calendar_find_or_create_client($pdo, $clientName, $phoneNorm);
      $clientId = (int)($found['id'] ?? 0);
      if ($clientId <= 0) {
        throw new RuntimeException('Р СњР Вµ РЎС“Р Т‘Р В°Р В»Р С•РЎРѓРЎРЉ Р С•Р С—РЎР‚Р ВµР Т‘Р ВµР В»Р С‘РЎвЂљРЎРЉ Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В°.');
      }

      $link = bot_adv_calendar_link_chat_actor($pdo, 'client', $clientId, $chatMeta);
      if (($link['ok'] ?? false) !== true) {
        throw new RuntimeException('Р СњР Вµ РЎС“Р Т‘Р В°Р В»Р С•РЎРѓРЎРЉ Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р В°РЎвЂљРЎРЉ Telegram РЎвЂЎР В°РЎвЂљ.');
      }

      bot_adv_calendar_onboarding_clear($pdo, $chatId);

      $actor = bot_adv_calendar_actor_row($pdo, 'client', $clientId);

      $pdo->commit();

      return [
        'ok' => true,
        'reason' => 'linked',
        'message' => 'Р СџРЎР‚Р С‘Р Р†РЎРЏР В·Р С”Р В° Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В° Р Р†РЎвЂ№Р С—Р С•Р В»Р Р…Р ВµР Р…Р В°.',
        'actor_type' => 'client',
        'actor_id' => $clientId,
        'actor_name' => (string)($actor['name'] ?? ''),
        'client_created' => !empty($found['created']) ? 1 : 0,
        'chat_id' => $chatId,
      ];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      return [
        'ok' => false,
        'reason' => 'db_error',
        'message' => 'Р СњР Вµ РЎС“Р Т‘Р В°Р В»Р С•РЎРѓРЎРЉ РЎРѓР С•Р В·Р Т‘Р В°РЎвЂљРЎРЉ Р С‘Р В»Р С‘ Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р В°РЎвЂљРЎРЉ Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В°.',
        'error' => $e->getMessage(),
      ];
    }
  }

  function bot_adv_calendar_bind_chat_by_start_token(PDO $pdo, string $rawToken, array $chatMeta): array
  {
    bot_adv_calendar_require_schema($pdo);

    $rawToken = trim($rawToken);
    if ($rawToken === '') {
      return ['ok' => false, 'reason' => 'token_empty', 'message' => 'Р С™Р С•Р Т‘ Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р С”Р С‘ Р Р…Р Вµ Р С—Р ВµРЎР‚Р ВµР Т‘Р В°Р Р….'];
    }

    bot_adv_calendar_cleanup_tokens($pdo);

    $hash = hash('sha256', $rawToken);
    $st = $pdo->prepare("
      SELECT id, actor_type, actor_id, expires_at
      FROM " . BOT_ADV_CALENDAR_TABLE_LINK_TOKENS . "
      WHERE token_hash = :token_hash
        AND used_at IS NULL
        AND expires_at >= :now
      ORDER BY id DESC
      LIMIT 1
    ");
    $st->execute([
      ':token_hash' => $hash,
      ':now' => bot_adv_calendar_now(),
    ]);
    $tokenRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($tokenRow)) {
      return ['ok' => false, 'reason' => 'token_invalid', 'message' => 'Р С™Р С•Р Т‘ Р Р…Р ВµР Т‘Р ВµР в„–РЎРѓРЎвЂљР Р†Р С‘РЎвЂљР ВµР В»Р ВµР Р… Р С‘Р В»Р С‘ Р С‘РЎРѓРЎвЂљР ВµР С”.'];
    }

    $tokenId = (int)($tokenRow['id'] ?? 0);
    $actorType = trim((string)($tokenRow['actor_type'] ?? ''));
    $actorId = (int)($tokenRow['actor_id'] ?? 0);
    if ($tokenId <= 0 || $actorId <= 0 || !bot_adv_calendar_is_actor_type($actorType)) {
      return ['ok' => false, 'reason' => 'token_invalid', 'message' => 'Р СњР ВµР С”Р С•РЎР‚РЎР‚Р ВµР С”РЎвЂљР Р…РЎвЂ№Р в„– Р С”Р С•Р Т‘ Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р С”Р С‘.'];
    }
    if (!bot_adv_calendar_is_link_token_actor_type($actorType)) {
      return ['ok' => false, 'reason' => 'token_forbidden_actor', 'message' => 'Р С™Р С•Р Т‘РЎвЂ№ Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р С”Р С‘ Р Т‘Р С•РЎРѓРЎвЂљРЎС“Р С—Р Р…РЎвЂ№ РЎвЂљР С•Р В»РЎРЉР С”Р С• Р Т‘Р В»РЎРЏ Р С—Р В»Р С•РЎвЂ°Р В°Р Т‘Р С•Р С”.'];
    }
    if ($actorType === 'user' && !bot_adv_calendar_user_is_attached($pdo, $actorId)) {
      return ['ok' => false, 'reason' => 'user_not_attached', 'message' => 'Р СџР С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»РЎРЉ CRM Р Р…Р Вµ Р С—Р С•Р Т‘Р С”Р В»РЎР‹РЎвЂЎР ВµР Р… Р С” bot_adv_calendar Р Р† Р В°Р Т‘Р СР С‘Р Р…Р С”Р Вµ.'];
    }

    $actor = bot_adv_calendar_actor_row($pdo, $actorType, $actorId);
    if (!$actor) {
      return ['ok' => false, 'reason' => 'actor_not_found', 'message' => 'Р РЋРЎС“РЎвЂ°Р Р…Р С•РЎРѓРЎвЂљРЎРЉ Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р С”Р С‘ Р Р…Р Вµ Р Р…Р В°Р в„–Р Т‘Р ВµР Р…Р В° Р Р† CRM.'];
    }

    $chatId = trim((string)($chatMeta['chat_id'] ?? ''));
    if ($chatId === '') {
      return ['ok' => false, 'reason' => 'chat_missing', 'message' => 'Р СњР Вµ РЎС“Р Т‘Р В°Р В»Р С•РЎРѓРЎРЉ Р С•Р С—РЎР‚Р ВµР Т‘Р ВµР В»Р С‘РЎвЂљРЎРЉ chat_id.'];
    }

    $now = bot_adv_calendar_now();

    try {
      $pdo->beginTransaction();

      $pdo->prepare("
        UPDATE " . BOT_ADV_CALENDAR_TABLE_LINK_TOKENS . "
        SET used_at = :used_at, used_chat_id = :chat_id
        WHERE id = :id
          AND used_at IS NULL
        LIMIT 1
      ")->execute([
        ':used_at' => $now,
        ':chat_id' => $chatId,
        ':id' => $tokenId,
      ]);

      $link = bot_adv_calendar_link_chat_actor($pdo, $actorType, $actorId, $chatMeta);
      if (($link['ok'] ?? false) !== true) {
        throw new RuntimeException('Р СњР Вµ РЎС“Р Т‘Р В°Р В»Р С•РЎРѓРЎРЉ РЎРѓР С•РЎвЂ¦РЎР‚Р В°Р Р…Р С‘РЎвЂљРЎРЉ Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р С”РЎС“ РЎвЂЎР В°РЎвЂљР В°.');
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      return [
        'ok' => false,
        'reason' => 'db_error',
        'message' => 'Р С›РЎв‚¬Р С‘Р В±Р С”Р В° Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р С”Р С‘ Telegram Р С” РЎРѓРЎС“РЎвЂ°Р Р…Р С•РЎРѓРЎвЂљР С‘ CRM.',
        'error' => $e->getMessage(),
      ];
    }

    return [
      'ok' => true,
      'reason' => 'linked',
      'message' => 'Р СџРЎР‚Р С‘Р Р†РЎРЏР В·Р С”Р В° Р Р†РЎвЂ№Р С—Р С•Р В»Р Р…Р ВµР Р…Р В°.',
      'actor_type' => $actorType,
      'actor_id' => $actorId,
      'actor_name' => (string)($actor['name'] ?? ''),
      'chat_id' => $chatId,
    ];
  }

  function bot_adv_calendar_menu_keyboard(string $actorType): array
  {
    $calendar = 'Р С™Р В°Р В»Р ВµР Р…Р Т‘Р В°РЎР‚РЎРЉ РЎР‚Р ВµР С”Р В»Р В°Р СРЎвЂ№';
    $menu = 'Р СљР ВµР Р…РЎР‹';
    return [
      'keyboard' => [
        [
          ['text' => $calendar],
          ['text' => $menu],
        ],
      ],
      'resize_keyboard' => true,
      'one_time_keyboard' => false,
      'is_persistent' => true,
    ];
  }

  function bot_adv_calendar_send_menu(string $botToken, string $chatId, string $actorType): void
  {
    $label = ($actorType === 'client') ? 'Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В°' : 'Р С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»РЎРЏ CRM';
    $text = "Р СљР ВµР Р…РЎР‹ {$label}. Р вЂ™РЎвЂ№Р В±Р ВµРЎР‚Р С‘РЎвЂљР Вµ Р Т‘Р ВµР в„–РЎРѓРЎвЂљР Р†Р С‘Р Вµ:";
    tg_send_message($botToken, $chatId, $text, [
      'parse_mode' => 'HTML',
      'reply_markup' => bot_adv_calendar_menu_keyboard($actorType),
    ]);
  }

  function bot_adv_calendar_hide_menu_keyboard(): array
  {
    return [
      'remove_keyboard' => true,
    ];
  }

  function bot_adv_calendar_client_specialists(PDO $pdo): array
  {
    static $cache = null;
    if (is_array($cache)) return $cache;

    $sql = "
      SELECT u.id, u.name
      FROM " . CALENDAR_USERS_TABLE . " u
      JOIN " . CALENDAR_USER_ROLES_TABLE . " ur ON ur.user_id = u.id
      JOIN " . CALENDAR_ROLES_TABLE . " r ON r.id = ur.role_id
      WHERE r.code = 'specialist'
        AND u.status = 'active'
      ORDER BY u.name ASC, u.id ASC
    ";

    $st = $pdo->query($sql);
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!is_array($rows)) $rows = [];

    $out = [];
    foreach ($rows as $r) {
      $id = (int)($r['id'] ?? 0);
      if ($id > 0) $out[] = $id;
    }

    $cache = array_values(array_unique($out));
    return $cache;
  }

  function bot_adv_calendar_client_month_start(string $month = ''): string
  {
    $month = trim($month);
    if ($month !== '' && preg_match('~^\d{4}-\d{2}-\d{2}$~', $month)) {
      $ts = strtotime($month);
      if ($ts !== false) return date('Y-m-01', $ts);
    }
    if ($month !== '' && preg_match('~^\d{4}-\d{2}$~', $month)) {
      $ts = strtotime($month . '-01');
      if ($ts !== false) return date('Y-m-01', $ts);
    }
    return date('Y-m-01');
  }

  function bot_adv_calendar_client_month_shift(string $monthStart, int $delta): string
  {
    $ts = strtotime(bot_adv_calendar_client_month_start($monthStart));
    if ($ts === false) $ts = time();
    $first = date('Y-m-01', $ts);
    $shifted = strtotime($first . ' ' . ($delta >= 0 ? '+' : '') . $delta . ' month');
    if ($shifted === false) $shifted = $ts;
    return date('Y-m-01', $shifted);
  }

  function bot_adv_calendar_client_month_label(string $monthStart): string
  {
    $months = [
      1 => 'Р Р‡Р Р…Р Р†Р В°РЎР‚РЎРЉ', 2 => 'Р В¤Р ВµР Р†РЎР‚Р В°Р В»РЎРЉ', 3 => 'Р СљР В°РЎР‚РЎвЂљ', 4 => 'Р С’Р С—РЎР‚Р ВµР В»РЎРЉ',
      5 => 'Р СљР В°Р в„–', 6 => 'Р ВРЎР‹Р Р…РЎРЉ', 7 => 'Р ВРЎР‹Р В»РЎРЉ', 8 => 'Р С’Р Р†Р С–РЎС“РЎРѓРЎвЂљ',
      9 => 'Р РЋР ВµР Р…РЎвЂљРЎРЏР В±РЎР‚РЎРЉ', 10 => 'Р С›Р С”РЎвЂљРЎРЏР В±РЎР‚РЎРЉ', 11 => 'Р СњР С•РЎРЏР В±РЎР‚РЎРЉ', 12 => 'Р вЂќР ВµР С”Р В°Р В±РЎР‚РЎРЉ',
    ];

    $ts = strtotime(bot_adv_calendar_client_month_start($monthStart));
    if ($ts === false) $ts = time();
    $m = (int)date('n', $ts);
    $y = (int)date('Y', $ts);
    $name = $months[$m] ?? date('F', $ts);
    return $name . ' ' . $y;
  }

  function bot_adv_calendar_adv_windows_enabled(PDO $pdo): bool
  {
    static $cache = null;
    if (is_bool($cache)) return $cache;

    $sql = "
      SELECT 1
      FROM " . BOT_ADV_CALENDAR_TABLE_USER_WINDOWS . " w
      JOIN " . BOT_ADV_CALENDAR_TABLE_USER_ACCESS . " ua ON ua.user_id = w.user_id
      WHERE w.is_active = 1
      LIMIT 1
    ";
    $st = $pdo->query($sql);
    $row = $st ? $st->fetch(PDO::FETCH_NUM) : false;
    $cache = (is_array($row) && !empty($row));
    return $cache;
  }

  function bot_adv_calendar_client_day_slots_total(PDO $pdo, string $date, array $specialistIds): int
  {
    $date = trim($date);
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $date)) return 0;

    static $cache = [];
    $key = $date . '|' . implode(',', $specialistIds);
    if (isset($cache[$key])) return (int)$cache[$key];

    $advSlots = bot_adv_calendar_client_adv_slots($pdo, $date);
    if ($advSlots) {
      $totalAdv = 0;
      foreach ($advSlots as $slot) {
        if ((int)($slot['is_busy'] ?? 0) !== 1) $totalAdv++;
      }
      $cache[$key] = $totalAdv;
      return $totalAdv;
    }
    if (bot_adv_calendar_adv_windows_enabled($pdo)) {
      $cache[$key] = 0;
      return 0;
    }
    if (!$specialistIds) return 0;

    $total = 0;
    foreach ($specialistIds as $sid) {
      $sid = (int)$sid;
      if ($sid <= 0) continue;

      $schedule = calendar_get_schedule_day($pdo, $sid, $date);
      if (!$schedule) continue;

      $bookings = calendar_get_bookings_day($pdo, $sid, $date);
      $slots = calendar_slots_build($schedule, $bookings, $date, 30);
      $total += is_array($slots) ? count($slots) : 0;
    }

    $cache[$key] = $total;
    return $total;
  }

  function bot_adv_calendar_client_day_state(int $slotsTotal): string
  {
    if ($slotsTotal <= 0) return 'red';
    if ($slotsTotal <= 6) return 'yellow';
    return 'green';
  }

  function bot_adv_calendar_client_day_state_emoji(string $state): string
  {
    if ($state === 'green') return bot_adv_calendar_utf8_icon('green');
    if ($state === 'yellow') return bot_adv_calendar_utf8_icon('yellow');
    return bot_adv_calendar_utf8_icon('red');
  }

  function bot_adv_calendar_utf8_icon(string $name): string
  {
    if ($name === 'green') return "\xF0\x9F\x9F\xA2";
    if ($name === 'yellow') return "\xF0\x9F\x9F\xA1";
    if ($name === 'red') return "\xF0\x9F\x94\xB4";
    if ($name === 'black') return "\xE2\x9A\xAB";
    return '';
  }

  function bot_adv_calendar_client_calendar_keyboard(PDO $pdo, string $monthStart): array
  {
    $monthStart = bot_adv_calendar_client_month_start($monthStart);
    $ts = strtotime($monthStart);
    if ($ts === false) $ts = time();

    $year = (int)date('Y', $ts);
    $month = (int)date('n', $ts);
    $daysInMonth = (int)date('t', $ts);
    $firstWeekday = (int)date('N', $ts); // 1..7

    $prevMonth = bot_adv_calendar_client_month_shift($monthStart, -1);
    $nextMonth = bot_adv_calendar_client_month_shift($monthStart, 1);
    $specIds = bot_adv_calendar_client_specialists($pdo);
    $advMode = bot_adv_calendar_adv_windows_enabled($pdo);

    $kb = [];
    $kb[] = [
      ['text' => '<', 'callback_data' => 'calendar_month:' . substr($prevMonth, 0, 7)],
      ['text' => bot_adv_calendar_client_month_label($monthStart), 'callback_data' => 'calendar_noop'],
      ['text' => '>', 'callback_data' => 'calendar_month:' . substr($nextMonth, 0, 7)],
    ];

    $weekdayRow = [];
    foreach (['Р СџР Р…', 'Р вЂ™РЎвЂљ', 'Р РЋРЎР‚', 'Р В§РЎвЂљ', 'Р СџРЎвЂљ', 'Р РЋР В±', 'Р вЂ™РЎРѓ'] as $wd) {
      $weekdayRow[] = ['text' => $wd, 'callback_data' => 'calendar_noop'];
    }
    $kb[] = $weekdayRow;

    $row = [];
    for ($i = 1; $i < $firstWeekday; $i++) {
      $row[] = ['text' => 'Р’В·', 'callback_data' => 'calendar_noop'];
    }

    for ($day = 1; $day <= $daysInMonth; $day++) {
      $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
      $isPast = ($date < date('Y-m-d'));
      $advSlots = $isPast ? [] : bot_adv_calendar_client_adv_slots($pdo, $date);
      $freeSlots = bot_adv_calendar_client_free_slots($advSlots);
      if ($advMode) {
        $slotsTotal = count($freeSlots);
      } else if ($advSlots) {
        $slotsTotal = count($freeSlots);
      } else {
        $slotsTotal = bot_adv_calendar_client_day_slots_total($pdo, $date, $specIds);
      }
      $state = bot_adv_calendar_client_day_state($slotsTotal);
      $color = $isPast ? bot_adv_calendar_utf8_icon('black') : bot_adv_calendar_client_day_state_emoji($state);
      $dayText = (string)$day;
      $label = $color . $dayText;
      $cb = $isPast ? 'calendar_noop' : ('calendar_day:' . $date);

      $row[] = ['text' => $label, 'callback_data' => $cb];

      if (count($row) >= 7) {
        $kb[] = $row;
        $row = [];
      }
    }

    if ($row) {
      while (count($row) < 7) {
        $row[] = ['text' => 'Р’В·', 'callback_data' => 'calendar_noop'];
      }
      $kb[] = $row;
    }

    return ['inline_keyboard' => $kb];
  }

  function bot_adv_calendar_client_calendar_text(string $monthStart): string
  {
    $g = bot_adv_calendar_utf8_icon('green');
    $y = bot_adv_calendar_utf8_icon('yellow');
    $r = bot_adv_calendar_utf8_icon('red');
    return "Р С™Р В°Р В»Р ВµР Р…Р Т‘Р В°РЎР‚РЎРЉ РЎР‚Р ВµР С”Р В»Р В°Р СРЎвЂ№: " . bot_adv_calendar_client_month_label($monthStart) . "\n"
      . "Р вЂ™РЎвЂ№Р В±Р ВµРЎР‚Р С‘РЎвЂљР Вµ Р Т‘Р ВµР Р…РЎРЉ.\n"
      . "{$g} РЎРѓР Р†Р С•Р В±Р С•Р Т‘Р Р…Р С•  {$y} Р СР В°Р В»Р С• Р СР ВµРЎРѓРЎвЂљ  {$r} Р Р…Р ВµРЎвЂљ Р СР ВµРЎРѓРЎвЂљ\n"
      . "Р вЂ™ РЎРЏРЎвЂЎР ВµР в„–Р С”Р Вµ: РЎвЂ Р Р†Р ВµРЎвЂљ + РЎвЂЎР С‘РЎРѓР В»Р С•.";
  }

  function bot_adv_calendar_send_client_calendar(PDO $pdo, string $botToken, string $chatId, string $monthStart = ''): void
  {
    $monthStart = bot_adv_calendar_client_month_start($monthStart);
    tg_send_message($botToken, $chatId, bot_adv_calendar_client_calendar_text($monthStart), [
      'parse_mode' => 'HTML',
      'reply_markup' => bot_adv_calendar_client_calendar_keyboard($pdo, $monthStart),
    ]);
  }

  function bot_adv_calendar_edit_client_calendar(PDO $pdo, string $botToken, string $chatId, int $messageId, string $monthStart = ''): void
  {
    $monthStart = bot_adv_calendar_client_month_start($monthStart);
    tg_edit_message_text($botToken, [
      'chat_id' => $chatId,
      'message_id' => $messageId,
      'text' => bot_adv_calendar_client_calendar_text($monthStart),
      'parse_mode' => 'HTML',
      'reply_markup' => bot_adv_calendar_client_calendar_keyboard($pdo, $monthStart),
    ]);
  }

  function bot_adv_calendar_extract_message_data(array $update): array
  {
    $message = [];
    $callback = (array)($update['callback_query'] ?? []);
    if (isset($update['message']) && is_array($update['message'])) {
      $message = (array)$update['message'];
    } elseif (isset($callback['message']) && is_array($callback['message'])) {
      $message = (array)$callback['message'];
    }

    $chat = (array)($message['chat'] ?? []);
    $reply = (array)($message['reply_to_message'] ?? []);
    $from = (array)($message['from'] ?? []);
    if (!$from && isset($callback['from']) && is_array($callback['from'])) {
      $from = (array)$callback['from'];
    }

    return [
      'chat_id' => trim((string)($chat['id'] ?? '')),
      'chat_type' => trim((string)($chat['type'] ?? '')),
      'message_id' => (int)($message['message_id'] ?? 0),
      'text' => trim((string)($message['text'] ?? '')),
      'reply_text' => trim((string)($reply['text'] ?? '')),
      'username' => trim((string)($from['username'] ?? '')),
      'first_name' => trim((string)($from['first_name'] ?? '')),
      'last_name' => trim((string)($from['last_name'] ?? '')),
      'callback_id' => trim((string)($callback['id'] ?? '')),
      'callback_data' => trim((string)($callback['data'] ?? '')),
    ];
  }

  function bot_adv_calendar_process_callback(PDO $pdo, array $settings, array $meta): array
  {
    $botToken = trim((string)($settings['bot_token'] ?? ''));
    $chatId = trim((string)($meta['chat_id'] ?? ''));
    $messageId = (int)($meta['message_id'] ?? 0);
    $callbackId = trim((string)($meta['callback_id'] ?? ''));
    $callbackData = trim((string)($meta['callback_data'] ?? ''));

    if ($callbackId !== '' && $botToken !== '') {
      tg_answer_callback_query($botToken, $callbackId, ['text' => 'Р СџРЎР‚Р С‘Р Р…РЎРЏРЎвЂљР С•']);
    }

    if ($chatId === '' || $callbackData === '') {
      return ['ok' => true, 'handled' => false, 'reason' => 'empty_callback'];
    }

    $link = bot_adv_calendar_find_link_by_chat($pdo, $chatId);
    if (!$link || (int)($link['is_active'] ?? 0) !== 1) {
      if ($botToken !== '') {
        tg_send_message($botToken, $chatId, 'Р РЋР Р…Р В°РЎвЂЎР В°Р В»Р В° Р С—РЎР‚Р С•Р в„–Р Т‘Р С‘РЎвЂљР Вµ Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р С”РЎС“: Р С—Р С•Р В»РЎРЉР В·Р С•Р Р†Р В°РЎвЂљР ВµР В»РЎРЉ CRM Р Р†Р Р†Р С•Р Т‘Р С‘РЎвЂљ Р С”Р С•Р Т‘, Р С”Р В»Р С‘Р ВµР Р…РЎвЂљ Р С•РЎвЂљР С—РЎР‚Р В°Р Р†Р В»РЎРЏР ВµРЎвЂљ Р С‘Р СРЎРЏ Р С‘ РЎвЂљР ВµР В»Р ВµРЎвЂћР С•Р Р….');
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'link_required'];
    }

    $actorType = (string)($link['actor_type'] ?? '');

    if ($callbackData === 'calendar_noop') {
      return ['ok' => true, 'handled' => true, 'reason' => 'calendar_noop'];
    }
    if ($callbackData === 'calendar_open') {
      if ($botToken !== '') {
        if ($actorType === 'client') {
          bot_adv_calendar_send_menu($botToken, $chatId, 'client');
          bot_adv_calendar_send_client_calendar($pdo, $botToken, $chatId, '');
        } else {
          $userId = (int)($link['actor_id'] ?? 0);
          bot_adv_calendar_send_menu($botToken, $chatId, 'user');
          bot_adv_calendar_send_user_calendar($pdo, $botToken, $chatId, $userId, '');
        }
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'calendar_open'];
    }

    if ($actorType === 'user' && preg_match('~^calendar_month:(\d{4}-\d{2})$~', $callbackData, $m)) {
      $monthStart = bot_adv_calendar_client_month_start((string)$m[1]);
      $userId = (int)($link['actor_id'] ?? 0);
      if ($botToken !== '') {
        if ($messageId > 0) {
          bot_adv_calendar_edit_user_calendar($pdo, $botToken, $chatId, $messageId, $userId, $monthStart);
        } else {
          bot_adv_calendar_send_user_calendar($pdo, $botToken, $chatId, $userId, $monthStart);
        }
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'calendar_month_user'];
    }

    if ($actorType === 'client' && preg_match('~^calendar_month:(\d{4}-\d{2})$~', $callbackData, $m)) {
      $monthStart = bot_adv_calendar_client_month_start((string)$m[1]);
      if ($botToken !== '') {
        if ($messageId > 0) {
          bot_adv_calendar_edit_client_calendar($pdo, $botToken, $chatId, $messageId, $monthStart);
        } else {
          bot_adv_calendar_send_client_calendar($pdo, $botToken, $chatId, $monthStart);
        }
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'calendar_month'];
    }
    if ($actorType === 'user' && strpos($callbackData, 'calendar_user_book:') === 0) {
      $parsed = bot_adv_calendar_user_slot_callback_parse($callbackData);
      if (($parsed['ok'] ?? false) !== true) {
        if ($botToken !== '') tg_send_message($botToken, $chatId, 'Invalid booking slot.');
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_user_book_invalid'];
      }

      $day = (string)($parsed['date'] ?? '');
      $time = (string)($parsed['time'] ?? '');
      $userId = (int)($link['actor_id'] ?? 0);

      if ($day < date('Y-m-d')) {
        if ($botToken !== '') tg_send_message($botToken, $chatId, 'Past dates are not available for booking.');
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_user_book_past'];
      }
      if ($userId <= 0) {
        if ($botToken !== '') tg_send_message($botToken, $chatId, 'Failed to detect CRM user.');
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_user_book_user_missing'];
      }

      $slots = bot_adv_calendar_user_day_slots($pdo, $day, $userId);
      $slot = bot_adv_calendar_user_slot_find($slots, $time);
      if (!$slot) {
        if ($botToken !== '') {
          tg_send_message(
            $botToken,
            $chatId,
            "Slot {$time} on {$day} is no longer available.\nRefresh the day and choose another one.",
            ['reply_markup' => bot_adv_calendar_user_day_slots_keyboard($day, $slots)]
          );
        }
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_user_book_slot_missing'];
      }

      if ((int)($slot['is_busy'] ?? 0) === 1) {
        if ($botToken !== '') {
          tg_send_message(
            $botToken,
            $chatId,
            "Slot {$time} is already busy. Choose another one.",
            ['reply_markup' => bot_adv_calendar_user_day_slots_keyboard($day, $slots)]
          );
        }
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_user_book_slot_busy'];
      }

      if ($botToken !== '') {
        $dayLabel = date('d.m.Y', strtotime($day));
        $token = bot_adv_calendar_user_booking_token($day, $time);
        $msg = "Slot {$dayLabel} {$time}.\n"
          . "Reply to this message with @user_nameTG.\n"
          . "This field is required.\n"
          . "Code: {$token}";
        tg_send_message($botToken, $chatId, $msg, [
          'reply_markup' => [
            'force_reply' => true,
            'selective' => true,
          ],
        ]);
      }

      return ['ok' => true, 'handled' => true, 'reason' => 'calendar_user_book_wait_username'];
    }

    if ($actorType === 'client' && strpos($callbackData, 'calendar_book:') === 0) {
      $parsed = bot_adv_calendar_client_slot_callback_parse($callbackData);
      if (($parsed['ok'] ?? false) !== true) {
        if ($botToken !== '') tg_send_message($botToken, $chatId, 'Р СњР ВµР С”Р С•РЎР‚РЎР‚Р ВµР С”РЎвЂљР Р…РЎвЂ№Р в„– РЎРѓР В»Р С•РЎвЂљ Р В±РЎР‚Р С•Р Р…Р С‘РЎР‚Р С•Р Р†Р В°Р Р…Р С‘РЎРЏ.');
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_book_invalid'];
      }

      $day = (string)($parsed['date'] ?? '');
      $userId = (int)($parsed['user_id'] ?? 0);
      $time = (string)($parsed['time'] ?? '');

      if ($day < date('Y-m-d')) {
        if ($botToken !== '') tg_send_message($botToken, $chatId, 'Р СџРЎР‚Р С•РЎв‚¬Р ВµР Т‘РЎв‚¬Р С‘Р Вµ Р Т‘Р В°РЎвЂљРЎвЂ№ Р Р…Р ВµР Т‘Р С•РЎРѓРЎвЂљРЎС“Р С—Р Р…РЎвЂ№ Р Т‘Р В»РЎРЏ Р В±РЎР‚Р С•Р Р…Р С‘РЎР‚Р С•Р Р†Р В°Р Р…Р С‘РЎРЏ.');
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_book_past'];
      }

      $clientId = (int)($link['actor_id'] ?? 0);
      if ($clientId <= 0) {
        if ($botToken !== '') tg_send_message($botToken, $chatId, 'Р РЋР Р…Р В°РЎвЂЎР В°Р В»Р В° Р В·Р В°Р Р†Р ВµРЎР‚РЎв‚¬Р С‘РЎвЂљР Вµ Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р С”РЎС“ Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В°.');
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_book_client_missing'];
      }

      $advSlots = bot_adv_calendar_client_adv_slots($pdo, $day);
      $freeSlots = bot_adv_calendar_client_free_slots($advSlots);
      $slot = bot_adv_calendar_client_slot_find($advSlots, $userId, $time);
      if (!$slot) {
        if ($botToken !== '') {
          $msg = "Р С›Р С”Р Р…Р С• {$time} Р Р…Р В° {$day} РЎС“Р В¶Р Вµ Р Р…Р ВµР Т‘Р С•РЎРѓРЎвЂљРЎС“Р С—Р Р…Р С•.\nР С›Р В±Р Р…Р С•Р Р†Р С‘РЎвЂљР Вµ Р Т‘Р ВµР Р…РЎРЉ Р С‘ Р Р†РЎвЂ№Р В±Р ВµРЎР‚Р С‘РЎвЂљР Вµ Р Т‘РЎР‚РЎС“Р С–Р С•Р Вµ.";
          $opts = [];
          if ($freeSlots) $opts['reply_markup'] = bot_adv_calendar_client_day_slots_keyboard($day, $freeSlots);
          tg_send_message($botToken, $chatId, $msg, $opts);
        }
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_book_slot_missing'];
      }

      if ((int)($slot['is_busy'] ?? 0) === 1) {
        if ($botToken !== '') {
          tg_send_message($botToken, $chatId, "Р С›Р С”Р Р…Р С• {$time} РЎС“Р В¶Р Вµ Р В·Р В°Р Р…РЎРЏРЎвЂљР С•. Р вЂ™РЎвЂ№Р В±Р ВµРЎР‚Р С‘РЎвЂљР Вµ Р Т‘РЎР‚РЎС“Р С–Р С•Р Вµ.", [
            'reply_markup' => bot_adv_calendar_client_day_slots_keyboard($day, $freeSlots),
          ]);
        }
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_book_slot_busy'];
      }

      $create = bot_adv_calendar_create_adv_booking_request($pdo, $clientId, $day, $slot);
      if (($create['ok'] ?? false) !== true) {
        $reason = (string)($create['reason'] ?? 'error');
        if ($botToken !== '') {
          $msg = ($reason === 'slot_taken')
            ? 'Р В­РЎвЂљР С• Р С•Р С”Р Р…Р С• РЎС“Р В¶Р Вµ Р В·Р В°Р Р…РЎРЏРЎвЂљР С•. Р вЂ™РЎвЂ№Р В±Р ВµРЎР‚Р С‘РЎвЂљР Вµ Р Т‘РЎР‚РЎС“Р С–Р С•Р Вµ.'
            : 'Р СњР Вµ РЎС“Р Т‘Р В°Р В»Р С•РЎРѓРЎРЉ РЎРѓР С•Р В·Р Т‘Р В°РЎвЂљРЎРЉ Р В±РЎР‚Р С•Р Р…РЎРЉ РЎР‚Р ВµР С”Р В»Р В°Р СРЎвЂ№. Р СџР С•Р С—РЎР‚Р С•Р В±РЎС“Р в„–РЎвЂљР Вµ РЎРѓР Р…Р С•Р Р†Р В°.';
          $opts = [];
          if ($freeSlots) $opts['reply_markup'] = bot_adv_calendar_client_day_slots_keyboard($day, $freeSlots);
          tg_send_message($botToken, $chatId, $msg, $opts);
        }
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_book_create_' . $reason];
      }

      if ($botToken !== '') {
        $requestId = (int)($create['request_id'] ?? 0);
        $price = (int)($create['price'] ?? 0);
        $dayLabel = date('d.m.Y', strtotime($day));
        $priceText = number_format($price, 0, '.', ' ') . ' РІвЂљР…';
        $msg = "Р вЂРЎР‚Р С•Р Р…РЎРЉ РЎР‚Р ВµР С”Р В»Р В°Р СРЎвЂ№ РЎРѓР С•Р В·Р Т‘Р В°Р Р…Р В°.\n"
          . "Р вЂ”Р В°РЎРЏР Р†Р С”Р В° #{$requestId}\n"
          . "Р вЂќР В°РЎвЂљР В°/Р Р†РЎР‚Р ВµР СРЎРЏ: {$dayLabel} {$time}\n"
          . "Р РЋРЎвЂљР С•Р С‘Р СР С•РЎРѓРЎвЂљРЎРЉ: {$priceText}\n"
          . "Р ВРЎРѓРЎвЂљР С•РЎвЂЎР Р…Р С‘Р С”: adv_bot";
        tg_send_message($botToken, $chatId, $msg);
        bot_adv_calendar_send_client_calendar($pdo, $botToken, $chatId, substr($day, 0, 7));
      }

      return ['ok' => true, 'handled' => true, 'reason' => 'calendar_book_created'];
    }

    if ($actorType === 'user' && preg_match('~^calendar_day:(\d{4}-\d{2}-\d{2})$~', $callbackData, $m)) {
      $day = (string)$m[1];
      $userId = (int)($link['actor_id'] ?? 0);
      if ($day < date('Y-m-d')) {
        if ($botToken !== '') {
          tg_send_message($botToken, $chatId, 'Past dates are not available for booking.');
        }
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_user_day_past'];
      }
      if ($userId <= 0) {
        if ($botToken !== '') {
          tg_send_message($botToken, $chatId, 'Failed to detect CRM user.');
        }
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_user_day_user_missing'];
      }

      if ($botToken !== '') {
        $slots = bot_adv_calendar_user_day_slots($pdo, $day, $userId);
        $freeSlots = bot_adv_calendar_user_free_slots($slots);
        $slotsTotal = count($freeSlots);
        $busyTotal = max(0, count($slots) - $slotsTotal);

        $state = bot_adv_calendar_client_day_state($slotsTotal);
        $emoji = bot_adv_calendar_client_day_state_emoji($state);
        $stateText = ($state === 'green') ? 'Free' : (($state === 'yellow') ? 'Few slots' : 'No slots');
        $dayLabel = date('d.m.Y', strtotime($day));
        $slotsListText = bot_adv_calendar_user_slots_text($slots, 40);
        if ($slotsListText !== '') {
          $slotsListText = "\n\nSlots:\n" . $slotsListText;
        }

        $msg = "Date: {$dayLabel}\n"
          . "Status: {$emoji} {$stateText}\n"
          . "Free slots: {$slotsTotal}\n"
          . "Busy slots: {$busyTotal}"
          . $slotsListText
          . "\n\nChoose a free slot below.";

        tg_send_message($botToken, $chatId, $msg, [
          'reply_markup' => bot_adv_calendar_user_day_slots_keyboard($day, $slots),
        ]);
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'calendar_user_day'];
    }

    if ($actorType === 'client' && preg_match('~^calendar_day:(\d{4}-\d{2}-\d{2})$~', $callbackData, $m)) {
      $day = (string)$m[1];
      if ($day < date('Y-m-d')) {
        if ($botToken !== '') {
          tg_send_message($botToken, $chatId, 'Р СџРЎР‚Р С•РЎв‚¬Р ВµР Т‘РЎв‚¬Р С‘Р Вµ Р Т‘Р В°РЎвЂљРЎвЂ№ Р Р…Р ВµР Т‘Р С•РЎРѓРЎвЂљРЎС“Р С—Р Р…РЎвЂ№ Р Т‘Р В»РЎРЏ Р В±РЎР‚Р С•Р Р…Р С‘РЎР‚Р С•Р Р†Р В°Р Р…Р С‘РЎРЏ.');
        }
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_day_past'];
      }

      if ($botToken !== '' && bot_adv_calendar_adv_windows_enabled($pdo)) {
        $advSlots = bot_adv_calendar_client_adv_slots($pdo, $day);
        $freeSlots = bot_adv_calendar_client_free_slots($advSlots);
        $slotsTotal = count($freeSlots);
        $busyTotal = 0;

        $state = bot_adv_calendar_client_day_state($slotsTotal);
        $emoji = bot_adv_calendar_client_day_state_emoji($state);
        $stateText = ($state === 'green') ? 'Р РЋР Р†Р С•Р В±Р С•Р Т‘Р Р…Р С•' : (($state === 'yellow') ? 'Р СљР В°Р В»Р С• Р СР ВµРЎРѓРЎвЂљ' : 'Р СњР ВµРЎвЂљ Р СР ВµРЎРѓРЎвЂљ');
        $dayLabel = date('d.m.Y', strtotime($day));
        $slotsListText = $freeSlots
          ? ("\n\nР С›Р С”Р Р…Р В° Р С‘ РЎвЂ Р ВµР Р…РЎвЂ№:\n" . bot_adv_calendar_client_adv_slots_text($freeSlots, 12))
          : '';
        $msg = "Р вЂќР В°РЎвЂљР В°: {$dayLabel}\n"
          . "Р РЋРЎвЂљР В°РЎвЂљРЎС“РЎРѓ: {$emoji} {$stateText}\n"
          . "Р РЋР Р†Р С•Р В±Р С•Р Т‘Р Р…РЎвЂ№РЎвЂ¦ Р С•Р С”Р С•Р Р…: {$slotsTotal}"
          . $slotsListText
          . "\n\nР вЂ™РЎвЂ№Р В±Р ВµРЎР‚Р С‘РЎвЂљР Вµ Р С•Р С”Р Р…Р С• Р С”Р Р…Р С•Р С—Р С”Р С•Р в„– Р Р…Р С‘Р В¶Р Вµ.";

        $opts = [];
        if ($freeSlots) {
          $opts['reply_markup'] = bot_adv_calendar_client_day_slots_keyboard($day, $freeSlots);
        }
        tg_send_message($botToken, $chatId, $msg, $opts);
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_day_adv'];
      }

      if ($botToken !== '') {
        $advSlots = bot_adv_calendar_client_adv_slots($pdo, $day);
        $freeSlots = bot_adv_calendar_client_free_slots($advSlots);
        if ($advSlots) {
          $slotsTotal = count($freeSlots);
          $busyTotal = 0;
        } else {
          if (bot_adv_calendar_adv_windows_enabled($pdo)) {
            $slotsTotal = 0;
          } else {
            $specIds = bot_adv_calendar_client_specialists($pdo);
            $slotsTotal = bot_adv_calendar_client_day_slots_total($pdo, $day, $specIds);
          }
          $busyTotal = 0;
        }

        $state = bot_adv_calendar_client_day_state($slotsTotal);
        $emoji = bot_adv_calendar_client_day_state_emoji($state);
        $stateText = ($state === 'green') ? 'Р РЋР Р†Р С•Р В±Р С•Р Т‘Р Р…Р С•' : (($state === 'yellow') ? 'Р СљР В°Р В»Р С• Р СР ВµРЎРѓРЎвЂљ' : 'Р СњР ВµРЎвЂљ Р СР ВµРЎРѓРЎвЂљ');
        $dayLabel = date('d.m.Y', strtotime($day));
        $slotsListText = $freeSlots ? ("\n\nР С›Р С”Р Р…Р В° Р С‘ РЎвЂ Р ВµР Р…РЎвЂ№:\n" . bot_adv_calendar_client_adv_slots_text($freeSlots, 0)) : '';
        $msg = "Р вЂќР В°РЎвЂљР В°: {$dayLabel}\n"
          . "Р РЋРЎвЂљР В°РЎвЂљРЎС“РЎРѓ: {$emoji} {$stateText}\n"
          . "Р РЋР Р†Р С•Р В±Р С•Р Т‘Р Р…РЎвЂ№РЎвЂ¦ Р С•Р С”Р С•Р Р…: {$slotsTotal}"
          . $slotsListText;
        tg_send_message($botToken, $chatId, $msg);
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'calendar_day'];
    }

    if ($botToken !== '') {
      tg_send_message($botToken, $chatId, 'Р С™Р С•Р СР В°Р Р…Р Т‘Р В° Р С—Р С•Р С”Р В° Р Р…Р Вµ Р С—Р С•Р Т‘Р Т‘Р ВµРЎР‚Р В¶Р С‘Р Р†Р В°Р ВµРЎвЂљРЎРѓРЎРЏ.');
    }

    return ['ok' => true, 'handled' => true, 'reason' => 'unknown_callback'];
  }

  function bot_adv_calendar_process_update(PDO $pdo, array $settings, array $update): array
  {
    $meta = bot_adv_calendar_extract_message_data($update);
    $chatId = (string)($meta['chat_id'] ?? '');
    $text = (string)($meta['text'] ?? '');
    $callbackData = (string)($meta['callback_data'] ?? '');
    $botToken = trim((string)($settings['bot_token'] ?? ''));

    if ($chatId !== '') {
      bot_adv_calendar_touch_link_by_chat($pdo, $chatId);
      bot_adv_calendar_onboarding_cleanup($pdo);
    }

    if ($callbackData !== '') {
      return bot_adv_calendar_process_callback($pdo, $settings, $meta);
    }

    if ($text === '') {
      return ['ok' => true, 'handled' => false, 'reason' => 'empty_text'];
    }

    $link = ($chatId !== '') ? bot_adv_calendar_find_link_by_chat($pdo, $chatId) : null;
    $activeLinked = ($link && (int)($link['is_active'] ?? 0) === 1);

    $textLower = function_exists('mb_strtolower')
      ? (string)mb_strtolower($text, 'UTF-8')
      : (string)strtolower($text);

    $isMenu = (bool)(preg_match('~^/(menu)$~iu', $text) || $textLower === 'Р СР ВµР Р…РЎР‹');
    $isCalendar = (bool)(
      preg_match('~^/(calendar)$~iu', $text)
      || $textLower === 'Р С”Р В°Р В»Р ВµР Р…Р Т‘Р В°РЎР‚РЎРЉ'
      || $textLower === 'Р С”Р В°Р В»Р ВµР Р…Р Т‘Р В°РЎР‚РЎРЉ РЎР‚Р ВµР С”Р В»Р В°Р СРЎвЂ№'
    );
    $isCancel = (bool)preg_match('~^/(cancel|reset)$~iu', $text);
    $isStart = false;
    $bindToken = '';
    $forceOnboardingStart = false;

    if (preg_match('~^\d{6}$~', $text)) {
      $bindToken = $text;
    } else {
      $m = [];
      $isStart = (preg_match('~^/start(?:\s+(\d{6}))?\s*$~u', $text, $m) === 1);
      if ($isStart) {
        $bindToken = trim((string)($m[1] ?? ''));
      }
    }

    $isStartWithoutToken = ($isStart && $bindToken === '');
    if ($activeLinked) {
      $actorType = (string)($link['actor_type'] ?? 'client');

      if ($actorType === 'user') {
        $userBooking = bot_adv_calendar_process_user_booking_input($pdo, $meta, (array)$link, $settings);
        if (($userBooking['handled'] ?? false) === true) {
          return $userBooking;
        }
      }

      if ($isCalendar) {
        if ($botToken !== '' && $chatId !== '') {
          if ($actorType === 'client') {
            bot_adv_calendar_send_client_calendar($pdo, $botToken, $chatId, '');
          } else {
            $userId = (int)($link['actor_id'] ?? 0);
            bot_adv_calendar_send_user_calendar($pdo, $botToken, $chatId, $userId, '');
          }
        }
        return ['ok' => true, 'handled' => true, 'reason' => 'calendar_text'];
      }

      if ($botToken !== '' && $chatId !== '') {
        bot_adv_calendar_send_menu($botToken, $chatId, $actorType);
      }
      return ['ok' => true, 'handled' => true, 'reason' => $isMenu ? 'menu' : 'fallback_menu'];
    }

    if ($bindToken !== '') {
      $bind = bot_adv_calendar_bind_chat_by_start_token($pdo, $bindToken, $meta);
      if (($bind['ok'] ?? false) === true) {
        if ($botToken !== '' && $chatId !== '') {
          $name = trim((string)($bind['actor_name'] ?? ''));
          $okText = $name !== '' ? ('Р СџРЎР‚Р С‘Р Р†РЎРЏР В·Р С”Р В° Р Р†РЎвЂ№Р С—Р С•Р В»Р Р…Р ВµР Р…Р В°: ' . $name . '.') : 'Р СџРЎР‚Р С‘Р Р†РЎРЏР В·Р С”Р В° Р Р†РЎвЂ№Р С—Р С•Р В»Р Р…Р ВµР Р…Р В°.';
          tg_send_message($botToken, $chatId, $okText);
          bot_adv_calendar_send_menu($botToken, $chatId, (string)($bind['actor_type'] ?? 'user'));
        }
        return [
          'ok' => true,
          'handled' => true,
          'reason' => (string)($bind['reason'] ?? ''),
          'bind' => $bind,
        ];
      }

      if ($botToken !== '' && $chatId !== '') {
        $errText = trim((string)($bind['message'] ?? 'Р С›РЎв‚¬Р С‘Р В±Р С”Р В° Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р С”Р С‘.'));
        tg_send_message($botToken, $chatId, $errText, [
          'reply_markup' => bot_adv_calendar_hide_menu_keyboard(),
        ]);
      }
      $forceOnboardingStart = true;
    }

    if ($chatId === '') {
      return ['ok' => true, 'handled' => false, 'reason' => 'chat_missing'];
    }

    if ($isCancel) {
      bot_adv_calendar_onboarding_clear($pdo, $chatId);
      bot_adv_calendar_onboarding_upsert($pdo, $chatId, 'await_name', '', '');
      if ($botToken !== '') {
        tg_send_message(
          $botToken,
          $chatId,
          "Р В Р ВµР С–Р С‘РЎРѓРЎвЂљРЎР‚Р В°РЎвЂ Р С‘РЎРЏ РЎРѓР В±РЎР‚Р С•РЎв‚¬Р ВµР Р…Р В°.\nР СњР В°Р С—Р С‘РЎв‚¬Р С‘РЎвЂљР Вµ Р С‘Р СРЎРЏ Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В°.\n\nР вЂўРЎРѓР В»Р С‘ РЎС“ Р Р†Р В°РЎРѓ Р ВµРЎРѓРЎвЂљРЎРЉ Р С”Р С•Р Т‘ CRM, Р С•РЎвЂљР С—РЎР‚Р В°Р Р†РЎРЉРЎвЂљР Вµ Р ВµР С–Р С• Р С•Р Т‘Р Р…Р С‘Р С РЎРѓР С•Р С•Р В±РЎвЂ°Р ВµР Р…Р С‘Р ВµР С.",
          ['reply_markup' => bot_adv_calendar_hide_menu_keyboard()]
        );
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'onboarding_reset'];
    }

    $onboarding = bot_adv_calendar_onboarding_get($pdo, $chatId);
    if (!$onboarding || $isStartWithoutToken || $isMenu || $forceOnboardingStart) {
      bot_adv_calendar_onboarding_upsert($pdo, $chatId, 'await_name', '', '');
      if ($botToken !== '') {
        tg_send_message(
          $botToken,
          $chatId,
          "Р вЂќР В»РЎРЏ Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В° Р Р…РЎС“Р В¶Р Р…Р В° Р В±РЎвЂ№РЎРѓРЎвЂљРЎР‚Р В°РЎРЏ РЎР‚Р ВµР С–Р С‘РЎРѓРЎвЂљРЎР‚Р В°РЎвЂ Р С‘РЎРЏ.\n1) Р СњР В°Р С—Р С‘РЎв‚¬Р С‘РЎвЂљР Вµ Р С‘Р СРЎРЏ.\n2) Р вЂ”Р В°РЎвЂљР ВµР С Р С•РЎвЂљР С—РЎР‚Р В°Р Р†РЎРЉРЎвЂљР Вµ Р Р…Р С•Р СР ВµРЎР‚ РЎвЂљР ВµР В»Р ВµРЎвЂћР С•Р Р…Р В°.\n\nР вЂўРЎРѓР В»Р С‘ РЎС“ Р Р†Р В°РЎРѓ Р ВµРЎРѓРЎвЂљРЎРЉ Р С”Р С•Р Т‘ CRM, Р С•РЎвЂљР С—РЎР‚Р В°Р Р†РЎРЉРЎвЂљР Вµ Р ВµР С–Р С• Р С•Р Т‘Р Р…Р С‘Р С РЎРѓР С•Р С•Р В±РЎвЂ°Р ВµР Р…Р С‘Р ВµР С.",
          ['reply_markup' => bot_adv_calendar_hide_menu_keyboard()]
        );
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'onboarding_started'];
    }

    $step = (string)($onboarding['step'] ?? 'await_name');
    if (!in_array($step, bot_adv_calendar_onboarding_steps(), true)) {
      $step = 'await_name';
    }

    if ($step === 'await_name') {
      if (strpos($text, '/') === 0) {
        if ($botToken !== '') {
          tg_send_message($botToken, $chatId, 'Р СњР В°Р С—Р С‘РЎв‚¬Р С‘РЎвЂљР Вµ Р С‘Р СРЎРЏ Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В° Р С•Р В±РЎвЂ№РЎвЂЎР Р…РЎвЂ№Р С РЎвЂљР ВµР С”РЎРѓРЎвЂљР С•Р С.', [
            'reply_markup' => bot_adv_calendar_hide_menu_keyboard(),
          ]);
        }
        return ['ok' => true, 'handled' => true, 'reason' => 'onboarding_name_expected'];
      }

      $clientName = bot_adv_calendar_str_limit($text, 160);
      $nameLen = function_exists('mb_strlen')
        ? (int)mb_strlen($clientName, 'UTF-8')
        : strlen($clientName);
      if ($nameLen < 2) {
        if ($botToken !== '') {
          tg_send_message($botToken, $chatId, 'Р ВР СРЎРЏ РЎРѓР В»Р С‘РЎв‚¬Р С”Р С•Р С Р С”Р С•РЎР‚Р С•РЎвЂљР С”Р С•Р Вµ. Р вЂ™Р Р†Р ВµР Т‘Р С‘РЎвЂљР Вµ Р С‘Р СРЎРЏ Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В° Р ВµРЎвЂ°Р Вµ РЎР‚Р В°Р В·.', [
            'reply_markup' => bot_adv_calendar_hide_menu_keyboard(),
          ]);
        }
        return ['ok' => true, 'handled' => true, 'reason' => 'onboarding_name_too_short'];
      }

      bot_adv_calendar_onboarding_upsert($pdo, $chatId, 'await_phone', $clientName, '');
      if ($botToken !== '') {
        tg_send_message($botToken, $chatId, 'Р С›РЎвЂљР В»Р С‘РЎвЂЎР Р…Р С•. Р СћР ВµР С—Р ВµРЎР‚РЎРЉ Р С•РЎвЂљР С—РЎР‚Р В°Р Р†РЎРЉРЎвЂљР Вµ Р Р…Р С•Р СР ВµРЎР‚ РЎвЂљР ВµР В»Р ВµРЎвЂћР С•Р Р…Р В° Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В° (Р Р…Р В°Р С—РЎР‚Р С‘Р СР ВµРЎР‚, +7 999 123-45-67).', [
          'reply_markup' => bot_adv_calendar_hide_menu_keyboard(),
        ]);
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'onboarding_name_saved'];
    }

    if (strpos($text, '/') === 0) {
      if ($botToken !== '') {
        tg_send_message($botToken, $chatId, 'Р С›Р В¶Р С‘Р Т‘Р В°РЎР‹ Р Р…Р С•Р СР ВµРЎР‚ РЎвЂљР ВµР В»Р ВµРЎвЂћР С•Р Р…Р В° Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В°. Р СџРЎР‚Р С‘Р СР ВµРЎР‚: +7 999 123-45-67.', [
          'reply_markup' => bot_adv_calendar_hide_menu_keyboard(),
        ]);
      }
      return ['ok' => true, 'handled' => true, 'reason' => 'onboarding_phone_expected'];
    }

    $clientName = trim((string)($onboarding['client_name'] ?? ''));
    $bindClient = bot_adv_calendar_bind_client_from_onboarding($pdo, $meta, $clientName, $text);

    if ($botToken !== '') {
      if (($bindClient['ok'] ?? false) === true) {
        $actorName = trim((string)($bindClient['actor_name'] ?? ''));
        $created = ((int)($bindClient['client_created'] ?? 0) === 1);
        $okText = $created ? 'Р С™Р В»Р С‘Р ВµР Р…РЎвЂљ РЎРѓР С•Р В·Р Т‘Р В°Р Р… Р С‘ Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р В°Р Р….' : 'Р С™Р В»Р С‘Р ВµР Р…РЎвЂљ Р Р…Р В°Р в„–Р Т‘Р ВµР Р… Р С‘ Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р В°Р Р….';
        if ($actorName !== '') {
          $okText .= ' ' . $actorName . '.';
        }
        tg_send_message($botToken, $chatId, $okText);
        bot_adv_calendar_send_menu($botToken, $chatId, 'client');
      } else {
        $errText = trim((string)($bindClient['message'] ?? 'Р С›РЎв‚¬Р С‘Р В±Р С”Р В° Р С—РЎР‚Р С‘Р Р†РЎРЏР В·Р С”Р С‘ Р С”Р В»Р С‘Р ВµР Р…РЎвЂљР В°.'));
        tg_send_message($botToken, $chatId, $errText . "\nР С›РЎвЂљР С—РЎР‚Р В°Р Р†РЎРЉРЎвЂљР Вµ Р Р…Р С•Р СР ВµРЎР‚ РЎвЂљР ВµР В»Р ВµРЎвЂћР С•Р Р…Р В° Р ВµРЎвЂ°Р Вµ РЎР‚Р В°Р В·.", [
          'reply_markup' => bot_adv_calendar_hide_menu_keyboard(),
        ]);
      }
    }

    return [
      'ok' => true,
      'handled' => true,
      'reason' => (string)($bindClient['reason'] ?? 'onboarding_phone'),
      'bind' => $bindClient,
    ];
  }

  function bot_adv_calendar_apply_webhook(array $settings): array
  {
    $token = trim((string)($settings['bot_token'] ?? ''));
    $webhookUrl = trim((string)($settings['webhook_url'] ?? ''));
    $secret = trim((string)($settings['webhook_secret'] ?? ''));

    if ($token === '') {
      return ['ok' => false, 'error' => 'TG_TOKEN_EMPTY', 'description' => 'Р СњР Вµ Р В·Р В°Р С—Р С•Р В»Р Р…Р ВµР Р… bot_token'];
    }
    if ($webhookUrl === '') {
      return ['ok' => false, 'error' => 'TG_WEBHOOK_URL_EMPTY', 'description' => 'Р СњР Вµ Р В·Р В°Р С—Р С•Р В»Р Р…Р ВµР Р… webhook_url'];
    }

    $options = [
      'allowed_updates' => ['message', 'callback_query'],
      'drop_pending_updates' => false,
    ];
    if ($secret !== '') {
      $options['secret_token'] = $secret;
    }

    return tg_set_webhook($token, $webhookUrl, $options);
  }

  function bot_adv_calendar_webhook_info(array $settings): array
  {
    $token = trim((string)($settings['bot_token'] ?? ''));
    if ($token === '') {
      return ['ok' => false, 'error' => 'TG_TOKEN_EMPTY', 'description' => 'Р СњР Вµ Р В·Р В°Р С—Р С•Р В»Р Р…Р ВµР Р… bot_token'];
    }
    return tg_get_webhook_info($token);
  }

  function bot_adv_calendar_webhook_process(PDO $pdo): array
  {
    bot_adv_calendar_require_schema($pdo);

    $settings = bot_adv_calendar_settings_get($pdo);
    $enabled = ((int)($settings['enabled'] ?? 0) === 1);
    $token = trim((string)($settings['bot_token'] ?? ''));
    $secret = trim((string)($settings['webhook_secret'] ?? ''));

    if (!$enabled || $token === '') {
      return [
        'ok' => false,
        'http' => 503,
        'reason' => 'disabled',
        'message' => 'Telegram-Р В±Р С•РЎвЂљ Р С•РЎвЂљР С”Р В»РЎР‹РЎвЂЎР ВµР Р….',
      ];
    }

    if (!tg_verify_webhook_secret($secret)) {
      return [
        'ok' => false,
        'http' => 403,
        'reason' => 'bad_secret',
        'message' => 'Forbidden',
      ];
    }

    $update = tg_read_update();
    if (!$update) {
      return [
        'ok' => true,
        'http' => 200,
        'handled' => false,
        'reason' => 'empty_update',
      ];
    }

    $result = bot_adv_calendar_process_update($pdo, $settings, $update);
    $result['http'] = 200;
    $result['update_id'] = (int)($update['update_id'] ?? 0);
    return $result;
  }

  function bot_adv_calendar_links_list(PDO $pdo, string $actorType, int $limit = 200): array
  {
    bot_adv_calendar_require_schema($pdo);
    if (!bot_adv_calendar_is_actor_type($actorType)) return [];
    if ($limit < 1) $limit = 1;
    if ($limit > 1000) $limit = 1000;

    if ($actorType === 'user') {
      $sql = "
        SELECT
          l.actor_id,
          COALESCE(NULLIF(TRIM(u.name), ''), CONCAT('User #', l.actor_id)) AS actor_name,
          COALESCE(u.status, '') AS actor_status,
          l.chat_id,
          l.username,
          l.is_active,
          l.linked_at,
          l.last_seen_at
        FROM " . BOT_ADV_CALENDAR_TABLE_LINKS . " l
        LEFT JOIN " . BOT_ADV_CALENDAR_USERS_TABLE . " u
          ON u.id = l.actor_id
        WHERE l.actor_type = 'user'
        ORDER BY l.actor_id ASC
        LIMIT :lim
      ";
    } else {
      $sql = "
        SELECT
          l.actor_id,
          COALESCE(NULLIF(TRIM(CONCAT_WS(' ', c.last_name, c.first_name, c.middle_name)), ''), CONCAT('Client #', l.actor_id)) AS actor_name,
          COALESCE(c.status, '') AS actor_status,
          l.chat_id,
          l.username,
          l.is_active,
          l.linked_at,
          l.last_seen_at
        FROM " . BOT_ADV_CALENDAR_TABLE_LINKS . " l
        LEFT JOIN " . BOT_ADV_CALENDAR_CLIENTS_TABLE . " c
          ON c.id = l.actor_id
        WHERE l.actor_type = 'client'
        ORDER BY l.actor_id ASC
        LIMIT :lim
      ";
    }

    $st = $pdo->prepare($sql);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  function bot_adv_calendar_users_manage_list(PDO $pdo, int $limit = 500): array
  {
    bot_adv_calendar_require_schema($pdo);

    if ($limit < 1) $limit = 1;
    if ($limit > 2000) $limit = 2000;

    $sql = "
      SELECT
        ua.user_id AS actor_id,
        COALESCE(NULLIF(TRIM(u.name), ''), CONCAT('User #', ua.user_id)) AS actor_name,
        COALESCE(u.status, '') AS actor_status,
        COALESCE(GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ', '), '') AS role_codes,
        COALESCE(l.chat_id, '') AS chat_id,
        COALESCE(l.username, '') AS username,
        COALESCE(l.is_active, 0) AS is_active,
        l.linked_at,
        l.last_seen_at,
        ua.created_at AS attached_at
      FROM " . BOT_ADV_CALENDAR_TABLE_USER_ACCESS . " ua
      JOIN " . BOT_ADV_CALENDAR_USERS_TABLE . " u
        ON u.id = ua.user_id
      LEFT JOIN user_roles ur
        ON ur.user_id = ua.user_id
      LEFT JOIN roles r
        ON r.id = ur.role_id
      LEFT JOIN " . BOT_ADV_CALENDAR_TABLE_LINKS . " l
        ON l.actor_type = 'user' AND l.actor_id = ua.user_id
      GROUP BY
        ua.user_id, u.name, u.status,
        l.chat_id, l.username, l.is_active, l.linked_at, l.last_seen_at,
        ua.created_at
      ORDER BY
        (u.status = 'active') DESC,
        u.name ASC,
        ua.user_id ASC
      LIMIT :lim
    ";

    $st = $pdo->prepare($sql);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  function bot_adv_calendar_unlink(PDO $pdo, string $actorType, int $actorId): bool
  {
    bot_adv_calendar_require_schema($pdo);

    if (!bot_adv_calendar_is_actor_type($actorType) || $actorId <= 0) {
      return false;
    }

    $st = $pdo->prepare("
      UPDATE " . BOT_ADV_CALENDAR_TABLE_LINKS . "
      SET is_active = 0,
          last_seen_at = :now
      WHERE actor_type = :actor_type
        AND actor_id = :actor_id
      LIMIT 1
    ");

    $st->execute([
      ':now' => bot_adv_calendar_now(),
      ':actor_type' => $actorType,
      ':actor_id' => $actorId,
    ]);

    return ((int)$st->rowCount() > 0);
  }
}








