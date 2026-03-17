<?php
/**
 * FILE: /adm/modules/tg_system_users/assets/php/tg_system_users_lib.php
 * ROLE: Бизнес-логика Telegram-уведомлений для сотрудников CRM
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once ROOT_PATH . '/core/telegram.php';

if (!function_exists('tg_system_users_now')) {

  /**
   * tg_system_users_now()
   * Текущее серверное время в формате DATETIME.
   *
   * @return string
   */
  function tg_system_users_now(): string
  {
    return date('Y-m-d H:i:s');
  }

  /**
   * tg_system_users_is_manage_role()
   * Проверяет, может ли роль управлять модулем.
   *
   * @param array<int,string> $roles
   * @return bool
   */
  function tg_system_users_is_manage_role(array $roles): bool
  {
    return in_array('admin', $roles, true) || in_array('manager', $roles, true);
  }

  /**
   * tg_system_users_settings_defaults()
   * Возвращает дефолтные настройки модуля.
   *
   * @return array<string,mixed>
   */
  function tg_system_users_settings_defaults(): array
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

  /**
   * tg_system_users_default_events()
   * Базовый список системных событий.
   *
   * @return array<int,array<string,mixed>>
   */
  function tg_system_users_default_events(): array
  {
    return [
      [
        'event_code' => TG_SYSTEM_USERS_EVENT_GENERAL,
        'title' => 'Общие системные уведомления',
        'description' => 'Служебные сообщения CRM, важные статусы и новости.',
        'sort_order' => 10,
      ],
      [
        'event_code' => 'system_updates',
        'title' => 'Обновления системы',
        'description' => 'Релизы, изменения функционала, плановые работы.',
        'sort_order' => 20,
      ],
      [
        'event_code' => 'service_alerts',
        'title' => 'Сервисные уведомления',
        'description' => 'Инфраструктурные события и рабочие предупреждения.',
        'sort_order' => 30,
      ],
      [
        'event_code' => 'developer_channel',
        'title' => 'Канал с разработчиком',
        'description' => 'Оперативные объявления от команды разработки.',
        'sort_order' => 40,
      ],
      [
        'event_code' => 'promotions',
        'title' => 'Акции и предложения',
        'description' => 'Маркетинговые и промо-уведомления.',
        'sort_order' => 50,
      ],
    ];
  }

  /**
   * tg_system_users_schema_required_tables()
   * Возвращает список обязательных таблиц модуля.
   *
   * @return array<int,string>
   */
  function tg_system_users_schema_required_tables(): array
  {
    return [
      TG_SYSTEM_USERS_TABLE_SETTINGS,
      TG_SYSTEM_USERS_TABLE_EVENTS,
      TG_SYSTEM_USERS_TABLE_USER_EVENTS,
      TG_SYSTEM_USERS_TABLE_LINKS,
      TG_SYSTEM_USERS_TABLE_LINK_TOKENS,
      TG_SYSTEM_USERS_TABLE_DISPATCH_LOG,
    ];
  }

  /**
   * tg_system_users_schema_db_name()
   * Возвращает имя текущей БД.
   *
   * @param PDO $pdo
   * @return string
   */
  function tg_system_users_schema_db_name(PDO $pdo): string
  {
    $cfg = function_exists('app_config') ? (array)app_config() : [];
    $dbCfg = (array)($cfg['db'] ?? []);
    $dbName = trim((string)($dbCfg['name'] ?? ''));
    if ($dbName !== '') return $dbName;

    $st = $pdo->query("SELECT DATABASE()");
    $name = $st ? trim((string)$st->fetchColumn()) : '';
    return $name;
  }

  /**
   * tg_system_users_schema_missing_tables()
   * Возвращает список отсутствующих таблиц модуля.
   *
   * @param PDO $pdo
   * @return array<int,string>
   */
  function tg_system_users_schema_missing_tables(PDO $pdo): array
  {
    $dbName = tg_system_users_schema_db_name($pdo);
    if ($dbName === '') {
      return tg_system_users_schema_required_tables();
    }

    $required = tg_system_users_schema_required_tables();
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
      if (!isset($existsMap[$table])) {
        $missing[] = $table;
      }
    }

    return $missing;
  }

  /**
   * tg_system_users_require_schema()
   * Проверяет, что SQL модуля применён вручную.
   *
   * @param PDO $pdo
   * @return void
   */
  function tg_system_users_require_schema(PDO $pdo): void
  {
    /**
     * $checked — флаг проверки схемы в рамках запроса.
     */
    static $checked = false;

    if ($checked) return;

    $missing = tg_system_users_schema_missing_tables($pdo);
    if ($missing) {
      throw new RuntimeException(
        'Не применён SQL модуля tg_system_users. Отсутствуют таблицы: ' . implode(', ', $missing)
      );
    }

    $checked = true;
  }

  /**
   * tg_system_users_settings_get()
   * Возвращает текущие настройки модуля.
   *
   * @param PDO $pdo
   * @return array<string,mixed>
   */
  function tg_system_users_settings_get(PDO $pdo): array
  {
    tg_system_users_require_schema($pdo);

    $defaults = tg_system_users_settings_defaults();

    $st = $pdo->query("
      SELECT enabled, bot_token, webhook_secret, webhook_url, default_parse_mode, token_ttl_minutes, retention_days
      FROM " . TG_SYSTEM_USERS_TABLE_SETTINGS . "
      WHERE id = 1
      LIMIT 1
    ");

    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
    if (!is_array($row)) {
      return $defaults;
    }

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

  /**
   * tg_system_users_settings_save()
   * Сохраняет настройки модуля.
   *
   * @param PDO $pdo
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  function tg_system_users_settings_save(PDO $pdo, array $input): array
  {
    tg_system_users_require_schema($pdo);

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
      INSERT INTO " . TG_SYSTEM_USERS_TABLE_SETTINGS . "
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

    return tg_system_users_settings_get($pdo);
  }

  /**
   * tg_system_users_events_get()
   * Возвращает список системных событий.
   *
   * @param PDO $pdo
   * @return array<int,array<string,mixed>>
   */
  function tg_system_users_events_get(PDO $pdo): array
  {
    tg_system_users_require_schema($pdo);

    $st = $pdo->query("
      SELECT id, event_code, title, description, global_enabled, sort_order
      FROM " . TG_SYSTEM_USERS_TABLE_EVENTS . "
      ORDER BY sort_order ASC, id ASC
    ");

    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!is_array($rows)) return [];

    return $rows;
  }

  /**
   * tg_system_users_toggle_event()
   * Включает/выключает событие глобально.
   *
   * @param PDO $pdo
   * @param string $eventCode
   * @param int $enabled
   * @return bool
   */
  function tg_system_users_toggle_event(PDO $pdo, string $eventCode, int $enabled): bool
  {
    tg_system_users_require_schema($pdo);

    $eventCode = trim($eventCode);
    if ($eventCode === '') return false;

    $enabled = $enabled === 1 ? 1 : 0;

    $st = $pdo->prepare("
      UPDATE " . TG_SYSTEM_USERS_TABLE_EVENTS . "
      SET global_enabled = :enabled
      WHERE event_code = :event_code
      LIMIT 1
    ");

    return $st->execute([
      ':enabled' => $enabled,
      ':event_code' => $eventCode,
    ]);
  }

  /**
   * tg_system_users_user_preferences()
   * Возвращает список событий с персональными флагами пользователя.
   *
   * @param PDO $pdo
   * @param int $userId
   * @return array<int,array<string,mixed>>
   */
  function tg_system_users_user_preferences(PDO $pdo, int $userId): array
  {
    tg_system_users_require_schema($pdo);
    if ($userId <= 0) return [];

    $st = $pdo->prepare("
      SELECT
        e.event_code,
        e.title,
        e.description,
        e.global_enabled,
        e.sort_order,
        ue.enabled AS user_enabled
      FROM " . TG_SYSTEM_USERS_TABLE_EVENTS . " e
      LEFT JOIN " . TG_SYSTEM_USERS_TABLE_USER_EVENTS . " ue
        ON ue.event_code = e.event_code
       AND ue.user_id = :uid
      ORDER BY e.sort_order ASC, e.id ASC
    ");
    $st->execute([':uid' => $userId]);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) return [];

    $out = [];
    foreach ($rows as $row) {
      $globalEnabled = ((int)($row['global_enabled'] ?? 0) === 1) ? 1 : 0;
      $userEnabled = array_key_exists('user_enabled', $row)
        ? (((int)$row['user_enabled'] === 1) ? 1 : 0)
        : 1;

      $out[] = [
        'event_code' => (string)($row['event_code'] ?? ''),
        'title' => (string)($row['title'] ?? ''),
        'description' => (string)($row['description'] ?? ''),
        'sort_order' => (int)($row['sort_order'] ?? 100),
        'global_enabled' => $globalEnabled,
        'user_enabled' => $userEnabled,
        'effective_enabled' => ($globalEnabled === 1 && $userEnabled === 1) ? 1 : 0,
      ];
    }

    return $out;
  }

  /**
   * tg_system_users_user_preferences_save()
   * Сохраняет персональные on/off по событиям для пользователя.
   *
   * @param PDO $pdo
   * @param int $userId
   * @param array<string,mixed> $enabledMap
   * @return void
   */
  function tg_system_users_user_preferences_save(PDO $pdo, int $userId, array $enabledMap): void
  {
    tg_system_users_require_schema($pdo);
    if ($userId <= 0) return;

    $events = tg_system_users_events_get($pdo);
    if (!$events) return;

    $preparedMap = [];
    foreach ($enabledMap as $code => $val) {
      $preparedMap[(string)$code] = ((int)$val === 1 || (string)$val === '1') ? 1 : 0;
    }

    $st = $pdo->prepare("
      INSERT INTO " . TG_SYSTEM_USERS_TABLE_USER_EVENTS . "
      (user_id, event_code, enabled)
      VALUES (:uid, :event_code, :enabled)
      ON DUPLICATE KEY UPDATE
        enabled = VALUES(enabled)
    ");

    foreach ($events as $event) {
      $eventCode = (string)($event['event_code'] ?? '');
      if ($eventCode === '') continue;

      $hasInput = array_key_exists($eventCode, $preparedMap);
      $isGlobalEnabled = ((int)($event['global_enabled'] ?? 1) === 1);

      if (!$hasInput && !$isGlobalEnabled) {
        continue;
      }

      $enabled = $hasInput ? (int)$preparedMap[$eventCode] : 0;

      $st->execute([
        ':uid' => $userId,
        ':event_code' => $eventCode,
        ':enabled' => ($enabled === 1 ? 1 : 0),
      ]);
    }
  }

  /**
   * tg_system_users_users_with_links()
   * Возвращает пользователей и статус привязки Telegram.
   *
   * @param PDO $pdo
   * @param int $limit
   * @return array<int,array<string,mixed>>
   */
  function tg_system_users_users_with_links(PDO $pdo, int $limit = 200): array
  {
    tg_system_users_require_schema($pdo);

    if ($limit < 1) $limit = 1;
    if ($limit > 1000) $limit = 1000;

    $sql = "
      SELECT
        u.id,
        u.name,
        u.phone,
        u.email,
        u.status,
        GROUP_CONCAT(DISTINCT r.code ORDER BY r.sort ASC, r.id ASC SEPARATOR ', ') AS roles,
        l.chat_id,
        l.username,
        l.first_name,
        l.last_name,
        l.is_active,
        l.linked_at,
        l.last_seen_at
      FROM " . TG_SYSTEM_USERS_USERS_TABLE . " u
      LEFT JOIN " . TG_SYSTEM_USERS_USER_ROLES_TABLE . " ur ON ur.user_id = u.id
      LEFT JOIN " . TG_SYSTEM_USERS_ROLES_TABLE . " r ON r.id = ur.role_id
      LEFT JOIN " . TG_SYSTEM_USERS_TABLE_LINKS . " l ON l.user_id = u.id
      GROUP BY u.id
      ORDER BY u.id DESC
      LIMIT :lim
    ";

    $st = $pdo->prepare($sql);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  /**
   * tg_system_users_unlink_user()
   * Снимает активную TG-привязку у пользователя и закрывает активные коды.
   *
   * @param PDO $pdo
   * @param int $userId
   * @return array<string,mixed>
   */
  function tg_system_users_unlink_user(PDO $pdo, int $userId): array
  {
    tg_system_users_require_schema($pdo);

    if ($userId <= 0) {
      throw new RuntimeException('Некорректный user_id');
    }

    /**
     * $now — текущее серверное время.
     */
    $now = tg_system_users_now();

    /**
     * $hadActiveLink — был ли у пользователя активный Telegram-линк.
     */
    $hadActiveLink = false;

    try {
      $pdo->beginTransaction();

      $st = $pdo->prepare("
        SELECT chat_id, is_active
        FROM " . TG_SYSTEM_USERS_TABLE_LINKS . "
        WHERE user_id = :user_id
        LIMIT 1
      ");
      $st->execute([':user_id' => $userId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);

      if (is_array($row)) {
        $chatId = trim((string)($row['chat_id'] ?? ''));
        $isActive = ((int)($row['is_active'] ?? 0) === 1);
        $hadActiveLink = ($chatId !== '' && $isActive);

        $pdo->prepare("
          UPDATE " . TG_SYSTEM_USERS_TABLE_LINKS . "
          SET is_active = 0,
              last_seen_at = :now
          WHERE user_id = :user_id
          LIMIT 1
        ")->execute([
          ':now' => $now,
          ':user_id' => $userId,
        ]);
      }

      $pdo->prepare("
        UPDATE " . TG_SYSTEM_USERS_TABLE_LINK_TOKENS . "
        SET used_at = :used_at
        WHERE user_id = :user_id
          AND used_at IS NULL
      ")->execute([
        ':used_at' => $now,
        ':user_id' => $userId,
      ]);

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }

    return [
      'ok' => true,
      'user_id' => $userId,
      'had_active_link' => $hadActiveLink ? 1 : 0,
    ];
  }

  /**
   * tg_system_users_generate_raw_token()
   * Генерирует короткий одноразовый код привязки (4 цифры).
   *
   * @return string
   */
  function tg_system_users_generate_raw_token(): string
  {
    return (string)random_int(1000, 9999);
  }

  /**
   * tg_system_users_generate_link_token()
   * Создаёт одноразовый код привязки для пользователя.
   *
   * @param PDO $pdo
   * @param int $userId
   * @param int $createdBy
   * @return string
   */
  function tg_system_users_generate_link_token(PDO $pdo, int $userId, int $createdBy = 0): string
  {
    tg_system_users_require_schema($pdo);

    if ($userId <= 0) {
      throw new RuntimeException('Некорректный user_id');
    }

    $stUser = $pdo->prepare("
      SELECT id
      FROM " . TG_SYSTEM_USERS_USERS_TABLE . "
      WHERE id = :id
      LIMIT 1
    ");
    $stUser->execute([':id' => $userId]);
    if (!(int)$stUser->fetchColumn()) {
      throw new RuntimeException('Пользователь не найден');
    }

    $settings = tg_system_users_settings_get($pdo);
    $ttlMinutes = (int)($settings['token_ttl_minutes'] ?? 15);
    if ($ttlMinutes < 1) $ttlMinutes = 15;
    if ($ttlMinutes > 1440) $ttlMinutes = 1440;

    $now = tg_system_users_now();
    /**
     * $rawToken — короткий код привязки.
     */
    $rawToken = '';
    /**
     * $hash — sha256-код для хранения в БД.
     */
    $hash = '';
    /**
     * $attempt — счётчик попыток генерации уникального кода.
     */
    $attempt = 0;

    while ($attempt < 50) {
      $candidate = tg_system_users_generate_raw_token();
      $candidateHash = hash('sha256', $candidate);

      $stCheck = $pdo->prepare("
        SELECT id
        FROM " . TG_SYSTEM_USERS_TABLE_LINK_TOKENS . "
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
      throw new RuntimeException('Не удалось сгенерировать уникальный код привязки');
    }

    $expiresAt = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

    $pdo->prepare("
      UPDATE " . TG_SYSTEM_USERS_TABLE_LINK_TOKENS . "
      SET used_at = :used_at
      WHERE user_id = :user_id
        AND used_at IS NULL
    ")->execute([
      ':used_at' => $now,
      ':user_id' => $userId,
    ]);

    $pdo->prepare("
      INSERT INTO " . TG_SYSTEM_USERS_TABLE_LINK_TOKENS . "
      (user_id, token_hash, expires_at, created_by)
      VALUES (:user_id, :token_hash, :expires_at, :created_by)
    ")->execute([
      ':user_id' => $userId,
      ':token_hash' => $hash,
      ':expires_at' => $expiresAt,
      ':created_by' => $createdBy,
    ]);

    return $rawToken;
  }

  /**
   * tg_system_users_touch_link_by_chat()
   * Обновляет last_seen по chat_id.
   *
   * @param PDO $pdo
   * @param string $chatId
   * @return void
   */
  function tg_system_users_touch_link_by_chat(PDO $pdo, string $chatId): void
  {
    $chatId = trim($chatId);
    if ($chatId === '') return;

    $pdo->prepare("
      UPDATE " . TG_SYSTEM_USERS_TABLE_LINKS . "
      SET last_seen_at = :now
      WHERE chat_id = :chat_id
      LIMIT 1
    ")->execute([
      ':now' => tg_system_users_now(),
      ':chat_id' => $chatId,
    ]);
  }

  /**
   * tg_system_users_bind_chat_by_start_token()
   * Привязывает Telegram-чат к пользователю по коду из /start или обычного сообщения.
   *
   * @param PDO $pdo
   * @param string $rawToken
   * @param array<string,mixed> $chatMeta
   * @return array<string,mixed>
   */
  function tg_system_users_bind_chat_by_start_token(PDO $pdo, string $rawToken, array $chatMeta): array
  {
    tg_system_users_require_schema($pdo);

    $rawToken = trim($rawToken);
    if ($rawToken === '') {
      return ['ok' => false, 'reason' => 'token_empty', 'message' => 'Код привязки не передан.'];
    }

    $hash = hash('sha256', $rawToken);

    $st = $pdo->prepare("
      SELECT t.id, t.user_id, t.expires_at, u.name
      FROM " . TG_SYSTEM_USERS_TABLE_LINK_TOKENS . " t
      JOIN " . TG_SYSTEM_USERS_USERS_TABLE . " u ON u.id = t.user_id
      WHERE t.token_hash = :token_hash
        AND t.used_at IS NULL
        AND t.expires_at >= :now
      ORDER BY t.id DESC
      LIMIT 1
    ");
    $st->execute([
      ':token_hash' => $hash,
      ':now' => tg_system_users_now(),
    ]);

    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
      return ['ok' => false, 'reason' => 'token_invalid', 'message' => 'Код недействителен или истёк.'];
    }

    $tokenId = (int)($row['id'] ?? 0);
    $userId = (int)($row['user_id'] ?? 0);
    if ($tokenId <= 0 || $userId <= 0) {
      return ['ok' => false, 'reason' => 'token_invalid', 'message' => 'Некорректный код привязки.'];
    }

    $chatId = trim((string)($chatMeta['chat_id'] ?? ''));
    if ($chatId === '') {
      return ['ok' => false, 'reason' => 'chat_missing', 'message' => 'Не удалось определить chat_id.'];
    }

    $chatType = trim((string)($chatMeta['chat_type'] ?? ''));
    $username = trim((string)($chatMeta['username'] ?? ''));
    $firstName = trim((string)($chatMeta['first_name'] ?? ''));
    $lastName = trim((string)($chatMeta['last_name'] ?? ''));
    $now = tg_system_users_now();

    try {
      $pdo->beginTransaction();

      $pdo->prepare("
        UPDATE " . TG_SYSTEM_USERS_TABLE_LINK_TOKENS . "
        SET used_at = :used_at, used_chat_id = :chat_id
        WHERE id = :id
          AND used_at IS NULL
        LIMIT 1
      ")->execute([
        ':used_at' => $now,
        ':chat_id' => $chatId,
        ':id' => $tokenId,
      ]);

      $pdo->prepare("
        DELETE FROM " . TG_SYSTEM_USERS_TABLE_LINKS . "
        WHERE chat_id = :chat_id
          AND user_id <> :user_id
      ")->execute([
        ':chat_id' => $chatId,
        ':user_id' => $userId,
      ]);

      $pdo->prepare("
        INSERT INTO " . TG_SYSTEM_USERS_TABLE_LINKS . "
        (user_id, chat_id, chat_type, username, first_name, last_name, is_active, linked_at, last_seen_at)
        VALUES (:user_id, :chat_id, :chat_type, :username, :first_name, :last_name, 1, :linked_at, :last_seen_at)
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
        ':user_id' => $userId,
        ':chat_id' => $chatId,
        ':chat_type' => $chatType,
        ':username' => $username,
        ':first_name' => $firstName,
        ':last_name' => $lastName,
        ':linked_at' => $now,
        ':last_seen_at' => $now,
      ]);

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      return [
        'ok' => false,
        'reason' => 'db_error',
        'message' => 'Ошибка привязки Telegram к пользователю.',
        'error' => $e->getMessage(),
      ];
    }

    return [
      'ok' => true,
      'reason' => 'linked',
      'message' => 'Telegram успешно привязан.',
      'user_id' => $userId,
      'user_name' => (string)($row['name'] ?? ''),
      'chat_id' => $chatId,
    ];
  }

  /**
   * tg_system_users_cleanup()
   * Очищает старые записи журнала/токенов.
   *
   * @param PDO $pdo
   * @param int $retentionDays
   * @return void
   */
  function tg_system_users_cleanup(PDO $pdo, int $retentionDays): void
  {
    if ($retentionDays < 1) $retentionDays = 7;
    if ($retentionDays > 30) $retentionDays = 30;

    $pdo->exec("
      DELETE FROM " . TG_SYSTEM_USERS_TABLE_DISPATCH_LOG . "
      WHERE created_at < DATE_SUB(NOW(), INTERVAL " . (int)$retentionDays . " DAY)
    ");

    $pdo->exec("
      DELETE FROM " . TG_SYSTEM_USERS_TABLE_LINK_TOKENS . "
      WHERE (used_at IS NOT NULL AND used_at < DATE_SUB(NOW(), INTERVAL 7 DAY))
         OR (expires_at < DATE_SUB(NOW(), INTERVAL 2 DAY))
    ");
  }

  /**
   * tg_system_users_send_system()
   * Единая отправка системного Telegram-сообщения.
   *
   * @param PDO $pdo
   * @param string $message
   * @param string $eventCode
   * @param array<string,mixed> $options
   * @return array<string,mixed>
   */
  function tg_system_users_send_system(PDO $pdo, string $message, string $eventCode = TG_SYSTEM_USERS_EVENT_GENERAL, array $options = []): array
  {
    tg_system_users_require_schema($pdo);

    $message = trim($message);
    if ($message === '') {
      return ['ok' => false, 'reason' => 'message_empty', 'message' => 'Пустой текст уведомления.'];
    }

    $settings = tg_system_users_settings_get($pdo);
    if ((int)$settings['enabled'] !== 1) {
      return ['ok' => false, 'reason' => 'module_disabled', 'message' => 'Telegram-уведомления отключены в настройках модуля.'];
    }

    $botToken = trim((string)($settings['bot_token'] ?? ''));
    if ($botToken === '') {
      return ['ok' => false, 'reason' => 'token_empty', 'message' => 'Не заполнен токен Telegram-бота.'];
    }

    $eventCode = trim($eventCode);
    if ($eventCode === '') $eventCode = TG_SYSTEM_USERS_EVENT_GENERAL;

    $stEvent = $pdo->prepare("
      SELECT event_code, global_enabled
      FROM " . TG_SYSTEM_USERS_TABLE_EVENTS . "
      WHERE event_code = :event_code
      LIMIT 1
    ");
    $stEvent->execute([':event_code' => $eventCode]);
    $eventRow = $stEvent->fetch(PDO::FETCH_ASSOC);

    if (!is_array($eventRow)) {
      return ['ok' => false, 'reason' => 'event_not_found', 'message' => 'Событие не зарегистрировано.', 'event_code' => $eventCode];
    }

    if ((int)($eventRow['global_enabled'] ?? 0) !== 1) {
      return ['ok' => false, 'reason' => 'event_disabled', 'message' => 'Событие глобально отключено.', 'event_code' => $eventCode];
    }

    $parseMode = trim((string)($options['parse_mode'] ?? $settings['default_parse_mode'] ?? 'HTML'));
    if (!in_array($parseMode, ['HTML', 'Markdown', 'MarkdownV2'], true)) {
      $parseMode = 'HTML';
    }

    $userIds = [];
    if (isset($options['user_ids']) && is_array($options['user_ids'])) {
      foreach ($options['user_ids'] as $uid) {
        $uid = (int)$uid;
        if ($uid > 0) $userIds[] = $uid;
      }
      $userIds = array_values(array_unique($userIds));
    }

    $sql = "
      SELECT
        l.user_id,
        l.chat_id
      FROM " . TG_SYSTEM_USERS_TABLE_LINKS . " l
      JOIN " . TG_SYSTEM_USERS_USERS_TABLE . " u ON u.id = l.user_id
      LEFT JOIN " . TG_SYSTEM_USERS_TABLE_USER_EVENTS . " ue
        ON ue.user_id = l.user_id
       AND ue.event_code = :event_code
      WHERE l.is_active = 1
        AND u.status = 'active'
        AND COALESCE(ue.enabled, 1) = 1
    ";

    $params = [
      ':event_code' => $eventCode,
    ];

    if ($userIds) {
      $in = [];
      foreach ($userIds as $i => $uid) {
        $ph = ':u' . $i;
        $in[] = $ph;
        $params[$ph] = $uid;
      }
      $sql .= " AND l.user_id IN (" . implode(',', $in) . ")";
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $targets = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($targets)) $targets = [];

    $retentionDays = (int)($settings['retention_days'] ?? 7);
    tg_system_users_cleanup($pdo, $retentionDays);

    $sent = 0;
    $failed = 0;

    $stLog = $pdo->prepare("
      INSERT INTO " . TG_SYSTEM_USERS_TABLE_DISPATCH_LOG . "
      (event_code, user_id, chat_id, message_text, send_status, error_text)
      VALUES (:event_code, :user_id, :chat_id, :message_text, :send_status, :error_text)
    ");

    foreach ($targets as $target) {
      $uid = (int)($target['user_id'] ?? 0);
      $chatId = trim((string)($target['chat_id'] ?? ''));
      if ($uid <= 0 || $chatId === '') continue;

      $result = tg_send_message($botToken, $chatId, $message, [
        'parse_mode' => $parseMode,
      ]);

      $ok = (($result['ok'] ?? false) === true);
      $status = $ok ? 'sent' : 'failed';
      $error = $ok
        ? ''
        : trim((string)($result['description'] ?? $result['error'] ?? 'Telegram API error'));

      if ($ok) {
        $sent++;
      } else {
        $failed++;
      }

      $stLog->execute([
        ':event_code' => $eventCode,
        ':user_id' => $uid,
        ':chat_id' => $chatId,
        ':message_text' => tg_excerpt($message, 3000),
        ':send_status' => $status,
        ':error_text' => tg_excerpt($error, 255),
      ]);
    }

    return [
      'ok' => true,
      'event_code' => $eventCode,
      'targets' => count($targets),
      'sent' => $sent,
      'failed' => $failed,
    ];
  }

  /**
   * tg_system_users_apply_webhook()
   * Устанавливает webhook для бота модуля.
   *
   * @param array<string,mixed> $settings
   * @return array<string,mixed>
   */
  function tg_system_users_apply_webhook(array $settings): array
  {
    $token = trim((string)($settings['bot_token'] ?? ''));
    $webhookUrl = trim((string)($settings['webhook_url'] ?? ''));
    $secret = trim((string)($settings['webhook_secret'] ?? ''));

    if ($token === '') {
      return ['ok' => false, 'error' => 'TG_TOKEN_EMPTY', 'description' => 'Не заполнен bot_token'];
    }
    if ($webhookUrl === '') {
      return ['ok' => false, 'error' => 'TG_WEBHOOK_URL_EMPTY', 'description' => 'Не заполнен webhook_url'];
    }

    $options = [
      'allowed_updates' => ['message'],
      'drop_pending_updates' => false,
    ];
    if ($secret !== '') {
      $options['secret_token'] = $secret;
    }

    return tg_set_webhook($token, $webhookUrl, $options);
  }

  /**
   * tg_system_users_extract_message_data()
   * Извлекает из update минимальные поля для обработки.
   *
   * @param array<string,mixed> $update
   * @return array<string,mixed>
   */
  function tg_system_users_extract_message_data(array $update): array
  {
    $message = (array)($update['message'] ?? []);
    $chat = (array)($message['chat'] ?? []);
    $from = (array)($message['from'] ?? []);

    return [
      'chat_id' => trim((string)($chat['id'] ?? '')),
      'chat_type' => trim((string)($chat['type'] ?? '')),
      'text' => trim((string)($message['text'] ?? '')),
      'username' => trim((string)($from['username'] ?? '')),
      'first_name' => trim((string)($from['first_name'] ?? '')),
      'last_name' => trim((string)($from['last_name'] ?? '')),
    ];
  }

  /**
   * tg_system_users_process_update()
   * Обрабатывает входящий update Telegram-бота.
   *
   * @param PDO $pdo
   * @param array<string,mixed> $settings
   * @param array<string,mixed> $update
   * @return array<string,mixed>
   */
  function tg_system_users_process_update(PDO $pdo, array $settings, array $update): array
  {
    $meta = tg_system_users_extract_message_data($update);
    $chatId = (string)($meta['chat_id'] ?? '');
    $text = (string)($meta['text'] ?? '');
    $token = trim((string)($settings['bot_token'] ?? ''));

    if ($chatId !== '') {
      tg_system_users_touch_link_by_chat($pdo, $chatId);
    }

    if ($text === '') {
      return ['ok' => true, 'handled' => false, 'reason' => 'empty_text'];
    }

    /**
     * $bindToken — код для привязки (из /start либо из обычного сообщения).
     */
    $bindToken = '';

    if (preg_match('~^\d{4}$~', $text)) {
      $bindToken = $text;
    } elseif (preg_match('~^/start(?:\s+(\d{4}))?\s*$~u', $text, $m)) {
      $bindToken = trim((string)($m[1] ?? ''));
      if ($bindToken === '') {
        if ($token !== '' && $chatId !== '') {
          tg_send_message($token, $chatId, 'Отправьте 4-значный код привязки из CRM.');
        }
        return ['ok' => true, 'handled' => true, 'reason' => 'bind_token_missing'];
      }
    } else {
      return ['ok' => true, 'handled' => false, 'reason' => 'not_bind_code'];
    }

    $bind = tg_system_users_bind_chat_by_start_token($pdo, $bindToken, $meta);
    if ($token !== '' && $chatId !== '') {
      if (($bind['ok'] ?? false) === true) {
        $userName = trim((string)($bind['user_name'] ?? ''));
        $textOk = ($userName !== '')
          ? ('Привязка выполнена. Пользователь: ' . $userName . '.')
          : 'Привязка выполнена.';
        tg_send_message($token, $chatId, $textOk);
      } else {
        $textErr = trim((string)($bind['message'] ?? 'Ошибка привязки.'));
        tg_send_message($token, $chatId, $textErr);
      }
    }

    return [
      'ok' => true,
      'handled' => true,
      'reason' => (string)($bind['reason'] ?? ''),
      'bind' => $bind,
    ];
  }

  /**
   * tg_system_users_webhook_process()
   * Полный процесс обработки webhook-запроса модуля.
   *
   * @param PDO $pdo
   * @return array<string,mixed>
   */
  function tg_system_users_webhook_process(PDO $pdo): array
  {
    tg_system_users_require_schema($pdo);

    $settings = tg_system_users_settings_get($pdo);
    $enabled = ((int)($settings['enabled'] ?? 0) === 1);
    $token = trim((string)($settings['bot_token'] ?? ''));
    $secret = trim((string)($settings['webhook_secret'] ?? ''));

    if (!$enabled || $token === '') {
      return [
        'ok' => false,
        'http' => 503,
        'reason' => 'disabled',
        'message' => 'Telegram-бот отключён.',
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

    $result = tg_system_users_process_update($pdo, $settings, $update);
    $result['http'] = 200;
    $result['update_id'] = (int)($update['update_id'] ?? 0);
    return $result;
  }
}
