<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_lib.php
 * ROLE: Бизнес-логика модуля stopbot.
 * CONTAINS:
 *  - доступы, выборки, привязки, поиск промокодов;
 *  - обработчики webhook для Telegram и MAX.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/stopbot_i18n.php';
require_once ROOT_PATH . '/core/telegram.php';

if (!function_exists('stopbot_now')) {
  /**
   * stopbot_now()
   * Текущее время в формате Y-m-d H:i:s.
   *
   * @return string
   */
  function stopbot_now(): string
  {
    return date('Y-m-d H:i:s');
  }

  /**
   * stopbot_is_manage_role()
   * Проверяет, является ли роль управленческой.
   *
   * @param array<int,string>|string $roles
   * @return bool
   */
  function stopbot_is_manage_role($roles): bool
  {
    if (is_string($roles)) {
      $roles = [$roles];
    }
    if (!is_array($roles)) return false;

    foreach (STOPBOT_MANAGE_ROLES as $role) {
      if (in_array($role, $roles, true)) return true;
    }

    return false;
  }

  /**
   * stopbot_public_root_url()
   * Публичный корень сайта (scheme + host).
   *
   * @return string
   */
  function stopbot_public_root_url(): string
  {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return '';

    $https = (string)($_SERVER['HTTPS'] ?? '');
    $scheme = ($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';

    return $scheme . '://' . $host;
  }

  /**
   * stopbot_tables_missing()
   * Возвращает список отсутствующих таблиц.
   *
   * @param PDO $pdo
   * @param array<int,string> $tables
   * @return array<int,string>
   */
  function stopbot_tables_missing(PDO $pdo, array $tables): array
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
   * stopbot_tables_ready()
   * Проверяет, что таблицы для runtime существуют.
   *
   * @param PDO $pdo
   * @return bool
   */
  function stopbot_tables_ready(PDO $pdo): bool
  {
    return !stopbot_tables_missing($pdo, STOPBOT_REQUIRED_TABLES);
  }

  /**
   * stopbot_require_schema()
   * Блокирует runtime, если нет таблиц.
   *
   * @param PDO $pdo
   * @return void
   */
  function stopbot_require_schema(PDO $pdo): void
  {
    if (stopbot_tables_ready($pdo)) return;

    throw new RuntimeException(stopbot_t('stopbot.error_schema_missing'));
  }

  /**
   * stopbot_settings_defaults()
   * Значения настроек по умолчанию.
   *
   * @return array<string,mixed>
   */
  function stopbot_settings_defaults(): array
  {
    return [
      'id' => 1,
      'log_enabled' => 1,
      'stop_words_list' => '',
      'stop_domains_list' => '',
    ];
  }

  /**
   * stopbot_settings_field_map()
   * Возвращает реальные имена колонок слов/доменов (новая или старая схема БД).
   *
   * @param PDO $pdo
   * @return array<string,string>
   */
  function stopbot_settings_field_map(PDO $pdo): array
  {
    stopbot_require_schema($pdo);

    $wordCol = 'stop_words_list';
    $domainCol = 'stop_domains_list';

    try {
      $st = $pdo->query("SHOW COLUMNS FROM " . STOPBOT_TABLE_SETTINGS);
      $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
      $cols = [];
      if (is_array($rows)) {
        foreach ($rows as $row) {
          $name = trim((string)($row['Field'] ?? ''));
          if ($name !== '') $cols[$name] = true;
        }
      }

      if (!isset($cols[$wordCol]) && isset($cols['badwords_list'])) {
        $wordCol = 'badwords_list';
      }
      if (!isset($cols[$domainCol]) && isset($cols['allowed_domains_list'])) {
        $domainCol = 'allowed_domains_list';
      }
    } catch (\Throwable $e) {
      // Фоллбек на новую схему.
    }

    return [
      'words' => $wordCol,
      'domains' => $domainCol,
    ];
  }

  /**
   * stopbot_settings_get()
   * Читает настройки модуля.
   *
   * @param PDO $pdo
   * @return array<string,mixed>
   */
  function stopbot_settings_get(PDO $pdo): array
  {
    stopbot_require_schema($pdo);
    $map = stopbot_settings_field_map($pdo);

    $row = $pdo->query("SELECT * FROM " . STOPBOT_TABLE_SETTINGS . " WHERE id = 1 LIMIT 1")
      ->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
      return stopbot_settings_defaults();
    }

    return [
      'id' => 1,
      'log_enabled' => ((int)($row['log_enabled'] ?? 1) === 1) ? 1 : 0,
      'stop_words_list' => (string)($row[(string)($map['words'] ?? 'stop_words_list')] ?? ''),
      'stop_domains_list' => (string)($row[(string)($map['domains'] ?? 'stop_domains_list')] ?? ''),
    ];
  }

  /**
   * stopbot_settings_save_rules()
   * Сохраняет словарь стоп-слов и доменов в БД.
   *
   * @param PDO $pdo
   * @param string $stopWordsList
   * @param string $stopDomainsList
   * @return array<string,mixed>
   */
  function stopbot_settings_save_rules(PDO $pdo, string $stopWordsList, string $stopDomainsList): array
  {
    stopbot_require_schema($pdo);
    $map = stopbot_settings_field_map($pdo);
    $wordCol = (string)($map['words'] ?? 'stop_words_list');
    $domainCol = (string)($map['domains'] ?? 'stop_domains_list');

    $current = stopbot_settings_get($pdo);
    $logEnabled = ((int)($current['log_enabled'] ?? 1) === 1) ? 1 : 0;

    $st = $pdo->prepare("\n      INSERT INTO " . STOPBOT_TABLE_SETTINGS . " (id, log_enabled, " . $wordCol . ", " . $domainCol . ")\n      VALUES (1, :log_enabled, :stop_words_list, :stop_domains_list)\n      ON DUPLICATE KEY UPDATE\n        log_enabled = VALUES(log_enabled),\n        " . $wordCol . " = VALUES(" . $wordCol . "),\n        " . $domainCol . " = VALUES(" . $domainCol . ")\n    ");
    $st->execute([
      ':log_enabled' => $logEnabled,
      ':stop_words_list' => $stopWordsList,
      ':stop_domains_list' => $stopDomainsList,
    ]);

    return [
      'ok' => true,
      'stop_words_count' => count(stopbot_text_lines($stopWordsList)),
      'stop_domains_count' => count(stopbot_allowed_domains_parse($stopDomainsList)),
    ];
  }

  /**
   * stopbot_settings_toggle_log()
   * Переключает флаг логирования.
   *
   * @param PDO $pdo
   * @return array<string,mixed>
   */
  function stopbot_settings_toggle_log(PDO $pdo): array
  {
    stopbot_require_schema($pdo);
    $map = stopbot_settings_field_map($pdo);
    $wordCol = (string)($map['words'] ?? 'stop_words_list');
    $domainCol = (string)($map['domains'] ?? 'stop_domains_list');

    $current = stopbot_settings_get($pdo);
    $next = ((int)($current['log_enabled'] ?? 1) === 1) ? 0 : 1;

    $st = $pdo->prepare("\n      INSERT INTO " . STOPBOT_TABLE_SETTINGS . " (id, log_enabled, " . $wordCol . ", " . $domainCol . ")\n      VALUES (1, :log_enabled, :stop_words_list, :stop_domains_list)\n      ON DUPLICATE KEY UPDATE log_enabled = VALUES(log_enabled)\n    ");
    $st->execute([
      ':log_enabled' => $next,
      ':stop_words_list' => (string)($current['stop_words_list'] ?? ''),
      ':stop_domains_list' => (string)($current['stop_domains_list'] ?? ''),
    ]);

    return [
      'ok' => true,
      'log_enabled' => $next,
    ];
  }

  /**
   * stopbot_log_enabled()
   * Возвращает флаг логирования.
   *
   * @param PDO $pdo
   * @return bool
   */
  function stopbot_log_enabled(PDO $pdo): bool
  {
    $settings = stopbot_settings_get($pdo);
    return ((int)($settings['log_enabled'] ?? 1) === 1);
  }

  /**
   * stopbot_audit_log()
   * Пишет расширенный audit только если в модуле включено логирование.
   *
   * @param PDO $pdo
   * @param string $action
   * @param string $level
   * @param array<string,mixed> $payload
   * @return void
   */
  function stopbot_audit_log(PDO $pdo, string $action, string $level, array $payload): void
  {
    if (!stopbot_log_enabled($pdo)) {
      return;
    }

    audit_log(STOPBOT_MODULE_CODE, $action, $level, $payload);
  }

  /**
   * stopbot_excerpt()
   * Короткий безопасный фрагмент текста для аудита.
   *
   * @param string $text
   * @param int $maxLen
   * @return string
   */
  function stopbot_excerpt(string $text, int $maxLen = 220): string
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
   * stopbot_bot_webhook_url()
   * Возвращает URL webhook для бота.
   *
   * @param int $botId
   * @param string $platform
   * @param bool $absolute
   * @return string
   */
  function stopbot_bot_webhook_url(int $botId, string $platform, bool $absolute = true): string
  {
    $platform = strtolower(trim($platform));
    if ($platform !== STOPBOT_PLATFORM_MAX) $platform = STOPBOT_PLATFORM_TG;

    $path = ($platform === STOPBOT_PLATFORM_MAX)
      ? '/adm/modules/stopbot/max_webhook.php'
      : '/adm/modules/stopbot/webhook.php';

    $path .= '?bot_id=' . $botId;

    $rel = function_exists('url') ? (string)url($path) : $path;
    if (!$absolute) return $rel;

    $root = stopbot_public_root_url();
    if ($root === '') return $rel;

    return $root . $rel;
  }
  /**
   * stopbot_bot_list()
   * Список доступных ботов для пользователя.
   *
   * @param PDO $pdo
   * @param int $userId
   * @param array<int,string> $roles
   * @return array<int,array<string,mixed>>
   */
  function stopbot_bot_list(PDO $pdo, int $userId, array $roles): array
  {
    stopbot_require_schema($pdo);

    if (stopbot_is_manage_role($roles)) {
      $rows = $pdo->query("\n        SELECT *\n        FROM " . STOPBOT_TABLE_BOTS . "\n        ORDER BY id DESC\n      ")->fetchAll(PDO::FETCH_ASSOC);

      return is_array($rows) ? $rows : [];
    }

    if ($userId <= 0) return [];

    $st = $pdo->prepare("\n      SELECT b.*\n      FROM " . STOPBOT_TABLE_BOTS . " b\n      INNER JOIN " . STOPBOT_TABLE_USER_ACCESS . " ua\n        ON ua.bot_id = b.id AND ua.user_id = :uid\n      ORDER BY b.id DESC\n    ");
    $st->execute([':uid' => $userId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  /**
   * stopbot_bot_get()
   * Получает бота по id.
   *
   * @param PDO $pdo
   * @param int $botId
   * @return array<string,mixed>
   */
  function stopbot_bot_get(PDO $pdo, int $botId): array
  {
    stopbot_require_schema($pdo);

    if ($botId <= 0) return [];

    $st = $pdo->prepare("SELECT * FROM " . STOPBOT_TABLE_BOTS . " WHERE id = :id LIMIT 1");
    $st->execute([':id' => $botId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
  }

  /**
   * stopbot_bot_promo_source_id()
   * Возвращает id источника промокодов для бота.
   *
   * @param array<string,mixed> $bot
   * @return int
   */
  function stopbot_bot_promo_source_id(array $bot): int
  {
    return (int)($bot['promo_source_bot_id'] ?? 0);
  }

  /**
   * stopbot_bot_promo_owner_id()
   * Возвращает фактический bot_id владельца списка промокодов.
   *
   * @param PDO $pdo
   * @param int $botId
   * @return int
   */
  function stopbot_bot_promo_owner_id(PDO $pdo, int $botId): int
  {
    stopbot_require_schema($pdo);

    if ($botId <= 0) return 0;

    $originBotId = $botId;
    $currentBot = stopbot_bot_get($pdo, $botId);
    if (!$currentBot) return 0;

    $guard = [];
    $hops = 0;
    $maxHops = 16;

    while ($hops < $maxHops) {
      $sourceBotId = stopbot_bot_promo_source_id($currentBot);
      if ($sourceBotId <= 0 || $sourceBotId === $botId) {
        return $botId;
      }

      if (isset($guard[$sourceBotId])) {
        return $originBotId;
      }
      $guard[$sourceBotId] = true;

      $sourceBot = stopbot_bot_get($pdo, $sourceBotId);
      if (!$sourceBot) {
        return $botId;
      }

      $botId = $sourceBotId;
      $currentBot = $sourceBot;
      $hops++;
    }

    return $originBotId;
  }

  /**
   * stopbot_bot_promo_owner()
   * Возвращает бота-владельца списка промокодов.
   *
   * @param PDO $pdo
   * @param int $botId
   * @return array<string,mixed>
   */
  function stopbot_bot_promo_owner(PDO $pdo, int $botId): array
  {
    $ownerBotId = stopbot_bot_promo_owner_id($pdo, $botId);
    if ($ownerBotId <= 0) return [];

    return stopbot_bot_get($pdo, $ownerBotId);
  }

  /**
   * stopbot_bot_promo_source_options()
   * Возвращает список ботов для выбора источника промокодов.
   *
   * @param PDO $pdo
   * @param int $excludeBotId
   * @return array<int,array<string,mixed>>
   */
  function stopbot_bot_promo_source_options(PDO $pdo, int $excludeBotId = 0): array
  {
    stopbot_require_schema($pdo);

    $rows = $pdo->query("\n      SELECT id, name, platform\n      FROM " . STOPBOT_TABLE_BOTS . "\n      ORDER BY name ASC, id ASC\n    ")->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) return [];

    if ($excludeBotId <= 0) {
      return $rows;
    }

    $out = [];
    foreach ($rows as $row) {
      if ((int)($row['id'] ?? 0) === $excludeBotId) continue;
      $out[] = $row;
    }

    return $out;
  }

  /**
   * stopbot_promo_belongs_to_context()
   * Проверяет, что промокод принадлежит эффективному списку контекстного бота.
   *
   * @param PDO $pdo
   * @param array<string,mixed> $promo
   * @param int $contextBotId
   * @return bool
   */
  function stopbot_promo_belongs_to_context(PDO $pdo, array $promo, int $contextBotId): bool
  {
    if ($contextBotId <= 0) return false;

    $promoBotId = (int)($promo['bot_id'] ?? 0);
    if ($promoBotId <= 0) return false;

    $ownerBotId = stopbot_bot_promo_owner_id($pdo, $contextBotId);
    if ($ownerBotId <= 0) return false;

    return ($ownerBotId === $promoBotId);
  }

  /**
   * stopbot_user_has_bot_access()
   * Проверяет доступ пользователя к боту.
   *
   * @param PDO $pdo
   * @param int $userId
   * @param int $botId
   * @param array<int,string> $roles
   * @return bool
   */
  function stopbot_user_has_bot_access(PDO $pdo, int $userId, int $botId, array $roles): bool
  {
    if ($botId <= 0) return false;
    if (stopbot_is_manage_role($roles)) return true;
    if ($userId <= 0) return false;

    $st = $pdo->prepare("\n      SELECT user_id\n      FROM " . STOPBOT_TABLE_USER_ACCESS . "\n      WHERE user_id = :uid AND bot_id = :bid\n      LIMIT 1\n    ");
    $st->execute([':uid' => $userId, ':bid' => $botId]);

    return ((int)$st->fetchColumn() > 0);
  }

  /**
   * stopbot_user_access_list()
   * Список пользователей, назначенных на бота.
   *
   * @param PDO $pdo
   * @param int $botId
   * @return array<int,array<string,mixed>>
   */
  function stopbot_user_access_list(PDO $pdo, int $botId): array
  {
    stopbot_require_schema($pdo);

    if ($botId <= 0) return [];

    $st = $pdo->prepare("\n      SELECT\n        ua.user_id,\n        u.name,\n        u.status,\n        COALESCE(GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ', '), '') AS role_codes,\n        ua.created_at\n      FROM " . STOPBOT_TABLE_USER_ACCESS . " ua\n      INNER JOIN users u ON u.id = ua.user_id\n      LEFT JOIN user_roles ur ON ur.user_id = u.id\n      LEFT JOIN roles r ON r.id = ur.role_id\n      WHERE ua.bot_id = :bid\n      GROUP BY ua.user_id, u.name, u.status, ua.created_at\n      ORDER BY u.name ASC, ua.user_id ASC\n    ");
    $st->execute([':bid' => $botId]);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  /**
   * stopbot_users_attach_candidates()
   * Пользователи, которых можно назначить на бота.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param int $limit
   * @return array<int,array<string,mixed>>
   */
  function stopbot_users_attach_candidates(PDO $pdo, int $botId, int $limit = 500): array
  {
    stopbot_require_schema($pdo);

    if ($botId <= 0) return [];
    if ($limit < 1) $limit = 1;
    if ($limit > 2000) $limit = 2000;

    $st = $pdo->prepare("\n      SELECT\n        u.id,\n        u.name,\n        u.status,\n        COALESCE(GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ', '), '') AS role_codes\n      FROM users u\n      LEFT JOIN user_roles ur ON ur.user_id = u.id\n      LEFT JOIN roles r ON r.id = ur.role_id\n      LEFT JOIN " . STOPBOT_TABLE_USER_ACCESS . " ua\n        ON ua.user_id = u.id AND ua.bot_id = :bid\n      WHERE ua.user_id IS NULL\n      GROUP BY u.id, u.name, u.status\n      ORDER BY (u.status = 'active') DESC, u.name ASC, u.id ASC\n      LIMIT :lim\n    ");
    $st->bindValue(':bid', $botId, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  /**
   * stopbot_promos_list()
   * Список промокодов бота.
   *
   * @param PDO $pdo
   * @param int $botId
   * @return array<int,array<string,mixed>>
   */
  function stopbot_promos_list(PDO $pdo, int $botId): array
  {
    stopbot_require_schema($pdo);

    if ($botId <= 0) return [];

    $ownerBotId = stopbot_bot_promo_owner_id($pdo, $botId);
    if ($ownerBotId <= 0) return [];

    $st = $pdo->prepare("\n      SELECT *\n      FROM " . STOPBOT_TABLE_PROMOS . "\n      WHERE bot_id = :bid\n      ORDER BY id DESC\n    ");
    $st->execute([':bid' => $ownerBotId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows) && !$rows) {
      stopbot_promos_import_legacy_rules($pdo, $ownerBotId);
      $st->execute([':bid' => $ownerBotId]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    }

    return is_array($rows) ? $rows : [];
  }

  /**
   * stopbot_promos_import_legacy_rules()
   * Переносит старые rules (settings: слова) в единый список стоп-слов (promos).
   * Домены из settings не импортируются, т.к. это allowlist.
   *
   * @param PDO $pdo
   * @param int $ownerBotId
   * @return array<string,int|bool>
   */
  function stopbot_promos_import_legacy_rules(PDO $pdo, int $ownerBotId): array
  {
    stopbot_require_schema($pdo);

    if ($ownerBotId <= 0) return ['ok' => false, 'inserted' => 0, 'items' => 0];

    $settings = stopbot_settings_get($pdo);
    $items = [];

    foreach (stopbot_text_lines((string)($settings['stop_words_list'] ?? '')) as $line) {
      $line = trim(preg_replace('~\s+~u', ' ', (string)$line) ?? (string)$line);
      if ($line === '') continue;
      $items[$line] = true;
    }

    $list = array_keys($items);
    if (!$list) return ['ok' => true, 'inserted' => 0, 'items' => 0];

    $existingMap = [];
    $existing = $pdo->prepare("SELECT keywords FROM " . STOPBOT_TABLE_PROMOS . " WHERE bot_id = :bid");
    $existing->execute([':bid' => $ownerBotId]);
    $existingRows = $existing->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($existingRows)) {
      foreach ($existingRows as $row) {
        $keywords = (string)($row['keywords'] ?? '');
        foreach (stopbot_keywords_parse($keywords) as $kw) {
          $kw = trim((string)$kw);
          if ($kw === '') continue;
          $existingMap[stopbot_text_lower($kw)] = true;
        }
      }
    }

    $pending = [];
    foreach ($list as $item) {
      $key = stopbot_text_lower(trim((string)$item));
      if ($key === '' || isset($existingMap[$key])) continue;
      $pending[] = trim((string)$item);
    }

    if (!$pending) return ['ok' => true, 'inserted' => 0, 'items' => 0];

    if (!$pending) return ['ok' => true, 'inserted' => 0, 'items' => 0];

    $ins = $pdo->prepare("\n      INSERT INTO " . STOPBOT_TABLE_PROMOS . "\n        (bot_id, keywords, response_text, is_active, created_by, updated_by)\n      VALUES\n        (:bot_id, :keywords, '', 1, 0, 0)\n    ");

    $inserted = 0;
    foreach ($pending as $keywords) {
      $keywords = trim((string)$keywords);
      if ($keywords === '') continue;
      if (strlen($keywords) > 500) $keywords = substr($keywords, 0, 500);
      $ins->execute([
        ':bot_id' => $ownerBotId,
        ':keywords' => $keywords,
      ]);
      $inserted++;
    }

    return [
      'ok' => true,
      'inserted' => $inserted,
      'items' => count($pending),
    ];
  }

  /**
   * stopbot_promo_get()
   * Получает промокод по id.
   *
   * @param PDO $pdo
   * @param int $promoId
   * @return array<string,mixed>
   */
  function stopbot_promo_get(PDO $pdo, int $promoId): array
  {
    stopbot_require_schema($pdo);

    if ($promoId <= 0) return [];

    $st = $pdo->prepare("SELECT * FROM " . STOPBOT_TABLE_PROMOS . " WHERE id = :id LIMIT 1");
    $st->execute([':id' => $promoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : [];
  }

  /**
   * stopbot_keywords_parse()
   * Разбирает строку ключевых слов в список.
   *
   * @param string $raw
   * @return array<int,string>
   */
  function stopbot_keywords_parse(string $raw): array
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
   * stopbot_keywords_join()
   * Собирает список keywords обратно в строку.
   *
   * @param array<int,string> $list
   * @return string
   */
  function stopbot_keywords_join(array $list): string
  {
    $clean = [];
    foreach ($list as $item) {
      $item = trim((string)$item);
      if ($item === '') continue;
      $clean[] = $item;
    }
    return implode(', ', array_values(array_unique($clean)));
  }

  /**
   * stopbot_text_lower()
   * Приводит текст к нижнему регистру (с учетом UTF-8).
   *
   * @param string $text
   * @return string
   */
  function stopbot_text_lower(string $text): string
  {
    if (function_exists('mb_strtolower')) {
      return (string)mb_strtolower($text, 'UTF-8');
    }
    return (string)strtolower($text);
  }

  /**
   * stopbot_normalize_text()
   * Нормализует текст для устойчивого сравнения слов/корней.
   *
   * @param string $text
   * @return string
   */
  function stopbot_normalize_text(string $text): string
  {
    $text = stopbot_text_lower($text);
    $text = str_replace(['ё', 'Ё'], ['е', 'е'], $text);
    $text = preg_replace('~[\x{200B}-\x{200D}\x{FEFF}]~u', '', $text) ?? $text;
    $text = strtr($text, [
      '@' => 'а',
      'a' => 'а',
      'b' => 'в',
      'c' => 'с',
      'e' => 'е',
      'k' => 'к',
      'm' => 'м',
      'h' => 'н',
      'o' => 'о',
      'p' => 'р',
      't' => 'т',
      'x' => 'х',
      'y' => 'у',
      '3' => 'з',
      '6' => 'б',
      '0' => 'о',
      '1' => 'и',
    ]);
    $text = preg_replace('~[^[:alnum:]\p{Cyrillic}]+~u', ' ', $text) ?? $text;
    return trim((string)preg_replace('~\s+~u', ' ', $text));
  }

  /**
   * stopbot_tokenize_normalized()
   * Разбивает нормализованный текст на токены.
   *
   * @param string $normalizedText
   * @return array<int,string>
   */
  function stopbot_tokenize_normalized(string $normalizedText): array
  {
    if ($normalizedText === '') return [];
    $tokens = preg_split('~\s+~u', $normalizedText);
    if (!is_array($tokens)) return [];
    $out = [];
    foreach ($tokens as $token) {
      $token = trim((string)$token);
      if ($token === '') continue;
      $out[] = $token;
    }
    return $out;
  }

  /**
   * stopbot_text_lines()
   * Возвращает непустые строки без комментариев.
   *
   * @param string $text
   * @return array<int,string>
   */
  function stopbot_text_lines(string $text): array
  {
    $text = str_replace("\r", '', $text);
    $lines = explode("\n", $text);
    $out = [];
    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '' || strpos($line, '#') === 0) continue;
      $out[] = $line;
    }
    return $out;
  }

  /**
   * stopbot_badwords_rules_parse()
   * Парсит правила badwords (word:/flex:).
   *
   * @param string $text
   * @return array<string,array<int,string>>
   */
  function stopbot_badwords_rules_parse(string $text): array
  {
    $words = [];
    $roots = [];

    foreach (stopbot_text_lines($text) as $line) {
      if (stripos($line, 'word:') === 0) {
        $value = stopbot_normalize_text((string)substr($line, 5));
        if ($value !== '') $words[$value] = true;
        continue;
      }
      if (stripos($line, 'flex:') === 0) {
        $value = stopbot_normalize_text((string)substr($line, 5));
        if ($value !== '') $roots[$value] = true;
        continue;
      }

      $value = stopbot_normalize_text($line);
      if ($value !== '') $words[$value] = true;
    }

    return [
      'words' => array_keys($words),
      'roots' => array_keys($roots),
    ];
  }

  /**
   * stopbot_domain_from_value()
   * Извлекает host из URL или строки домена.
   *
   * @param string $value
   * @return string
   */
  function stopbot_domain_from_value(string $value): string
  {
    $value = trim($value);
    if ($value === '') return '';

    if (strpos($value, '://') === false) {
      $value = 'https://' . ltrim($value, '/');
    }

    $host = trim((string)parse_url($value, PHP_URL_HOST));
    $host = stopbot_text_lower($host);
    $host = trim($host, '.');
    if ($host === '') return '';
    if (strpos($host, 'www.') === 0) {
      $host = (string)substr($host, 4);
    }
    return $host;
  }

  /**
   * stopbot_allowed_domains_parse()
   * Парсит белый список доменов.
   *
   * @param string $text
   * @return array<int,string>
   */
  function stopbot_allowed_domains_parse(string $text): array
  {
    $out = [];
    foreach (stopbot_text_lines($text) as $line) {
      $host = stopbot_domain_from_value($line);
      if ($host === '') continue;
      $out[$host] = true;
    }
    return array_keys($out);
  }

  /**
   * stopbot_domain_allowed()
   * Проверяет, что host входит в белый список.
   *
   * @param string $host
   * @param array<int,string> $allowedDomains
   * @return bool
   */
  function stopbot_domain_allowed(string $host, array $allowedDomains): bool
  {
    $host = stopbot_domain_from_value($host);
    if ($host === '') return true;
    if (!$allowedDomains) return false;

    foreach ($allowedDomains as $allowed) {
      $allowed = stopbot_domain_from_value((string)$allowed);
      if ($allowed === '') continue;
      if ($host === $allowed) return true;
      if (substr($host, -strlen('.' . $allowed)) === '.' . $allowed) return true;
    }

    return false;
  }

  /**
   * stopbot_extract_urls_from_text()
   * Извлекает URL из текста.
   *
   * @param string $text
   * @return array<int,string>
   */
  function stopbot_extract_urls_from_text(string $text): array
  {
    $urls = [];
    if (preg_match_all('~https?://[^\s<>"\'\]\)]+~iu', $text, $m)) {
      foreach ((array)($m[0] ?? []) as $url) {
        $url = trim((string)$url);
        if ($url === '') continue;
        $urls[$url] = true;
      }
    }
    return array_keys($urls);
  }

  /**
   * stopbot_tg_entity_urls()
   * Извлекает text_link URL из entities Telegram update.
   *
   * @param array<string,mixed> $update
   * @return array<int,string>
   */
  function stopbot_tg_entity_urls(array $update): array
  {
    $msg = [];
    if (isset($update['message']) && is_array($update['message'])) {
      $msg = (array)$update['message'];
    } elseif (isset($update['channel_post']) && is_array($update['channel_post'])) {
      $msg = (array)$update['channel_post'];
    } elseif (isset($update['edited_message']) && is_array($update['edited_message'])) {
      $msg = (array)$update['edited_message'];
    } elseif (isset($update['edited_channel_post']) && is_array($update['edited_channel_post'])) {
      $msg = (array)$update['edited_channel_post'];
    }
    if (!$msg) return [];

    $entities = [];
    if (isset($msg['entities']) && is_array($msg['entities'])) {
      $entities = (array)$msg['entities'];
    } elseif (isset($msg['caption_entities']) && is_array($msg['caption_entities'])) {
      $entities = (array)$msg['caption_entities'];
    }

    $urls = [];
    foreach ($entities as $entity) {
      if (!is_array($entity)) continue;
      if (trim((string)($entity['type'] ?? '')) !== 'text_link') continue;
      $url = trim((string)($entity['url'] ?? ''));
      if ($url === '') continue;
      $urls[$url] = true;
    }
    return array_keys($urls);
  }

  /**
   * stopbot_external_rules()
   * Загружает словари (слова/корни/домены) из настроек БД.
   *
   * @return array<string,mixed>
   */
  function stopbot_external_rules(PDO $pdo): array
  {
    $settings = stopbot_settings_get($pdo);
    $stopWordsRaw = (string)($settings['stop_words_list'] ?? '');
    $domainsRaw = (string)($settings['stop_domains_list'] ?? '');
    $words = [];
    $roots = [];
    foreach (stopbot_text_lines($stopWordsRaw) as $line) {
      $line = trim($line);
      if ($line === '') continue;
      $isFlex = false;
      if (stripos($line, 'word:') === 0) {
        $line = (string)substr($line, 5);
      } elseif (stripos($line, 'flex:') === 0) {
        $line = (string)substr($line, 5);
        $isFlex = true;
      }
      $value = stopbot_normalize_text($line);
      if ($value === '') continue;
      if ($isFlex) {
        $roots[$value] = true;
        continue;
      }
      $words[$value] = true;
      $root = stopbot_auto_root($value);
      if ($root !== '') $roots[$root] = true;
    }
    return [
      'words' => array_keys($words),
      'roots' => array_keys($roots),
      'domains' => stopbot_allowed_domains_parse($domainsRaw),
    ];
  }

  /**
   * stopbot_auto_root()
   * Возвращает авто-корень слова из единого списка стоп-слов.
   *
   * @param string $word
   * @return string
   */
  function stopbot_auto_root(string $word): string
  {
    $word = stopbot_normalize_text($word);
    if ($word === '') return '';

    $len = function_exists('mb_strlen') ? (int)mb_strlen($word, 'UTF-8') : strlen($word);
    if ($len <= 2) return '';
    if ($len <= 4) {
      return function_exists('mb_substr') ? (string)mb_substr($word, 0, 2, 'UTF-8') : substr($word, 0, 2);
    }
    if ($len <= 6) {
      return function_exists('mb_substr') ? (string)mb_substr($word, 0, 3, 'UTF-8') : substr($word, 0, 3);
    }
    return function_exists('mb_substr') ? (string)mb_substr($word, 0, 4, 'UTF-8') : substr($word, 0, 4);
  }

  /**
   * stopbot_find_badword_hit()
   * Ищет попадание по правилам word/flex.
   *
   * @param string $text
   * @param array<string,mixed> $rules
   * @return string
   */
  function stopbot_find_badword_hit(string $text, array $rules): string
  {
    $normalized = stopbot_normalize_text($text);
    if ($normalized === '') return '';

    $tokens = stopbot_tokenize_normalized($normalized);
    if (!$tokens) return '';

    foreach ((array)($rules['words'] ?? []) as $word) {
      $word = trim((string)$word);
      if ($word === '') continue;
      // Совпадение по вхождению в строке (включая словосочетания).
      if (strpos($normalized, $word) !== false) return $word;
    }

    foreach ((array)($rules['roots'] ?? []) as $root) {
      $root = trim((string)$root);
      if ($root === '') continue;
      foreach ($tokens as $token) {
        if ($token !== '' && strpos($token, $root) !== false) return $root;
      }
    }

    return '';
  }

  /**
   * stopbot_detect_external_violation()
   * Проверяет стоп-слова/корни/домены по словарям из БД.
   *
   * @param string $text
   * @param array<int,string> $extraUrls
   * @return array<string,string>
   */
  function stopbot_detect_external_violation(PDO $pdo, string $text, array $extraUrls = []): array
  {
    $rules = stopbot_external_rules($pdo);

    $badwordHit = stopbot_find_badword_hit($text, $rules);
    if ($badwordHit !== '') {
      return [
        'rule_code' => 'badword',
        'match' => $badwordHit,
        'matched_url' => '',
      ];
    }

    $allowedDomains = (array)($rules['domains'] ?? []);

    $allUrls = [];
    foreach (stopbot_extract_urls_from_text($text) as $url) {
      $allUrls[$url] = true;
    }
    foreach ($extraUrls as $url) {
      $url = trim((string)$url);
      if ($url === '') continue;
      $allUrls[$url] = true;
    }

    foreach (array_keys($allUrls) as $url) {
      $host = stopbot_domain_from_value($url);
      if ($host === '') continue;
      if (!stopbot_domain_allowed($host, $allowedDomains)) {
        return [
          'rule_code' => 'domain_forbidden',
          'match' => $host,
          'matched_url' => $url,
        ];
      }
    }

    // Фоллбек для "голых" доменов в тексте без http://
    if (preg_match_all('~(?:^|[^a-z0-9])((?:[a-z0-9-]+\.)+[a-z]{2,24})(?:$|[^a-z0-9])~iu', stopbot_text_lower($text), $m)) {
      foreach ((array)($m[1] ?? []) as $hostRaw) {
        $host = stopbot_domain_from_value((string)$hostRaw);
        if ($host === '') continue;
        if (!stopbot_domain_allowed($host, $allowedDomains)) {
          return [
            'rule_code' => 'domain_forbidden',
            'match' => $host,
            'matched_url' => '',
          ];
        }
      }
    }

    return [
      'rule_code' => '',
      'match' => '',
      'matched_url' => '',
    ];
  }

  /**
   * stopbot_promo_find_match()
   * Ищет первый подходящий промокод по тексту.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param string $text
   * @return array<string,mixed>
   */
  function stopbot_promo_find_match(PDO $pdo, int $botId, string $text, array $extraUrls = []): array
  {
    stopbot_require_schema($pdo);

    if ($botId <= 0) return [];

    $ownerBotId = stopbot_bot_promo_owner_id($pdo, $botId);
    if ($ownerBotId <= 0) return [];
    stopbot_promos_import_legacy_rules($pdo, $ownerBotId);

    $text = trim($text);
    if ($text === '' && !$extraUrls) return [];

    $textNorm = stopbot_text_lower($text);
    $textNormClean = stopbot_normalize_text($text);
    $tokens = stopbot_tokenize_normalized($textNormClean);
    if ($extraUrls) {
      // extra urls are processed by external violation checker in webhook pipeline
    }

    $st = $pdo->prepare("\n      SELECT id, keywords, response_text\n      FROM " . STOPBOT_TABLE_PROMOS . "\n      WHERE bot_id = :bid AND is_active = 1\n      ORDER BY id ASC\n    ");
    $st->execute([':bid' => $ownerBotId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) return [];

    foreach ($rows as $row) {
      $keywords = (string)($row['keywords'] ?? '');
      $list = stopbot_keywords_parse($keywords);
      if (!$list) continue;

      foreach ($list as $kw) {
        $kwRaw = trim((string)$kw);
        if ($kwRaw === '') continue;

        if (stripos($kwRaw, 'word:') === 0) {
          $needle = stopbot_normalize_text((string)substr($kwRaw, 5));
          if ($needle === '' || !$tokens) continue;
          foreach ($tokens as $token) {
            if ($token === $needle) return $row;
          }
          continue;
        }

        if (stripos($kwRaw, 'flex:') === 0) {
          $needle = stopbot_normalize_text((string)substr($kwRaw, 5));
          if ($needle === '' || !$tokens) continue;
          foreach ($tokens as $token) {
            if ($token !== '' && strpos($token, $needle) !== false) return $row;
          }
          continue;
        }

        $domainRaw = $kwRaw;
        $forceDomain = false;
        if (stripos($domainRaw, 'domain:') === 0) {
          $domainRaw = (string)substr($domainRaw, 7);
          $forceDomain = true;
        } elseif (stripos($domainRaw, 'site:') === 0) {
          $domainRaw = (string)substr($domainRaw, 5);
          $forceDomain = true;
        } elseif (stripos($domainRaw, 'url:') === 0) {
          $domainRaw = (string)substr($domainRaw, 4);
          $forceDomain = true;
        }
        $domain = stopbot_domain_from_value($domainRaw);
        $domainLooksLikeHost = (
          strpos($domainRaw, ' ') === false
          && (strpos($domainRaw, '.') !== false || strpos($domainRaw, '://') !== false || strpos($domainRaw, '/') !== false)
        );
        if ($domain !== '' && ($forceDomain || $domainLooksLikeHost)) {
          // Доменные ключи в promo-правилах не применяем:
          // домены обрабатываются только через allowlist в settings.
          continue;
        }

        $kwNorm = stopbot_text_lower($kwRaw);
        if ($kwNorm === '') continue;

        if (function_exists('mb_strpos')) {
          if (mb_strpos($textNorm, $kwNorm, 0, 'UTF-8') !== false) return $row;
        } else {
          if (strpos($textNorm, $kwNorm) !== false) return $row;
        }

        $kwNormClean = stopbot_normalize_text($kwRaw);
        if ($kwNormClean !== '' && $textNormClean !== '' && strpos($textNormClean, $kwNormClean) !== false) return $row;
      }
    }

    return [];
  }

  /**
   * stopbot_rules_split()
   * Возвращает раздельные списки правил:
   * слова/корни из promos + legacy words; домены — только allowlist из settings.
   *
   * @param PDO $pdo
   * @param int $botId
   * @return array<string,array<int,string>>
   */
  function stopbot_rules_split(PDO $pdo, int $botId): array
  {
    stopbot_require_schema($pdo);

    $empty = [
      'words' => [],
      'roots' => [],
      'domains' => [],
    ];

    if ($botId <= 0) return $empty;
    $ownerBotId = stopbot_bot_promo_owner_id($pdo, $botId);
    if ($ownerBotId <= 0) return $empty;

    stopbot_promos_import_legacy_rules($pdo, $ownerBotId);

    $words = [];
    $roots = [];
    $domains = [];

    $settings = stopbot_settings_get($pdo);
    foreach (stopbot_text_lines((string)($settings['stop_words_list'] ?? '')) as $line) {
      $line = trim((string)$line);
      if ($line === '') continue;
      if (stripos($line, 'flex:') === 0) {
        $value = trim((string)substr($line, 5));
        if ($value !== '') $roots[stopbot_text_lower($value)] = $value;
        continue;
      }
      if (stripos($line, 'word:') === 0) {
        $value = trim((string)substr($line, 5));
        if ($value !== '') $words[stopbot_text_lower($value)] = $value;
        continue;
      }
      $words[stopbot_text_lower($line)] = $line;
    }
    foreach (stopbot_text_lines((string)($settings['stop_domains_list'] ?? '')) as $line) {
      $host = stopbot_domain_from_value((string)$line);
      if ($host === '') continue;
      $domains[stopbot_text_lower($host)] = $host;
    }

    $st = $pdo->prepare("\n      SELECT keywords\n      FROM " . STOPBOT_TABLE_PROMOS . "\n      WHERE bot_id = :bid\n      ORDER BY id ASC\n    ");
    $st->execute([':bid' => $ownerBotId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) {
      foreach ($rows as $row) {
        $list = stopbot_keywords_parse((string)($row['keywords'] ?? ''));
        foreach ($list as $kw) {
          $kwRaw = trim((string)$kw);
          if ($kwRaw === '') continue;

          if (stripos($kwRaw, 'flex:') === 0) {
            $value = trim((string)substr($kwRaw, 5));
            if ($value !== '') $roots[stopbot_text_lower($value)] = $value;
            continue;
          }
          if (stripos($kwRaw, 'word:') === 0) {
            $value = trim((string)substr($kwRaw, 5));
            if ($value !== '') $words[stopbot_text_lower($value)] = $value;
            continue;
          }

          $domainRaw = $kwRaw;
          $isDomainRule = false;
          if (stripos($domainRaw, 'domain:') === 0) {
            $domainRaw = (string)substr($domainRaw, 7);
            $isDomainRule = true;
          } elseif (stripos($domainRaw, 'site:') === 0) {
            $domainRaw = (string)substr($domainRaw, 5);
            $isDomainRule = true;
          } elseif (stripos($domainRaw, 'url:') === 0) {
            $domainRaw = (string)substr($domainRaw, 4);
            $isDomainRule = true;
          }

          $domain = stopbot_domain_from_value($domainRaw);
          $domainLooksLikeHost = (
            strpos($domainRaw, ' ') === false
            && (strpos($domainRaw, '.') !== false || strpos($domainRaw, '://') !== false || strpos($domainRaw, '/') !== false)
          );
          if ($domain !== '' && ($isDomainRule || $domainLooksLikeHost)) continue;

          $words[stopbot_text_lower($kwRaw)] = $kwRaw;
        }
      }
    }

    natcasesort($words);
    natcasesort($roots);
    natcasesort($domains);

    return [
      'words' => array_values($words),
      'roots' => array_values($roots),
      'domains' => array_values($domains),
    ];
  }

  /**
   * stopbot_rule_delete()
   * Удаляет правило из источников:
   *  - word/root: из promos + legacy stop_words_list;
   *  - domain: из allowlist stop_domains_list (+ очистка legacy domain:* в promos).
   *
   * @param PDO $pdo
   * @param int $botId
   * @param string $kind word|root|domain
   * @param string $value
   * @return array<string,mixed>
   */
  function stopbot_rule_delete(PDO $pdo, int $botId, string $kind, string $value): array
  {
    stopbot_require_schema($pdo);

    $kind = trim(stopbot_text_lower($kind));
    $valueRaw = trim((string)$value);
    if ($botId <= 0 || $valueRaw === '') {
      return ['ok' => false, 'error' => 'INVALID_PARAMS'];
    }
    if (!in_array($kind, ['word', 'root', 'domain'], true)) {
      return ['ok' => false, 'error' => 'INVALID_KIND'];
    }

    $ownerBotId = stopbot_bot_promo_owner_id($pdo, $botId);
    if ($ownerBotId <= 0) return ['ok' => false, 'error' => 'PROMO_OWNER_NOT_FOUND'];

    $settings = stopbot_settings_get($pdo);
    $settingsWords = (string)($settings['stop_words_list'] ?? '');
    $settingsDomains = (string)($settings['stop_domains_list'] ?? '');

    $targetNorm = stopbot_normalize_text($valueRaw);
    $targetHost = stopbot_domain_from_value($valueRaw);

    $settingsWordsChanged = false;
    $settingsDomainsChanged = false;
    $promosChanged = 0;
    $promosRemoved = 0;
    $tokenHits = 0;

    // 1) settings: stop_words_list
    if ($kind === 'word' || $kind === 'root') {
      $newWordLines = [];
      foreach (stopbot_text_lines($settingsWords) as $line) {
        $lineRaw = trim((string)$line);
        if ($lineRaw === '') continue;

        $lineIsFlex = (stripos($lineRaw, 'flex:') === 0);
        $lineIsWord = (stripos($lineRaw, 'word:') === 0);
        $lineVal = $lineRaw;
        if ($lineIsFlex) $lineVal = (string)substr($lineRaw, 5);
        if ($lineIsWord) $lineVal = (string)substr($lineRaw, 5);
        $lineNorm = stopbot_normalize_text($lineVal);

        $remove = false;
        if ($kind === 'root') {
          $remove = ($lineIsFlex && $targetNorm !== '' && $lineNorm === $targetNorm);
        } else { // word
          $remove = (!$lineIsFlex && $targetNorm !== '' && $lineNorm === $targetNorm);
        }

        if ($remove) {
          $settingsWordsChanged = true;
          $tokenHits++;
          continue;
        }
        $newWordLines[] = $lineRaw;
      }
      $settingsWords = implode("\n", $newWordLines);
    }

    // 2) settings: stop_domains_list (allowlist)
    if ($kind === 'domain' && $targetHost !== '') {
      $newDomainLines = [];
      foreach (stopbot_text_lines($settingsDomains) as $line) {
        $host = stopbot_domain_from_value((string)$line);
        if ($host !== '' && $host === $targetHost) {
          $settingsDomainsChanged = true;
          $tokenHits++;
          continue;
        }
        $newDomainLines[] = trim((string)$line);
      }
      $settingsDomains = implode("\n", $newDomainLines);
    }

    // 3) promos tokens
    $st = $pdo->prepare("\n      SELECT id, keywords\n      FROM " . STOPBOT_TABLE_PROMOS . "\n      WHERE bot_id = :bid\n      ORDER BY id ASC\n    ");
    $st->execute([':bid' => $ownerBotId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (is_array($rows)) {
      foreach ($rows as $row) {
        $promoId = (int)($row['id'] ?? 0);
        if ($promoId <= 0) continue;
        $tokens = stopbot_keywords_parse((string)($row['keywords'] ?? ''));
        if (!$tokens) continue;

        $filtered = [];
        $rowChanged = false;
        foreach ($tokens as $token) {
          $raw = trim((string)$token);
          if ($raw === '') continue;

          $isFlex = (stripos($raw, 'flex:') === 0);
          $isWord = (stripos($raw, 'word:') === 0);
          $isDomain = false;
          $domainRaw = $raw;
          if (stripos($domainRaw, 'domain:') === 0) {
            $domainRaw = (string)substr($domainRaw, 7);
            $isDomain = true;
          } elseif (stripos($domainRaw, 'site:') === 0) {
            $domainRaw = (string)substr($domainRaw, 5);
            $isDomain = true;
          } elseif (stripos($domainRaw, 'url:') === 0) {
            $domainRaw = (string)substr($domainRaw, 4);
            $isDomain = true;
          }
          $domainHost = stopbot_domain_from_value($domainRaw);
          $domainLike = (!$isDomain && strpos($domainRaw, ' ') === false
            && (strpos($domainRaw, '.') !== false || strpos($domainRaw, '://') !== false || strpos($domainRaw, '/') !== false));
          if ($domainLike && $domainHost !== '') $isDomain = true;

          $normVal = $raw;
          if ($isFlex) $normVal = (string)substr($raw, 5);
          if ($isWord) $normVal = (string)substr($raw, 5);
          $normVal = stopbot_normalize_text($normVal);

          $remove = false;
          if ($kind === 'root') {
            $remove = ($isFlex && $targetNorm !== '' && $normVal === $targetNorm);
          } elseif ($kind === 'word') {
            $remove = (!$isFlex && !$isDomain && $targetNorm !== '' && $normVal === $targetNorm);
          } elseif ($kind === 'domain') {
            $remove = ($isDomain && $targetHost !== '' && $domainHost === $targetHost);
          }

          if ($remove) {
            $rowChanged = true;
            $tokenHits++;
            continue;
          }
          $filtered[] = $raw;
        }

        if (!$rowChanged) continue;

        if (!$filtered) {
          $pdo->prepare("DELETE FROM " . STOPBOT_TABLE_PROMOS . " WHERE id = :id LIMIT 1")
            ->execute([':id' => $promoId]);
          $promosRemoved++;
          $promosChanged++;
          continue;
        }

        $pdo->prepare("UPDATE " . STOPBOT_TABLE_PROMOS . " SET keywords = :kw WHERE id = :id LIMIT 1")
          ->execute([
            ':id' => $promoId,
            ':kw' => stopbot_keywords_join($filtered),
          ]);
        $promosChanged++;
      }
    }

    if ($settingsWordsChanged || $settingsDomainsChanged) {
      stopbot_settings_save_rules($pdo, $settingsWords, $settingsDomains);
    }

    return [
      'ok' => true,
      'deleted' => $tokenHits > 0 ? 1 : 0,
      'token_hits' => $tokenHits,
      'promos_changed' => $promosChanged,
      'promos_removed' => $promosRemoved,
      'settings_words_changed' => $settingsWordsChanged ? 1 : 0,
      'settings_domains_changed' => $settingsDomainsChanged ? 1 : 0,
      'kind' => $kind,
      'value' => $valueRaw,
    ];
  }
  /**
   * stopbot_channel_get()
   * Возвращает канал/чат по bot_id + chat_id.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param string $platform
   * @param string $chatId
   * @return array<string,mixed>
   */
  function stopbot_channel_get(PDO $pdo, int $botId, string $platform, string $chatId): array
  {
    stopbot_require_schema($pdo);

    if ($botId <= 0 || $chatId === '') return [];

    $st = $pdo->prepare("\n      SELECT *\n      FROM " . STOPBOT_TABLE_CHANNELS . "\n      WHERE bot_id = :bid AND platform = :platform AND chat_id = :chat_id\n      LIMIT 1\n    ");
    $st->execute([
      ':bid' => $botId,
      ':platform' => $platform,
      ':chat_id' => $chatId,
    ]);

    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
  }

  /**
   * stopbot_channels_list()
   * Список каналов/чатов бота.
   *
   * @param PDO $pdo
   * @param int $botId
   * @return array<int,array<string,mixed>>
   */
  function stopbot_channels_list(PDO $pdo, int $botId): array
  {
    stopbot_require_schema($pdo);

    if ($botId <= 0) return [];

    $st = $pdo->prepare("\n      SELECT *\n      FROM " . STOPBOT_TABLE_CHANNELS . "\n      WHERE bot_id = :bid\n      ORDER BY id DESC\n    ");
    $st->execute([':bid' => $botId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
  }

  /**
   * stopbot_logs_moderation_list()
   * Возвращает последние логи модерации для бота.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param int $limit
   * @return array<int,array<string,mixed>>
   */
  function stopbot_logs_moderation_list(PDO $pdo, int $botId, int $limit = 200): array
  {
    stopbot_require_schema($pdo);

    if ($botId <= 0) return [];
    if ($limit < 1) $limit = 1;
    if ($limit > 1000) $limit = 1000;

    $st = $pdo->prepare("\n      SELECT\n        l.id,\n        l.platform,\n        l.chat_id,\n        l.message_id,\n        l.message_text,\n        l.send_status,\n        l.error_text,\n        l.created_at,\n        COALESCE(NULLIF(c.chat_title, ''), l.chat_id) AS chat_title\n      FROM " . STOPBOT_TABLE_LOGS . " l\n      LEFT JOIN " . STOPBOT_TABLE_CHANNELS . " c\n        ON c.bot_id = l.bot_id\n       AND c.platform = l.platform\n       AND c.chat_id = l.chat_id\n      WHERE l.bot_id = :bid\n        AND l.send_status IN ('deleted', 'delete_failed', 'edited', 'edit_failed')\n      ORDER BY l.id DESC\n      LIMIT :lim\n    ");
    $st->bindValue(':bid', $botId, PDO::PARAM_INT);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
  }

  /**
   * stopbot_channel_upsert()
   * Создает/обновляет привязку чата.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param string $platform
   * @param array<string,mixed> $meta
   * @return array<string,mixed>
   */
  function stopbot_channel_upsert(PDO $pdo, int $botId, string $platform, array $meta): array
  {
    stopbot_require_schema($pdo);

    $chatId = trim((string)($meta['chat_id'] ?? ''));
    if ($botId <= 0 || $chatId === '') {
      return ['ok' => false, 'error' => 'CHAT_ID_EMPTY'];
    }

    $chatTitle = trim((string)($meta['chat_title'] ?? ''));
    $chatType = trim((string)($meta['chat_type'] ?? ''));

    $st = $pdo->prepare("\n      INSERT INTO " . STOPBOT_TABLE_CHANNELS . "\n        (bot_id, platform, chat_id, chat_title, chat_type, is_active, linked_at, last_seen_at)\n      VALUES\n        (:bot_id, :platform, :chat_id, :chat_title, :chat_type, 1, :linked_at, :last_seen_at)\n      ON DUPLICATE KEY UPDATE\n        chat_title = VALUES(chat_title),\n        chat_type = VALUES(chat_type),\n        is_active = 1,\n        last_seen_at = VALUES(last_seen_at)\n    ");

    $now = stopbot_now();
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
   * stopbot_bind_code_generate()
   * Генерирует код привязки чата.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param string $platform
   * @param int $createdBy
   * @return array<string,mixed>
   */
  function stopbot_bind_code_generate(PDO $pdo, int $botId, string $platform, int $createdBy = 0): array
  {
    stopbot_require_schema($pdo);

    if ($botId <= 0) {
      return ['ok' => false, 'error' => 'BOT_ID_REQUIRED'];
    }

    $platform = strtolower(trim($platform));
    if ($platform !== STOPBOT_PLATFORM_MAX) $platform = STOPBOT_PLATFORM_TG;

    $attempts = 0;
    $code = '';

    while ($attempts < 8) {
      $code = (string)random_int(100000, 999999);

      $st = $pdo->prepare("SELECT id FROM " . STOPBOT_TABLE_BIND_TOKENS . " WHERE code = :code LIMIT 1");
      $st->execute([':code' => $code]);
      if (!(int)$st->fetchColumn()) break;
      $attempts++;
    }

    if ($code === '') {
      return ['ok' => false, 'error' => 'CODE_GENERATE_FAILED'];
    }

    $expiresAt = date('Y-m-d H:i:s', time() + (STOPBOT_BIND_CODE_TTL_MINUTES * 60));

    $st = $pdo->prepare("\n      INSERT INTO " . STOPBOT_TABLE_BIND_TOKENS . "\n        (bot_id, platform, code, expires_at, created_by)\n      VALUES\n        (:bot_id, :platform, :code, :expires_at, :created_by)\n    ");
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
   * stopbot_bind_code_consume()
   * Поглощает код привязки и возвращает bot_id.
   *
   * @param PDO $pdo
   * @param string $platform
   * @param string $code
   * @param string $chatId
   * @return array<string,mixed>
   */
  function stopbot_bind_code_consume(PDO $pdo, string $platform, string $code, string $chatId): array
  {
    stopbot_require_schema($pdo);

    $code = trim($code);
    if ($code === '' || $chatId === '') {
      return ['ok' => false, 'reason' => 'code_or_chat_empty'];
    }

    $platform = strtolower(trim($platform));
    if ($platform !== STOPBOT_PLATFORM_MAX) $platform = STOPBOT_PLATFORM_TG;

    $st = $pdo->prepare("\n      SELECT *\n      FROM " . STOPBOT_TABLE_BIND_TOKENS . "\n      WHERE code = :code AND platform = :platform AND used_at IS NULL\n      LIMIT 1\n    ");
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

    $st = $pdo->prepare("\n      UPDATE " . STOPBOT_TABLE_BIND_TOKENS . "\n      SET used_at = :used_at, used_chat_id = :chat_id\n      WHERE id = :id\n    ");
    $st->execute([
      ':used_at' => stopbot_now(),
      ':chat_id' => $chatId,
      ':id' => (int)$row['id'],
    ]);

    return [
      'ok' => true,
      'bot_id' => $botId,
    ];
  }

  /**
   * stopbot_log_add()
   * Записывает лог модуля (если включен).
   *
   * @param PDO $pdo
   * @param array<string,mixed> $row
   * @return void
   */
  function stopbot_log_add(PDO $pdo, array $row): void
  {
    try {
      if (!stopbot_log_enabled($pdo)) return;

      $msg = (string)($row['message_text'] ?? '');
      $resp = (string)($row['response_text'] ?? '');

      $st = $pdo->prepare("\n      INSERT INTO " . STOPBOT_TABLE_LOGS . "\n      (\n        bot_id,\n        platform,\n        chat_id,\n        message_id,\n        message_text,\n        matched_promo_id,\n        response_text,\n        send_status,\n        error_text\n      )\n      VALUES\n      (\n        :bot_id,\n        :platform,\n        :chat_id,\n        :message_id,\n        :message_text,\n        :matched_promo_id,\n        :response_text,\n        :send_status,\n        :error_text\n      )\n    ");

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
   * stopbot_log_has_message()
   * Проверяет, обрабатывалось ли уже входящее сообщение.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param string $platform
   * @param string $chatId
   * @param string $messageId
   * @return bool
   */
  function stopbot_log_has_message(PDO $pdo, int $botId, string $platform, string $chatId, string $messageId): bool
  {
    try {
      if ($botId <= 0 || $platform === '' || $chatId === '' || $messageId === '') {
        return false;
      }

      $st = $pdo->prepare("\n      SELECT id\n      FROM " . STOPBOT_TABLE_LOGS . "\n      WHERE bot_id = :bot_id\n        AND platform = :platform\n        AND chat_id = :chat_id\n        AND message_id = :message_id\n      LIMIT 1\n    ");
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
   * stopbot_tg_extract_meta()
   * Извлекает метаданные из Telegram update.
   *
   * @param array<string,mixed> $update
   * @return array<string,string>
   */
  function stopbot_tg_extract_meta(array $update): array
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
   * stopbot_tg_chat_is_bindable()
   * Разрешает авто-привязку только для групп/супергрупп/каналов.
   *
   * @param string $chatType
   * @return bool
   */
  function stopbot_tg_chat_is_bindable(string $chatType): bool
  {
    $chatType = strtolower(trim($chatType));
    return in_array($chatType, ['group', 'supergroup', 'channel'], true);
  }

  /**
   * stopbot_tg_member_status_is_active()
   * Определяет, что бот присутствует в чате и может принимать апдейты.
   *
   * @param string $status
   * @return bool
   */
  function stopbot_tg_member_status_is_active(string $status): bool
  {
    $status = strtolower(trim($status));
    return in_array($status, ['member', 'administrator', 'creator'], true);
  }

  /**
   * stopbot_tg_extract_member_update()
   * Извлекает изменение статуса бота в чате из my_chat_member.
   *
   * @param array<string,mixed> $update
   * @return array<string,mixed>
   */
  function stopbot_tg_extract_member_update(array $update): array
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

    if ($chatId === '' || !stopbot_tg_chat_is_bindable($chatType)) {
      return [];
    }

    return [
      'chat_id' => $chatId,
      'chat_type' => $chatType,
      'chat_title' => $chatTitle,
      'old_status' => $oldStatus,
      'new_status' => $newStatus,
      'is_active' => stopbot_tg_member_status_is_active($newStatus),
    ];
  }

  /**
   * stopbot_channel_set_active()
   * Меняет флаг активности уже известного чата.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param string $platform
   * @param string $chatId
   * @param bool $isActive
   * @return void
   */
  function stopbot_channel_set_active(PDO $pdo, int $botId, string $platform, string $chatId, bool $isActive): void
  {
    stopbot_require_schema($pdo);

    if ($botId <= 0 || trim($chatId) === '') {
      return;
    }

    $st = $pdo->prepare("\n      UPDATE " . STOPBOT_TABLE_CHANNELS . "\n      SET is_active = :is_active,\n          last_seen_at = :last_seen_at\n      WHERE bot_id = :bot_id AND platform = :platform AND chat_id = :chat_id\n    ");
    $st->execute([
      ':is_active' => $isActive ? 1 : 0,
      ':last_seen_at' => stopbot_now(),
      ':bot_id' => $botId,
      ':platform' => $platform,
      ':chat_id' => $chatId,
    ]);
  }

  /**
   * stopbot_tg_is_bot_sender()
   * Проверяет, что сообщение отправлено ботом.
   *
   * @param array<string,mixed> $update
   * @return bool
   */
  function stopbot_tg_is_bot_sender(array $update): bool
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
   * stopbot_tg_is_admin_sender()
   * Проверяет, что отправитель — админ/владелец чата.
   *
   * @param string $token
   * @param array<string,string> $meta
   * @param array<string,mixed> $update
   * @return bool
   */
  function stopbot_tg_is_admin_sender(string $token, array $meta, array $update): bool
  {
    $chatId = trim((string)($meta['chat_id'] ?? ''));
    $chatType = trim((string)($meta['chat_type'] ?? ''));
    $userId = trim((string)($meta['from_id'] ?? ''));

    if (isset($update['channel_post']) && is_array($update['channel_post'])) {
      return true;
    }

    $message = [];
    if (isset($update['message']) && is_array($update['message'])) {
      $message = (array)$update['message'];
    } elseif (isset($update['edited_message']) && is_array($update['edited_message'])) {
      $message = (array)$update['edited_message'];
    }
    if ($message) {
      $senderChat = (array)($message['sender_chat'] ?? []);
      if ($senderChat) return true;
    }

    if ($chatType === 'private' || $chatId === '' || $userId === '') {
      return false;
    }

    static $adminsCache = [];
    if (!array_key_exists($chatId, $adminsCache)) {
      $admins = function_exists('tg_get_chat_administrators') ? tg_get_chat_administrators($token, $chatId) : ['ok' => false];
      $map = [];
      if (($admins['ok'] ?? false) === true && is_array($admins['result'] ?? null)) {
        foreach ((array)$admins['result'] as $item) {
          if (!is_array($item)) continue;
          $adminUser = (array)($item['user'] ?? []);
          $id = trim((string)($adminUser['id'] ?? ''));
          if ($id !== '') $map[$id] = true;
        }
      }
      $adminsCache[$chatId] = $map;
    }
    if (isset($adminsCache[$chatId][$userId])) return true;

    $member = function_exists('tg_get_chat_member') ? tg_get_chat_member($token, $chatId, $userId) : ['ok' => false];
    $status = trim((string)($member['result']['status'] ?? ''));
    return in_array($status, ['creator', 'administrator'], true);
  }

  /**
   * stopbot_tg_extract_bind_code()
   * Достаёт код привязки из текста.
   *
   * @param string $text
   * @return string
   */
  function stopbot_tg_extract_bind_code(string $text): string
  {
    if (preg_match('~^/(?:bind|start)(?:@\w+)?\s+(\d{6})\s*$~u', trim($text), $m)) {
      return (string)($m[1] ?? '');
    }
    return '';
  }

  /**
   * stopbot_max_extract_message()
   * Извлекает метаданные из MAX payload.
   *
   * @param array<string,mixed> $payload
   * @return array<string,string>
   */
  function stopbot_max_extract_message(array $payload): array
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
   * stopbot_max_is_bot_sender()
   * Проверяет, что сообщение отправлено ботом (best-effort).
   *
   * @param array<string,string> $meta
   * @return bool
   */
  function stopbot_max_is_bot_sender(array $meta): bool
  {
    $type = strtolower(trim((string)($meta['sender_type'] ?? '')));
    return ($type === 'bot');
  }

  /**
   * stopbot_max_extract_bind_code()
   * Достаёт код привязки из текста.
   *
   * @param string $text
   * @return string
   */
  function stopbot_max_extract_bind_code(string $text): string
  {
    if (preg_match('~^/(?:bind|start)\s+(\d{6})\s*$~u', trim($text), $m)) {
      return (string)($m[1] ?? '');
    }
    return '';
  }

  /**
   * stopbot_tg_send_message()
   * Отправляет сообщение в Telegram.
   *
   * @param string $token
   * @param string $chatId
   * @param string $text
   * @return array<string,mixed>
   */
  function stopbot_tg_send_message(string $token, string $chatId, string $text): array
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
   * stopbot_tg_delete_message()
   * Удаляет сообщение в Telegram.
   *
   * @param string $token
   * @param string $chatId
   * @param string $messageId
   * @return array<string,mixed>
   */
  function stopbot_tg_delete_message(string $token, string $chatId, string $messageId): array
  {
    if (!function_exists('tg_request')) {
      return ['ok' => false, 'error' => 'TG_REQUEST_MISSING'];
    }
    if ($token === '' || $chatId === '' || $messageId === '') {
      return ['ok' => false, 'error' => 'TG_PARAMS_REQUIRED'];
    }

    return tg_request($token, 'deleteMessage', [
      'chat_id' => $chatId,
      'message_id' => $messageId,
    ]);
  }

  /**
   * stopbot_max_send_message()
   * Отправляет сообщение в MAX.
   *
   * @param array<string,mixed> $bot
   * @param string $chatId
   * @param string $text
   * @return array<string,mixed>
   */
  function stopbot_max_send_message(array $bot, string $chatId, string $text): array
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

    $http = stopbot_http_post_json($url, $payload, [
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
   * stopbot_max_moderation_text()
   * Текст замены для MAX, когда сообщение нарушает стоп-слова.
   *
   * @return string
   */
  function stopbot_max_moderation_text(): string
  {
    return 'Сообщение скрыто модерацией.';
  }

  /**
   * stopbot_http_post_json()
   * HTTP POST JSON helper.
   *
   * @param string $url
   * @param array<string,mixed> $payload
   * @param array<int,string> $headers
   * @return array<string,mixed>
   */
  function stopbot_http_post_json(string $url, array $payload, array $headers = []): array
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
   * stopbot_max_api_key_normalize()
   * Нормализует MAX API key (без префиксов Bearer/OAuth).
   *
   * @param string $apiKey
   * @return string
   */
  function stopbot_max_api_key_normalize(string $apiKey): string
  {
    $apiKey = trim($apiKey);
    if (stripos($apiKey, 'Bearer ') === 0) {
      $apiKey = trim((string)substr($apiKey, 7));
    }
    if (stripos($apiKey, 'OAuth ') === 0) {
      $apiKey = trim((string)substr($apiKey, 6));
    }
    return $apiKey;
  }

  /**
   * stopbot_max_api_version()
   * Текущая версия webhook-подписки MAX API.
   *
   * @return string
   */
  function stopbot_max_api_version(): string
  {
    return '1.2.5';
  }

  /**
   * stopbot_max_http_json()
   * Универсальный HTTP JSON helper для MAX API.
   *
   * @param string $method
   * @param string $url
   * @param array<int,string> $headers
   * @param array<string,mixed>|null $payload
   * @param int $timeout
   * @return array<string,mixed>
   */
  function stopbot_max_http_json(string $method, string $url, array $headers = [], ?array $payload = null, int $timeout = 20): array
  {
    $method = strtoupper(trim($method));
    if ($method === '') $method = 'GET';

    $url = trim($url);
    if ($url === '') {
      return ['ok' => false, 'error' => 'URL_EMPTY', 'http_code' => 0, 'json' => [], 'raw' => ''];
    }

    $httpCode = 0;
    $raw = '';
    $jsonPayload = '';
    if ($payload !== null) {
      $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if (!is_string($jsonPayload)) $jsonPayload = '{}';
    }

    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      if (!$ch) {
        return ['ok' => false, 'error' => 'CURL_INIT_FAILED', 'http_code' => 0, 'json' => [], 'raw' => ''];
      }

      $requestHeaders = array_merge(['Accept: application/json'], $headers);
      if ($payload !== null) $requestHeaders[] = 'Content-Type: application/json';

      $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout > 0 ? $timeout : 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_CUSTOMREQUEST => $method,
      ];
      if ($payload !== null) $opts[CURLOPT_POSTFIELDS] = $jsonPayload;
      curl_setopt_array($ch, $opts);

      $result = curl_exec($ch);
      $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = trim((string)curl_error($ch));
      curl_close($ch);

      if ($result === false) {
        return ['ok' => false, 'error' => 'CURL_ERROR', 'description' => $curlError, 'http_code' => $httpCode, 'json' => [], 'raw' => ''];
      }

      $raw = (string)$result;
    } else {
      $headersTxt = "Accept: application/json\r\n";
      foreach ($headers as $header) {
        $headersTxt .= trim((string)$header) . "\r\n";
      }
      if ($payload !== null) $headersTxt .= "Content-Type: application/json\r\n";

      $ctx = stream_context_create([
        'http' => [
          'method' => $method,
          'header' => $headersTxt,
          'timeout' => $timeout > 0 ? $timeout : 20,
          'content' => $payload !== null ? $jsonPayload : '',
          'ignore_errors' => true,
        ],
      ]);
      $result = @file_get_contents($url, false, $ctx);
      $meta = $http_response_header ?? [];
      if (is_array($meta) && isset($meta[0]) && preg_match('~\s(\d{3})\s~', (string)$meta[0], $m)) {
        $httpCode = (int)$m[1];
      }
      if ($result === false) {
        return ['ok' => false, 'error' => 'HTTP_ERROR', 'http_code' => $httpCode, 'json' => [], 'raw' => ''];
      }

      $raw = (string)$result;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) $json = [];

    return [
      'ok' => true,
      'http_code' => $httpCode,
      'json' => $json,
      'raw' => $raw,
    ];
  }

  /**
   * stopbot_max_update_message()
   * Редактирует сообщение в MAX (используется как эквивалент удаления).
   *
   * @param array<string,mixed> $bot
   * @param string $chatId
   * @param string $messageId
   * @param string $text
   * @return array<string,mixed>
   */
  function stopbot_max_update_message(array $bot, string $chatId, string $messageId, string $text): array
  {
    $apiKey = stopbot_max_api_key_normalize((string)($bot['max_api_key'] ?? ''));
    if ($apiKey === '') return ['ok' => false, 'error' => 'MAX_API_KEY_EMPTY'];

    $baseUrl = rtrim(trim((string)($bot['max_base_url'] ?? 'https://platform-api.max.ru')), '/');
    if ($baseUrl === '') return ['ok' => false, 'error' => 'MAX_BASE_URL_EMPTY'];

    $chatId = trim($chatId);
    $messageId = trim($messageId);
    if ($chatId === '' || $messageId === '') {
      return ['ok' => false, 'error' => 'MAX_PARAMS_REQUIRED'];
    }

    $url = $baseUrl . '/messages?chat_id=' . rawurlencode($chatId) . '&message_id=' . rawurlencode($messageId);
    $res = stopbot_max_http_json('PUT', $url, [
      'Authorization: ' . $apiKey,
    ], [
      'text' => $text,
      'attachments' => [],
    ], 20);
    if (($res['ok'] ?? false) !== true) {
      return [
        'ok' => false,
        'error' => (string)($res['error'] ?? 'MAX_HTTP_ERROR'),
        'http_code' => (int)($res['http_code'] ?? 0),
        'raw' => $res,
      ];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    $ok = ($httpCode >= 200 && $httpCode < 300);
    $json = (array)($res['json'] ?? []);

    if ($ok && array_key_exists('success', $json) && (bool)$json['success'] === false) {
      $ok = false;
    }

    $error = '';
    if (!$ok) {
      $error = stopbot_max_extract_error($res);
      if ($error === '') {
        $error = 'HTTP_' . $httpCode;
      }
    }

    return [
      'ok' => $ok,
      'error' => $error,
      'http_code' => $httpCode,
      'raw' => $res,
    ];
  }

  /**
   * stopbot_max_delete_message()
   * Удаляет сообщение в MAX.
   *
   * @param array<string,mixed> $bot
   * @param string $messageId
   * @return array<string,mixed>
   */
  function stopbot_max_delete_message(array $bot, string $messageId): array
  {
    $apiKey = stopbot_max_api_key_normalize((string)($bot['max_api_key'] ?? ''));
    if ($apiKey === '') return ['ok' => false, 'error' => 'MAX_API_KEY_EMPTY'];

    $baseUrl = rtrim(trim((string)($bot['max_base_url'] ?? 'https://platform-api.max.ru')), '/');
    if ($baseUrl === '') return ['ok' => false, 'error' => 'MAX_BASE_URL_EMPTY'];

    $messageId = trim($messageId);
    if ($messageId === '') {
      return ['ok' => false, 'error' => 'MAX_MESSAGE_ID_REQUIRED'];
    }

    $url = $baseUrl . '/messages?message_id=' . rawurlencode($messageId);
    $res = stopbot_max_http_json('DELETE', $url, [
      'Authorization: ' . $apiKey,
    ], null, 20);
    if (($res['ok'] ?? false) !== true) {
      return [
        'ok' => false,
        'error' => (string)($res['error'] ?? 'MAX_HTTP_ERROR'),
        'http_code' => (int)($res['http_code'] ?? 0),
        'raw' => $res,
      ];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    $ok = ($httpCode >= 200 && $httpCode < 300);
    $json = (array)($res['json'] ?? []);

    if ($ok && array_key_exists('success', $json) && (bool)$json['success'] === false) {
      $ok = false;
    }

    $error = '';
    if (!$ok) {
      $error = stopbot_max_extract_error($res);
      if ($error === '') {
        $error = 'HTTP_' . $httpCode;
      }
    }

    return [
      'ok' => $ok,
      'error' => $error,
      'http_code' => $httpCode,
      'raw' => $res,
    ];
  }

  /**
   * stopbot_max_extract_error()
   * Преобразует ответ MAX API в компактную ошибку.
   *
   * @param array<string,mixed> $res
   * @return string
   */
  function stopbot_max_extract_error(array $res): string
  {
    $json = (array)($res['json'] ?? []);
    $err = trim((string)($json['message'] ?? $json['error'] ?? $res['description'] ?? $res['error'] ?? ''));
    if ($err === '') {
      $httpCode = (int)($res['http_code'] ?? 0);
      if ($httpCode > 0) $err = 'HTTP_' . $httpCode;
    }
    return $err !== '' ? $err : 'MAX_ERROR';
  }

  /**
   * stopbot_max_webhook_target_url()
   * Публичный URL webhook для MAX-бота.
   *
   * @param array<string,mixed> $bot
   * @return string
   */
  function stopbot_max_webhook_target_url(array $bot): string
  {
    $url = trim((string)($bot['webhook_url'] ?? ''));
    if ($url !== '' && preg_match('~^https?://~i', $url)) return $url;

    $botId = (int)($bot['id'] ?? 0);
    if ($botId <= 0) return '';

    $computed = stopbot_bot_webhook_url($botId, STOPBOT_PLATFORM_MAX, true);
    return trim((string)$computed);
  }

  /**
   * stopbot_max_subscription_url()
   * URL подписки из объекта подписки MAX API.
   *
   * @param array<string,mixed> $subscription
   * @return string
   */
  function stopbot_max_subscription_url(array $subscription): string
  {
    $req = (array)($subscription['request'] ?? []);
    return trim((string)($subscription['url'] ?? $req['url'] ?? $subscription['endpoint_url'] ?? ''));
  }

  /**
   * stopbot_max_subscription_version()
   * Версия подписки из объекта MAX API.
   *
   * @param array<string,mixed> $subscription
   * @return string
   */
  function stopbot_max_subscription_version(array $subscription): string
  {
    $req = (array)($subscription['request'] ?? []);
    return trim((string)($subscription['version'] ?? $req['version'] ?? ''));
  }

  /**
   * stopbot_max_subscription_accepts_messages()
   * Проверяет, что подписка принимает message_created.
   *
   * @param array<string,mixed> $subscription
   * @return bool
   */
  function stopbot_max_subscription_accepts_messages(array $subscription): bool
  {
    $req = (array)($subscription['request'] ?? []);
    $types = $subscription['update_types'] ?? ($req['update_types'] ?? []);
    if (!is_array($types) || !$types) return true;

    foreach ($types as $type) {
      if (trim((string)$type) === 'message_created') return true;
    }
    return false;
  }

  /**
   * stopbot_max_subscription_recreate_reason()
   * Причина, по которой подписку нужно пересоздать.
   *
   * @param array<string,mixed> $subscription
   * @return string
   */
  function stopbot_max_subscription_recreate_reason(array $subscription): string
  {
    if (!stopbot_max_subscription_accepts_messages($subscription)) {
      return 'missing_message_created';
    }

    $version = stopbot_max_subscription_version($subscription);
    if ($version === '') return 'missing_version';
    if ($version !== stopbot_max_api_version()) return 'version_mismatch';

    return '';
  }

  /**
   * stopbot_max_subscription_match()
   * Проверяет совпадение подписки с целевым URL.
   *
   * @param array<string,mixed> $subscription
   * @param string $endpointUrl
   * @return bool
   */
  function stopbot_max_subscription_match(array $subscription, string $endpointUrl): bool
  {
    $url = stopbot_max_subscription_url($subscription);
    if ($url === '' || $endpointUrl === '' || $url !== $endpointUrl) return false;
    return stopbot_max_subscription_accepts_messages($subscription);
  }

  /**
   * stopbot_max_subscription_list_for_bot()
   * Получает список webhook-подписок MAX API.
   *
   * @param array<string,mixed> $bot
   * @return array<string,mixed>
   */
  function stopbot_max_subscription_list_for_bot(array $bot): array
  {
    $baseUrl = rtrim(trim((string)($bot['max_base_url'] ?? 'https://platform-api.max.ru')), '/');
    $apiKey = stopbot_max_api_key_normalize((string)($bot['max_api_key'] ?? ''));
    if ($baseUrl === '') return ['ok' => false, 'error' => 'MAX_BASE_URL_EMPTY', 'items' => []];
    if ($apiKey === '') return ['ok' => false, 'error' => 'MAX_API_KEY_EMPTY', 'items' => []];

    $url = $baseUrl . '/subscriptions?count=100';
    $res = stopbot_max_http_json('GET', $url, [
      'Authorization: ' . $apiKey,
    ], null, 20);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => stopbot_max_extract_error($res), 'items' => []];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    if ($httpCode < 200 || $httpCode >= 300) {
      return ['ok' => false, 'error' => stopbot_max_extract_error($res), 'items' => []];
    }

    $json = (array)($res['json'] ?? []);
    $items = [];
    if (isset($json['subscriptions']) && is_array($json['subscriptions'])) {
      $items = $json['subscriptions'];
    } elseif (isset($json['items']) && is_array($json['items'])) {
      $items = $json['items'];
    } elseif (isset($json['result']['subscriptions']) && is_array($json['result']['subscriptions'])) {
      $items = $json['result']['subscriptions'];
    } elseif (isset($json['result']['items']) && is_array($json['result']['items'])) {
      $items = $json['result']['items'];
    }

    return ['ok' => true, 'items' => is_array($items) ? $items : []];
  }

  /**
   * stopbot_max_subscription_delete_for_bot()
   * Удаляет подписку MAX по URL.
   *
   * @param array<string,mixed> $bot
   * @param string $urlToDelete
   * @return array<string,mixed>
   */
  function stopbot_max_subscription_delete_for_bot(array $bot, string $urlToDelete): array
  {
    $urlToDelete = trim($urlToDelete);
    if ($urlToDelete === '') return ['ok' => false, 'error' => 'SUBSCRIPTION_URL_EMPTY'];

    $baseUrl = rtrim(trim((string)($bot['max_base_url'] ?? 'https://platform-api.max.ru')), '/');
    $apiKey = stopbot_max_api_key_normalize((string)($bot['max_api_key'] ?? ''));
    if ($baseUrl === '') return ['ok' => false, 'error' => 'MAX_BASE_URL_EMPTY'];
    if ($apiKey === '') return ['ok' => false, 'error' => 'MAX_API_KEY_EMPTY'];

    $url = $baseUrl . '/subscriptions?url=' . rawurlencode($urlToDelete);
    $res = stopbot_max_http_json('DELETE', $url, [
      'Authorization: ' . $apiKey,
    ], null, 25);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => stopbot_max_extract_error($res)];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    $json = (array)($res['json'] ?? []);
    if ($httpCode < 200 || $httpCode >= 300) {
      return ['ok' => false, 'error' => stopbot_max_extract_error($res), 'http_code' => $httpCode];
    }

    if (array_key_exists('success', $json) && (bool)$json['success'] === false) {
      $error = trim((string)($json['message'] ?? $json['error'] ?? 'MAX_UNSUBSCRIBE_FAILED'));
      if ($error === '') $error = 'MAX_UNSUBSCRIBE_FAILED';
      return ['ok' => false, 'error' => $error, 'http_code' => $httpCode];
    }

    return ['ok' => true];
  }

  /**
   * stopbot_max_webhook_info_for_bot()
   * Проверяет наличие и актуальность MAX webhook-подписки.
   *
   * @param array<string,mixed> $bot
   * @return array<string,mixed>
   */
  function stopbot_max_webhook_info_for_bot(array $bot): array
  {
    $endpointUrl = stopbot_max_webhook_target_url($bot);
    if ($endpointUrl === '') {
      return ['ok' => false, 'error' => 'MAX_WEBHOOK_URL_EMPTY', 'result' => ['url' => ''], 'expected_url' => ''];
    }

    $listed = stopbot_max_subscription_list_for_bot($bot);
    if (($listed['ok'] ?? false) !== true) {
      $listed['result'] = ['url' => ''];
      $listed['expected_url'] = $endpointUrl;
      return $listed;
    }

    $sameUrlSubscription = null;
    foreach ((array)($listed['items'] ?? []) as $sub) {
      if (!is_array($sub)) continue;

      $subUrl = stopbot_max_subscription_url($sub);
      if ($subUrl === $endpointUrl && $sameUrlSubscription === null) {
        $sameUrlSubscription = $sub;
      }

      if (stopbot_max_subscription_match($sub, $endpointUrl)) {
        return [
          'ok' => true,
          'result' => ['url' => $endpointUrl],
          'subscription' => $sub,
          'items_count' => count((array)($listed['items'] ?? [])),
          'expected_url' => $endpointUrl,
          'subscription_version' => stopbot_max_subscription_version($sub),
          'recreate_needed' => 0,
          'recreate_reason' => '',
        ];
      }
    }

    if (is_array($sameUrlSubscription)) {
      $recreateReason = stopbot_max_subscription_recreate_reason($sameUrlSubscription);
      return [
        'ok' => false,
        'error' => 'MAX_WEBHOOK_RECREATE_REQUIRED',
        'result' => ['url' => $endpointUrl],
        'subscription' => $sameUrlSubscription,
        'items_count' => count((array)($listed['items'] ?? [])),
        'expected_url' => $endpointUrl,
        'subscription_version' => stopbot_max_subscription_version($sameUrlSubscription),
        'recreate_needed' => 1,
        'recreate_reason' => $recreateReason,
      ];
    }

    return [
      'ok' => false,
      'error' => 'MAX_WEBHOOK_NOT_FOUND',
      'result' => ['url' => ''],
      'items_count' => count((array)($listed['items'] ?? [])),
      'expected_url' => $endpointUrl,
      'recreate_needed' => 0,
      'recreate_reason' => '',
    ];
  }

  /**
   * stopbot_max_webhook_set_for_bot()
   * Создаёт/переустанавливает MAX webhook-подписку.
   *
   * @param array<string,mixed> $bot
   * @return array<string,mixed>
   */
  function stopbot_max_webhook_set_for_bot(array $bot): array
  {
    $endpointUrl = stopbot_max_webhook_target_url($bot);
    if ($endpointUrl === '' || !preg_match('~^https?://~i', $endpointUrl)) {
      return ['ok' => false, 'error' => 'MAX_WEBHOOK_URL_INVALID', 'url' => $endpointUrl];
    }

    $listed = stopbot_max_subscription_list_for_bot($bot);
    $removed = 0;
    $removeErrors = [];
    $recreated = 0;
    $recreateReason = '';

    if (($listed['ok'] ?? false) === true) {
      foreach ((array)($listed['items'] ?? []) as $sub) {
        if (!is_array($sub)) continue;

        $subUrl = stopbot_max_subscription_url($sub);
        if ($subUrl !== $endpointUrl) continue;

        $reason = stopbot_max_subscription_recreate_reason($sub);
        if ($reason === '' && stopbot_max_subscription_match($sub, $endpointUrl)) {
          return [
            'ok' => true,
            'created' => 0,
            'removed' => $removed,
            'remove_errors' => $removeErrors,
            'recreated' => $recreated,
            'recreate_reason' => $recreateReason,
            'url' => $endpointUrl,
          ];
        }

        $delete = stopbot_max_subscription_delete_for_bot($bot, $subUrl);
        if (($delete['ok'] ?? false) !== true) {
          $removeErrors[] = [
            'url' => $subUrl,
            'error' => (string)($delete['error'] ?? 'DELETE_FAILED'),
          ];
          return [
            'ok' => false,
            'error' => 'MAX_WEBHOOK_DELETE_FAILED',
            'details' => $removeErrors,
            'url' => $endpointUrl,
          ];
        }

        $removed++;
        $recreated = 1;
        $recreateReason = $reason !== '' ? $reason : 'recreate';
        break;
      }
    } else {
      return ['ok' => false, 'error' => (string)($listed['error'] ?? 'MAX_SUBSCRIPTIONS_FAILED')];
    }

    $baseUrl = rtrim(trim((string)($bot['max_base_url'] ?? 'https://platform-api.max.ru')), '/');
    $apiKey = stopbot_max_api_key_normalize((string)($bot['max_api_key'] ?? ''));
    if ($baseUrl === '') return ['ok' => false, 'error' => 'MAX_BASE_URL_EMPTY'];
    if ($apiKey === '') return ['ok' => false, 'error' => 'MAX_API_KEY_EMPTY'];

    $url = $baseUrl . '/subscriptions';
    $payload = [
      'url' => $endpointUrl,
      'version' => stopbot_max_api_version(),
    ];
    $res = stopbot_max_http_json('POST', $url, [
      'Authorization: ' . $apiKey,
    ], $payload, 25);
    if (($res['ok'] ?? false) !== true) {
      return [
        'ok' => false,
        'error' => stopbot_max_extract_error($res),
        'removed' => $removed,
        'remove_errors' => $removeErrors,
        'recreated' => $recreated,
        'recreate_reason' => $recreateReason,
      ];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    $json = (array)($res['json'] ?? []);
    if ($httpCode < 200 || $httpCode >= 300) {
      return [
        'ok' => false,
        'error' => stopbot_max_extract_error($res),
        'http_code' => $httpCode,
        'removed' => $removed,
        'remove_errors' => $removeErrors,
        'recreated' => $recreated,
        'recreate_reason' => $recreateReason,
      ];
    }

    if (array_key_exists('success', $json) && (bool)$json['success'] === false) {
      $error = trim((string)($json['message'] ?? $json['error'] ?? 'MAX_SUBSCRIBE_FAILED'));
      if ($error === '') $error = 'MAX_SUBSCRIBE_FAILED';
      return [
        'ok' => false,
        'error' => $error,
        'http_code' => $httpCode,
        'removed' => $removed,
        'remove_errors' => $removeErrors,
        'recreated' => $recreated,
        'recreate_reason' => $recreateReason,
      ];
    }

    return [
      'ok' => true,
      'created' => 1,
      'removed' => $removed,
      'remove_errors' => $removeErrors,
      'recreated' => $recreated,
      'recreate_reason' => $recreateReason,
      'url' => $endpointUrl,
      'raw' => $json,
    ];
  }
  /**
   * stopbot_tg_webhook_process()
   * Обрабатывает входящий Telegram update.
   *
   * @param PDO $pdo
   * @param int $botId
   * @return array<string,mixed>
   */
  function stopbot_tg_webhook_process(PDO $pdo, int $botId): array
  {
    stopbot_require_schema($pdo);

    $bot = stopbot_bot_get($pdo, $botId);
    if (!$bot) {
      return ['ok' => false, 'http' => 404, 'reason' => 'bot_not_found', 'message' => 'Bot not found'];
    }

    if ((string)($bot['platform'] ?? '') !== STOPBOT_PLATFORM_TG) {
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

    $meta = stopbot_tg_extract_meta($update);
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
      'platform' => STOPBOT_PLATFORM_TG,
      'chat_id' => $chatId,
      'message_id' => $messageId,
      'message_text' => $text,
    ];
    $log = static function (array $extra) use ($pdo, $logBase): void {
      stopbot_log_add($pdo, array_merge($logBase, $extra));
    };
    $auditBase = [
      'bot_id' => $botId,
      'platform' => STOPBOT_PLATFORM_TG,
      'update_type' => (string)($meta['type'] ?? ''),
      'chat_id' => $chatId,
      'chat_type' => (string)($meta['chat_type'] ?? ''),
      'chat_title' => (string)($meta['chat_title'] ?? ''),
      'message_id' => $messageId,
      'message_text' => stopbot_excerpt($text),
      'from_id' => (string)($meta['from_id'] ?? ''),
      'from_username' => (string)($meta['from_username'] ?? ''),
    ];
    $audit = static function (string $reason, array $extra = [], string $level = 'info') use ($pdo, $auditBase): void {
      stopbot_audit_log($pdo, 'webhook_trace', $level, array_merge($auditBase, [
        'reason' => $reason,
      ], $extra));
    };

    $memberUpdate = stopbot_tg_extract_member_update($update);
    if ($memberUpdate) {
      if (!empty($memberUpdate['is_active'])) {
        stopbot_channel_upsert($pdo, $botId, STOPBOT_PLATFORM_TG, [
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

      stopbot_channel_set_active(
        $pdo,
        $botId,
        STOPBOT_PLATFORM_TG,
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

    if (stopbot_log_has_message($pdo, $botId, STOPBOT_PLATFORM_TG, $chatId, $messageId)) {
      $audit('duplicate_update', [
        'handled' => 0,
        'send_status' => 'duplicate_update',
      ]);
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'duplicate_update'];
    }

    if (stopbot_tg_is_bot_sender($update)) {
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

    $bindCode = stopbot_tg_extract_bind_code($text);
    if ($bindCode !== '') {
      $bind = stopbot_bind_code_consume($pdo, STOPBOT_PLATFORM_TG, $bindCode, $chatId);

      if (($bind['ok'] ?? false) === true) {
        $upsert = stopbot_channel_upsert($pdo, (int)$bind['bot_id'], STOPBOT_PLATFORM_TG, [
          'chat_id' => $chatId,
          'chat_title' => (string)($meta['chat_title'] ?? ''),
          'chat_type' => (string)($meta['chat_type'] ?? ''),
        ]);

        if (($upsert['ok'] ?? false) === true) {
          $responseText = stopbot_t('stopbot.bind_ok');
          $send = stopbot_tg_send_message($token, $chatId, $responseText);
          $log([
            'matched_promo_id' => 0,
            'response_text' => $responseText,
            'send_status' => (($send['ok'] ?? false) === true) ? 'bind_ok' : 'bind_ok_error',
            'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
          ]);
          $audit('bind_ok', [
            'handled' => 1,
            'send_status' => (($send['ok'] ?? false) === true) ? 'bind_ok' : 'bind_ok_error',
            'response_text' => stopbot_excerpt($responseText),
            'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
          ], (($send['ok'] ?? false) === true) ? 'info' : 'warn');
          return ['ok' => true, 'http' => 200, 'handled' => true, 'reason' => 'bind_ok'];
        }
      }

      $responseText = stopbot_t('stopbot.bind_fail');
      $send = stopbot_tg_send_message($token, $chatId, $responseText);
      $log([
        'matched_promo_id' => 0,
        'response_text' => $responseText,
        'send_status' => (($send['ok'] ?? false) === true) ? 'bind_fail' : 'bind_fail_error',
        'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
      ]);
      $audit('bind_fail', [
        'handled' => 1,
        'send_status' => (($send['ok'] ?? false) === true) ? 'bind_fail' : 'bind_fail_error',
        'response_text' => stopbot_excerpt($responseText),
        'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
      ], 'warn');
      return ['ok' => true, 'http' => 200, 'handled' => true, 'reason' => 'bind_fail'];
    }

    if (stopbot_tg_chat_is_bindable((string)($meta['chat_type'] ?? ''))) {
      stopbot_channel_upsert($pdo, $botId, STOPBOT_PLATFORM_TG, [
        'chat_id' => $chatId,
        'chat_title' => (string)($meta['chat_title'] ?? ''),
        'chat_type' => (string)($meta['chat_type'] ?? ''),
      ]);
    }

    $channel = stopbot_channel_get($pdo, $botId, STOPBOT_PLATFORM_TG, $chatId);
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

    if (stopbot_tg_is_admin_sender($token, $meta, $update)) {
      $log([
        'matched_promo_id' => 0,
        'response_text' => '',
        'send_status' => 'admin_skipped',
        'error_text' => 'admin_skipped',
      ]);
      $audit('admin_skipped', [
        'handled' => 0,
        'send_status' => 'admin_skipped',
      ]);
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'admin_skipped'];
    }

    $extraUrls = stopbot_tg_entity_urls($update);
    $match = stopbot_promo_find_match($pdo, $botId, $text, $extraUrls);
    if (!$match) {
      $external = stopbot_detect_external_violation($pdo, $text, $extraUrls);
      if (($external['rule_code'] ?? '') === '') {
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

      $match = [
        'id' => 0,
        'keywords' => (string)$external['rule_code'] . ':' . (string)($external['match'] ?? ''),
      ];
    }

    $delete = stopbot_tg_delete_message($token, $chatId, $messageId);
    $deleteOk = (($delete['ok'] ?? false) === true);

    $log([
      'matched_promo_id' => (int)($match['id'] ?? 0),
      'response_text' => '',
      'send_status' => $deleteOk ? 'deleted' : 'delete_failed',
      'error_text' => $deleteOk ? '' : trim((string)($delete['description'] ?? $delete['error'] ?? 'TG_DELETE_FAILED')),
    ]);
    $audit($deleteOk ? 'deleted' : 'delete_failed', [
      'handled' => 1,
      'matched_promo_id' => (int)($match['id'] ?? 0),
      'matched_keywords' => (string)($match['keywords'] ?? ''),
      'send_status' => $deleteOk ? 'deleted' : 'delete_failed',
      'error_text' => $deleteOk ? '' : trim((string)($delete['description'] ?? $delete['error'] ?? 'TG_DELETE_FAILED')),
    ], $deleteOk ? 'info' : 'warn');

    return [
      'ok' => $deleteOk,
      'http' => $deleteOk ? 200 : 500,
      'handled' => true,
      'reason' => $deleteOk ? 'deleted' : 'delete_failed',
    ];
  }

  /**
   * stopbot_max_webhook_process()
   * Обрабатывает входящий MAX webhook.
   *
   * @param PDO $pdo
   * @param int $botId
   * @param array<string,mixed> $payload
   * @return array<string,mixed>
   */
  function stopbot_max_webhook_process(PDO $pdo, int $botId, array $payload, string $traceId = ''): array
  {
    stopbot_require_schema($pdo);

    $bot = stopbot_bot_get($pdo, $botId);
    if (!$bot) {
      return ['ok' => false, 'http' => 404, 'reason' => 'bot_not_found', 'message' => 'Bot not found'];
    }

    if ((string)($bot['platform'] ?? '') !== STOPBOT_PLATFORM_MAX) {
      return ['ok' => false, 'http' => 404, 'reason' => 'bot_platform_mismatch', 'message' => 'Bot platform mismatch'];
    }

    if ((int)($bot['enabled'] ?? 0) !== 1) {
      return ['ok' => false, 'http' => 503, 'reason' => 'bot_disabled', 'message' => 'Bot disabled'];
    }

    $meta = stopbot_max_extract_message($payload);
    $chatId = trim((string)($meta['chat_id'] ?? ''));
    $text = (string)($meta['text'] ?? '');
    $messageId = trim((string)($meta['message_id'] ?? ''));

    if ($chatId === '') {
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'chat_id_empty'];
    }

    $logBase = [
      'bot_id' => $botId,
      'platform' => STOPBOT_PLATFORM_MAX,
      'chat_id' => $chatId,
      'message_id' => $messageId,
      'message_text' => $text,
    ];
    $log = static function (array $extra) use ($pdo, $logBase): void {
      stopbot_log_add($pdo, array_merge($logBase, $extra));
    };
    $auditBase = [
      'bot_id' => $botId,
      'platform' => STOPBOT_PLATFORM_MAX,
      'handler_module' => STOPBOT_MODULE_CODE,
      'handler_script' => '/adm/modules/stopbot/max_webhook.php',
      'trace_id' => $traceId,
      'chat_id' => $chatId,
      'chat_type' => '',
      'chat_title' => '',
      'message_id' => $messageId,
      'message_text' => stopbot_excerpt($text),
      'from_id' => (string)($meta['from_id'] ?? ''),
      'from_username' => (string)($meta['from_username'] ?? ''),
    ];
    $audit = static function (string $reason, array $extra = [], string $level = 'info') use ($pdo, $auditBase): void {
      stopbot_audit_log($pdo, 'max_webhook_trace', $level, array_merge($auditBase, [
        'phase' => 'process',
        'reason' => $reason,
      ], $extra));
    };

    if (stopbot_log_has_message($pdo, $botId, STOPBOT_PLATFORM_MAX, $chatId, $messageId)) {
      $audit('duplicate_update', [
        'handled' => 0,
        'send_status' => 'duplicate_update',
      ]);
      return ['ok' => true, 'http' => 200, 'handled' => false, 'reason' => 'duplicate_update'];
    }

    if (stopbot_max_is_bot_sender($meta)) {
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

    $bindCode = stopbot_max_extract_bind_code($text);
    if ($bindCode !== '') {
      $bind = stopbot_bind_code_consume($pdo, STOPBOT_PLATFORM_MAX, $bindCode, $chatId);

      if (($bind['ok'] ?? false) === true) {
        $upsert = stopbot_channel_upsert($pdo, (int)$bind['bot_id'], STOPBOT_PLATFORM_MAX, [
          'chat_id' => $chatId,
          'chat_title' => '',
          'chat_type' => '',
        ]);

        if (($upsert['ok'] ?? false) === true) {
          $responseText = stopbot_t('stopbot.bind_ok');
          $send = stopbot_max_send_message($bot, $chatId, $responseText);
          $log([
            'matched_promo_id' => 0,
            'response_text' => $responseText,
            'send_status' => (($send['ok'] ?? false) === true) ? 'bind_ok' : 'bind_ok_error',
            'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
          ]);
          $audit('bind_ok', [
            'handled' => 1,
            'send_status' => (($send['ok'] ?? false) === true) ? 'bind_ok' : 'bind_ok_error',
            'response_text' => stopbot_excerpt($responseText),
            'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
          ], (($send['ok'] ?? false) === true) ? 'info' : 'warn');
          return ['ok' => true, 'http' => 200, 'handled' => true, 'reason' => 'bind_ok'];
        }
      }

      $responseText = stopbot_t('stopbot.bind_fail');
      $send = stopbot_max_send_message($bot, $chatId, $responseText);
      $log([
        'matched_promo_id' => 0,
        'response_text' => $responseText,
        'send_status' => (($send['ok'] ?? false) === true) ? 'bind_fail' : 'bind_fail_error',
        'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
      ]);
      $audit('bind_fail', [
        'handled' => 1,
        'send_status' => (($send['ok'] ?? false) === true) ? 'bind_fail' : 'bind_fail_error',
        'response_text' => stopbot_excerpt($responseText),
        'error_text' => (($send['ok'] ?? false) === true) ? '' : (string)($send['error'] ?? ''),
      ], 'warn');
      return ['ok' => true, 'http' => 200, 'handled' => true, 'reason' => 'bind_fail'];
    }

    $channel = stopbot_channel_get($pdo, $botId, STOPBOT_PLATFORM_MAX, $chatId);
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

    $match = stopbot_promo_find_match($pdo, $botId, $text);
    if (!$match) {
      $external = stopbot_detect_external_violation($pdo, $text, []);
      if (($external['rule_code'] ?? '') === '') {
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

      $match = [
        'id' => 0,
        'keywords' => (string)$external['rule_code'] . ':' . (string)($external['match'] ?? ''),
      ];
    }

    $providerRawPreview = static function (array $apiResult): string {
      $json = (array)($apiResult['raw']['json'] ?? []);
      if (!$json) return '';
      $enc = json_encode($json, JSON_UNESCAPED_UNICODE);
      if (!is_string($enc) || $enc === '') return '';
      return stopbot_excerpt($enc, 220);
    };

    $delete = stopbot_max_delete_message($bot, $messageId);
    $deleteOk = (($delete['ok'] ?? false) === true);
    $deleteHttp = (int)($delete['http_code'] ?? 0);
    $deleteErr = (string)($delete['error'] ?? '');
    $deleteRaw = $providerRawPreview($delete);

    if ($deleteOk) {
      $log([
        'matched_promo_id' => (int)($match['id'] ?? 0),
        'response_text' => '',
        'send_status' => 'deleted',
        'error_text' => '',
      ]);
      $audit('deleted', [
        'handled' => 1,
        'matched_promo_id' => (int)($match['id'] ?? 0),
        'matched_keywords' => (string)($match['keywords'] ?? ''),
        'response_text' => '',
        'send_status' => 'deleted',
        'error_text' => '',
        'provider_http' => $deleteHttp,
        'provider_error' => '',
        'provider_raw' => $deleteRaw,
      ]);
      return [
        'ok' => true,
        'http' => 200,
        'handled' => true,
        'reason' => 'deleted',
      ];
    }

    $edit = stopbot_max_update_message($bot, $chatId, $messageId, stopbot_max_moderation_text());
    $editOk = (($edit['ok'] ?? false) === true);
    $editHttp = (int)($edit['http_code'] ?? 0);
    $editErr = (string)($edit['error'] ?? '');
    $editRaw = $providerRawPreview($edit);

    $log([
      'matched_promo_id' => (int)($match['id'] ?? 0),
      'response_text' => stopbot_max_moderation_text(),
      'send_status' => $editOk ? 'edited' : 'edit_failed',
      'error_text' => $editOk ? '' : ($editErr !== '' ? $editErr : ($deleteErr !== '' ? 'DELETE:' . $deleteErr : 'MAX_MODERATION_FAILED')),
    ]);
    $audit($editOk ? 'edited' : 'edit_failed', [
      'handled' => 1,
      'matched_promo_id' => (int)($match['id'] ?? 0),
      'matched_keywords' => (string)($match['keywords'] ?? ''),
      'response_text' => stopbot_max_moderation_text(),
      'send_status' => $editOk ? 'edited' : 'edit_failed',
      'error_text' => $editOk ? '' : ($editErr !== '' ? $editErr : ($deleteErr !== '' ? 'DELETE:' . $deleteErr : 'MAX_MODERATION_FAILED')),
      'provider_action' => 'delete_then_edit',
      'provider_delete_http' => $deleteHttp,
      'provider_delete_error' => $deleteErr,
      'provider_delete_raw' => $deleteRaw,
      'provider_edit_http' => $editHttp,
      'provider_edit_error' => $editErr,
      'provider_edit_raw' => $editRaw,
    ], $editOk ? 'info' : 'warn');

    return [
      'ok' => $editOk,
      'http' => $editOk ? 200 : 500,
      'handled' => true,
      'reason' => $editOk ? 'edited' : 'moderation_failed',
    ];
  }
}
