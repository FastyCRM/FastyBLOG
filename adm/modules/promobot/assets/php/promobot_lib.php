<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_lib.php
 * ROLE: Бизнес-логика модуля promobot.
 * CONTAINS:
 *  - доступы, выборки, привязки, поиск промокодов;
 *  - обработчики webhook для Telegram и MAX.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/promobot_i18n.php';
require_once ROOT_PATH . '/core/telegram.php';

if (!function_exists('promobot_now')) {
  /**
   * promobot_now()
   * Текущее время в формате Y-m-d H:i:s.
   *
   * @return string
   */
  function promobot_now(): string
  {
    return date('Y-m-d H:i:s');
  }

  /**
   * promobot_is_manage_role()
   * Проверяет, является ли роль управленческой.
   *
   * @param array<int,string>|string $roles
   * @return bool
   */
  function promobot_is_manage_role($roles): bool
  {
    if (is_string($roles)) {
      $roles = [$roles];
    }
    if (!is_array($roles)) return false;

    foreach (PROMOBOT_MANAGE_ROLES as $role) {
      if (in_array($role, $roles, true)) return true;
    }

    return false;
  }

  /**
   * promobot_public_root_url()
   * Публичный корень сайта (scheme + host).
   *
   * @return string
   */
  function promobot_public_root_url(): string
  {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return '';

    $https = (string)($_SERVER['HTTPS'] ?? '');
    $scheme = ($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';

    return $scheme . '://' . $host;
  }

  /**
   * promobot_tables_missing()
   * Возвращает список отсутствующих таблиц.
   *
   * @param PDO $pdo
   * @param array<int,string> $tables
   * @return array<int,string>
   */
  function promobot_tables_missing(PDO $pdo, array $tables): array
  {
    $clean = array_values(array_unique(array_filter(array_map(static function ($name) {
      return trim((string)$name);
    }, $tables), static function ($name) {
      return $name !== '';
    })));

    if (!$clean) return [];

    $dbName = trim((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
    if ($dbName === '') return $clean;

    $in = implode(',', array_fill(0, count($clean), '?'));
    $params = array_merge([$dbName], $clean);

    $st = $pdo->prepare("\n      SELECT TABLE_NAME\n      FROM information_schema.TABLES\n      WHERE TABLE_SCHEMA = ?\n        AND TABLE_NAME IN ($in)\n    ");
    $st->execute($params);

    $existingRaw = $st->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!is_array($existingRaw)) $existingRaw = [];
    $existing = array_fill_keys(array_map('strval', $existingRaw), true);

    $missing = [];
    foreach ($clean as $table) {
      if (!isset($existing[$table])) $missing[] = $table;
    }

    return $missing;
  }

  /**
   * promobot_tables_ready()
   * Проверяет, что таблицы для runtime существуют.
   *
   * @param PDO $pdo
   * @return bool
   */
  function promobot_tables_ready(PDO $pdo): bool
  {
    return !promobot_tables_missing($pdo, PROMOBOT_REQUIRED_TABLES);
  }

  /**
   * promobot_require_schema()
   * Блокирует runtime, если нет таблиц.
   *
   * @param PDO $pdo
   * @return void
   */
  function promobot_require_schema(PDO $pdo): void
  {
    if (promobot_tables_ready($pdo)) return;

    throw new RuntimeException(promobot_t('promobot.error_schema_missing'));
  }

  /**
   * promobot_settings_defaults()
   * Значения настроек по умолчанию.
   *
   * @return array<string,mixed>
   */
  function promobot_settings_defaults(): array
  {
    return [
      'id' => 1,
      'log_enabled' => 1,
    ];
  }

  /**
   * promobot_settings_get()
   * Читает настройки модуля.
   *
   * @param PDO $pdo
   * @return array<string,mixed>
   */
  function promobot_settings_get(PDO $pdo): array
  {
    promobot_require_schema($pdo);

    $row = $pdo->query("SELECT * FROM " . PROMOBOT_TABLE_SETTINGS . " WHERE id = 1 LIMIT 1")
      ->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
      return promobot_settings_defaults();
    }

    return [
      'id' => 1,
      'log_enabled' => ((int)($row['log_enabled'] ?? 1) === 1) ? 1 : 0,
    ];
  }

  /**
   * promobot_settings_toggle_log()
   * Переключает флаг логирования.
   *
   * @param PDO $pdo
   * @return array<string,mixed>
   */
  function promobot_settings_toggle_log(PDO $pdo): array
  {
    promobot_require_schema($pdo);

    $current = promobot_settings_get($pdo);
    $next = ((int)($current['log_enabled'] ?? 1) === 1) ? 0 : 1;

    $st = $pdo->prepare("\n      INSERT INTO " . PROMOBOT_TABLE_SETTINGS . " (id, log_enabled)\n      VALUES (1, :log_enabled)\n      ON DUPLICATE KEY UPDATE log_enabled = VALUES(log_enabled)\n    ");
    $st->execute([':log_enabled' => $next]);

    return [
      'ok' => true,
      'log_enabled' => $next,
    ];
  }

  /**
   * promobot_log_enabled()
   * Возвращает флаг логирования.
   *
   * @param PDO $pdo
   * @return bool
   */
  function promobot_log_enabled(PDO $pdo): bool
  {
    $settings = promobot_settings_get($pdo);
    return ((int)($settings['log_enabled'] ?? 1) === 1);
  }

  /**
   * promobot_audit_log()
   * Пишет расширенный audit только если в модуле включено логирование.
   *
   * @param PDO $pdo
   * @param string $action
   * @param string $level
   * @param array<string,mixed> $payload
   * @return void
   */
  function promobot_audit_log(PDO $pdo, string $action, string $level, array $payload): void
  {
    if (!promobot_log_enabled($pdo)) {
      return;
    }

    audit_log(PROMOBOT_MODULE_CODE, $action, $level, $payload);
  }

  /**
   * promobot_excerpt()
   * Короткий безопасный фрагмент текста для аудита.
   *
   * @param string $text
   * @param int $maxLen
   * @return string
   */
  function promobot_excerpt(string $text, int $maxLen = 220): string
  {
    $text = trim(preg_replace('~\s+~u', ' ', $text) ?? $text);
    if ($text === '') {
      return '';
    }

    if (function_exists('mb_substr')) {
      return (string)mb_substr($text, 0, $maxLen, 'UTF-8');
    }

    return (string)substr($text, 0, $maxLen);
  }

  /**
   * promobot_bot_webhook_url()
   * Возвращает URL webhook для бота.
   *
   * @param int $botId
   * @param string $platform
   * @param bool $absolute
   * @return string
   */
  function promobot_bot_webhook_url(int $botId, string $platform, bool $absolute = true): string
  {
    $platform = strtolower(trim($platform));
    if ($platform !== PROMOBOT_PLATFORM_MAX) $platform = PROMOBOT_PLATFORM_TG;

    $path = ($platform === PROMOBOT_PLATFORM_MAX)
      ? '/adm/modules/promobot/max_webhook.php'
      : '/adm/modules/promobot/webhook.php';

    $path .= '?bot_id=' . $botId;

    $rel = function_exists('url') ? (string)url($path) : $path;
    if (!$absolute) return $rel;

    $root = promobot_public_root_url();
    if ($root === '') return $rel;

    return $root . $rel;
  }
  /**
   * promobot_bot_list()
   * Список доступных ботов для пользователя.
   *
   * @param PDO $pdo
   * @param int $userId
   * @param array<int,string> $roles
   * @return array<int,array<string,mixed>>
   */
  function promobot_bot_list(PDO $pdo, int $userId, array $roles): array
  {
    promobot_require_schema($pdo);

    if (promobot_is_manage_role($roles)) {
      $rows = $pdo->query("\n        SELECT *\n        FROM " . PROMOBOT_TABLE_BOTS . "\n        ORDER BY id DESC\n      ")->fetchAll(PDO::FETCH_ASSOC);

      return is_array($rows) ? $rows : [];
    }

    if ($userId <= 0) return [];

    $st = $pdo->prepare("\n      SELECT b.*\n      FROM " . PROMOBOT_TABLE_BOTS . " b\n      INNER JOIN " . PROMOBOT_TABLE_USER_ACCESS . " ua\n        ON ua.bot_id = b.id AND ua.user_id = :uid\n      ORDER BY b.id DESC\n    ");
    $st->execute([':uid' => $userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  /**
   * promobot_bot_get()
   * Получает бота по id.
   *
   * @param PDO $pdo
   * @param int $botId
   * @return array<string,mixed>
   */
  function promobot_bot_get(PDO $pdo, int $botId): array
  {
    promobot_require_schema($pdo);

    if ($botId <= 0) return [];

    $st = $pdo->prepare("SELECT * FROM " . PROMOBOT_TABLE_BOTS . " WHERE id = :id LIMIT 1");
    $st->execute([':id' => $botId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
  }

  /**
   * promobot_user_has_bot_access()
   * Проверяет доступ пользователя к боту.
   *
   * @param PDO $pdo
   * @param int $userId
   * @param int $botId
   * @param array<int,string> $roles
   * @return bool
   */
  function promobot_user_has_bot_access(PDO $pdo, int $userId, int $botId, array $roles): bool
  {
    if ($botId <= 0) return false;
    if (promobot_is_manage_role($roles)) return true;
    if ($userId <= 0) return false;

    $st = $pdo->prepare("\n      SELECT user_id\n      FROM " . PROMOBOT_TABLE_USER_ACCESS . "\n      WHERE user_id = :uid AND bot_id = :bid\n      LIMIT 1\n    ");
    $st->execute([':uid' => $userId, ':bid' => $botId]);

    return ((int)$st->fetchColumn() > 0);
  }

  /**
   * promobot_user_access_list()
   * Список пользователей, назначенных на бота.
   *
   * @param PDO $pdo
   * @param int $botId
   * @return array<int,array<string,mixed>>
   */
  function promobot_user_access_list(PDO $pdo, int $botId): array
  {
    promobot_require_schema($pdo);

    if ($botId <= 0) return [];

    $st = $pdo->prepare("\n      SELECT\n        ua.user_id,\n        u.name,\n        u.status,\n        COALESCE(GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ', '), '') AS role_codes,\n        ua.created_at\n      FROM " . PROMOBOT_TABLE_USER_ACCESS . " ua\n      INNER JOIN users u ON u.id = ua.user_id\n      LEFT JOIN user_roles ur ON ur.user_id = u.id\n      LEFT JOIN roles r ON r.id = ur.role_id\n      WHERE ua.bot_id = :bid\n      GROUP BY ua.user_id, u.name, u.status, ua.created_at\n      ORDER BY u.name ASC, ua.user_id ASC\n    ");
    $st->execute([':bid' => $botId]);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  /**
   * promobot_users_attach_candidates()
   * Пользователи, которых можно назначить на бота.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param int $limit
   * @return array<int,array<string,mixed>>
   */
  function promobot_users_attach_candidates(PDO $pdo, int $botId, int $limit = 500): array
  {
    promobot_require_schema($pdo);

    if ($botId <= 0) return [];
    if ($limit < 1) $limit = 1;
    if ($limit > 2000) $limit = 2000;

    $st = $pdo->prepare("\n      SELECT\n        u.id,\n        u.name,\n        u.status,\n        COALESCE(GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ', '), '') AS role_codes\n      FROM users u\n      LEFT JOIN user_roles ur ON ur.user_id = u.id\n      LEFT JOIN roles r ON r.id = ur.role_id\n      LEFT JOIN " . PROMOBOT_TABLE_USER_ACCESS . " ua\n        ON ua.user_id = u.id AND ua.bot_id = :bid\n      WHERE ua.user_id IS NULL\n      GROUP BY u.id, u.name, u.status\n      ORDER BY (u.status = 'active') DESC, u.name ASC, u.id ASC\n      LIMIT :lim\n    ");
    $st->bindValue(':bid', $botId, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  /**
   * promobot_promos_list()
   * Список промокодов бота.
   *
   * @param PDO $pdo
   * @param int $botId
   * @return array<int,array<string,mixed>>
   */
  function promobot_promos_list(PDO $pdo, int $botId): array
  {
    promobot_require_schema($pdo);

    if ($botId <= 0) return [];

    $st = $pdo->prepare("\n      SELECT *\n      FROM " . PROMOBOT_TABLE_PROMOS . "\n      WHERE bot_id = :bid\n      ORDER BY id DESC\n    ");
    $st->execute([':bid' => $botId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
  }

  /**
   * promobot_promo_get()
   * Получает промокод по id.
   *
   * @param PDO $pdo
   * @param int $promoId
   * @return array<string,mixed>
   */
  function promobot_promo_get(PDO $pdo, int $promoId): array
  {
    promobot_require_schema($pdo);

    if ($promoId <= 0) return [];

    $st = $pdo->prepare("SELECT * FROM " . PROMOBOT_TABLE_PROMOS . " WHERE id = :id LIMIT 1");
    $st->execute([':id' => $promoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
  }

  /**
   * promobot_keywords_parse()
   * Разбирает строку ключевых слов в список.
   *
   * @param string $raw
   * @return array<int,string>
   */
  function promobot_keywords_parse(string $raw): array
  {
    $raw = trim($raw);
    if ($raw === '') return [];

    $parts = preg_split('~\s*,\s*~u', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
      $p = trim((string)$p);
      if ($p === '') continue;
      $out[] = $p;
    }

    return array_values(array_unique($out));
  }

  /**
   * promobot_text_lower()
   * Приводит текст к нижнему регистру (с учетом UTF-8).
   *
   * @param string $text
   * @return string
   */
  function promobot_text_lower(string $text): string
  {
    if (function_exists('mb_strtolower')) {
      return (string)mb_strtolower($text, 'UTF-8');
    }
    return (string)strtolower($text);
  }

  /**
   * promobot_promo_find_match()
   * Ищет первый подходящий промокод по тексту.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param string $text
   * @return array<string,mixed>
   */
  function promobot_promo_find_match(PDO $pdo, int $botId, string $text): array
  {
    promobot_require_schema($pdo);

    if ($botId <= 0) return [];

    $text = trim($text);
    if ($text === '') return [];

    $textNorm = promobot_text_lower($text);

    $st = $pdo->prepare("\n      SELECT id, keywords, response_text\n      FROM " . PROMOBOT_TABLE_PROMOS . "\n      WHERE bot_id = :bid AND is_active = 1\n      ORDER BY id ASC\n    ");
    $st->execute([':bid' => $botId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) return [];

    foreach ($rows as $row) {
      $keywords = (string)($row['keywords'] ?? '');
      $list = promobot_keywords_parse($keywords);
      if (!$list) continue;

      foreach ($list as $kw) {
        $kwNorm = promobot_text_lower($kw);
        if ($kwNorm === '') continue;

        if (function_exists('mb_strpos')) {
          if (mb_strpos($textNorm, $kwNorm, 0, 'UTF-8') !== false) {
            return $row;
          }
        } else {
          if (strpos($textNorm, $kwNorm) !== false) {
            return $row;
          }
        }
      }
    }

    return [];
  }
  /**
   * promobot_channel_get()
   * Возвращает канал/чат по bot_id + chat_id.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param string $platform
   * @param string $chatId
   * @return array<string,mixed>
   */
  function promobot_channel_get(PDO $pdo, int $botId, string $platform, string $chatId): array
  {
    promobot_require_schema($pdo);

    if ($botId <= 0 || $chatId === '') return [];

    $st = $pdo->prepare("\n      SELECT *\n      FROM " . PROMOBOT_TABLE_CHANNELS . "\n      WHERE bot_id = :bid AND platform = :platform AND chat_id = :chat_id\n      LIMIT 1\n    ");
    $st->execute([
      ':bid' => $botId,
      ':platform' => $platform,
      ':chat_id' => $chatId,
    ]);

    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
  }

  /**
   * promobot_channels_list()
   * Список каналов/чатов бота.
   *
   * @param PDO $pdo
   * @param int $botId
   * @return array<int,array<string,mixed>>
   */
  function promobot_channels_list(PDO $pdo, int $botId): array
  {
    promobot_require_schema($pdo);

    if ($botId <= 0) return [];

    $st = $pdo->prepare("\n      SELECT *\n      FROM " . PROMOBOT_TABLE_CHANNELS . "\n      WHERE bot_id = :bid\n      ORDER BY id DESC\n    ");
    $st->execute([':bid' => $botId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
  }

  /**
   * promobot_channel_upsert()
   * Создает/обновляет привязку чата.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param string $platform
   * @param array<string,mixed> $meta
   * @return array<string,mixed>
   */
  function promobot_channel_upsert(PDO $pdo, int $botId, string $platform, array $meta): array
  {
    promobot_require_schema($pdo);

    $chatId = trim((string)($meta['chat_id'] ?? ''));
    if ($botId <= 0 || $chatId === '') {
      return ['ok' => false, 'error' => 'CHAT_ID_EMPTY'];
    }

    $chatTitle = trim((string)($meta['chat_title'] ?? ''));
    $chatType = trim((string)($meta['chat_type'] ?? ''));

    $st = $pdo->prepare("\n      INSERT INTO " . PROMOBOT_TABLE_CHANNELS . "\n        (bot_id, platform, chat_id, chat_title, chat_type, is_active, linked_at, last_seen_at)\n      VALUES\n        (:bot_id, :platform, :chat_id, :chat_title, :chat_type, 1, :linked_at, :last_seen_at)\n      ON DUPLICATE KEY UPDATE\n        chat_title = VALUES(chat_title),\n        chat_type = VALUES(chat_type),\n        is_active = 1,\n        last_seen_at = VALUES(last_seen_at)\n    ");

    $now = promobot_now();
    $st->execute([
      ':bot_id' => $botId,
      ':platform' => $platform,
      ':chat_id' => $chatId,
      ':chat_title' => $chatTitle,
      ':chat_type' => $chatType,
      ':linked_at' => $now,
      ':last_seen_at' => $now,
    ]);

    return ['ok' => true, 'chat_id' => $chatId];
  }

  /**
   * promobot_bind_code_generate()
   * Генерирует код привязки чата.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param string $platform
   * @param int $createdBy
   * @return array<string,mixed>
   */
  function promobot_bind_code_generate(PDO $pdo, int $botId, string $platform, int $createdBy = 0): array
  {
    promobot_require_schema($pdo);

    if ($botId <= 0) {
      return ['ok' => false, 'error' => 'BOT_ID_REQUIRED'];
    }

    $platform = strtolower(trim($platform));
    if ($platform !== PROMOBOT_PLATFORM_MAX) $platform = PROMOBOT_PLATFORM_TG;

    $attempts = 0;
    $code = '';

    while ($attempts < 8) {
      $code = (string)random_int(100000, 999999);

      $st = $pdo->prepare("SELECT id FROM " . PROMOBOT_TABLE_BIND_TOKENS . " WHERE code = :code LIMIT 1");
      $st->execute([':code' => $code]);
      if (!(int)$st->fetchColumn()) break;
      $attempts++;
    }

    if ($code === '') {
      return ['ok' => false, 'error' => 'CODE_GENERATE_FAILED'];
    }

    $expiresAt = date('Y-m-d H:i:s', time() + (PROMOBOT_BIND_CODE_TTL_MINUTES * 60));

    $st = $pdo->prepare("\n      INSERT INTO " . PROMOBOT_TABLE_BIND_TOKENS . "\n        (bot_id, platform, code, expires_at, created_by)\n      VALUES\n        (:bot_id, :platform, :code, :expires_at, :created_by)\n    ");
    $st->execute([
      ':bot_id' => $botId,
      ':platform' => $platform,
      ':code' => $code,
      ':expires_at' => $expiresAt,
      ':created_by' => $createdBy,
    ]);

    return [
      'ok' => true,
      'code' => $code,
      'expires_at' => $expiresAt,
    ];
  }

  /**
   * promobot_bind_code_consume()
   * Поглощает код привязки и возвращает bot_id.
   *
   * @param PDO $pdo
   * @param string $platform
   * @param string $code
   * @param string $chatId
   * @return array<string,mixed>
   */
  function promobot_bind_code_consume(PDO $pdo, string $platform, string $code, string $chatId): array
  {
    promobot_require_schema($pdo);

    $code = trim($code);
    if ($code === '' || $chatId === '') {
      return ['ok' => false, 'reason' => 'code_or_chat_empty'];
    }

    $platform = strtolower(trim($platform));
    if ($platform !== PROMOBOT_PLATFORM_MAX) $platform = PROMOBOT_PLATFORM_TG;

    $st = $pdo->prepare("\n      SELECT *\n      FROM " . PROMOBOT_TABLE_BIND_TOKENS . "\n      WHERE code = :code AND platform = :platform AND used_at IS NULL\n      LIMIT 1\n    ");
    $st->execute([
      ':code' => $code,
      ':platform' => $platform,
    ]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
      return ['ok' => false, 'reason' => 'code_not_found'];
    }

    $expiresAt = (string)($row['expires_at'] ?? '');
    if ($expiresAt !== '' && strtotime($expiresAt) < time()) {
      return ['ok' => false, 'reason' => 'code_expired'];
    }

    $botId = (int)($row['bot_id'] ?? 0);
    if ($botId <= 0) {
      return ['ok' => false, 'reason' => 'bot_not_found'];
    }

    $st = $pdo->prepare("\n      UPDATE " . PROMOBOT_TABLE_BIND_TOKENS . "\n      SET used_at = :used_at, used_chat_id = :chat_id\n      WHERE id = :id\n    ");
    $st->execute([
      ':used_at' => promobot_now(),
      ':chat_id' => $chatId,
      ':id' => (int)$row['id'],
    ]);

    return [
      'ok' => true,
      'bot_id' => $botId,
    ];
  }

  /**
   * promobot_log_add()
   * Записывает лог модуля (если включен).
   *
   * @param PDO $pdo
   * @param array<string,mixed> $row
   * @return void
   */
  function promobot_log_add(PDO $pdo, array $row): void
  {
    try {
      if (!promobot_log_enabled($pdo)) return;

      $msg = (string)($row['message_text'] ?? '');
      $resp = (string)($row['response_text'] ?? '');

      $st = $pdo->prepare("\n      INSERT INTO " . PROMOBOT_TABLE_LOGS . "\n      (\n        bot_id,\n        platform,\n        chat_id,\n        message_id,\n        message_text,\n        matched_promo_id,\n        response_text,\n        send_status,\n        error_text\n      )\n      VALUES\n      (\n        :bot_id,\n        :platform,\n        :chat_id,\n        :message_id,\n        :message_text,\n        :matched_promo_id,\n        :response_text,\n        :send_status,\n        :error_text\n      )\n    ");

      $st->execute([
        ':bot_id' => (int)($row['bot_id'] ?? 0),
        ':platform' => (string)($row['platform'] ?? ''),
        ':chat_id' => (string)($row['chat_id'] ?? ''),
        ':message_id' => (string)($row['message_id'] ?? ''),
        ':message_text' => $msg,
        ':matched_promo_id' => (int)($row['matched_promo_id'] ?? 0),
        ':response_text' => $resp,
        ':send_status' => (string)($row['send_status'] ?? 'queued'),
        ':error_text' => (string)($row['error_text'] ?? ''),
      ]);
    } catch (Throwable $e) {
      // Логирование не должно ломать работу модуля.
    }
  }

  /**
   * promobot_log_has_message()
   * Проверяет, обрабатывалось ли уже входящее сообщение.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param string $platform
   * @param string $chatId
   * @param string $messageId
   * @return bool
   */
  function promobot_log_has_message(PDO $pdo, int $botId, string $platform, string $chatId, string $messageId): bool
  {
    try {
      if ($botId <= 0 || $platform === '' || $chatId === '' || $messageId === '') {
        return false;
      }

      $st = $pdo->prepare("\n      SELECT id\n      FROM " . PROMOBOT_TABLE_LOGS . "\n      WHERE bot_id = :bot_id\n        AND platform = :platform\n        AND chat_id = :chat_id\n        AND message_id = :message_id\n      LIMIT 1\n    ");
      $st->execute([
        ':bot_id' => $botId,
        ':platform' => $platform,
        ':chat_id' => $chatId,
        ':message_id' => $messageId,
      ]);

      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }

  /**
   * promobot_tg_extract_meta()
   * Извлекает метаданные из Telegram update.
   *
   * @param array<string,mixed> $update
   * @return array<string,string>
   */
  function promobot_tg_extract_meta(array $update): array
  {
    $meta = function_exists('tg_extract_update_meta') ? (array)tg_extract_update_meta($update) : [];

    $type = (string)($meta['type'] ?? '');
    $chatId = (string)($meta['chat_id'] ?? '');
    $chatType = (string)($meta['chat_type'] ?? '');
    $chatTitle = (string)($meta['chat_title'] ?? '');
    $fromId = (string)($meta['from_id'] ?? '');
    $fromUsername = (string)($meta['from_username'] ?? '');
    $text = (string)($meta['text'] ?? '');

    if (isset($update['my_chat_member']) && is_array($update['my_chat_member'])) {
      $row = (array)$update['my_chat_member'];
      $chat = (array)($row['chat'] ?? []);
      $from = (array)($row['from'] ?? []);

      $type = 'my_chat_member';
      $chatId = trim((string)($chat['id'] ?? $chatId));
      $chatType = trim((string)($chat['type'] ?? $chatType));
      $chatTitle = trim((string)($chat['title'] ?? $chatTitle));
      $fromId = trim((string)($from['id'] ?? $fromId));
      $fromUsername = trim((string)($from['username'] ?? $fromUsername));
      $text = '';
    } elseif (isset($update['chat_member']) && is_array($update['chat_member'])) {
      $row = (array)$update['chat_member'];
      $chat = (array)($row['chat'] ?? []);
      $from = (array)($row['from'] ?? []);

      $type = 'chat_member';
      $chatId = trim((string)($chat['id'] ?? $chatId));
      $chatType = trim((string)($chat['type'] ?? $chatType));
      $chatTitle = trim((string)($chat['title'] ?? $chatTitle));
      $fromId = trim((string)($from['id'] ?? $fromId));
      $fromUsername = trim((string)($from['username'] ?? $fromUsername));
      $text = '';
    }

    $messageId = '';
    $candidates = [
      $update['message']['message_id'] ?? null,
      $update['edited_message']['message_id'] ?? null,
      $update['channel_post']['message_id'] ?? null,
      $update['edited_channel_post']['message_id'] ?? null,
      $update['callback_query']['message']['message_id'] ?? null,
      $update['my_chat_member']['message_id'] ?? null,
      $update['chat_member']['message_id'] ?? null,
      $update['update_id'] ?? null,
    ];
    foreach ($candidates as $raw) {
      if (!is_scalar($raw)) continue;
      $value = trim((string)$raw);
      if ($value !== '') {
        $messageId = $value;
        break;
      }
    }

    return [
      'type' => $type,
      'chat_id' => $chatId,
      'chat_type' => $chatType,
      'chat_title' => $chatTitle,
      'from_id' => $fromId,
      'from_username' => $fromUsername,
      'message_id' => $messageId,
      'text' => $text,
    ];
  }

  /**
   * promobot_tg_chat_is_bindable()
   * Разрешает авто-привязку только для групп/супергрупп/каналов.
   *
   * @param string $chatType
   * @return bool
   */
  function promobot_tg_chat_is_bindable(string $chatType): bool
  {
    $chatType = strtolower(trim($chatType));
    return in_array($chatType, ['group', 'supergroup', 'channel'], true);
  }

  /**
   * promobot_tg_member_status_is_active()
   * Определяет, что бот присутствует в чате и может принимать апдейты.
   *
   * @param string $status
   * @return bool
   */
  function promobot_tg_member_status_is_active(string $status): bool
  {
    $status = strtolower(trim($status));
    return in_array($status, ['member', 'administrator', 'creator'], true);
  }

  /**
   * promobot_tg_extract_member_update()
   * Извлекает изменение статуса бота в чате из my_chat_member.
   *
   * @param array<string,mixed> $update
   * @return array<string,mixed>
   */
  function promobot_tg_extract_member_update(array $update): array
  {
    if (!isset($update['my_chat_member']) || !is_array($update['my_chat_member'])) {
      return [];
    }

    $row = (array)$update['my_chat_member'];
    $chat = (array)($row['chat'] ?? []);
    $oldMember = (array)($row['old_chat_member'] ?? []);
    $newMember = (array)($row['new_chat_member'] ?? []);

    $chatId = trim((string)($chat['id'] ?? ''));
    $chatType = trim((string)($chat['type'] ?? ''));
    $chatTitle = trim((string)($chat['title'] ?? ''));
    $oldStatus = trim((string)($oldMember['status'] ?? ''));
    $newStatus = trim((string)($newMember['status'] ?? ''));

    if ($chatId === '' || !promobot_tg_chat_is_bindable($chatType)) {
      return [];
    }

    return [
      'chat_id' => $chatId,
      'chat_type' => $chatType,
      'chat_title' => $chatTitle,
      'old_status' => $oldStatus,
      'new_status' => $newStatus,
      'is_active' => promobot_tg_member_status_is_active($newStatus),
    ];
  }

  /**
   * promobot_channel_set_active()
   * Меняет флаг активности уже известного чата.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param string $platform
   * @param string $chatId
   * @param bool $isActive
   * @return void
   */
  function promobot_channel_set_active(PDO $pdo, int $botId, string $platform, string $chatId, bool $isActive): void
  {
    promobot_require_schema($pdo);

    if ($botId <= 0 || trim($chatId) === '') {
      return;
    }

    $st = $pdo->prepare("\n      UPDATE " . PROMOBOT_TABLE_CHANNELS . "\n      SET is_active = :is_active,\n          last_seen_at = :last_seen_at\n      WHERE bot_id = :bot_id AND platform = :platform AND chat_id = :chat_id\n    ");
    $st->execute([
      ':is_active' => $isActive ? 1 : 0,
      ':last_seen_at' => promobot_now(),
      ':bot_id' => $botId,
      ':platform' => $platform,
      ':chat_id' => $chatId,
    ]);
  }

  /**
   * promobot_tg_is_bot_sender()
   * Проверяет, что сообщение отправлено ботом.
   *
   * @param array<string,mixed> $update
   * @return bool
   */
  function promobot_tg_is_bot_sender(array $update): bool
  {
    $message = [];
    if (isset($update['message']) && is_array($update['message'])) {
      $message = $update['message'];
    } elseif (isset($update['edited_message']) && is_array($update['edited_message'])) {
      $message = $update['edited_message'];
    }

    if (!$message) {
      return false;
    }

    $from = (isset($message['from']) && is_array($message['from'])) ? $message['from'] : [];
    if (!$from) {
      return false;
    }

    $fromId = (string)($from['id'] ?? '');
    $fromUsername = mb_strtolower(trim((string)($from['username'] ?? '')), 'UTF-8');
    if ($fromId === '1087968824' || $fromUsername === 'groupanonymousbot') {
      return false;
    }

    if (isset($from['is_bot'])) {
      return (bool)$from['is_bot'];
    }

    return false;
  }

  /**
   * promobot_tg_extract_bind_code()
   * Достаёт код привязки из текста.
   *
   * @param string $text
   * @return string
   */
  function promobot_tg_extract_bind_code(string $text): string
  {
    if (preg_match('~^/(?:bind|start)(?:@\w+)?\s+(\d{6})\s*$~u', trim($text), $m)) {
      return (string)($m[1] ?? '');
    }
    return '';
  }

  /**
   * promobot_max_extract_message()
   * Извлекает метаданные из MAX payload.
   *
   * @param array<string,mixed> $payload
   * @return array<string,string>
   */
  function promobot_max_extract_message(array $payload): array
  {
    $message = [];
    if (isset($payload['message']) && is_array($payload['message'])) {
      $message = (array)$payload['message'];
    } elseif (isset($payload['data']['message']) && is_array($payload['data']['message'])) {
      $message = (array)$payload['data']['message'];
    } elseif (isset($payload['data']) && is_array($payload['data'])) {
      $message = (array)$payload['data'];
    }

    $recipient = (array)($message['recipient'] ?? []);
    $recipientChat = (array)($recipient['chat'] ?? []);
    $body = (array)($message['body'] ?? []);

    $chatId = trim((string)(
      $recipient['chat_id']
      ?? $recipient['id']
      ?? $recipientChat['chat_id']
      ?? $recipientChat['id']
      ?? $message['chat_id']
      ?? $payload['chat_id']
      ?? ''
    ));

    $text = (string)($body['text'] ?? $message['text'] ?? '');

    $messageId = '';
    $candidates = [
      $message['message_id'] ?? null,
      $message['id'] ?? null,
      $message['mid'] ?? null,
      $body['message_id'] ?? null,
      $body['id'] ?? null,
      $body['mid'] ?? null,
      $payload['message_id'] ?? null,
      $payload['id'] ?? null,
    ];
    foreach ($candidates as $raw) {
      if (!is_scalar($raw)) continue;
      $val = trim((string)$raw);
      if ($val !== '') {
        $messageId = $val;
        break;
      }
    }

    $sender = (array)($message['sender'] ?? $message['author'] ?? []);
    $senderType = trim((string)($sender['type'] ?? $sender['kind'] ?? ''));

    return [
      'chat_id' => $chatId,
      'text' => $text,
      'message_id' => $messageId,
      'sender_type' => $senderType,
    ];
  }

  /**
   * promobot_max_is_bot_sender()
   * Проверяет, что сообщение отправлено ботом (best-effort).
   *
   * @param array<string,string> $meta
   * @return bool
   */
  function promobot_max_is_bot_sender(array $meta): bool
  {
    $type = strtolower(trim((string)($meta['sender_type'] ?? '')));
    return ($type === 'bot');
  }

  /**
   * promobot_max_extract_bind_code()
   * Достаёт код привязки из текста.
   *
   * @param string $text
   * @return string
   */
  function promobot_max_extract_bind_code(string $text): string
  {
    if (preg_match('~^/(?:bind|start)\s+(\d{6})\s*$~u', trim($text), $m)) {
      return (string)($m[1] ?? '');
    }
    return '';
  }

  /**
   * promobot_tg_send_message()
   * Отправляет сообщение в Telegram.
   *
   * @param string $token
   * @param string $chatId
   * @param string $text
   * @return array<string,mixed>
   */
  function promobot_tg_send_message(string $token, string $chatId, string $text): array
  {
    if (!function_exists('tg_send_message')) {
      return ['ok' => false, 'error' => 'TG_SEND_MISSING'];
    }
    if ($token === '' || $chatId === '' || $text === '') {
      return ['ok' => false, 'error' => 'TG_PARAMS_REQUIRED'];
    }

    return tg_send_message($token, $chatId, $text, [
      'disable_web_page_preview' => true,
    ]);
  }

  /**
   * promobot_max_send_message()
   * Отправляет сообщение в MAX.
   *
   * @param array<string,mixed> $bot
   * @param string $chatId
   * @param string $text
   * @return array<string,mixed>
   */
  function promobot_max_send_message(array $bot, string $chatId, string $text): array
  {
    $apiKey = trim((string)($bot['max_api_key'] ?? ''));
    if ($apiKey === '') {
      return ['ok' => false, 'error' => 'MAX_API_KEY_EMPTY'];
    }

    $baseUrl = rtrim(trim((string)($bot['max_base_url'] ?? 'https://platform-api.max.ru')), '/');
    $sendPath = trim((string)($bot['max_send_path'] ?? '/messages'));
    if ($baseUrl === '' || $sendPath === '') {
      return ['ok' => false, 'error' => 'MAX_ENDPOINT_EMPTY'];
    }

    if ($chatId === '' || $text === '') {
      return ['ok' => false, 'error' => 'MAX_PARAMS_REQUIRED'];
    }

    $url = $baseUrl . '/' . ltrim($sendPath, '/');
    $url .= '?' . http_build_query(['chat_id' => $chatId]);

    $payload = [
      'text' => $text,
    ];

    $http = promobot_http_post_json($url, $payload, [
      'Authorization: ' . $apiKey,
    ]);

    if (($http['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($http['error'] ?? 'MAX_HTTP_ERROR'), 'raw' => $http];
    }

    $code = (int)($http['http_code'] ?? 0);
    $ok = ($code >= 200 && $code < 300);

    return ['ok' => $ok, 'error' => $ok ? '' : ('HTTP_' . $code), 'raw' => $http['json'] ?? (string)($http['raw'] ?? '')];
  }

  /**
   * promobot_http_post_json()
   * HTTP POST JSON helper.
   *
   * @param string $url
   * @param array<string,mixed> $payload
   * @param array<int,string> $headers
   * @return array<string,mixed>
   */
  function promobot_http_post_json(string $url, array $payload, array $headers = []): array
  {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) $body = '{}';

    $ch = curl_init();
    if (!$ch) return ['ok' => false, 'error' => 'CURL_INIT_FAILED'];

    $headers[] = 'Content-Type: application/json';

    curl_setopt_array($ch, [
      CURLOPT_URL => $url,
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $body,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 15,
      CURLOPT_HTTPHEADER => $headers,
    ]);

    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
      return ['ok' => false, 'error' => $err !== '' ? $err : 'CURL_ERROR'];
    }

    $json = json_decode((string)$raw, true);

    return [
      'ok' => true,
      'http_code' => $code,
      'raw' => (string)$raw,
      'json' => is_array($json) ? $json : null,
    ];
  }
  /**
   * promobot_tg_webhook_process()
   * Обрабатывает входящий Telegram update.
   *
   * @param PDO $pdo
   * @param int $botId
   * @return array<string,mixed>
   */
  function promobot_tg_webhook_process(PDO $pdo, int $botId): array
  {
    promobot_require_schema($pdo);

    $bot = promobot_bot_get($pdo, $botId);
    if (!$bot) {
      return ['ok' => false, 'http' => 404, 'reason' => 'bot_not_found', 'message' => 'Bot not found'];
    }

    if ((string)($bot['platform'] ?? '') !== PROMOBOT_PLATFORM_TG) {
      return ['ok' => false, 'http' => 404, 'reason' => 'bot_platform_mismatch', 'message' => 'Bot platform mismatch'];
    }

    if ((int)($bot['enabled'] ?? 0) !== 1) {
      return ['ok' => false, 'http' => 503, 'reason' => 'bot_disabled', 'message' => 'Bot disabled'];
    }

    $token = trim((string)($bot['bot_token'] ?? ''));
    if ($token === '') {
      return ['ok' => false, 'http' => 503, 'reason' => 'token_empty', 'message' => 'Bot token empty'];
    }

    $secret = trim((string)($bot['webhook_secret'] ?? ''));
    if (!function_exists('tg_verify_webhook_secret') || !tg_verify_webhook_secret($secret)) {
      return ['ok' => false, 'http' => 403, 'reason' => 'bad_secret', 'message' => 'Forbidden'];
    }

    $update = function_exists('tg_read_update') ? (array)tg_read_update() : [];
    if (!$update) {
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'empty_update'];
    }

    $meta = promobot_tg_extract_meta($update);
    $chatId = trim((string)($meta['chat_id'] ?? ''));
    $text = (string)($meta['text'] ?? '');
    $messageId = trim((string)($meta['message_id'] ?? ''));
    if ($messageId === '') {
      $messageId = (string)($update['update_id'] ?? '');
    }

    if ($chatId === '') {
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'chat_id_empty'];
    }

    $logBase = [
      'bot_id' => $botId,
      'platform' => PROMOBOT_PLATFORM_TG,
      'chat_id' => $chatId,
      'message_id' => $messageId,
      'message_text' => $text,
    ];
    $log = static function (array $extra) use ($pdo, $logBase): void {
      promobot_log_add($pdo, array_merge($logBase, $extra));
    };
    $auditBase = [
      'bot_id' => $botId,
      'platform' => PROMOBOT_PLATFORM_TG,
      'update_type' => (string)($meta['type'] ?? ''),
      'chat_id' => $chatId,
      'chat_type' => (string)($meta['chat_type'] ?? ''),
      'chat_title' => (string)($meta['chat_title'] ?? ''),
      'message_id' => $messageId,
      'message_text' => promobot_excerpt($text),
      'from_id' => (string)($meta['from_id'] ?? ''),
      'from_username' => (string)($meta['from_username'] ?? ''),
    ];
    $audit = static function (string $reason, array $extra = [], string $level = 'info') use ($pdo, $auditBase): void {
      promobot_audit_log($pdo, 'webhook_trace', $level, array_merge($auditBase, [
        'reason' => $reason,
      ], $extra));
    };

    $memberUpdate = promobot_tg_extract_member_update($update);
    if ($memberUpdate) {
      if (!empty($memberUpdate['is_active'])) {
        promobot_channel_upsert($pdo, $botId, PROMOBOT_PLATFORM_TG, [
          'chat_id' => (string)($memberUpdate['chat_id'] ?? ''),
          'chat_title' => (string)($memberUpdate['chat_title'] ?? ''),
          'chat_type' => (string)($memberUpdate['chat_type'] ?? ''),
        ]);
        $log([
          'matched_promo_id' => 0,
          'response_text' => '',
          'send_status' => 'auto_bind',
          'error_text' => '',
        ]);
        $audit('auto_bind', [
          'handled' => 1,
          'old_status' => (string)($memberUpdate['old_status'] ?? ''),
          'new_status' => (string)($memberUpdate['new_status'] ?? ''),
          'send_status' => 'auto_bind',
        ]);
        return ['ok' => true, 'http' => 200, 'handled' => true, 'reason' => 'auto_bind'];
      }

      promobot_channel_set_active(
        $pdo,
        $botId,
        PROMOBOT_PLATFORM_TG,
        (string)($memberUpdate['chat_id'] ?? ''),
        false
      );
      $log([
        'matched_promo_id' => 0,
        'response_text' => '',
        'send_status' => 'auto_unbind',
        'error_text' => '',
      ]);
      $audit('auto_unbind', [
        'handled' => 1,
        'old_status' => (string)($memberUpdate['old_status'] ?? ''),
        'new_status' => (string)($memberUpdate['new_status'] ?? ''),
        'send_status' => 'auto_unbind',
      ]);
      return ['ok' => true, 'http' => 200, 'handled' => true, 'reason' => 'auto_unbind'];
    }

    if (promobot_log_has_message($pdo, $botId, PROMOBOT_PLATFORM_TG, $chatId, $messageId)) {
      $audit('duplicate_update', [
        'handled' => 0,
        'send_status' => 'duplicate_update',
      ]);
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'duplicate_update'];
    }

    if (promobot_tg_is_bot_sender($update)) {
      $log([
        'matched_promo_id' => 0,
        'response_text' => '',
        'send_status' => 'bot_sender',
        'error_text' => 'bot_sender',
      ]);
      $audit('bot_sender', [
        'handled' => 0,
        'send_status' => 'bot_sender',
      ]);
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'bot_sender'];
    }

    $bindCode = promobot_tg_extract_bind_code($text);
    if ($bindCode !== '') {
      $bind = promobot_bind_code_consume($pdo, PROMOBOT_PLATFORM_TG, $bindCode, $chatId);

      if (($bind['ok'] ?? false) === true) {
        $upsert = promobot_channel_upsert($pdo, (int)$bind['bot_id'], PROMOBOT_PLATFORM_TG, [
          'chat_id' => $chatId,
          'chat_title' => (string)($meta['chat_title'] ?? ''),
          'chat_type' => (string)($meta['chat_type'] ?? ''),
        ]);

        if (($upsert['ok'] ?? false) === true) {
          $responseText = promobot_t('promobot.bind_ok');
          $send = promobot_tg_send_message($token, $chatId, $responseText);
          $log([
            'matched_promo_id' => 0,
            'response_text' => $responseText,
            'send_status' => (($send['ok'] ?? false) === true) ? 'bind_ok' : 'bind_ok_error',
            'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
          ]);
          $audit('bind_ok', [
            'handled' => 1,
            'send_status' => (($send['ok'] ?? false) === true) ? 'bind_ok' : 'bind_ok_error',
            'response_text' => promobot_excerpt($responseText),
            'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
          ], (($send['ok'] ?? false) === true) ? 'info' : 'warn');
          return ['ok' => true, 'http' => 200, 'handled' => true, 'reason' => 'bind_ok'];
        }
      }

      $responseText = promobot_t('promobot.bind_fail');
      $send = promobot_tg_send_message($token, $chatId, $responseText);
      $log([
        'matched_promo_id' => 0,
        'response_text' => $responseText,
        'send_status' => (($send['ok'] ?? false) === true) ? 'bind_fail' : 'bind_fail_error',
        'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
      ]);
      $audit('bind_fail', [
        'handled' => 1,
        'send_status' => (($send['ok'] ?? false) === true) ? 'bind_fail' : 'bind_fail_error',
        'response_text' => promobot_excerpt($responseText),
        'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
      ], 'warn');
      return ['ok' => true, 'http' => 200, 'handled' => true, 'reason' => 'bind_fail'];
    }

    if (promobot_tg_chat_is_bindable((string)($meta['chat_type'] ?? ''))) {
      promobot_channel_upsert($pdo, $botId, PROMOBOT_PLATFORM_TG, [
        'chat_id' => $chatId,
        'chat_title' => (string)($meta['chat_title'] ?? ''),
        'chat_type' => (string)($meta['chat_type'] ?? ''),
      ]);
    }

    $channel = promobot_channel_get($pdo, $botId, PROMOBOT_PLATFORM_TG, $chatId);
    if (!$channel || (int)($channel['is_active'] ?? 0) !== 1) {
      $log([
        'matched_promo_id' => 0,
        'response_text' => '',
        'send_status' => 'chat_not_bound',
        'error_text' => 'chat_not_bound',
      ]);
      $audit('chat_not_bound', [
        'handled' => 0,
        'send_status' => 'chat_not_bound',
      ], 'warn');
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'chat_not_bound'];
    }

    $match = promobot_promo_find_match($pdo, $botId, $text);
    if (!$match) {
      $log([
        'matched_promo_id' => 0,
        'response_text' => '',
        'send_status' => 'no_match',
        'error_text' => 'no_match',
      ]);
      $audit('no_match', [
        'handled' => 0,
        'send_status' => 'no_match',
      ]);
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'no_match'];
    }

    $response = (string)($match['response_text'] ?? '');
    if ($response === '') {
      $log([
        'matched_promo_id' => (int)($match['id'] ?? 0),
        'response_text' => '',
        'send_status' => 'empty_response',
        'error_text' => 'empty_response',
      ]);
      $audit('empty_response', [
        'handled' => 0,
        'matched_promo_id' => (int)($match['id'] ?? 0),
        'matched_keywords' => (string)($match['keywords'] ?? ''),
        'send_status' => 'empty_response',
      ], 'warn');
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'empty_response'];
    }

    $send = promobot_tg_send_message($token, $chatId, $response);

    $log([
      'matched_promo_id' => (int)($match['id'] ?? 0),
      'response_text' => $response,
      'send_status' => (($send['ok'] ?? false) === true) ? 'sent' : 'error',
      'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
    ]);
    $audit((($send['ok'] ?? false) === true) ? 'sent' : 'send_failed', [
      'handled' => 1,
      'matched_promo_id' => (int)($match['id'] ?? 0),
      'matched_keywords' => (string)($match['keywords'] ?? ''),
      'response_text' => promobot_excerpt($response),
      'send_status' => (($send['ok'] ?? false) === true) ? 'sent' : 'error',
      'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
    ], (($send['ok'] ?? false) === true) ? 'info' : 'warn');

    return [
      'ok' => true,
      'http' => 200,
      'handled' => true,
      'reason' => (($send['ok'] ?? false) === true) ? 'sent' : 'send_failed',
    ];
  }

  /**
   * promobot_max_webhook_process()
   * Обрабатывает входящий MAX webhook.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param array<string,mixed> $payload
   * @return array<string,mixed>
   */
  function promobot_max_webhook_process(PDO $pdo, int $botId, array $payload): array
  {
    promobot_require_schema($pdo);

    $bot = promobot_bot_get($pdo, $botId);
    if (!$bot) {
      return ['ok' => false, 'http' => 404, 'reason' => 'bot_not_found', 'message' => 'Bot not found'];
    }

    if ((string)($bot['platform'] ?? '') !== PROMOBOT_PLATFORM_MAX) {
      return ['ok' => false, 'http' => 404, 'reason' => 'bot_platform_mismatch', 'message' => 'Bot platform mismatch'];
    }

    if ((int)($bot['enabled'] ?? 0) !== 1) {
      return ['ok' => false, 'http' => 503, 'reason' => 'bot_disabled', 'message' => 'Bot disabled'];
    }

    $meta = promobot_max_extract_message($payload);
    $chatId = trim((string)($meta['chat_id'] ?? ''));
    $text = (string)($meta['text'] ?? '');
    $messageId = trim((string)($meta['message_id'] ?? ''));

    if ($chatId === '') {
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'chat_id_empty'];
    }

    $logBase = [
      'bot_id' => $botId,
      'platform' => PROMOBOT_PLATFORM_MAX,
      'chat_id' => $chatId,
      'message_id' => $messageId,
      'message_text' => $text,
    ];
    $log = static function (array $extra) use ($pdo, $logBase): void {
      promobot_log_add($pdo, array_merge($logBase, $extra));
    };
    $auditBase = [
      'bot_id' => $botId,
      'platform' => PROMOBOT_PLATFORM_MAX,
      'chat_id' => $chatId,
      'chat_type' => '',
      'chat_title' => '',
      'message_id' => $messageId,
      'message_text' => promobot_excerpt($text),
      'from_id' => (string)($meta['from_id'] ?? ''),
      'from_username' => (string)($meta['from_username'] ?? ''),
    ];
    $audit = static function (string $reason, array $extra = [], string $level = 'info') use ($pdo, $auditBase): void {
      promobot_audit_log($pdo, 'max_webhook_trace', $level, array_merge($auditBase, [
        'reason' => $reason,
      ], $extra));
    };

    if (promobot_log_has_message($pdo, $botId, PROMOBOT_PLATFORM_MAX, $chatId, $messageId)) {
      $audit('duplicate_update', [
        'handled' => 0,
        'send_status' => 'duplicate_update',
      ]);
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'duplicate_update'];
    }

    if (promobot_max_is_bot_sender($meta)) {
      $log([
        'matched_promo_id' => 0,
        'response_text' => '',
        'send_status' => 'bot_sender',
        'error_text' => 'bot_sender',
      ]);
      $audit('bot_sender', [
        'handled' => 0,
        'send_status' => 'bot_sender',
      ]);
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'bot_sender'];
    }

    $bindCode = promobot_max_extract_bind_code($text);
    if ($bindCode !== '') {
      $bind = promobot_bind_code_consume($pdo, PROMOBOT_PLATFORM_MAX, $bindCode, $chatId);

      if (($bind['ok'] ?? false) === true) {
        $upsert = promobot_channel_upsert($pdo, (int)$bind['bot_id'], PROMOBOT_PLATFORM_MAX, [
          'chat_id' => $chatId,
          'chat_title' => '',
          'chat_type' => '',
        ]);

        if (($upsert['ok'] ?? false) === true) {
          $responseText = promobot_t('promobot.bind_ok');
          $send = promobot_max_send_message($bot, $chatId, $responseText);
          $log([
            'matched_promo_id' => 0,
            'response_text' => $responseText,
            'send_status' => (($send['ok'] ?? false) === true) ? 'bind_ok' : 'bind_ok_error',
            'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
          ]);
          $audit('bind_ok', [
            'handled' => 1,
            'send_status' => (($send['ok'] ?? false) === true) ? 'bind_ok' : 'bind_ok_error',
            'response_text' => promobot_excerpt($responseText),
            'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
          ], (($send['ok'] ?? false) === true) ? 'info' : 'warn');
          return ['ok' => true, 'http' => 200, 'handled' => true, 'reason' => 'bind_ok'];
        }
      }

      $responseText = promobot_t('promobot.bind_fail');
      $send = promobot_max_send_message($bot, $chatId, $responseText);
      $log([
        'matched_promo_id' => 0,
        'response_text' => $responseText,
        'send_status' => (($send['ok'] ?? false) === true) ? 'bind_fail' : 'bind_fail_error',
        'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
      ]);
      $audit('bind_fail', [
        'handled' => 1,
        'send_status' => (($send['ok'] ?? false) === true) ? 'bind_fail' : 'bind_fail_error',
        'response_text' => promobot_excerpt($responseText),
        'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
      ], 'warn');
      return ['ok' => true, 'http' => 200, 'handled' => true, 'reason' => 'bind_fail'];
    }

    $channel = promobot_channel_get($pdo, $botId, PROMOBOT_PLATFORM_MAX, $chatId);
    if (!$channel || (int)($channel['is_active'] ?? 0) !== 1) {
      $log([
        'matched_promo_id' => 0,
        'response_text' => '',
        'send_status' => 'chat_not_bound',
        'error_text' => 'chat_not_bound',
      ]);
      $audit('chat_not_bound', [
        'handled' => 0,
        'send_status' => 'chat_not_bound',
      ], 'warn');
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'chat_not_bound'];
    }

    $match = promobot_promo_find_match($pdo, $botId, $text);
    if (!$match) {
      $log([
        'matched_promo_id' => 0,
        'response_text' => '',
        'send_status' => 'no_match',
        'error_text' => 'no_match',
      ]);
      $audit('no_match', [
        'handled' => 0,
        'send_status' => 'no_match',
      ]);
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'no_match'];
    }

    $response = (string)($match['response_text'] ?? '');
    if ($response === '') {
      $log([
        'matched_promo_id' => (int)($match['id'] ?? 0),
        'response_text' => '',
        'send_status' => 'empty_response',
        'error_text' => 'empty_response',
      ]);
      $audit('empty_response', [
        'handled' => 0,
        'matched_promo_id' => (int)($match['id'] ?? 0),
        'matched_keywords' => (string)($match['keywords'] ?? ''),
        'send_status' => 'empty_response',
      ], 'warn');
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'empty_response'];
    }

    $send = promobot_max_send_message($bot, $chatId, $response);

    $log([
      'matched_promo_id' => (int)($match['id'] ?? 0),
      'response_text' => $response,
      'send_status' => (($send['ok'] ?? false) === true) ? 'sent' : 'error',
      'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
    ]);
    $audit((($send['ok'] ?? false) === true) ? 'sent' : 'send_failed', [
      'handled' => 1,
      'matched_promo_id' => (int)($match['id'] ?? 0),
      'matched_keywords' => (string)($match['keywords'] ?? ''),
      'response_text' => promobot_excerpt($response),
      'send_status' => (($send['ok'] ?? false) === true) ? 'sent' : 'error',
      'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
    ], (($send['ok'] ?? false) === true) ? 'info' : 'warn');

    return [
      'ok' => true,
      'http' => 200,
      'handled' => true,
      'reason' => (($send['ok'] ?? false) === true) ? 'sent' : 'send_failed',
    ];
  }
}
