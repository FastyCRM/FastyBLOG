<?php
/**
 * FILE: /adm/modules/ym_link_bot/assets/php/ym_link_bot_lib.php
 * ROLE: Business logic for copyable ym_link_bot module.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

$moduleDir = basename(dirname(__DIR__, 2));
$moduleRoot = ROOT_PATH . '/adm/modules/' . $moduleDir;
require_once $moduleRoot . '/settings.php';
require_once ROOT_PATH . '/core/telegram.php';
require_once ROOT_PATH . '/core/market_url.php';

if (!function_exists('ymlb_module_code')) {
  /**
   * ymlb_module_code()
   * Resolves current module code from directory name.
   */
  function ymlb_module_code(): string
  {
    $code = basename(dirname(__DIR__, 2));
    $code = strtolower((string)$code);
    $code = preg_replace('~[^a-z0-9_]+~', '_', $code) ?? 'ym_link_bot';
    $code = trim($code, '_');
    if ($code === '') $code = 'ym_link_bot';
    return substr($code, 0, 24);
  }

  /**
   * ymlb_table()
   * Returns module-local table name.
   */
  function ymlb_table(string $suffix): string
  {
    $suffix = strtolower(trim($suffix));
    $suffix = preg_replace('~[^a-z0-9_]+~', '_', $suffix) ?? '';
    $suffix = trim($suffix, '_');
    if ($suffix === '') {
      throw new InvalidArgumentException('Table suffix is empty');
    }
    $suffix = substr($suffix, 0, 28);

    return 'ymlb_' . ymlb_module_code() . '_' . $suffix;
  }

  /**
   * ymlb_qi()
   * Quotes SQL identifier.
   */
  function ymlb_qi(string $name): string
  {
    return '`' . str_replace('`', '``', $name) . '`';
  }

  /**
   * ymlb_table_index_exists()
   */
  function ymlb_table_index_exists(PDO $pdo, string $tableName, string $indexName): bool
  {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND table_name = :table_name
        AND index_name = :index_name
    ");
    $st->execute([
      ':table_name' => $tableName,
      ':index_name' => $indexName,
    ]);
    return ((int)($st->fetchColumn() ?: 0) > 0);
  }

  /**
   * ymlb_table_column_exists()
   */
  function ymlb_table_column_exists(PDO $pdo, string $tableName, string $columnName): bool
  {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.columns
      WHERE table_schema = DATABASE()
        AND table_name = :table_name
        AND column_name = :column_name
    ");
    $st->execute([
      ':table_name' => $tableName,
      ':column_name' => $columnName,
    ]);
    return ((int)($st->fetchColumn() ?: 0) > 0);
  }

  /**
   * ymlb_ensure_channels_indexes()
   * Keeps channels indexes compatible with multi-binding mode.
   */
  function ymlb_ensure_channels_indexes(PDO $pdo): void
  {
    $channelsName = ymlb_table('channels');
    $channels = ymlb_qi($channelsName);

    if (ymlb_table_index_exists($pdo, $channelsName, 'uq_channel_chat_id')) {
      $pdo->exec("ALTER TABLE {$channels} DROP INDEX uq_channel_chat_id");
    }
    if (ymlb_table_index_exists($pdo, $channelsName, 'uq_kind_chat_id')) {
      $pdo->exec("ALTER TABLE {$channels} DROP INDEX uq_kind_chat_id");
    }

    $hasChatKind = ymlb_table_column_exists($pdo, $channelsName, 'chat_kind');
    if ($hasChatKind) {
      if (!ymlb_table_index_exists($pdo, $channelsName, 'uq_kind_binding_chat')) {
        $pdo->exec("
          DELETE c1
          FROM {$channels} c1
          JOIN {$channels} c2
            ON c1.id < c2.id
           AND c1.chat_kind = c2.chat_kind
           AND c1.binding_id = c2.binding_id
           AND (c1.channel_chat_id <=> c2.channel_chat_id)
        ");
        $pdo->exec("
          ALTER TABLE {$channels}
          ADD UNIQUE KEY uq_kind_binding_chat (chat_kind, binding_id, channel_chat_id)
        ");
      }
      return;
    }

    if (!ymlb_table_index_exists($pdo, $channelsName, 'uq_binding_chat_id')) {
      $pdo->exec("
        DELETE c1
        FROM {$channels} c1
        JOIN {$channels} c2
          ON c1.id < c2.id
         AND c1.binding_id = c2.binding_id
         AND (c1.channel_chat_id <=> c2.channel_chat_id)
      ");
      $pdo->exec("
        ALTER TABLE {$channels}
        ADD UNIQUE KEY uq_binding_chat_id (binding_id, channel_chat_id)
      ");
    }
  }

  /**
   * ymlb_now()
   * Current server datetime.
   */
  function ymlb_now(): string
  {
    return date('Y-m-d H:i:s');
  }

  /**
   * ymlb_stage_log()
   * Unified audit logger for critical pipeline stages.
   *
   * @param array<string,mixed> $payload
   */
  function ymlb_stage_log(string $action, string $level = 'info', array $payload = []): void
  {
    if (!function_exists('audit_log')) return;
    try {
      $uid = function_exists('auth_user_id') ? auth_user_id() : null;
      $role = function_exists('auth_user_role') ? auth_user_role() : null;
      audit_log(ymlb_module_code(), $action, $level, $payload, null, null, $uid, $role);
    } catch (Throwable $e) {
      // Logging must never break pipeline.
    }
  }

  /**
   * ymlb_log_excerpt()
   * Safe short preview for large payloads in logs.
   */
  function ymlb_log_excerpt(string $text, int $max = 2000): string
  {
    if ($max < 32) $max = 32;
    $text = trim($text);
    if ($text === '') return '';

    if (function_exists('mb_substr') && function_exists('mb_strlen')) {
      if (mb_strlen($text) <= $max) return $text;
      return (string)mb_substr($text, 0, $max) . '...[truncated]';
    }

    if (strlen($text) <= $max) return $text;
    return substr($text, 0, $max) . '...[truncated]';
  }

  /**
   * ymlb_manage_roles()
   * Roles allowed to mutate module data.
   *
   * @return array<int,string>
   */
  function ymlb_manage_roles(): array
  {
    $out = [];
    foreach (YM_LINK_BOT_MANAGE_ROLES as $role) {
      $role = trim((string)$role);
      if ($role !== '') $out[] = $role;
    }
    return array_values(array_unique($out));
  }

  /**
   * ymlb_is_manage_role()
   * Checks if any role is in manage list.
   *
   * @param array<int,string> $roles
   */
  function ymlb_is_manage_role(array $roles): bool
  {
    $manage = array_fill_keys(ymlb_manage_roles(), true);
    foreach ($roles as $role) {
      if (isset($manage[(string)$role])) return true;
    }
    return false;
  }

  /**
   * ymlb_module_allowed_roles()
   *
   * @return array<int,string>
   */
  function ymlb_module_allowed_roles(): array
  {
    return ['admin', 'manager', 'user'];
  }

  /**
   * ymlb_sync_module_roles()
   * Keeps modules.roles in sync so users can see the module in sidebar.
   */
  function ymlb_sync_module_roles(PDO $pdo): void
  {
    static $done = [];
    $code = ymlb_module_code();
    if (isset($done[$code])) return;

    try {
      $st = $pdo->prepare("SELECT roles FROM modules WHERE code = :code LIMIT 1");
      $st->execute([':code' => $code]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!is_array($row)) {
        $done[$code] = true;
        return;
      }

      $raw = trim((string)($row['roles'] ?? ''));
      $current = [];
      if (function_exists('modules_parse_roles')) {
        $current = modules_parse_roles($raw);
      } else {
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
          foreach ($parsed as $item) {
            $role = trim((string)$item);
            if ($role !== '') $current[] = $role;
          }
        }
      }

      $required = ymlb_module_allowed_roles();
      $set = [];
      foreach ($current as $role) {
        $role = trim((string)$role);
        if ($role !== '') $set[$role] = true;
      }

      $changed = false;
      foreach ($required as $role) {
        if (!isset($set[$role])) {
          $set[$role] = true;
          $changed = true;
        }
      }

      if ($changed) {
        $rolesJson = json_encode(array_keys($set), JSON_UNESCAPED_UNICODE);
        if ($rolesJson !== false) {
          $up = $pdo->prepare("UPDATE modules SET roles = :roles WHERE code = :code LIMIT 1");
          $up->execute([
            ':roles' => $rolesJson,
            ':code' => $code,
          ]);
        }
      }
    } catch (Throwable $e) {
      ymlb_stage_log('module_roles_sync', 'warn', [
        'stage' => 'update',
        'error' => $e->getMessage(),
      ]);
    }

    $done[$code] = true;
  }

  /**
   * ymlb_listener_path_default()
   * Default relative listener path for current module.
   */
  function ymlb_listener_path_default(): string
  {
    return '/adm/modules/' . ymlb_module_code() . '/webhook.php';
  }

  /**
   * ymlb_chat_listener_path_default()
   * Default relative listener path for optional dedicated chat bot.
   */
  function ymlb_chat_listener_path_default(): string
  {
    return '/adm/modules/' . ymlb_module_code() . '/chat_webhook.php';
  }

  /**
   * ymlb_base_url()
   * Best-effort site base URL.
   */
  function ymlb_base_url(): string
  {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return '';

    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    $scheme = ($https !== '' && $https !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
      ? 'https'
      : 'http';

    return $scheme . '://' . $host;
  }

  /**
   * ymlb_listener_url()
   * Computes full webhook URL.
   *
   * @param array<string,mixed> $settings
   */
  function ymlb_listener_url(array $settings): string
  {
    $path = trim((string)($settings['listener_path'] ?? ''));
    if ($path === '') {
      $path = ymlb_listener_path_default();
    }

    if (preg_match('~^https?://~i', $path)) {
      return $path;
    }

    if (function_exists('url')) {
      $path = url($path);
    }

    $base = ymlb_base_url();
    if ($base === '') return $path;

    return rtrim($base, '/') . '/' . ltrim($path, '/');
  }

  /**
   * ymlb_chat_listener_url()
   * Computes full webhook URL for dedicated chat bot listener.
   *
   * @param array<string,mixed> $settings
   */
  function ymlb_chat_listener_url(array $settings): string
  {
    $path = trim((string)($settings['chat_listener_path'] ?? ''));
    if ($path === '') {
      $path = ymlb_chat_listener_path_default();
    }

    if (preg_match('~^https?://~i', $path)) {
      return $path;
    }

    if (function_exists('url')) {
      $path = url($path);
    }

    $base = ymlb_base_url();
    if ($base === '') return $path;

    return rtrim($base, '/') . '/' . ltrim($path, '/');
  }

  /**
   * ymlb_settings_defaults()
   *
   * @return array<string,mixed>
   */
  function ymlb_settings_defaults(): array
  {
    return [
      'enabled' => 0,
      'chat_mode_enabled' => 1,
      'chat_bot_separate' => 0,
      'bot_token' => '',
      'bot_username' => '',
      'webhook_secret' => '',
      'chat_bot_token' => '',
      'chat_bot_username' => '',
      'chat_webhook_secret' => '',
      'affiliate_api_key' => '',
      'geo_id' => 213,
      'link_static_params' => 'pp=900&mclid=1003&distr_type=7',
      'listener_path' => ymlb_listener_path_default(),
      'chat_listener_path' => ymlb_chat_listener_path_default(),
    ];
  }

  /**
   * ymlb_ensure_schema()
   * Creates module-local schema if needed.
   */
  function ymlb_ensure_schema(PDO $pdo): void
  {
    static $checked = [];

    $signature = ymlb_table('settings');
    if (isset($checked[$signature])) return;

    $settings = ymlb_qi(ymlb_table('settings'));
    $bindings = ymlb_qi(ymlb_table('bindings'));
    $channels = ymlb_qi(ymlb_table('channels'));
    $sites = ymlb_qi(ymlb_table('sites'));
    $updates = ymlb_qi(ymlb_table('updates'));
    $photos = ymlb_qi(ymlb_table('photos'));

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS {$settings} (
        id TINYINT UNSIGNED NOT NULL,
        enabled TINYINT UNSIGNED NOT NULL DEFAULT 0,
        chat_mode_enabled TINYINT UNSIGNED NOT NULL DEFAULT 1,
        chat_bot_separate TINYINT UNSIGNED NOT NULL DEFAULT 0,
        bot_token VARCHAR(255) NOT NULL DEFAULT '',
        bot_username VARCHAR(64) NOT NULL DEFAULT '',
        webhook_secret VARCHAR(128) NOT NULL DEFAULT '',
        chat_bot_token VARCHAR(255) NOT NULL DEFAULT '',
        chat_bot_username VARCHAR(64) NOT NULL DEFAULT '',
        chat_webhook_secret VARCHAR(128) NOT NULL DEFAULT '',
        affiliate_api_key VARCHAR(255) NOT NULL DEFAULT '',
        geo_id INT UNSIGNED NOT NULL DEFAULT 213,
        link_static_params VARCHAR(255) NOT NULL DEFAULT 'pp=900&mclid=1003&distr_type=7',
        listener_path VARCHAR(255) NOT NULL DEFAULT '',
        chat_listener_path VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!ymlb_table_column_exists($pdo, ymlb_table('settings'), 'chat_mode_enabled')) {
      $pdo->exec("
        ALTER TABLE {$settings}
        ADD COLUMN chat_mode_enabled TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER enabled
      ");
    }
    if (!ymlb_table_column_exists($pdo, ymlb_table('settings'), 'chat_bot_separate')) {
      $pdo->exec("
        ALTER TABLE {$settings}
        ADD COLUMN chat_bot_separate TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER chat_mode_enabled
      ");
    }
    if (!ymlb_table_column_exists($pdo, ymlb_table('settings'), 'chat_bot_token')) {
      $pdo->exec("
        ALTER TABLE {$settings}
        ADD COLUMN chat_bot_token VARCHAR(255) NOT NULL DEFAULT '' AFTER webhook_secret
      ");
    }
    if (!ymlb_table_column_exists($pdo, ymlb_table('settings'), 'chat_bot_username')) {
      $pdo->exec("
        ALTER TABLE {$settings}
        ADD COLUMN chat_bot_username VARCHAR(64) NOT NULL DEFAULT '' AFTER chat_bot_token
      ");
    }
    if (!ymlb_table_column_exists($pdo, ymlb_table('settings'), 'chat_webhook_secret')) {
      $pdo->exec("
        ALTER TABLE {$settings}
        ADD COLUMN chat_webhook_secret VARCHAR(128) NOT NULL DEFAULT '' AFTER chat_bot_username
      ");
    }
    if (!ymlb_table_column_exists($pdo, ymlb_table('settings'), 'chat_listener_path')) {
      $pdo->exec("
        ALTER TABLE {$settings}
        ADD COLUMN chat_listener_path VARCHAR(255) NOT NULL DEFAULT '' AFTER listener_path
      ");
    }

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS {$bindings} (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(120) NOT NULL,
        crm_user_id INT UNSIGNED NULL DEFAULT NULL,
        telegram_user_id BIGINT NULL DEFAULT NULL,
        telegram_username VARCHAR(120) NOT NULL DEFAULT '',
        oauth_access_token TEXT NOT NULL,
        is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL DEFAULT NULL,
        updated_by INT UNSIGNED NULL DEFAULT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_tg_user (telegram_user_id),
        KEY idx_crm_user (crm_user_id),
        KEY idx_active (is_active)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS {$channels} (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        binding_id INT UNSIGNED NOT NULL,
        channel_chat_id VARCHAR(64) NULL DEFAULT NULL,
        channel_username VARCHAR(128) NOT NULL DEFAULT '',
        channel_title VARCHAR(255) NOT NULL DEFAULT '',
        confirm_code VARCHAR(32) NOT NULL DEFAULT '',
        confirm_expires_at DATETIME NULL DEFAULT NULL,
        confirmed_at DATETIME NULL DEFAULT NULL,
        is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_binding_chat_id (binding_id, channel_chat_id),
        KEY idx_binding (binding_id),
        KEY idx_confirm (confirm_code, confirm_expires_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS {$sites} (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        binding_id INT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        clid VARCHAR(32) NOT NULL,
        is_active TINYINT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_binding_clid (binding_id, clid),
        KEY idx_binding_active (binding_id, is_active)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS {$updates} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        webhook_scope VARCHAR(16) NOT NULL DEFAULT 'main',
        update_id BIGINT UNSIGNED NOT NULL,
        chat_id VARCHAR(64) NOT NULL DEFAULT '',
        event_type VARCHAR(50) NOT NULL DEFAULT '',
        status VARCHAR(32) NOT NULL DEFAULT 'received',
        error_text TEXT NULL,
        payload_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_scope_update_id (webhook_scope, update_id),
        KEY idx_chat (chat_id),
        KEY idx_event (event_type)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!ymlb_table_column_exists($pdo, ymlb_table('updates'), 'webhook_scope')) {
      $pdo->exec("
        ALTER TABLE {$updates}
        ADD COLUMN webhook_scope VARCHAR(16) NOT NULL DEFAULT 'main' AFTER id
      ");
    }
    if (ymlb_table_index_exists($pdo, ymlb_table('updates'), 'uq_update_id')) {
      $pdo->exec("ALTER TABLE {$updates} DROP INDEX uq_update_id");
    }
    if (!ymlb_table_index_exists($pdo, ymlb_table('updates'), 'uq_scope_update_id')) {
      $pdo->exec("
        ALTER TABLE {$updates}
        ADD UNIQUE KEY uq_scope_update_id (webhook_scope, update_id)
      ");
    }

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS {$photos} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        binding_id INT UNSIGNED NULL DEFAULT NULL,
        source_url TEXT NULL,
        local_path VARCHAR(255) NOT NULL DEFAULT '',
        public_url VARCHAR(255) NOT NULL DEFAULT '',
        photo_date DATE NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_photo_date (photo_date),
        KEY idx_binding (binding_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    ymlb_ensure_channels_indexes($pdo);

    $defaults = ymlb_settings_defaults();
    $stInit = $pdo->prepare("
      INSERT INTO {$settings}
      (id, enabled, chat_mode_enabled, chat_bot_separate, bot_token, bot_username, webhook_secret, chat_bot_token, chat_bot_username, chat_webhook_secret, affiliate_api_key, geo_id, link_static_params, listener_path, chat_listener_path, created_at, updated_at)
      VALUES
      (1, :enabled, :chat_mode_enabled, :chat_bot_separate, :bot_token, :bot_username, :webhook_secret, :chat_bot_token, :chat_bot_username, :chat_webhook_secret, :affiliate_api_key, :geo_id, :link_static_params, :listener_path, :chat_listener_path, :created_at, :updated_at)
      ON DUPLICATE KEY UPDATE
        id = VALUES(id)
    ");
    $now = ymlb_now();
    $stInit->execute([
      ':enabled' => (int)$defaults['enabled'],
      ':chat_mode_enabled' => (int)$defaults['chat_mode_enabled'],
      ':chat_bot_separate' => (int)$defaults['chat_bot_separate'],
      ':bot_token' => (string)$defaults['bot_token'],
      ':bot_username' => (string)$defaults['bot_username'],
      ':webhook_secret' => (string)$defaults['webhook_secret'],
      ':chat_bot_token' => (string)$defaults['chat_bot_token'],
      ':chat_bot_username' => (string)$defaults['chat_bot_username'],
      ':chat_webhook_secret' => (string)$defaults['chat_webhook_secret'],
      ':affiliate_api_key' => (string)$defaults['affiliate_api_key'],
      ':geo_id' => (int)$defaults['geo_id'],
      ':link_static_params' => (string)$defaults['link_static_params'],
      ':listener_path' => (string)$defaults['listener_path'],
      ':chat_listener_path' => (string)$defaults['chat_listener_path'],
      ':created_at' => $now,
      ':updated_at' => $now,
    ]);

    $checked[$signature] = true;
  }

  /**
   * ymlb_settings_get()
   *
   * @return array<string,mixed>
   */
  function ymlb_settings_get(PDO $pdo): array
  {
    ymlb_ensure_schema($pdo);
    $defaults = ymlb_settings_defaults();

    $table = ymlb_qi(ymlb_table('settings'));
    $row = $pdo->query("
      SELECT
        enabled,
        chat_mode_enabled,
        chat_bot_separate,
        bot_token,
        bot_username,
        webhook_secret,
        chat_bot_token,
        chat_bot_username,
        chat_webhook_secret,
        affiliate_api_key,
        geo_id,
        link_static_params,
        listener_path,
        chat_listener_path
      FROM {$table}
      WHERE id = 1
      LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
      return $defaults;
    }

    $settings = [
      'enabled' => ((int)($row['enabled'] ?? 0) === 1) ? 1 : 0,
      'chat_mode_enabled' => ((int)($row['chat_mode_enabled'] ?? 1) === 1) ? 1 : 0,
      'chat_bot_separate' => ((int)($row['chat_bot_separate'] ?? 0) === 1) ? 1 : 0,
      'bot_token' => trim((string)($row['bot_token'] ?? '')),
      'bot_username' => ltrim(trim((string)($row['bot_username'] ?? '')), '@'),
      'webhook_secret' => trim((string)($row['webhook_secret'] ?? '')),
      'chat_bot_token' => trim((string)($row['chat_bot_token'] ?? '')),
      'chat_bot_username' => ltrim(trim((string)($row['chat_bot_username'] ?? '')), '@'),
      'chat_webhook_secret' => trim((string)($row['chat_webhook_secret'] ?? '')),
      'affiliate_api_key' => trim((string)($row['affiliate_api_key'] ?? '')),
      'geo_id' => (int)($row['geo_id'] ?? 213),
      'link_static_params' => trim((string)($row['link_static_params'] ?? '')),
      'listener_path' => trim((string)($row['listener_path'] ?? '')),
      'chat_listener_path' => trim((string)($row['chat_listener_path'] ?? '')),
    ];

    if ($settings['geo_id'] < 1) $settings['geo_id'] = 213;
    if ($settings['link_static_params'] === '') {
      $settings['link_static_params'] = (string)$defaults['link_static_params'];
    }
    if ($settings['listener_path'] === '') {
      $settings['listener_path'] = (string)$defaults['listener_path'];
    }
    if ($settings['chat_listener_path'] === '') {
      $settings['chat_listener_path'] = (string)$defaults['chat_listener_path'];
    }

    return $settings;
  }

  /**
   * ymlb_settings_save()
   *
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  function ymlb_settings_save(PDO $pdo, array $input): array
  {
    ymlb_ensure_schema($pdo);
    $defaults = ymlb_settings_defaults();

    $enabled = ((int)($input['enabled'] ?? 0) === 1) ? 1 : 0;
    $chatModeEnabled = ((int)($input['chat_mode_enabled'] ?? 0) === 1) ? 1 : 0;
    $chatBotSeparate = ((int)($input['chat_bot_separate'] ?? 0) === 1) ? 1 : 0;
    $botToken = trim((string)($input['bot_token'] ?? ''));
    $botUsername = ltrim(trim((string)($input['bot_username'] ?? '')), '@');
    $webhookSecret = trim((string)($input['webhook_secret'] ?? ''));
    $chatBotToken = trim((string)($input['chat_bot_token'] ?? ''));
    $chatBotUsername = ltrim(trim((string)($input['chat_bot_username'] ?? '')), '@');
    $chatWebhookSecret = trim((string)($input['chat_webhook_secret'] ?? ''));
    $affiliateApiKey = trim((string)($input['affiliate_api_key'] ?? ''));
    $geoId = (int)($input['geo_id'] ?? 213);
    $linkStaticParams = trim((string)($input['link_static_params'] ?? ''));
    $listenerPath = trim((string)($input['listener_path'] ?? ''));
    $chatListenerPath = trim((string)($input['chat_listener_path'] ?? ''));

    if ($geoId < 1) $geoId = 213;
    if ($geoId > 1000000) $geoId = 213;
    if ($linkStaticParams === '') {
      $linkStaticParams = (string)$defaults['link_static_params'];
    }
    if ($listenerPath === '') {
      $listenerPath = (string)$defaults['listener_path'];
    }
    if ($chatListenerPath === '') {
      $chatListenerPath = (string)$defaults['chat_listener_path'];
    }

    $table = ymlb_qi(ymlb_table('settings'));
    $now = ymlb_now();

    $st = $pdo->prepare("
      INSERT INTO {$table}
      (id, enabled, chat_mode_enabled, chat_bot_separate, bot_token, bot_username, webhook_secret, chat_bot_token, chat_bot_username, chat_webhook_secret, affiliate_api_key, geo_id, link_static_params, listener_path, chat_listener_path, created_at, updated_at)
      VALUES
      (1, :enabled, :chat_mode_enabled, :chat_bot_separate, :bot_token, :bot_username, :webhook_secret, :chat_bot_token, :chat_bot_username, :chat_webhook_secret, :affiliate_api_key, :geo_id, :link_static_params, :listener_path, :chat_listener_path, :created_at, :updated_at)
      ON DUPLICATE KEY UPDATE
        enabled = VALUES(enabled),
        chat_mode_enabled = VALUES(chat_mode_enabled),
        chat_bot_separate = VALUES(chat_bot_separate),
        bot_token = VALUES(bot_token),
        bot_username = VALUES(bot_username),
        webhook_secret = VALUES(webhook_secret),
        chat_bot_token = VALUES(chat_bot_token),
        chat_bot_username = VALUES(chat_bot_username),
        chat_webhook_secret = VALUES(chat_webhook_secret),
        affiliate_api_key = VALUES(affiliate_api_key),
        geo_id = VALUES(geo_id),
        link_static_params = VALUES(link_static_params),
        listener_path = VALUES(listener_path),
        chat_listener_path = VALUES(chat_listener_path),
        updated_at = VALUES(updated_at)
    ");
    $st->execute([
      ':enabled' => $enabled,
      ':chat_mode_enabled' => $chatModeEnabled,
      ':chat_bot_separate' => $chatBotSeparate,
      ':bot_token' => $botToken,
      ':bot_username' => $botUsername,
      ':webhook_secret' => $webhookSecret,
      ':chat_bot_token' => $chatBotToken,
      ':chat_bot_username' => $chatBotUsername,
      ':chat_webhook_secret' => $chatWebhookSecret,
      ':affiliate_api_key' => $affiliateApiKey,
      ':geo_id' => $geoId,
      ':link_static_params' => $linkStaticParams,
      ':listener_path' => $listenerPath,
      ':chat_listener_path' => $chatListenerPath,
      ':created_at' => $now,
      ':updated_at' => $now,
    ]);

    return ymlb_settings_get($pdo);
  }

  /**
   * ymlb_chat_bot_effective_settings()
   * Resolves chat webhook credentials:
   * - by default uses main bot credentials;
   * - if chat_bot_separate=1 and chat_bot_token is set, uses dedicated chat bot fields.
   *
   * @param array<string,mixed> $settings
   * @return array<string,mixed>
   */
  function ymlb_chat_bot_effective_settings(array $settings): array
  {
    $out = $settings;
    $separate = ((int)($settings['chat_bot_separate'] ?? 0) === 1);
    $chatToken = trim((string)($settings['chat_bot_token'] ?? ''));

    if ($separate && $chatToken !== '') {
      $out['bot_token'] = $chatToken;
      $out['bot_username'] = trim((string)($settings['chat_bot_username'] ?? ''));
      $out['webhook_secret'] = trim((string)($settings['chat_webhook_secret'] ?? ''));
      $out['listener_path'] = trim((string)($settings['chat_listener_path'] ?? ymlb_chat_listener_path_default()));
      $out['chat_bot_effective'] = 1;
      return $out;
    }

    $out['chat_bot_effective'] = 0;
    return $out;
  }

  /**
   * ymlb_oauth_token_row_by_crm_user()
   * Reads OAuth token row assigned to CRM user from oauth_token_users/oauth_tokens.
   *
   * @return array<string,mixed>|null
   */
  function ymlb_oauth_token_row_by_crm_user(PDO $pdo, int $crmUserId): ?array
  {
    if ($crmUserId <= 0) return null;
    static $cache = [];
    if (array_key_exists($crmUserId, $cache)) {
      return $cache[$crmUserId];
    }

    try {
      $st = $pdo->prepare("
        SELECT t.id, t.access_token, t.token_received_at
        FROM oauth_token_users otu
        JOIN oauth_tokens t ON t.id = otu.oauth_token_id
        WHERE otu.user_id = :uid
        LIMIT 1
      ");
      $st->execute([':uid' => $crmUserId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!is_array($row)) {
        $cache[$crmUserId] = null;
        return null;
      }

      $row['id'] = (int)($row['id'] ?? 0);
      $row['access_token'] = trim((string)($row['access_token'] ?? ''));
      $row['token_received_at'] = trim((string)($row['token_received_at'] ?? ''));
      $cache[$crmUserId] = $row;
      return $row;
    } catch (Throwable $e) {
      ymlb_stage_log('oauth_lookup', 'warn', [
        'stage' => 'query',
        'crm_user_id' => $crmUserId,
        'reason' => 'oauth_tables_unavailable',
      ]);
      $cache[$crmUserId] = null;
      return null;
    }
  }

  /**
   * ymlb_oauth_status_by_crm_user()
   *
   * @return array<string,mixed>
   */
  function ymlb_oauth_status_by_crm_user(PDO $pdo, int $crmUserId): array
  {
    $row = ymlb_oauth_token_row_by_crm_user($pdo, $crmUserId);
    $token = trim((string)($row['access_token'] ?? ''));
    return [
      'oauth_mode' => 'auto',
      'oauth_token_id' => (int)($row['id'] ?? 0),
      'oauth_token_received_at' => (string)($row['token_received_at'] ?? ''),
      'oauth_ready' => ($token !== '' ? 1 : 0),
    ];
  }

  /**
   * ymlb_binding_oauth_resolve()
   * OAuth precedence: manual token in binding -> auto token by CRM user.
   *
   * @param array<string,mixed> $binding
   * @return array<string,mixed>
   */
  function ymlb_binding_oauth_resolve(PDO $pdo, array $binding): array
  {
    $manualToken = trim((string)($binding['oauth_access_token'] ?? ''));
    if ($manualToken !== '') {
      return [
        'oauth_token' => $manualToken,
        'oauth_mode' => 'manual',
        'oauth_token_id' => 0,
        'oauth_token_received_at' => '',
        'oauth_age_days' => null,
        'oauth_likely_expired' => 0,
        'oauth_ready' => 1,
      ];
    }

    $crmUserId = (int)($binding['crm_user_id'] ?? 0);
    $row = ymlb_oauth_token_row_by_crm_user($pdo, $crmUserId);
    $token = trim((string)($row['access_token'] ?? ''));
    $receivedAt = (string)($row['token_received_at'] ?? '');
    $ageDays = ymlb_oauth_age_days($receivedAt);
    $likelyExpired = ($ageDays !== null && $ageDays >= 29) ? 1 : 0;

    return [
      'oauth_token' => $token,
      'oauth_mode' => 'auto',
      'oauth_token_id' => (int)($row['id'] ?? 0),
      'oauth_token_received_at' => $receivedAt,
      'oauth_age_days' => $ageDays,
      'oauth_likely_expired' => $likelyExpired,
      'oauth_ready' => ($token !== '' ? 1 : 0),
    ];
  }

  /**
   * ymlb_crm_users_list()
   *
   * @return array<int,array<string,mixed>>
   */
  function ymlb_crm_users_list(PDO $pdo): array
  {
    try {
      $st = $pdo->query("
        SELECT
          id,
          COALESCE(NULLIF(TRIM(name), ''), NULLIF(TRIM(phone), ''), NULLIF(TRIM(email), ''), CONCAT('user#', id)) AS title
        FROM users
        ORDER BY id ASC
      ");
      $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
      return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
      ymlb_stage_log('crm_users', 'warn', [
        'stage' => 'list',
        'reason' => 'users_table_unavailable',
      ]);
      return [];
    }
  }

  /**
   * ymlb_crm_user_exists()
   */
  function ymlb_crm_user_exists(PDO $pdo, int $crmUserId): bool
  {
    if ($crmUserId <= 0) return false;
    try {
      $st = $pdo->prepare("SELECT id FROM users WHERE id = :id LIMIT 1");
      $st->execute([':id' => $crmUserId]);
      return ((int)($st->fetchColumn() ?: 0) > 0);
    } catch (Throwable $e) {
      return false;
    }
  }

  /**
   * ymlb_bindings_list()
   *
   * @return array<int,array<string,mixed>>
   */
  function ymlb_bindings_list(PDO $pdo): array
  {
    ymlb_ensure_schema($pdo);
    $table = ymlb_qi(ymlb_table('bindings'));
    $st = $pdo->query("
      SELECT
        b.id,
        b.title,
        b.crm_user_id,
        b.telegram_user_id,
        b.telegram_username,
        b.oauth_access_token,
        b.is_active,
        b.created_at,
        b.updated_at,
        COALESCE(NULLIF(TRIM(u.name), ''), NULLIF(TRIM(u.phone), ''), NULLIF(TRIM(u.email), ''), '') AS crm_user_title
      FROM {$table} b
      LEFT JOIN users u ON u.id = b.crm_user_id
      ORDER BY b.id DESC
    ");
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!is_array($rows)) return [];

    foreach ($rows as &$row) {
      $oauthStatus = ymlb_binding_oauth_resolve($pdo, $row);
      unset($oauthStatus['oauth_token']);
      foreach ($oauthStatus as $k => $v) {
        $row[$k] = $v;
      }
      $row['oauth_access_token'] = '';
    }
    unset($row);

    return $rows;
  }

  /**
   * ymlb_binding_ids_by_crm_user()
   *
   * @return array<int,int>
   */
  function ymlb_binding_ids_by_crm_user(PDO $pdo, int $crmUserId): array
  {
    if ($crmUserId <= 0) return [];
    ymlb_ensure_schema($pdo);

    $table = ymlb_qi(ymlb_table('bindings'));
    $st = $pdo->prepare("
      SELECT id
      FROM {$table}
      WHERE crm_user_id = :crm_user_id
      ORDER BY id DESC
    ");
    $st->execute([':crm_user_id' => $crmUserId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) return [];

    $out = [];
    foreach ($rows as $row) {
      $id = (int)($row['id'] ?? 0);
      if ($id > 0) $out[] = $id;
    }
    return $out;
  }

  /**
   * ymlb_binding_is_owned_by_crm_user()
   */
  function ymlb_binding_is_owned_by_crm_user(PDO $pdo, int $bindingId, int $crmUserId): bool
  {
    if ($bindingId <= 0 || $crmUserId <= 0) return false;
    ymlb_ensure_schema($pdo);

    $table = ymlb_qi(ymlb_table('bindings'));
    $st = $pdo->prepare("
      SELECT id
      FROM {$table}
      WHERE id = :id
        AND crm_user_id = :crm_user_id
      LIMIT 1
    ");
    $st->execute([
      ':id' => $bindingId,
      ':crm_user_id' => $crmUserId,
    ]);

    return ((int)($st->fetchColumn() ?: 0) > 0);
  }

  /**
   * ymlb_rows_filter_by_binding_ids()
   *
   * @param array<int,array<string,mixed>> $rows
   * @param array<int,int> $bindingIds
   * @return array<int,array<string,mixed>>
   */
  function ymlb_rows_filter_by_binding_ids(array $rows, array $bindingIds): array
  {
    if (!$rows || !$bindingIds) return [];

    $set = [];
    foreach ($bindingIds as $id) {
      $id = (int)$id;
      if ($id > 0) $set[$id] = true;
    }

    if (!$set) return [];

    $out = [];
    foreach ($rows as $row) {
      $bindingId = (int)($row['binding_id'] ?? 0);
      if ($bindingId > 0 && isset($set[$bindingId])) {
        $out[] = $row;
      }
    }

    return $out;
  }

  /**
   * ymlb_bindings_list_for_actor()
   *
   * @return array<int,array<string,mixed>>
   */
  function ymlb_bindings_list_for_actor(PDO $pdo, int $actorUserId, bool $canManage): array
  {
    $rows = ymlb_bindings_list($pdo);
    if ($canManage) return $rows;
    if ($actorUserId <= 0) return [];

    $out = [];
    foreach ($rows as $row) {
      if ((int)($row['crm_user_id'] ?? 0) === $actorUserId) {
        $out[] = $row;
      }
    }
    return $out;
  }

  /**
   * ymlb_binding_get()
   *
   * @return array<string,mixed>|null
   */
  function ymlb_binding_get(PDO $pdo, int $id): ?array
  {
    if ($id <= 0) return null;
    ymlb_ensure_schema($pdo);
    $table = ymlb_qi(ymlb_table('bindings'));
    $st = $pdo->prepare("
      SELECT id, title, crm_user_id, telegram_user_id, telegram_username, oauth_access_token, is_active
      FROM {$table}
      WHERE id = :id
      LIMIT 1
    ");
    $st->execute([':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
  }

  /**
   * ymlb_binding_find_active_by_tg_user()
   *
   * @return array<string,mixed>|null
   */
  function ymlb_binding_find_active_by_tg_user(PDO $pdo, string $telegramUserId): ?array
  {
    $telegramUserId = trim($telegramUserId);
    if ($telegramUserId === '') return null;

    ymlb_ensure_schema($pdo);
    $table = ymlb_qi(ymlb_table('bindings'));
    $st = $pdo->prepare("
      SELECT id, title, crm_user_id, telegram_user_id, telegram_username, oauth_access_token, is_active
      FROM {$table}
      WHERE telegram_user_id = :tg
        AND is_active = 1
      LIMIT 1
    ");
    $st->execute([':tg' => $telegramUserId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
  }

  /**
   * ymlb_bindings_active_map()
   *
   * @return array<int,array<string,mixed>>
   */
  function ymlb_bindings_active_map(PDO $pdo): array
  {
    ymlb_ensure_schema($pdo);
    $table = ymlb_qi(ymlb_table('bindings'));
    $st = $pdo->query("
      SELECT id, title, crm_user_id, telegram_user_id, telegram_username, oauth_access_token, is_active
      FROM {$table}
      WHERE is_active = 1
      ORDER BY id ASC
    ");
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!is_array($rows)) return [];

    $out = [];
    foreach ($rows as $row) {
      $id = (int)($row['id'] ?? 0);
      if ($id > 0) $out[$id] = $row;
    }
    return $out;
  }

  /**
   * ymlb_binding_save()
   *
   * @param array<string,mixed> $input
   */
  function ymlb_binding_save(PDO $pdo, array $input, int $actorId): int
  {
    ymlb_ensure_schema($pdo);

    $id = (int)($input['id'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));
    $crmUserId = (int)($input['crm_user_id'] ?? 0);
    $telegramUserId = trim((string)($input['telegram_user_id'] ?? ''));
    $telegramUserId = preg_replace('~[^0-9\-]+~', '', $telegramUserId) ?? '';
    $telegramUsername = ltrim(trim((string)($input['telegram_username'] ?? '')), '@');
    $manualOauthInput = trim((string)($input['oauth_access_token'] ?? ''));
    $manualOauthInput = preg_replace('~^\s*(oauth|bearer)\s+~i', '', $manualOauthInput) ?? $manualOauthInput;
    $manualOauthClear = ((int)($input['oauth_access_token_clear'] ?? 0) === 1) ? 1 : 0;
    $isActive = ((int)($input['is_active'] ?? 0) === 1) ? 1 : 0;

    if ($title === '') {
      throw new RuntimeException('Name is required');
    }
    if ($crmUserId <= 0) {
      throw new RuntimeException('CRM user id is required');
    }
    if (!ymlb_crm_user_exists($pdo, $crmUserId)) {
      throw new RuntimeException('CRM user not found');
    }

    $table = ymlb_qi(ymlb_table('bindings'));
    $now = ymlb_now();

    if ($id > 0) {
      $existing = ymlb_binding_get($pdo, $id);
      if (!$existing) {
        throw new RuntimeException('Binding not found');
      }
      $oauthAccessToken = trim((string)($existing['oauth_access_token'] ?? ''));
      if ($manualOauthClear === 1) {
        $oauthAccessToken = '';
      }
      if ($manualOauthInput !== '') {
        $oauthAccessToken = $manualOauthInput;
      }

      $st = $pdo->prepare("
        UPDATE {$table}
        SET
          title = :title,
          crm_user_id = :crm_user_id,
          telegram_user_id = :telegram_user_id,
          telegram_username = :telegram_username,
          oauth_access_token = :oauth_access_token,
          is_active = :is_active,
          updated_by = :updated_by,
          updated_at = :updated_at
        WHERE id = :id
        LIMIT 1
      ");
      $st->execute([
        ':title' => $title,
        ':crm_user_id' => ($crmUserId > 0 ? $crmUserId : null),
        ':telegram_user_id' => ($telegramUserId !== '' ? $telegramUserId : null),
        ':telegram_username' => $telegramUsername,
        ':oauth_access_token' => $oauthAccessToken,
        ':is_active' => $isActive,
        ':updated_by' => ($actorId > 0 ? $actorId : null),
        ':updated_at' => $now,
        ':id' => $id,
      ]);
      return $id;
    }

    $oauthAccessToken = ($manualOauthInput !== '' ? $manualOauthInput : '');

    $st = $pdo->prepare("
      INSERT INTO {$table}
      (title, crm_user_id, telegram_user_id, telegram_username, oauth_access_token, is_active, created_by, updated_by, created_at, updated_at)
      VALUES
      (:title, :crm_user_id, :telegram_user_id, :telegram_username, :oauth_access_token, :is_active, :created_by, :updated_by, :created_at, :updated_at)
    ");
    $st->execute([
      ':title' => $title,
      ':crm_user_id' => ($crmUserId > 0 ? $crmUserId : null),
      ':telegram_user_id' => ($telegramUserId !== '' ? $telegramUserId : null),
      ':telegram_username' => $telegramUsername,
      ':oauth_access_token' => $oauthAccessToken,
      ':is_active' => $isActive,
      ':created_by' => ($actorId > 0 ? $actorId : null),
      ':updated_by' => ($actorId > 0 ? $actorId : null),
      ':created_at' => $now,
      ':updated_at' => $now,
    ]);

    return (int)$pdo->lastInsertId();
  }

  /**
   * ymlb_binding_delete()
   */
  function ymlb_binding_delete(PDO $pdo, int $id): bool
  {
    if ($id <= 0) return false;
    ymlb_ensure_schema($pdo);

    $bindings = ymlb_qi(ymlb_table('bindings'));
    $channels = ymlb_qi(ymlb_table('channels'));
    $sites = ymlb_qi(ymlb_table('sites'));

    try {
      $pdo->beginTransaction();
      $pdo->prepare("DELETE FROM {$sites} WHERE binding_id = :id")->execute([':id' => $id]);
      $pdo->prepare("DELETE FROM {$channels} WHERE binding_id = :id")->execute([':id' => $id]);
      $pdo->prepare("DELETE FROM {$bindings} WHERE id = :id LIMIT 1")->execute([':id' => $id]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }

    return true;
  }

  /**
   * ymlb_channels_list()
   *
   * @return array<int,array<string,mixed>>
   */
  function ymlb_channels_list(PDO $pdo): array
  {
    ymlb_ensure_schema($pdo);
    $channelsName = ymlb_table('channels');
    $channels = ymlb_qi($channelsName);
    $bindings = ymlb_qi(ymlb_table('bindings'));
    $hasChatKind = ymlb_table_column_exists($pdo, $channelsName, 'chat_kind');
    $channelWhere = ($hasChatKind ? "WHERE c.chat_kind = 'channel'" : '');

    $st = $pdo->query("
      SELECT
        c.id,
        c.binding_id,
        " . ($hasChatKind ? "c.chat_kind," : "") . "
        c.channel_chat_id,
        c.channel_username,
        c.channel_title,
        c.confirm_code,
        c.confirm_expires_at,
        c.confirmed_at,
        c.is_active,
        c.created_at,
        c.updated_at,
        b.title AS binding_title
      FROM {$channels} c
      LEFT JOIN {$bindings} b ON b.id = c.binding_id
      {$channelWhere}
      ORDER BY c.id DESC
    ");
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    return is_array($rows) ? $rows : [];
  }

  /**
   * ymlb_channels_list_for_actor()
   *
   * @return array<int,array<string,mixed>>
   */
  function ymlb_channels_list_for_actor(PDO $pdo, int $actorUserId, bool $canManage): array
  {
    $rows = ymlb_channels_list($pdo);
    if ($canManage) return $rows;
    if ($actorUserId <= 0) return [];

    $bindingIds = ymlb_binding_ids_by_crm_user($pdo, $actorUserId);
    return ymlb_rows_filter_by_binding_ids($rows, $bindingIds);
  }

  /**
   * ymlb_chats_list()
   *
   * @return array<int,array<string,mixed>>
   */
  function ymlb_chats_list(PDO $pdo): array
  {
    ymlb_ensure_schema($pdo);
    $channelsName = ymlb_table('channels');
    $channels = ymlb_qi($channelsName);
    $bindings = ymlb_qi(ymlb_table('bindings'));
    $hasChatKind = ymlb_table_column_exists($pdo, $channelsName, 'chat_kind');

    if (!$hasChatKind) return [];

    $st = $pdo->query("
      SELECT
        c.id,
        c.binding_id,
        c.chat_kind,
        c.channel_chat_id,
        c.channel_username,
        c.channel_title,
        c.confirm_code,
        c.confirm_expires_at,
        c.confirmed_at,
        c.is_active,
        c.created_at,
        c.updated_at,
        b.title AS binding_title
      FROM {$channels} c
      LEFT JOIN {$bindings} b ON b.id = c.binding_id
      WHERE c.chat_kind = 'chat'
      ORDER BY c.id DESC
    ");
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    return is_array($rows) ? $rows : [];
  }

  /**
   * ymlb_chats_list_for_actor()
   *
   * @return array<int,array<string,mixed>>
   */
  function ymlb_chats_list_for_actor(PDO $pdo, int $actorUserId, bool $canManage): array
  {
    $rows = ymlb_chats_list($pdo);
    if ($canManage) return $rows;
    if ($actorUserId <= 0) return [];

    $bindingIds = ymlb_binding_ids_by_crm_user($pdo, $actorUserId);
    return ymlb_rows_filter_by_binding_ids($rows, $bindingIds);
  }

  /**
   * ymlb_channel_is_owned_by_crm_user()
   */
  function ymlb_channel_is_owned_by_crm_user(PDO $pdo, int $channelId, int $crmUserId): bool
  {
    if ($channelId <= 0 || $crmUserId <= 0) return false;
    ymlb_ensure_schema($pdo);

    $channels = ymlb_qi(ymlb_table('channels'));
    $bindings = ymlb_qi(ymlb_table('bindings'));
    $st = $pdo->prepare("
      SELECT c.id
      FROM {$channels} c
      JOIN {$bindings} b ON b.id = c.binding_id
      WHERE c.id = :id
        AND b.crm_user_id = :crm_user_id
      LIMIT 1
    ");
    $st->execute([
      ':id' => $channelId,
      ':crm_user_id' => $crmUserId,
    ]);

    return ((int)($st->fetchColumn() ?: 0) > 0);
  }

  /**
   * ymlb_target_generate_code()
   *
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  function ymlb_target_generate_code(PDO $pdo, array $input, string $chatKind = 'channel'): array
  {
    ymlb_ensure_schema($pdo);
    $chatKind = strtolower(trim($chatKind));
    if ($chatKind !== 'chat') $chatKind = 'channel';

    $requestedBindingId = (int)($input['binding_id'] ?? 0);
    $bindingId = $requestedBindingId;
    $binding = null;
    if ($bindingId > 0) {
      $binding = ymlb_binding_get($pdo, $bindingId);
    }
    if (!$binding) {
      $activeBindings = ymlb_bindings_active_map($pdo);
      if (!$activeBindings) {
        throw new RuntimeException('Create at least one active binding first');
      }
      $bindingIds = array_values(array_map('intval', array_keys($activeBindings)));
      $bindingId = (int)($bindingIds[0] ?? 0);
      $binding = ($bindingId > 0 ? ($activeBindings[$bindingId] ?? null) : null);
    }
    if (!$binding || $bindingId <= 0) {
      throw new RuntimeException('Binding not found');
    }

    $expectedUsername = ltrim(trim((string)($input['channel_username'] ?? '')), '@');
    $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    $expiresAt = date('Y-m-d H:i:s', time() + 1800);

    $channels = ymlb_qi(ymlb_table('channels'));
    $channelsName = ymlb_table('channels');
    $hasChatKind = ymlb_table_column_exists($pdo, $channelsName, 'chat_kind');
    $chatModeEnabled = ((int)(ymlb_settings_get($pdo)['chat_mode_enabled'] ?? 1) === 1);
    $now = ymlb_now();

    $channelId = 0;
    if ($chatModeEnabled) {
      if ($hasChatKind) {
        $stFind = $pdo->query("
          SELECT id
          FROM {$channels}
          WHERE chat_kind = '" . $chatKind . "'
          ORDER BY id ASC
          LIMIT 1
        ");
      } else {
        $stFind = $pdo->query("
          SELECT id
          FROM {$channels}
          ORDER BY id ASC
          LIMIT 1
        ");
      }
      $channelId = (int)(($stFind && $stFind->fetchColumn()) ?: 0);
    }

    if ($channelId > 0) {
      if ($hasChatKind) {
        $st = $pdo->prepare("
          UPDATE {$channels}
          SET
            binding_id = :binding_id,
            chat_kind = :chat_kind,
            channel_chat_id = NULL,
            channel_username = :channel_username,
            channel_title = '',
            confirm_code = :confirm_code,
            confirm_expires_at = :confirm_expires_at,
            confirmed_at = NULL,
            is_active = 1,
            updated_at = :updated_at
          WHERE id = :id
          LIMIT 1
        ");
      } else {
        $st = $pdo->prepare("
          UPDATE {$channels}
          SET
            binding_id = :binding_id,
            channel_chat_id = NULL,
            channel_username = :channel_username,
            channel_title = '',
            confirm_code = :confirm_code,
            confirm_expires_at = :confirm_expires_at,
            confirmed_at = NULL,
            is_active = 1,
            updated_at = :updated_at
          WHERE id = :id
          LIMIT 1
        ");
      }
      $params = [
        ':binding_id' => $bindingId,
        ':channel_username' => $expectedUsername,
        ':confirm_code' => $code,
        ':confirm_expires_at' => $expiresAt,
        ':updated_at' => $now,
        ':id' => $channelId,
      ];
      if ($hasChatKind) $params[':chat_kind'] = $chatKind;
      $st->execute($params);
    } else {
      if ($hasChatKind) {
        $st = $pdo->prepare("
          INSERT INTO {$channels}
          (binding_id, chat_kind, channel_chat_id, channel_username, channel_title, confirm_code, confirm_expires_at, confirmed_at, is_active, created_at, updated_at)
          VALUES
          (:binding_id, :chat_kind, NULL, :channel_username, '', :confirm_code, :confirm_expires_at, NULL, 1, :created_at, :updated_at)
        ");
      } else {
        $st = $pdo->prepare("
          INSERT INTO {$channels}
          (binding_id, channel_chat_id, channel_username, channel_title, confirm_code, confirm_expires_at, confirmed_at, is_active, created_at, updated_at)
          VALUES
          (:binding_id, NULL, :channel_username, '', :confirm_code, :confirm_expires_at, NULL, 1, :created_at, :updated_at)
        ");
      }
      $params = [
        ':binding_id' => $bindingId,
        ':channel_username' => $expectedUsername,
        ':confirm_code' => $code,
        ':confirm_expires_at' => $expiresAt,
        ':created_at' => $now,
        ':updated_at' => $now,
      ];
      if ($hasChatKind) $params[':chat_kind'] = $chatKind;
      $st->execute($params);
      $channelId = (int)$pdo->lastInsertId();
    }

    if ($chatModeEnabled) {
      if ($hasChatKind) {
        $stCleanup = $pdo->prepare("
          DELETE FROM {$channels}
          WHERE chat_kind = :chat_kind
            AND id <> :id
        ");
        $stCleanup->execute([
          ':chat_kind' => $chatKind,
          ':id' => $channelId,
        ]);
      } else {
        $stCleanup = $pdo->prepare("
          DELETE FROM {$channels}
          WHERE id <> :id
        ");
        $stCleanup->execute([':id' => $channelId]);
      }
    }

    $targetWord = ($chatKind === 'chat') ? 'chat' : 'channel';
    return [
      'channel_id' => $channelId,
      'binding_id' => $bindingId,
      'requested_binding_id' => $requestedBindingId,
      'chat_kind' => $chatKind,
      'code' => $code,
      'expires_at' => $expiresAt,
      'instruction' => 'Publish /bind ' . $code . ' in the ' . $targetWord . ' where bot is admin.',
    ];
  }

  /**
   * ymlb_channel_generate_code()
   *
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  function ymlb_channel_generate_code(PDO $pdo, array $input): array
  {
    return ymlb_target_generate_code($pdo, $input, 'channel');
  }

  /**
   * ymlb_chat_generate_code()
   *
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  function ymlb_chat_generate_code(PDO $pdo, array $input): array
  {
    return ymlb_target_generate_code($pdo, $input, 'chat');
  }

  /**
   * ymlb_channel_toggle()
   */
  function ymlb_channel_toggle(PDO $pdo, int $id, int $isActive): bool
  {
    if ($id <= 0) return false;
    ymlb_ensure_schema($pdo);
    $channels = ymlb_qi(ymlb_table('channels'));
    $st = $pdo->prepare("
      UPDATE {$channels}
      SET is_active = :is_active, updated_at = :updated_at
      WHERE id = :id
      LIMIT 1
    ");
    return $st->execute([
      ':is_active' => ($isActive === 1 ? 1 : 0),
      ':updated_at' => ymlb_now(),
      ':id' => $id,
    ]);
  }

  /**
   * ymlb_channel_delete()
   */
  function ymlb_channel_delete(PDO $pdo, int $id): bool
  {
    if ($id <= 0) return false;
    ymlb_ensure_schema($pdo);
    $channels = ymlb_qi(ymlb_table('channels'));
    $st = $pdo->prepare("DELETE FROM {$channels} WHERE id = :id LIMIT 1");
    return $st->execute([':id' => $id]);
  }

  /**
   * ymlb_channels_find_active_by_chat()
   *
   * @return array<int,array<string,mixed>>
   */
  function ymlb_targets_find_active_by_chat(PDO $pdo, string $chatId, string $chatKind = 'channel'): array
  {
    $chatId = trim($chatId);
    if ($chatId === '') return [];
    ymlb_ensure_schema($pdo);

    $chatKind = strtolower(trim($chatKind));
    if ($chatKind !== 'chat') $chatKind = 'channel';

    $channelsName = ymlb_table('channels');
    $channels = ymlb_qi($channelsName);
    $hasChatKind = ymlb_table_column_exists($pdo, $channelsName, 'chat_kind');
    $sql = "
      SELECT id, binding_id, channel_chat_id, channel_username, channel_title, is_active, confirmed_at
      FROM {$channels}
      WHERE channel_chat_id = :chat_id
    ";
    if ($hasChatKind) {
      $sql .= " AND chat_kind = '" . $chatKind . "'";
    }
    $sql .= "
        AND is_active = 1
        AND confirmed_at IS NOT NULL
      ORDER BY id DESC
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':chat_id' => $chatId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  /**
   * ymlb_channels_find_active_by_chat()
   *
   * @return array<int,array<string,mixed>>
   */
  function ymlb_channels_find_active_by_chat(PDO $pdo, string $chatId): array
  {
    return ymlb_targets_find_active_by_chat($pdo, $chatId, 'channel');
  }

  /**
   * ymlb_chats_find_active_by_chat()
   *
   * @return array<int,array<string,mixed>>
   */
  function ymlb_chats_find_active_by_chat(PDO $pdo, string $chatId): array
  {
    return ymlb_targets_find_active_by_chat($pdo, $chatId, 'chat');
  }

  /**
   * ymlb_channel_find_active_by_chat()
   *
   * @return array<string,mixed>|null
   */
  function ymlb_channel_find_active_by_chat(PDO $pdo, string $chatId): ?array
  {
    $rows = ymlb_channels_find_active_by_chat($pdo, $chatId);
    return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
  }

  /**
   * ymlb_chat_find_active_by_chat()
   *
   * @return array<string,mixed>|null
   */
  function ymlb_chat_find_active_by_chat(PDO $pdo, string $chatId): ?array
  {
    $rows = ymlb_chats_find_active_by_chat($pdo, $chatId);
    return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
  }

  /**
   * ymlb_channel_confirm_from_text()
   *
   * @return array<string,mixed>|null
   */
  function ymlb_target_confirm_from_text(PDO $pdo, string $text, string $chatId, string $chatUsername, string $chatTitle, string $chatKind = 'channel'): ?array
  {
    $text = trim($text);
    if ($text === '') return null;

    $chatKind = strtolower(trim($chatKind));
    if ($chatKind !== 'chat') $chatKind = 'channel';

    if (!preg_match('~(?:/bind\s+)?([A-Z0-9]{6})~i', $text, $m)) {
      return null;
    }
    $code = strtoupper(trim((string)($m[1] ?? '')));
    if ($code === '') return null;

    ymlb_ensure_schema($pdo);
    $channelsName = ymlb_table('channels');
    $channels = ymlb_qi($channelsName);
    $hasChatKind = ymlb_table_column_exists($pdo, $channelsName, 'chat_kind');
    $now = ymlb_now();

    $sqlFind = "
      SELECT id, binding_id, channel_username
      FROM {$channels}
      WHERE confirm_code = :confirm_code
    ";
    if ($hasChatKind) {
      $sqlFind .= " AND chat_kind = '" . $chatKind . "'";
    }
    $sqlFind .= "
        AND is_active = 1
        AND confirmed_at IS NULL
        AND (confirm_expires_at IS NULL OR confirm_expires_at >= :now)
      ORDER BY id DESC
      LIMIT 1
    ";
    $stFind = $pdo->prepare($sqlFind);
    $stFind->execute([
      ':confirm_code' => $code,
      ':now' => $now,
    ]);
    $row = $stFind->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
      return null;
    }

    $expectedUsername = ltrim(trim((string)($row['channel_username'] ?? '')), '@');
    $actualUsername = ltrim(trim($chatUsername), '@');
    if ($expectedUsername !== '' && strcasecmp($expectedUsername, $actualUsername) !== 0) {
      return null;
    }

    $id = (int)($row['id'] ?? 0);
    if ($id <= 0) return null;
    $bindingId = (int)($row['binding_id'] ?? 0);

    // Keep one active row per (binding, chat) on re-bind.
    if ($bindingId > 0 && $chatId !== '') {
      $sqlCleanup = "
        DELETE FROM {$channels}
        WHERE binding_id = :binding_id
          AND channel_chat_id = :channel_chat_id
          AND id <> :id
      ";
      if ($hasChatKind) {
        $sqlCleanup .= " AND chat_kind = '" . $chatKind . "'";
      }
      $stCleanup = $pdo->prepare($sqlCleanup);
      $stCleanup->execute([
        ':binding_id' => $bindingId,
        ':channel_chat_id' => $chatId,
        ':id' => $id,
      ]);
    }

    $stUpdate = $pdo->prepare("
      UPDATE {$channels}
      SET
        channel_chat_id = :channel_chat_id,
        channel_username = :channel_username,
        channel_title = :channel_title,
        confirmed_at = :confirmed_at,
        updated_at = :updated_at
      WHERE id = :id
      LIMIT 1
    ");
    $stUpdate->execute([
      ':channel_chat_id' => $chatId,
      ':channel_username' => $chatUsername,
      ':channel_title' => $chatTitle,
      ':confirmed_at' => $now,
      ':updated_at' => $now,
      ':id' => $id,
    ]);

    return [
      'channel_id' => $id,
      'binding_id' => $bindingId,
      'chat_kind' => $chatKind,
      'code' => $code,
    ];
  }

  /**
   * ymlb_channel_confirm_from_text()
   *
   * @return array<string,mixed>|null
   */
  function ymlb_channel_confirm_from_text(PDO $pdo, string $text, string $chatId, string $chatUsername, string $chatTitle): ?array
  {
    return ymlb_target_confirm_from_text($pdo, $text, $chatId, $chatUsername, $chatTitle, 'channel');
  }

  /**
   * ymlb_chat_confirm_from_text()
   *
   * @return array<string,mixed>|null
   */
  function ymlb_chat_confirm_from_text(PDO $pdo, string $text, string $chatId, string $chatUsername, string $chatTitle): ?array
  {
    return ymlb_target_confirm_from_text($pdo, $text, $chatId, $chatUsername, $chatTitle, 'chat');
  }

  /**
   * ymlb_sites_list()
   *
   * @return array<int,array<string,mixed>>
   */
  function ymlb_sites_list(PDO $pdo): array
  {
    ymlb_ensure_schema($pdo);
    $sites = ymlb_qi(ymlb_table('sites'));
    $bindings = ymlb_qi(ymlb_table('bindings'));

    $st = $pdo->query("
      SELECT
        s.id,
        s.binding_id,
        s.name,
        s.clid,
        s.is_active,
        s.created_at,
        s.updated_at,
        b.title AS binding_title
      FROM {$sites} s
      LEFT JOIN {$bindings} b ON b.id = s.binding_id
      ORDER BY s.id DESC
    ");
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    return is_array($rows) ? $rows : [];
  }

  /**
   * ymlb_sites_list_for_actor()
   *
   * @return array<int,array<string,mixed>>
   */
  function ymlb_sites_list_for_actor(PDO $pdo, int $actorUserId, bool $canManage): array
  {
    $rows = ymlb_sites_list($pdo);
    if ($canManage) return $rows;
    if ($actorUserId <= 0) return [];

    $bindingIds = ymlb_binding_ids_by_crm_user($pdo, $actorUserId);
    return ymlb_rows_filter_by_binding_ids($rows, $bindingIds);
  }

  /**
   * ymlb_site_is_owned_by_crm_user()
   */
  function ymlb_site_is_owned_by_crm_user(PDO $pdo, int $siteId, int $crmUserId): bool
  {
    if ($siteId <= 0 || $crmUserId <= 0) return false;
    ymlb_ensure_schema($pdo);

    $sites = ymlb_qi(ymlb_table('sites'));
    $bindings = ymlb_qi(ymlb_table('bindings'));
    $st = $pdo->prepare("
      SELECT s.id
      FROM {$sites} s
      JOIN {$bindings} b ON b.id = s.binding_id
      WHERE s.id = :id
        AND b.crm_user_id = :crm_user_id
      LIMIT 1
    ");
    $st->execute([
      ':id' => $siteId,
      ':crm_user_id' => $crmUserId,
    ]);

    return ((int)($st->fetchColumn() ?: 0) > 0);
  }

  /**
   * ymlb_sites_by_binding()
   *
   * @return array<int,array<string,mixed>>
   */
  function ymlb_sites_by_binding(PDO $pdo, int $bindingId, bool $onlyActive = true): array
  {
    if ($bindingId <= 0) return [];
    ymlb_ensure_schema($pdo);

    $sites = ymlb_qi(ymlb_table('sites'));
    $sql = "
      SELECT id, binding_id, name, clid, is_active
      FROM {$sites}
      WHERE binding_id = :binding_id
    ";
    if ($onlyActive) {
      $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY id ASC";

    $st = $pdo->prepare($sql);
    $st->execute([':binding_id' => $bindingId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  /**
   * ymlb_site_save()
   *
   * @param array<string,mixed> $input
   */
  function ymlb_site_save(PDO $pdo, array $input): int
  {
    ymlb_ensure_schema($pdo);
    $id = (int)($input['id'] ?? 0);
    $bindingId = (int)($input['binding_id'] ?? 0);
    $name = trim((string)($input['name'] ?? ''));
    $clid = trim((string)($input['clid'] ?? ''));
    $clid = preg_replace('~[^a-zA-Z0-9_-]+~', '', $clid) ?? '';
    $isActive = ((int)($input['is_active'] ?? 0) === 1) ? 1 : 0;

    if ($bindingId <= 0) {
      throw new RuntimeException('Binding is required');
    }
    if ($name === '') {
      throw new RuntimeException('Site name is required');
    }
    if ($clid === '') {
      throw new RuntimeException('CLID is required');
    }

    if (!ymlb_binding_get($pdo, $bindingId)) {
      throw new RuntimeException('Binding not found');
    }

    $sites = ymlb_qi(ymlb_table('sites'));
    $now = ymlb_now();

    if ($id > 0) {
      $st = $pdo->prepare("
        UPDATE {$sites}
        SET
          binding_id = :binding_id,
          name = :name,
          clid = :clid,
          is_active = :is_active,
          updated_at = :updated_at
        WHERE id = :id
        LIMIT 1
      ");
      $st->execute([
        ':binding_id' => $bindingId,
        ':name' => $name,
        ':clid' => $clid,
        ':is_active' => $isActive,
        ':updated_at' => $now,
        ':id' => $id,
      ]);
      return $id;
    }

    $st = $pdo->prepare("
      INSERT INTO {$sites}
      (binding_id, name, clid, is_active, created_at, updated_at)
      VALUES
      (:binding_id, :name, :clid, :is_active, :created_at, :updated_at)
    ");
    $st->execute([
      ':binding_id' => $bindingId,
      ':name' => $name,
      ':clid' => $clid,
      ':is_active' => $isActive,
      ':created_at' => $now,
      ':updated_at' => $now,
    ]);

    return (int)$pdo->lastInsertId();
  }

  /**
   * ymlb_site_delete()
   */
  function ymlb_site_delete(PDO $pdo, int $id): bool
  {
    if ($id <= 0) return false;
    ymlb_ensure_schema($pdo);
    $sites = ymlb_qi(ymlb_table('sites'));
    $st = $pdo->prepare("DELETE FROM {$sites} WHERE id = :id LIMIT 1");
    return $st->execute([':id' => $id]);
  }

  /**
   * ymlb_bootstrap_payload()
   *
   * @return array<string,mixed>
   */
  function ymlb_bootstrap_payload(PDO $pdo, int $actorUserId = 0, bool $canManage = false): array
  {
    ymlb_sync_module_roles($pdo);
    $settings = ymlb_settings_get($pdo);
    $bindings = ymlb_bindings_list_for_actor($pdo, $actorUserId, $canManage);
    $channels = ymlb_channels_list_for_actor($pdo, $actorUserId, $canManage);
    $chats = ymlb_chats_list_for_actor($pdo, $actorUserId, $canManage);
    $sites = ymlb_sites_list_for_actor($pdo, $actorUserId, $canManage);
    $payload = [
      'module_code' => ymlb_module_code(),
      'bindings' => $bindings,
      'channels' => $channels,
      'chats' => $chats,
      'sites' => $sites,
    ];

    if ($canManage) {
      $payload['settings'] = $settings;
      $payload['listener_url'] = ymlb_listener_url($settings);
      $payload['chat_listener_url'] = ymlb_chat_listener_url($settings);
      $payload['crm_users'] = ymlb_crm_users_list($pdo);
    }

    return $payload;
  }

  /**
   * ymlb_drop_query_keys()
   *
   * @return array<int,string>
   */
  function ymlb_drop_query_keys(): array
  {
    return [
      'vid',
      'mclid',
      'offerid',
      'pp',
      'clid',
      'distr_type',
      'utm_source',
      'utm_medium',
      'utm_campaign',
      'utm_term',
      'how',
      'rs',
      'sponsored',
      'do-waremd5',
      'cpc',
      'cc',
      'erid',
      'show-uid',
      'offhid',
      'nid',
      'cpa',
      'publicId',
      'utm_share_id',
    ];
  }

  /**
   * ymlb_should_keep_aprice()
   */
  function ymlb_should_keep_aprice(string $url): bool
  {
    if (strpos($url, '/search?') !== false) {
      return true;
    }
    $query = (string)(parse_url($url, PHP_URL_QUERY) ?? '');
    return (stripos($query, 'how=') !== false);
  }

  /**
   * ymlb_is_market_short_url()
   * Checks YM short format like https://market.yandex.ru/cc/xxxx
   */
  function ymlb_is_market_short_url(string $url): bool
  {
    $parts = parse_url($url);
    if (!is_array($parts)) return false;
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');
    if ($host === '' || $path === '') return false;
    if (strpos($host, 'market.yandex.') === false) return false;
    return (bool)preg_match('~^/cc/[^/]+~i', $path);
  }

  /**
   * ymlb_is_market_url()
   * True only for market.yandex.* links.
   */
  function ymlb_is_market_url(string $url): bool
  {
    $parts = parse_url($url);
    if (!is_array($parts)) return false;
    $host = strtolower(trim((string)($parts['host'] ?? '')));
    if ($host === '') return false;
    return (strpos($host, 'market.yandex.') !== false);
  }

  /**
   * ymlb_url_query_clid()
   * Extracts clid param from URL query.
   */
  function ymlb_url_query_clid(string $url): string
  {
    $query = (string)(parse_url($url, PHP_URL_QUERY) ?? '');
    if ($query === '') return '';

    $map = [];
    parse_str($query, $map);
    $clid = trim((string)($map['clid'] ?? ''));
    return $clid;
  }

  /**
   * ymlb_url_has_clid_from_list()
   *
   * @param array<int,string> $clids
   */
  function ymlb_url_has_clid_from_list(string $url, array $clids): bool
  {
    $urlClid = ymlb_url_query_clid($url);
    if ($urlClid === '') return false;

    foreach ($clids as $clid) {
      if ($clid !== '' && strcmp($urlClid, (string)$clid) === 0) {
        return true;
      }
    }
    return false;
  }

  /**
   * ymlb_url_without_query()
   * Returns URL without query/fragment params.
   */
  function ymlb_url_without_query(string $url): string
  {
    $url = trim($url);
    if ($url === '') return $url;

    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
      return $url;
    }

    $scheme = (string)$parts['scheme'];
    $host = (string)$parts['host'];
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = (string)($parts['path'] ?? '');
    return $scheme . '://' . $host . $port . $path;
  }

  /**
   * ymlb_affiliate_partner_link_create()
   * Creates partner link via Market Affiliate API.
   *
   * @return array<string,string>|null
   */
  function ymlb_affiliate_partner_link_create(string $url, string $authKey, string $clid): ?array
  {
    $url = trim($url);
    $authKey = trim($authKey);
    $clid = trim($clid);
    if ($url === '' || $authKey === '' || $clid === '') {
      return null;
    }
    if (!ymlb_is_market_url($url)) {
      return null;
    }

    $endpoint = 'https://api.content.market.yandex.ru/v3/affiliate/partner/link/create'
      . '?url=' . rawurlencode($url)
      . '&clid=' . rawurlencode($clid)
      . '&format=json';

    ymlb_stage_log('market_api', 'info', [
      'stage' => 'partner_link_create_request',
      'clid' => $clid,
      'url' => $url,
      'endpoint' => $endpoint,
    ]);

    $resp = ymlb_http_json_debug($endpoint, [
      'Authorization: ' . $authKey,
      'Accept: application/json',
      'User-Agent: Mozilla/5.0',
    ]);

    ymlb_stage_log('market_api', (!empty($resp['ok']) ? 'info' : 'warn'), [
      'stage' => 'partner_link_create_response',
      'clid' => $clid,
      'http_code' => (int)($resp['http_code'] ?? 0),
      'content_type' => (string)($resp['content_type'] ?? ''),
      'curl_error' => (string)($resp['curl_error'] ?? ''),
      'json_error' => (string)($resp['json_error'] ?? ''),
      'body_preview' => (string)($resp['body_preview'] ?? ''),
    ]);

    if (empty($resp['ok']) || !is_array($resp['json'] ?? null)) {
      return null;
    }

    $json = (array)$resp['json'];
    $link = (is_array($json['link'] ?? null) ? (array)$json['link'] : []);
    $partnerUrl = trim((string)($link['url'] ?? ''));
    $shortUrl = trim((string)($link['shortUrl'] ?? ''));
    if ($partnerUrl === '') return null;

    return [
      'url' => $partnerUrl,
      'short_url' => $shortUrl,
    ];
  }

  /**
   * ymlb_oauth_age_days()
   * Returns token age in days by token_received_at, null if unknown.
   */
  function ymlb_oauth_age_days(string $receivedAt): ?int
  {
    $receivedAt = trim($receivedAt);
    if ($receivedAt === '') return null;
    $ts = strtotime($receivedAt);
    if ($ts === false) return null;
    $ageSec = (time() - (int)$ts);
    if ($ageSec < 0) return 0;
    return (int)floor($ageSec / 86400);
  }

  /**
   * ymlb_http_json_debug()
   * Returns transport and parse diagnostics for Market API calls.
   *
   * @param array<int,string> $headers
   * @return array<string,mixed>
   */
  function ymlb_http_json_debug(string $url, array $headers): array
  {
    $out = [
      'ok' => false,
      'http_code' => 0,
      'content_type' => '',
      'curl_error' => '',
      'body_preview' => '',
      'json' => null,
      'json_error' => '',
    ];

    if (!function_exists('curl_init')) {
      $out['curl_error'] = 'curl_missing';
      return $out;
    }

    $ch = curl_init($url);
    if (!$ch) {
      $out['curl_error'] = 'curl_init_failed';
      return $out;
    }

    curl_setopt_array($ch, [
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADER => true,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_TIMEOUT => 20,
    ]);

    $resp = curl_exec($ch);
    $out['http_code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $out['content_type'] = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $out['curl_error'] = (string)curl_error($ch);

    if ($resp === false) {
      curl_close($ch);
      return $out;
    }

    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = (string)substr((string)$resp, $hsize);
    curl_close($ch);

    $out['body_preview'] = ymlb_log_excerpt($body, 4000);
    $json = json_decode($body, true);
    $out['json_error'] = json_last_error_msg();
    $out['json'] = (is_array($json) ? $json : null);
    $out['ok'] = (
      $out['http_code'] === 200
      && stripos((string)$out['content_type'], 'application/json') !== false
      && is_array($out['json'])
    );

    return $out;
  }

  /**
   * ymlb_http_json()
   *
   * @param array<int,string> $headers
   * @return array<string,mixed>|null
   */
  function ymlb_http_json(string $url, array $headers): ?array
  {
    $meta = ymlb_http_json_debug($url, $headers);
    return ($meta['ok'] && is_array($meta['json'])) ? $meta['json'] : null;
  }

  /**
   * ymlb_http_text_debug()
   *
   * @param array<int,string> $headers
   * @return array<string,mixed>
   */
  function ymlb_http_text_debug(string $url, array $headers): array
  {
    $out = [
      'ok' => false,
      'http_code' => 0,
      'content_type' => '',
      'curl_error' => '',
      'body' => '',
      'body_preview' => '',
    ];

    if (!function_exists('curl_init')) {
      $out['curl_error'] = 'curl_missing';
      return $out;
    }

    $ch = curl_init($url);
    if (!$ch) {
      $out['curl_error'] = 'curl_init_failed';
      return $out;
    }

    curl_setopt_array($ch, [
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADER => true,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
    ]);

    $resp = curl_exec($ch);
    $out['http_code'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $out['content_type'] = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $out['curl_error'] = (string)curl_error($ch);

    if ($resp === false) {
      curl_close($ch);
      return $out;
    }

    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = (string)substr((string)$resp, $hsize);
    curl_close($ch);

    $out['body'] = $body;
    $out['body_preview'] = ymlb_log_excerpt($body, 4000);
    $out['ok'] = ($out['http_code'] >= 200 && $out['http_code'] < 400 && $body !== '');

    return $out;
  }

  /**
   * ymlb_html_clean_text()
   */
  function ymlb_html_clean_text(?string $value): string
  {
    $value = (string)($value ?? '');
    if ($value === '') return '';
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('~\s+~u', ' ', $value) ?? $value;
    return trim($value);
  }

  /**
   * ymlb_pick_first_image_url()
   * @param mixed $value
   */
  function ymlb_pick_first_image_url($value): ?string
  {
    if (is_string($value)) {
      $v = trim($value);
      return ($v !== '' ? $v : null);
    }
    if (!is_array($value)) return null;

    if (!empty($value['url']) && is_string($value['url'])) {
      $v = trim((string)$value['url']);
      if ($v !== '') return $v;
    }
    foreach ($value as $item) {
      $hit = ymlb_pick_first_image_url($item);
      if ($hit !== null) return $hit;
    }
    return null;
  }

  /**
   * ymlb_market_jsonld_collect()
   * @param mixed $node
   */
  function ymlb_market_jsonld_collect($node, ?string &$name, ?string &$photo, ?string &$description): void
  {
    if (!is_array($node)) return;

    if ($name === null && !empty($node['name']) && is_string($node['name'])) {
      $val = ymlb_html_clean_text((string)$node['name']);
      if ($val !== '') $name = $val;
    }
    if ($description === null && !empty($node['description']) && is_string($node['description'])) {
      $val = ymlb_html_clean_text((string)$node['description']);
      if ($val !== '') $description = $val;
    }
    if ($photo === null) {
      $img = ymlb_pick_first_image_url($node['image'] ?? null);
      if ($img !== null) $photo = $img;
    }

    foreach ($node as $child) {
      if (is_array($child)) {
        ymlb_market_jsonld_collect($child, $name, $photo, $description);
      }
    }
  }

  /**
   * ymlb_market_meta_content()
   */
  function ymlb_market_meta_content(string $html, string $key): ?string
  {
    $keyRx = preg_quote($key, '~');
    $patterns = [
      '~<meta[^>]*(?:property|name)\s*=\s*["\']' . $keyRx . '["\'][^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*>~iu',
      '~<meta[^>]*content\s*=\s*["\']([^"\']+)["\'][^>]*(?:property|name)\s*=\s*["\']' . $keyRx . '["\'][^>]*>~iu',
    ];
    foreach ($patterns as $rx) {
      if (preg_match($rx, $html, $m)) {
        $val = ymlb_html_clean_text((string)($m[1] ?? ''));
        if ($val !== '') return $val;
      }
    }
    return null;
  }

  /**
   * ymlb_market_page_data()
   *
   * @return array{0:?string,1:?string,2:?string,3:string}
   */
  function ymlb_market_page_data(string $url): array
  {
    $url = trim($url);
    if ($url === '') return [null, null, null, 'none'];

    $headers = [
      'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
      'User-Agent: Mozilla/5.0',
    ];

    ymlb_stage_log('market_api', 'info', [
      'stage' => 'card_page_request',
      'url' => $url,
    ]);

    $resp = ymlb_http_text_debug($url, $headers);
    ymlb_stage_log('market_api', (!empty($resp['ok']) ? 'info' : 'warn'), [
      'stage' => 'card_page_response',
      'url' => $url,
      'http_code' => (int)($resp['http_code'] ?? 0),
      'content_type' => (string)($resp['content_type'] ?? ''),
      'curl_error' => (string)($resp['curl_error'] ?? ''),
      'body_preview' => (string)($resp['body_preview'] ?? ''),
    ]);

    if (empty($resp['ok'])) return [null, null, null, 'none'];

    $html = (string)($resp['body'] ?? '');
    $name = null;
    $photo = null;
    $description = null;
    $sourceParts = [];
    $jsonldScripts = 0;
    $jsonldDecoded = 0;

    if (preg_match_all('~<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>~is', $html, $mScripts)) {
      $jsonldScripts = count((array)($mScripts[1] ?? []));
      foreach ((array)($mScripts[1] ?? []) as $chunk) {
        $raw = trim((string)$chunk);
        if ($raw === '') continue;
        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $json = json_decode($raw, true);
        if (!is_array($json)) continue;
        $jsonldDecoded++;
        ymlb_market_jsonld_collect($json, $name, $photo, $description);
      }
    }
    if (($name !== null || $photo !== null || $description !== null) && !in_array('jsonld', $sourceParts, true)) {
      $sourceParts[] = 'jsonld';
    }

    $metaName = ymlb_market_meta_content($html, 'og:title') ?? ymlb_market_meta_content($html, 'twitter:title');
    $metaDescription = ymlb_market_meta_content($html, 'og:description') ?? ymlb_market_meta_content($html, 'description');
    $metaPhoto = ymlb_market_meta_content($html, 'og:image') ?? ymlb_market_meta_content($html, 'twitter:image');
    if ($name === null && $metaName !== null) $name = $metaName;
    if ($description === null && $metaDescription !== null) $description = $metaDescription;
    if ($photo === null && $metaPhoto !== null) $photo = $metaPhoto;
    if (($metaName !== null || $metaDescription !== null || $metaPhoto !== null) && !in_array('meta', $sourceParts, true)) {
      $sourceParts[] = 'meta';
    }

    if ($name === null && preg_match('~<title[^>]*>(.*?)</title>~is', $html, $mTitle)) {
      $title = ymlb_html_clean_text((string)($mTitle[1] ?? ''));
      if ($title !== '') {
        $name = $title;
        if (!in_array('title', $sourceParts, true)) $sourceParts[] = 'title';
      }
    }

    $source = ($sourceParts ? implode('+', $sourceParts) : 'none');
    ymlb_stage_log('market_api', 'info', [
      'stage' => 'card_page_parse',
      'url' => $url,
      'source' => $source,
      'jsonld_scripts' => $jsonldScripts,
      'jsonld_decoded' => $jsonldDecoded,
      'name_found' => ($name !== null ? 1 : 0),
      'photo_found' => ($photo !== null ? 1 : 0),
      'description_found' => ($description !== null ? 1 : 0),
      'photo_url' => ($photo !== null ? $photo : ''),
    ]);

    return [$name, $photo, $description, $source];
  }

  /**
   * ymlb_title_from_url()
   * Builds readable fallback title from market URL slug.
   */
  function ymlb_title_from_url(string $url): string
  {
    $slug = trim((string)(market_url_extract_slug($url) ?? ''));
    if ($slug === '') return '';

    $slug = rawurldecode($slug);
    $parts = preg_split('~[-_]+~u', $slug) ?: [];
    $out = [];
    foreach ($parts as $part) {
      $part = trim((string)$part);
      if ($part === '') continue;

      if (preg_match('~^[a-z]+$~i', $part)) {
        $part = mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
      } elseif (preg_match('~^[a-z0-9]+$~i', $part)) {
        $part = strtoupper($part);
      }

      if (strtolower($part) === 'kf') {
        $part = 'K&F';
      }
      $out[] = $part;
    }

    $title = trim(implode(' ', $out));
    if ($title === '') return '';
    if (function_exists('mb_substr')) {
      return (string)mb_substr($title, 0, 220);
    }
    return substr($title, 0, 220);
  }

  /**
   * ymlb_strip_emoji()
   * Removes emoji/symbol code points from text.
   */
  function ymlb_strip_emoji(string $text): string
  {
    $text = preg_replace('~[\x{1F1E6}-\x{1F1FF}]~u', '', $text) ?? $text; // flags
    $text = preg_replace('~[\x{1F300}-\x{1FAFF}]~u', '', $text) ?? $text; // pictographs
    $text = preg_replace('~[\x{2600}-\x{27BF}]~u', '', $text) ?? $text;   // misc symbols
    $text = str_replace(["\u{FE0F}", "\u{200D}"], '', $text); // variants/zwj
    $text = preg_replace('~\s+~u', ' ', $text) ?? $text;
    return trim($text);
  }

  /**
   * ymlb_clean_market_description()
   * Cuts marketplace boilerplate and keeps product-focused text.
   */
  function ymlb_clean_market_description(string $text): string
  {
    $text = trim($text);
    if ($text === '') return '';

    // Typical YM pattern: "Название – купить ...".
    $text = preg_replace('~\s+[–-]\s*купить\b.*$~ui', '', $text) ?? $text;

    // Fallback cleanup if boilerplate came without " - купить".
    $text = preg_replace('~\bдоставка на дом[^.]*\.?~ui', '', $text) ?? $text;
    $text = preg_replace('~\bотзывы реальных покупателей[^.]*\.?~ui', '', $text) ?? $text;
    $text = preg_replace('~\bзакажите на сайте или в приложении\b[^.]*\.?~ui', '', $text) ?? $text;
    $text = preg_replace('~\bв интернет-магазине\b[^.]*\.?~ui', '', $text) ?? $text;

    $text = preg_replace('~\s+~u', ' ', $text) ?? $text;
    $text = preg_replace('~\s*([,.;:!?])\s*~u', '$1 ', $text) ?? $text;
    $text = preg_replace('~\s+~u', ' ', $text) ?? $text;
    $text = trim($text, " \t\n\r\0\x0B,.;:-");

    return trim($text);
  }

  /**
   * ymlb_affiliate_search_by_slug()
   *
   * @return array{0:?string,1:?string,2:?string,3:?string}
   */
  function ymlb_affiliate_search_by_slug(string $slug, string $authKey, int $geoId): array
  {
    $slug = trim($slug);
    $authKey = trim($authKey);
    if ($slug === '' || $authKey === '') {
      ymlb_stage_log('market_api', 'warn', [
        'stage' => 'search_by_slug_validate',
        'slug' => $slug,
        'geo_id' => $geoId,
        'has_auth_key' => ($authKey !== '' ? 1 : 0),
        'reason' => 'slug_or_auth_empty',
      ]);
      return [null, null, null, null];
    }

    $fields = ['MODEL_PHOTO', 'MODEL_PHOTOS', 'MODEL_DEFAULT_OFFER', 'OFFER_PHOTO'];
    $url = 'https://api.content.market.yandex.ru/v3/affiliate/search?text='
      . rawurlencode($slug)
      . '&geo_id=' . $geoId
      . '&count=20'
      . '&fields=' . implode(',', $fields);

    $headers = [
      'Authorization: ' . $authKey,
      'Accept: application/json',
      'User-Agent: Mozilla/5.0',
    ];

    ymlb_stage_log('market_api', 'info', [
      'stage' => 'search_by_slug_request',
      'slug' => $slug,
      'geo_id' => $geoId,
      'url' => $url,
      'fields' => implode(',', $fields),
    ]);

    $resp = ymlb_http_json_debug($url, $headers);
    $json = (is_array($resp['json'] ?? null) ? (array)$resp['json'] : []);
    $itemsProbe = $json['items'] ?? null;
    $itemsCount = is_array($itemsProbe) ? count($itemsProbe) : 0;

    $bodyPreview = (string)($resp['body_preview'] ?? '');
    $endpointDeprecated = (stripos($bodyPreview, 'not supported anymore') !== false ? 1 : 0);
    ymlb_stage_log('market_api', (!empty($resp['ok']) ? 'info' : 'warn'), [
      'stage' => 'search_by_slug_response',
      'slug' => $slug,
      'geo_id' => $geoId,
      'http_code' => (int)($resp['http_code'] ?? 0),
      'content_type' => (string)($resp['content_type'] ?? ''),
      'curl_error' => (string)($resp['curl_error'] ?? ''),
      'json_error' => (string)($resp['json_error'] ?? ''),
      'items_count' => $itemsCount,
      'endpoint_deprecated' => $endpointDeprecated,
      'body_preview' => $bodyPreview,
    ]);

    if (empty($resp['ok']) || !$json) return [null, null, null, null];
    $j = $json;

    $items = $j['items'] ?? null;
    if (!is_array($items) || !$items) {
      ymlb_stage_log('market_api', 'warn', [
        'stage' => 'search_by_slug_parse',
        'slug' => $slug,
        'geo_id' => $geoId,
        'reason' => 'items_empty',
      ]);
      return [null, null, null, null];
    }

    $it = $items[0];
    $modelId = $it['id'] ?? $it['modelId'] ?? null;
    $name = $it['name'] ?? ($it['titles']['raw'] ?? null);
    $description = $it['description'] ?? ($it['model']['description'] ?? null);

    $photo = $it['photo']['url'] ?? null;
    if (!$photo && !empty($it['photos'][0]['url'])) {
      $photo = $it['photos'][0]['url'];
    }
    if (!$photo && !empty($it['offer'])) {
      $off = $it['offer'];
      $photo = $off['photo']['url'] ?? ($off['photos'][0]['url'] ?? null);
      if (!$name) $name = $off['name'] ?? null;
      if (!$description) $description = $off['description'] ?? null;
    }

    ymlb_stage_log('market_api', 'info', [
      'stage' => 'search_by_slug_parse',
      'slug' => $slug,
      'geo_id' => $geoId,
      'model_id' => ($modelId ? (string)$modelId : ''),
      'name_found' => ($name ? 1 : 0),
      'photo_found' => ($photo ? 1 : 0),
      'description_found' => ($description ? 1 : 0),
    ]);

    return [
      $modelId ? (string)$modelId : null,
      $name ? (string)$name : null,
      $photo ? (string)$photo : null,
      $description ? (string)$description : null,
    ];
  }

  /**
   * ymlb_fetch_offers_photo_name()
   *
   * @param array<int,int> $geoList
   * @return array{0:?string,1:?string,2:?string}
   */
  function ymlb_fetch_offers_photo_name(string $modelId, string $authKey, array $geoList): array
  {
    $modelId = trim($modelId);
    $authKey = trim($authKey);
    if ($modelId === '' || $authKey === '') {
      ymlb_stage_log('market_api', 'warn', [
        'stage' => 'offers_validate',
        'model_id' => $modelId,
        'has_auth_key' => ($authKey !== '' ? 1 : 0),
        'reason' => 'model_or_auth_empty',
      ]);
      return [null, null, null];
    }

    $headers = [
      'Authorization: ' . $authKey,
      'Accept: application/json',
      'User-Agent: Mozilla/5.0',
    ];

    foreach ($geoList as $geo) {
      $geo = (int)$geo;
      if ($geo <= 0) continue;

      $url = "https://api.content.market.yandex.ru/v3/affiliate/models/{$modelId}/offers?geo_id={$geo}&count=10&fields=OFFER_PHOTO";
      ymlb_stage_log('market_api', 'info', [
        'stage' => 'offers_request',
        'model_id' => $modelId,
        'geo_id' => $geo,
        'url' => $url,
      ]);

      $resp = ymlb_http_json_debug($url, $headers);
      $json = (is_array($resp['json'] ?? null) ? (array)$resp['json'] : []);
      $offersProbe = $json['offers'] ?? null;
      $offersCount = is_array($offersProbe) ? count($offersProbe) : 0;

      ymlb_stage_log('market_api', (!empty($resp['ok']) ? 'info' : 'warn'), [
        'stage' => 'offers_response',
        'model_id' => $modelId,
        'geo_id' => $geo,
        'http_code' => (int)($resp['http_code'] ?? 0),
        'content_type' => (string)($resp['content_type'] ?? ''),
        'curl_error' => (string)($resp['curl_error'] ?? ''),
        'json_error' => (string)($resp['json_error'] ?? ''),
        'offers_count' => $offersCount,
        'body_preview' => (string)($resp['body_preview'] ?? ''),
      ]);

      if (empty($resp['ok']) || !$json) continue;
      $j = $json;

      $offers = $j['offers'] ?? null;
      if (!is_array($offers) || !$offers) continue;

      foreach ($offers as $off) {
        if (!is_array($off)) continue;
        $name = $off['name'] ?? ($off['model']['name'] ?? null);
        $description = $off['description'] ?? ($off['model']['description'] ?? null);
        $photo = $off['photo']['url'] ?? ($off['photos'][0]['url'] ?? null);
        if ($name || $photo || $description) {
          ymlb_stage_log('market_api', 'info', [
            'stage' => 'offers_parse',
            'model_id' => $modelId,
            'geo_id' => $geo,
            'name_found' => ($name ? 1 : 0),
            'photo_found' => ($photo ? 1 : 0),
            'description_found' => ($description ? 1 : 0),
          ]);
          return [
            $photo ? (string)$photo : null,
            $name ? (string)$name : null,
            $description ? (string)$description : null,
          ];
        }
      }
    }

    ymlb_stage_log('market_api', 'warn', [
      'stage' => 'offers_parse',
      'model_id' => $modelId,
      'reason' => 'offers_not_found',
    ]);

    return [null, null, null];
  }

  /**
   * ymlb_product_data()
   *
   * @return array<string,mixed>
   */
  function ymlb_product_data(string $url, string $affiliateApiKey, int $geoId): array
  {
    $resolved = market_url_resolve($url) ?: $url;
    $slug = market_url_extract_slug($resolved) ?? '';

    $name = null;
    $photo = null;
    $modelId = null;
    $description = null;
    $source = 'none';

    ymlb_stage_log('market_api', 'info', [
      'stage' => 'product_data_start',
      'source_url' => $url,
      'resolved_url' => $resolved,
      'slug' => $slug,
      'geo_id' => $geoId,
      'has_affiliate_key' => (trim($affiliateApiKey) !== '' ? 1 : 0),
    ]);

    if ($slug !== '' && trim($affiliateApiKey) !== '') {
      [$modelId, $name, $photo, $description] = ymlb_affiliate_search_by_slug($slug, $affiliateApiKey, $geoId);
      if ($modelId || $name || $photo || $description) {
        $source = 'affiliate_search';
      }
      if ($modelId && !$photo) {
        [$photo2, $name2, $description2] = ymlb_fetch_offers_photo_name((string)$modelId, $affiliateApiKey, [$geoId, 1, 2, 54]);
        if ($photo2) {
          $photo = $photo2;
          $source = ($source === 'none' ? 'offers_fallback' : 'affiliate_search+offers_fallback');
        }
        if (!$name && $name2) {
          $name = $name2;
          if ($source === 'none') $source = 'offers_fallback';
        }
        if (!$description && $description2) {
          $description = $description2;
          if ($source === 'none') $source = 'offers_fallback';
        }
      }
    } else {
      ymlb_stage_log('market_api', 'warn', [
        'stage' => 'product_data_start',
        'slug' => $slug,
        'geo_id' => $geoId,
        'reason' => ($slug === '' ? 'slug_empty' : 'affiliate_key_empty'),
      ]);
    }

    if (!$name || !$photo || !$description) {
      [$pageName, $pagePhoto, $pageDescription, $pageSource] = ymlb_market_page_data($resolved);
      if (!$name && $pageName) $name = $pageName;
      if (!$photo && $pagePhoto) $photo = $pagePhoto;
      if (!$description && $pageDescription) $description = $pageDescription;

      if ($pageSource !== 'none') {
        if ($source === 'none') {
          $source = 'page_' . $pageSource;
        } else {
          $source .= '+page_' . $pageSource;
        }
      }
    }

    ymlb_stage_log('market_api', 'info', [
      'stage' => 'product_data_result',
      'resolved_url' => $resolved,
      'slug' => $slug,
      'model_id' => ($modelId ? (string)$modelId : ''),
      'source' => $source,
      'name_found' => ($name ? 1 : 0),
      'photo_found' => ($photo ? 1 : 0),
      'description_found' => ($description ? 1 : 0),
      'photo_url' => ($photo ? (string)$photo : ''),
    ]);

    return [
      'resolved_url' => $resolved,
      'slug' => $slug,
      'model_id' => $modelId,
      'name' => $name,
      'photo' => $photo,
      'description' => $description,
    ];
  }

  /**
   * ymlb_photo_storage_root()
   */
  function ymlb_photo_storage_root(): string
  {
    return ROOT_PATH . '/storage/ym_link_bot/' . ymlb_module_code() . '/photos';
  }

  /**
   * ymlb_photo_public_root()
   */
  function ymlb_photo_public_root(): string
  {
    $base = ymlb_base_url();
    if ($base === '') return '';
    return rtrim($base, '/') . '/storage/ym_link_bot/' . ymlb_module_code() . '/photos';
  }

  /**
   * ymlb_save_photo()
   *
   * @return array{0:?string,1:?string,2:?string}
   */
  function ymlb_save_photo(string $url): array
  {
    $url = trim($url);
    if ($url === '') return [null, null, null];

    $opts = [
      'http' => [
        'method' => 'GET',
        'timeout' => 20,
        'header' => "User-Agent: Mozilla/5.0\r\nAccept: image/*,*/*;q=0.8\r\n",
      ],
      'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
      ],
    ];
    $context = stream_context_create($opts);
    $bin = @file_get_contents($url, false, $context);
    if ($bin === false || $bin === '') {
      return [null, null, null];
    }

    $ext = '.jpeg';
    if (function_exists('getimagesizefromstring')) {
      $info = @getimagesizefromstring($bin);
      $mime = (string)($info['mime'] ?? '');
      if ($mime === 'image/png') $ext = '.png';
      if ($mime === 'image/webp') $ext = '.webp';
      if ($mime === 'image/gif') $ext = '.gif';
      if ($mime === 'image/jpeg') $ext = '.jpeg';
    }

    $day = date('Y-m-d');
    $dir = ymlb_photo_storage_root() . '/' . $day;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
      return [null, null, null];
    }
    if (!is_writable($dir)) {
      return [null, null, null];
    }

    $name = 'photo_' . date('YmdHis') . '_' . bin2hex(random_bytes(2)) . $ext;
    $path = $dir . '/' . $name;
    $ok = @file_put_contents($path, $bin);
    if ($ok === false) {
      return [null, null, null];
    }

    $publicRoot = ymlb_photo_public_root();
    $public = ($publicRoot !== '')
      ? ($publicRoot . '/' . $day . '/' . $name)
      : null;

    return [$path, $public, $day];
  }

  /**
   * ymlb_photo_track()
   */
  function ymlb_photo_track(PDO $pdo, int $bindingId, string $sourceUrl, ?string $localPath, ?string $publicUrl, string $photoDate): void
  {
    if ($photoDate === '') return;
    ymlb_ensure_schema($pdo);
    $table = ymlb_qi(ymlb_table('photos'));
    $st = $pdo->prepare("
      INSERT INTO {$table}
      (binding_id, source_url, local_path, public_url, photo_date, created_at)
      VALUES
      (:binding_id, :source_url, :local_path, :public_url, :photo_date, :created_at)
    ");
    $st->execute([
      ':binding_id' => ($bindingId > 0 ? $bindingId : null),
      ':source_url' => $sourceUrl,
      ':local_path' => (string)($localPath ?? ''),
      ':public_url' => (string)($publicUrl ?? ''),
      ':photo_date' => $photoDate,
      ':created_at' => ymlb_now(),
    ]);
  }

  /**
   * ymlb_ord_create_erid()
   */
  function ymlb_ord_create_erid(string $clid, string $vid, string $oauthToken, string $textOrd, string $mediaLink): ?string
  {
    $clid = trim($clid);
    $vid = trim($vid);
    $oauthToken = trim($oauthToken);
    if ($clid === '' || $oauthToken === '') {
      ymlb_stage_log('ord_api', 'warn', [
        'stage' => 'validate',
        'reason' => 'clid_or_oauth_empty',
        'clid' => $clid,
      ]);
      return null;
    }
    if (!function_exists('curl_init')) {
      ymlb_stage_log('ord_api', 'error', [
        'stage' => 'validate',
        'reason' => 'curl_missing',
        'clid' => $clid,
      ]);
      return null;
    }

    $textOrd = trim($textOrd);
    if ($textOrd === '') $textOrd = 'Товар';

    $payload = [
      'clid' => $clid,
      'form' => 'text-graphic-block',
      'text_data' => [
        'Яндекс Маркет товар ' . $textOrd . '_' . $vid,
      ],
      'media_data' => [],
      'description' => $textOrd,
    ];
    if (trim($mediaLink) !== '') {
      $payload['media_data'][] = [
        'media_url' => $mediaLink,
        'media_url_file_type' => 'image',
        'description' => $textOrd,
      ];
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '') {
      ymlb_stage_log('ord_api', 'error', [
        'stage' => 'prepare_payload',
        'reason' => 'json_encode_failed',
        'clid' => $clid,
      ]);
      return null;
    }

    $ch = curl_init('https://distribution.yandex.net/api/v2/creatives/');
    if (!$ch) {
      ymlb_stage_log('ord_api', 'error', [
        'stage' => 'request',
        'reason' => 'curl_init_failed',
        'clid' => $clid,
      ]);
      return null;
    }

    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $json,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 20,
      CURLOPT_HTTPHEADER => [
        'Authorization: OAuth ' . $oauthToken,
        'Content-Type: application/json',
      ],
      CURLOPT_SSL_VERIFYPEER => false,
    ]);

    ymlb_stage_log('ord_api', 'info', [
      'stage' => 'request',
      'clid' => $clid,
      'has_media' => (trim($mediaLink) !== '' ? 1 : 0),
      'text_len' => strlen($textOrd),
      'payload_preview' => ymlb_log_excerpt($json, 2000),
    ]);

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = (string)curl_error($ch);
    curl_close($ch);

    if (!is_string($raw) || $raw === '' || $http < 200 || $http >= 300) {
      ymlb_stage_log('ord_api', 'warn', [
        'stage' => 'response',
        'reason' => 'http_or_body_invalid',
        'clid' => $clid,
        'http_code' => $http,
        'curl_error' => $curlErr,
        'raw_preview' => ymlb_log_excerpt((string)$raw, 2000),
      ]);
      return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
      ymlb_stage_log('ord_api', 'warn', [
        'stage' => 'response',
        'reason' => 'json_decode_failed',
        'clid' => $clid,
        'http_code' => $http,
        'raw_preview' => ymlb_log_excerpt((string)$raw, 2000),
      ]);
      return null;
    }

    $token = trim((string)($data['data']['token'] ?? ''));
    if ($token === '') {
      $token = trim((string)($data['token'] ?? ''));
    }
    if ($token === '') {
      ymlb_stage_log('ord_api', 'warn', [
        'stage' => 'response',
        'reason' => 'token_missing',
        'clid' => $clid,
        'raw_preview' => ymlb_log_excerpt((string)$raw, 2000),
      ]);
      return null;
    }
    ymlb_stage_log('ord_api', 'info', [
      'stage' => 'response',
      'reason' => 'token_ok',
      'clid' => $clid,
    ]);
    return ($token !== '') ? $token : null;
  }

  /**
   * ymlb_parse_query_string()
   *
   * @return array<string,string>
   */
  function ymlb_parse_query_string(string $query): array
  {
    $out = [];
    parse_str($query, $out);
    $norm = [];
    foreach ($out as $k => $v) {
      $key = trim((string)$k);
      if ($key === '') continue;
      $norm[$key] = is_scalar($v) ? (string)$v : '';
    }
    return $norm;
  }

  /**
   * ymlb_url_with_params()
   *
   * @param array<string,string> $params
   */
  function ymlb_url_with_params(string $url, array $params): string
  {
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
      return $url;
    }

    $existing = [];
    if (!empty($parts['query'])) {
      $existing = ymlb_parse_query_string((string)$parts['query']);
    }

    foreach ($params as $k => $v) {
      $k = trim((string)$k);
      if ($k === '') continue;
      $existing[$k] = (string)$v;
    }

    $query = http_build_query($existing, '', '&', PHP_QUERY_RFC3986);
    $scheme = (string)$parts['scheme'];
    $host = (string)$parts['host'];
    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
    $path = (string)($parts['path'] ?? '');
    $frag = isset($parts['fragment']) ? '#' . (string)$parts['fragment'] : '';

    return $scheme . '://' . $host . $port . $path . ($query !== '' ? ('?' . $query) : '') . $frag;
  }

  /**
   * ymlb_build_final_link()
   */
  function ymlb_build_final_link(string $cleanUrl, string $clid, string $vid, bool $forceHow, ?string $erid, string $staticParams): string
  {
    $params = ymlb_parse_query_string($staticParams);
    $params['clid'] = $clid;
    $params['vid'] = $vid;
    if ($forceHow) $params['how'] = 'aprice';
    if ($erid !== null && trim($erid) !== '') {
      $params['erid'] = trim($erid);
    }
    return ymlb_url_with_params($cleanUrl, $params);
  }

  /**
   * ymlb_tg_escape()
   */
  function ymlb_tg_escape(string $text): string
  {
    return str_replace(
      ['&', '<', '>'],
      ['&amp;', '&lt;', '&gt;'],
      $text
    );
  }

  /**
   * ymlb_update_register_once()
   */
  function ymlb_update_register_once(PDO $pdo, int $updateId, string $chatId, string $eventType, array $payload, string $webhookScope = 'main'): bool
  {
    if ($updateId <= 0) return true;
    ymlb_ensure_schema($pdo);
    $webhookScope = strtolower(trim($webhookScope));
    if ($webhookScope !== 'chat') $webhookScope = 'main';
    $table = ymlb_qi(ymlb_table('updates'));
    $st = $pdo->prepare("
      INSERT IGNORE INTO {$table}
      (webhook_scope, update_id, chat_id, event_type, status, error_text, payload_json, created_at, updated_at)
      VALUES
      (:webhook_scope, :update_id, :chat_id, :event_type, 'received', '', :payload_json, :created_at, :updated_at)
    ");
    $st->execute([
      ':webhook_scope' => $webhookScope,
      ':update_id' => $updateId,
      ':chat_id' => $chatId,
      ':event_type' => $eventType,
      ':payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ':created_at' => ymlb_now(),
      ':updated_at' => ymlb_now(),
    ]);

    return ($st->rowCount() > 0);
  }

  /**
   * ymlb_update_finish()
   */
  function ymlb_update_finish(PDO $pdo, int $updateId, string $status, string $error = '', string $webhookScope = 'main'): void
  {
    if ($updateId <= 0) return;
    ymlb_ensure_schema($pdo);
    $webhookScope = strtolower(trim($webhookScope));
    if ($webhookScope !== 'chat') $webhookScope = 'main';
    $table = ymlb_qi(ymlb_table('updates'));
    $st = $pdo->prepare("
      UPDATE {$table}
      SET status = :status, error_text = :error_text, updated_at = :updated_at
      WHERE webhook_scope = :webhook_scope
        AND update_id = :update_id
      LIMIT 1
    ");
    $st->execute([
      ':status' => substr(trim($status), 0, 32),
      ':error_text' => $error,
      ':updated_at' => ymlb_now(),
      ':webhook_scope' => $webhookScope,
      ':update_id' => $updateId,
    ]);
  }

  /**
   * ymlb_process_private_message()
   *
   * @param array<string,mixed> $message
   * @param array<string,mixed> $settings
   * @return array<string,mixed>
   */
  function ymlb_process_private_message(PDO $pdo, array $settings, array $message): array
  {
    $chat = (array)($message['chat'] ?? []);
    $from = (array)($message['from'] ?? []);
    $text = trim((string)($message['text'] ?? ''));
    $chatId = trim((string)($chat['id'] ?? ''));
    $fromId = trim((string)($from['id'] ?? ''));
    $token = trim((string)($settings['bot_token'] ?? ''));

    if ($chatId === '' || $fromId === '') {
      return ['handled' => false, 'reason' => 'private_missing_ids'];
    }

    $binding = ymlb_binding_find_active_by_tg_user($pdo, $fromId);

    if ($text === '/id' || $text === '/whoami') {
      if ($token !== '') {
        tg_send_message($token, $chatId, 'Your Telegram ID: ' . $fromId);
      }
      return ['handled' => true, 'reason' => 'id_sent'];
    }

    if (preg_match('~^/start\b~u', $text)) {
      if ($token !== '') {
        if ($binding) {
          tg_send_message($token, $chatId, 'Binding found. Send links in bound channel/chat for processing.');
        } else {
          tg_send_message($token, $chatId, 'No active binding for your Telegram account. Ask admin to link your CRM user.');
        }
      }
      return ['handled' => true, 'reason' => ($binding ? 'start_allowed' : 'start_denied')];
    }

    if (!$binding) {
      if ($token !== '' && $text !== '') {
        tg_send_message($token, $chatId, 'No binding found. Send /id and ask admin to bind this Telegram ID.');
      }
      return ['handled' => true, 'reason' => 'private_unbound'];
    }

    if ($token !== '' && $text !== '') {
      tg_send_message($token, $chatId, 'Send links in your configured channel/chat. Use /id to see Telegram ID.');
    }

    return ['handled' => true, 'reason' => 'private_help_sent'];
  }

  /**
   * ymlb_process_chat_message()
   * Handles group/supergroup messages for chat-mode link processing.
   *
   * @param array<string,mixed> $message
   * @param array<string,mixed> $settings
   * @return array<string,mixed>
   */
  function ymlb_process_chat_message(PDO $pdo, array $settings, array $message): array
  {
    $chat = (array)($message['chat'] ?? []);
    $chatType = strtolower(trim((string)($chat['type'] ?? '')));
    if ($chatType !== 'group' && $chatType !== 'supergroup') {
      return ['handled' => false, 'reason' => 'chat_type_not_supported'];
    }

    return ymlb_process_channel_post($pdo, $settings, $message, 'chat');
  }

  /**
   * ymlb_process_channel_post()
   *
   * @param array<string,mixed> $channelPost
   * @param array<string,mixed> $settings
   * @return array<string,mixed>
   */
  function ymlb_process_channel_post(PDO $pdo, array $settings, array $channelPost, string $targetKind = 'channel'): array
  {
    $chat = (array)($channelPost['chat'] ?? []);
    $chatId = trim((string)($chat['id'] ?? ''));
    $chatUsername = ltrim(trim((string)($chat['username'] ?? '')), '@');
    $chatTitle = trim((string)($chat['title'] ?? ''));
    $text = trim((string)($channelPost['text'] ?? ($channelPost['caption'] ?? '')));
    $token = trim((string)($settings['bot_token'] ?? ''));
    $targetKind = strtolower(trim($targetKind));
    if ($targetKind !== 'chat') $targetKind = 'channel';

    if ($chatId === '') {
      ymlb_stage_log('pipeline_channel', 'warn', [
        'stage' => 'validate_input',
        'reason' => 'channel_chat_missing',
      ]);
      return ['handled' => false, 'reason' => 'channel_chat_missing'];
    }
    if ($text === '') {
      ymlb_stage_log('pipeline_channel', 'warn', [
        'stage' => 'validate_input',
        'chat_id' => $chatId,
        'reason' => 'channel_text_empty',
      ]);
      return ['handled' => false, 'reason' => 'channel_text_empty'];
    }

    ymlb_stage_log('pipeline_channel', 'info', [
      'stage' => 'start',
      'target_kind' => $targetKind,
      'chat_id' => $chatId,
      'chat_username' => $chatUsername,
      'chat_title' => $chatTitle,
      'text_len' => strlen($text),
    ]);

    $confirm = ($targetKind === 'chat')
      ? ymlb_chat_confirm_from_text($pdo, $text, $chatId, $chatUsername, $chatTitle)
      : ymlb_channel_confirm_from_text($pdo, $text, $chatId, $chatUsername, $chatTitle);
    if (is_array($confirm)) {
      ymlb_stage_log('pipeline_channel', 'info', [
        'stage' => 'channel_confirmed',
        'target_kind' => $targetKind,
        'chat_id' => $chatId,
        'channel_id' => (int)($confirm['channel_id'] ?? 0),
        'binding_id' => (int)($confirm['binding_id'] ?? 0),
      ]);
      if ($token !== '') {
        $okText = ($targetKind === 'chat')
          ? 'Chat confirmed and linked.'
          : 'Channel confirmed and linked.';
        tg_send_message($token, $chatId, $okText);
      }
      return ['handled' => true, 'reason' => 'channel_confirmed', 'channel_id' => (int)($confirm['channel_id'] ?? 0)];
    }

    if ((int)($settings['chat_mode_enabled'] ?? 1) !== 1) {
      ymlb_stage_log('pipeline_channel', 'info', [
        'stage' => 'precheck',
        'target_kind' => $targetKind,
        'chat_id' => $chatId,
        'reason' => 'chat_mode_disabled',
      ]);
      return ['handled' => false, 'reason' => 'chat_mode_disabled'];
    }

    $channels = ($targetKind === 'chat')
      ? ymlb_chats_find_active_by_chat($pdo, $chatId)
      : ymlb_channels_find_active_by_chat($pdo, $chatId);
    if (!$channels) {
      ymlb_stage_log('pipeline_channel', 'warn', [
        'stage' => 'find_channel',
        'target_kind' => $targetKind,
        'chat_id' => $chatId,
        'reason' => 'channel_not_registered',
      ]);
      return ['handled' => false, 'reason' => 'channel_not_registered'];
    }
    ymlb_stage_log('pipeline_channel', 'info', [
      'stage' => 'find_channel',
      'target_kind' => $targetKind,
      'chat_id' => $chatId,
      'channel_rows' => count($channels),
    ]);

    $activeBindingsAll = ymlb_bindings_active_map($pdo);
    if (!$activeBindingsAll) {
      ymlb_stage_log('pipeline_channel', 'warn', [
        'stage' => 'resolve_binding',
        'target_kind' => $targetKind,
        'chat_id' => $chatId,
        'reason' => 'bindings_empty',
      ]);
      return ['handled' => false, 'reason' => 'bindings_empty'];
    }

    $activeBindings = $activeBindingsAll;
    if ($targetKind === 'chat') {
      $chatRow = isset($channels[0]) && is_array($channels[0]) ? (array)$channels[0] : [];
      $chatBindingId = (int)($chatRow['binding_id'] ?? 0);
      if ($chatBindingId <= 0) {
        ymlb_stage_log('pipeline_channel', 'warn', [
          'stage' => 'resolve_binding',
          'target_kind' => $targetKind,
          'chat_id' => $chatId,
          'reason' => 'chat_binding_missing',
        ]);
        return ['handled' => false, 'reason' => 'chat_binding_missing'];
      }
      if (!isset($activeBindingsAll[$chatBindingId])) {
        ymlb_stage_log('pipeline_channel', 'warn', [
          'stage' => 'resolve_binding',
          'target_kind' => $targetKind,
          'chat_id' => $chatId,
          'binding_id' => $chatBindingId,
          'reason' => 'chat_binding_inactive',
        ]);
        return ['handled' => true, 'reason' => 'chat_binding_inactive'];
      }
      if (count($channels) > 1) {
        ymlb_stage_log('pipeline_channel', 'warn', [
          'stage' => 'resolve_binding',
          'target_kind' => $targetKind,
          'chat_id' => $chatId,
          'reason' => 'multiple_chat_rows_detected',
          'channel_rows' => count($channels),
          'selected_binding_id' => $chatBindingId,
        ]);
      }
      $activeBindings = [$chatBindingId => $activeBindingsAll[$chatBindingId]];
    }
    ymlb_stage_log('pipeline_channel', 'info', [
      'stage' => 'resolve_binding',
      'target_kind' => $targetKind,
      'chat_id' => $chatId,
      'binding_ids' => array_values(array_map('intval', array_keys($activeBindings))),
      'active_bindings_count' => count($activeBindings),
    ]);

    $bindingIds = array_values(array_map('intval', array_keys($activeBindings)));
    $bindingId = (int)($bindingIds[0] ?? 0); // compatibility id for shared logs

    $startUrl = market_url_extract_first($text);
    if ($startUrl === null) {
      ymlb_stage_log('pipeline_channel', 'warn', [
        'stage' => 'extract_url',
        'chat_id' => $chatId,
        'binding_id' => $bindingId,
        'reason' => 'url_not_found',
      ]);
      return ['handled' => false, 'reason' => 'url_not_found'];
    }

    ymlb_stage_log('pipeline_channel', 'info', [
      'stage' => 'extract_url',
      'chat_id' => $chatId,
      'binding_id' => $bindingId,
      'source_url' => $startUrl,
    ]);

    if (!ymlb_is_market_url($startUrl)) {
      ymlb_stage_log('pipeline_channel', 'info', [
        'stage' => 'extract_url',
        'target_kind' => $targetKind,
        'chat_id' => $chatId,
        'binding_id' => $bindingId,
        'reason' => 'non_market_url_ignored',
        'source_url' => $startUrl,
      ]);
      return ['handled' => false, 'reason' => 'non_market_url_ignored'];
    }

    $resolvedRaw = market_url_resolve($startUrl);
    $url1 = ($resolvedRaw ?: $startUrl);
    if (!ymlb_is_market_url($url1)) {
      ymlb_stage_log('pipeline_channel', 'info', [
        'stage' => 'extract_url',
        'target_kind' => $targetKind,
        'chat_id' => $chatId,
        'binding_id' => $bindingId,
        'reason' => 'resolved_non_market_url_ignored',
        'source_url' => $startUrl,
        'resolved_url' => $url1,
      ]);
      return ['handled' => false, 'reason' => 'resolved_non_market_url_ignored'];
    }

    if ($targetKind === 'chat') {
      $chatSites = ymlb_sites_by_binding($pdo, $bindingId, true);
      $chatClids = [];
      foreach ($chatSites as $chatSite) {
        $siteClid = trim((string)($chatSite['clid'] ?? ''));
        if ($siteClid !== '') $chatClids[] = $siteClid;
      }

      if ($chatClids && ymlb_url_has_clid_from_list($url1, $chatClids)) {
        ymlb_stage_log('pipeline_channel', 'info', [
          'stage' => 'extract_url',
          'target_kind' => $targetKind,
          'chat_id' => $chatId,
          'binding_id' => $bindingId,
          'reason' => 'own_clid_detected_skip',
          'resolved_url' => $url1,
          'url_clid' => ymlb_url_query_clid($url1),
        ]);
        return ['handled' => false, 'reason' => 'own_clid_detected_skip'];
      }
    }

    if (ymlb_is_market_short_url($startUrl) && ymlb_is_market_short_url($url1)) {
      ymlb_stage_log('pipeline_channel', 'warn', [
        'stage' => 'extract_url',
        'chat_id' => $chatId,
        'binding_id' => $bindingId,
        'reason' => 'short_url_unresolved',
        'source_url' => $startUrl,
        'resolved_url' => $url1,
      ]);
      if ($token !== '') {
        tg_send_message($token, $chatId, 'Не удалось автоматически раскрыть короткую ссылку. Пожалуйста, отправьте ссылку на товар.');
      }
      return ['handled' => true, 'reason' => 'short_url_unresolved'];
    }

    if ($targetKind === 'chat' && $token !== '') {
      $sourceMessageId = (int)($channelPost['message_id'] ?? 0);
      if ($sourceMessageId > 0) {
        $deleteResult = tg_request($token, 'deleteMessage', [
          'chat_id' => $chatId,
          'message_id' => $sourceMessageId,
        ]);
        ymlb_stage_log('pipeline_channel', (!empty($deleteResult['ok']) ? 'info' : 'warn'), [
          'stage' => 'delete_source_message',
          'target_kind' => $targetKind,
          'chat_id' => $chatId,
          'binding_id' => $bindingId,
          'message_id' => $sourceMessageId,
          'tg_ok' => !empty($deleteResult['ok']) ? 1 : 0,
          'tg_error_code' => (int)($deleteResult['error_code'] ?? 0),
          'tg_description' => (string)($deleteResult['description'] ?? ''),
        ]);
      }
    }

    $url2 = ymlb_url_without_query($url1);
    if ($url2 === '') $url2 = $url1;

    $manualClean = market_url_cleanup($url1, ymlb_drop_query_keys()) ?: $url1;
    $forceHow = ymlb_should_keep_aprice($url1);
    $affiliateApiKey = trim((string)($settings['affiliate_api_key'] ?? ''));
    $linkStaticParams = (string)($settings['link_static_params'] ?? '');

    ymlb_stage_log('pipeline_channel', 'info', [
      'stage' => 'clean_url',
      'chat_id' => $chatId,
      'binding_id' => $bindingId,
      'url1_resolved' => $url1,
      'url2_clean' => $url2,
      'manual_clean_url' => $manualClean,
      'force_how' => $forceHow ? 1 : 0,
    ]);

    $product = ymlb_product_data(
      $url2,
      $affiliateApiKey,
      (int)($settings['geo_id'] ?? 213)
    );

    $productName = trim((string)($product['name'] ?? ''));
    if ($productName === '') $productName = 'Товар';
    $productPhoto = trim((string)($product['photo'] ?? ''));
    $productDescription = trim((string)($product['description'] ?? ''));

    ymlb_stage_log('pipeline_channel', 'info', [
      'stage' => 'market_product',
      'chat_id' => $chatId,
      'binding_id' => $bindingId,
      'model_id' => (string)($product['model_id'] ?? ''),
      'product_name' => $productName,
      'description_preview' => (function_exists('mb_substr') ? (string)mb_substr($productDescription, 0, 180) : substr($productDescription, 0, 180)),
      'photo_url' => $productPhoto,
      'name_found' => ($productName !== '' ? 1 : 0),
      'description_found' => ($productDescription !== '' ? 1 : 0),
      'photo_found' => ($productPhoto !== '' ? 1 : 0),
    ]);

    $photoLocal = null;
    $photoPublic = null;
    $photoDay = null;
    if ($productPhoto !== '') {
      [$photoLocal, $photoPublic, $photoDay] = ymlb_save_photo($productPhoto);
      if ($photoDay !== null) {
        ymlb_photo_track($pdo, $bindingId, $productPhoto, $photoLocal, $photoPublic, $photoDay);
        ymlb_stage_log('pipeline_channel', 'info', [
          'stage' => 'photo_saved',
          'chat_id' => $chatId,
          'binding_id' => $bindingId,
          'photo_date' => $photoDay,
          'has_public_url' => ($photoPublic !== null ? 1 : 0),
        ]);
      } else {
        ymlb_stage_log('pipeline_channel', 'warn', [
          'stage' => 'photo_saved',
          'chat_id' => $chatId,
          'binding_id' => $bindingId,
          'reason' => 'save_failed',
          'source_photo_url' => $productPhoto,
        ]);
      }
    } else {
      ymlb_stage_log('pipeline_channel', 'warn', [
        'stage' => 'photo_saved',
        'chat_id' => $chatId,
        'binding_id' => $bindingId,
        'reason' => 'photo_url_empty',
      ]);
    }

    $vid = date('dmyHis');
    $ordEnabled = (defined('YM_LINK_BOT_ORD_ENABLED') ? (bool)YM_LINK_BOT_ORD_ENABLED : true);
    $descriptionForReply = ymlb_clean_market_description(
      ymlb_strip_emoji(trim($productDescription))
    );
    $titleFromUrlForReply = ymlb_title_from_url($url2);
    if ($descriptionForReply !== '') {
      if (function_exists('mb_substr')) {
        $descriptionForReply = (string)mb_substr($descriptionForReply, 0, 700);
      } else {
        $descriptionForReply = substr($descriptionForReply, 0, 700);
      }
    }
    $replyText = ($descriptionForReply !== '' ? $descriptionForReply : $titleFromUrlForReply);
    $ordText = trim($replyText !== '' ? $replyText : $productName);
    if ($ordText === '') $ordText = 'Товар';
    if (function_exists('mb_substr')) {
      $ordText = (string)mb_substr($ordText, 0, 250);
    } else {
      $ordText = substr($ordText, 0, 250);
    }

    $rows = [];
    $hasAnySites = false;
    $eridFailed = false;
    $eridFailedBindingId = 0;
    $eridFailedSite = 0;
    $eridFailedClid = '';
    $eridFailedOauthLikelyExpired = false;
    $eridFailedOauthAgeDays = -1;

    foreach ($activeBindings as $bindingId => $binding) {
      $sites = ymlb_sites_by_binding($pdo, (int)$bindingId, true);
      if ($targetKind === 'chat' && is_array($sites) && count($sites) > 1) {
        ymlb_stage_log('pipeline_channel', 'info', [
          'stage' => 'sites_loaded',
          'chat_id' => $chatId,
          'binding_id' => (int)$bindingId,
          'reason' => 'chat_single_site_mode',
          'sites_total' => count($sites),
          'selected_site_id' => (int)($sites[0]['id'] ?? 0),
        ]);
        $sites = [(array)$sites[0]];
      }
      ymlb_stage_log('pipeline_channel', 'info', [
        'stage' => 'sites_loaded',
        'chat_id' => $chatId,
        'binding_id' => (int)$bindingId,
        'sites_count' => is_array($sites) ? count($sites) : 0,
      ]);
      if (!$sites) {
        ymlb_stage_log('pipeline_channel', 'warn', [
          'stage' => 'sites_loaded',
          'chat_id' => $chatId,
          'binding_id' => (int)$bindingId,
          'reason' => 'sites_empty',
        ]);
        continue;
      }
      $hasAnySites = true;

      $crmUserId = (int)($binding['crm_user_id'] ?? 0);
      $oauthResolved = ymlb_binding_oauth_resolve($pdo, $binding);
      $oauth = trim((string)($oauthResolved['oauth_token'] ?? ''));
      $oauthReceivedAt = (string)($oauthResolved['oauth_token_received_at'] ?? '');
      $oauthAgeDays = $oauthResolved['oauth_age_days'];
      $oauthLikelyExpired = ((int)($oauthResolved['oauth_likely_expired'] ?? 0) === 1);
      ymlb_stage_log('pipeline_channel', ($oauth !== '' ? 'info' : 'warn'), [
        'stage' => 'oauth_resolve',
        'chat_id' => $chatId,
        'binding_id' => (int)$bindingId,
        'crm_user_id' => $crmUserId,
        'oauth_mode' => (string)($oauthResolved['oauth_mode'] ?? 'auto'),
        'oauth_token_id' => (int)($oauthResolved['oauth_token_id'] ?? 0),
        'oauth_ready' => ($oauth !== '' ? 1 : 0),
        'token_received_at' => $oauthReceivedAt,
        'oauth_age_days' => ($oauthAgeDays ?? -1),
        'oauth_likely_expired' => ($oauthLikelyExpired ? 1 : 0),
      ]);

      foreach ($sites as $site) {
      $clid = trim((string)($site['clid'] ?? ''));
      if ($clid === '') {
        ymlb_stage_log('pipeline_channel', 'warn', [
          'stage' => 'site_iterate',
          'chat_id' => $chatId,
          'binding_id' => $bindingId,
          'site_id' => (int)($site['id'] ?? 0),
          'reason' => 'clid_empty',
        ]);
        continue;
      }
      $siteName = trim((string)($site['name'] ?? 'Площадка'));
      $baseLink = $manualClean;
      $linkMode = 'fallback_manual';
      $partnerReason = '';
      if ($affiliateApiKey === '') {
        $partnerReason = 'affiliate_api_key_empty';
      } else {
        $partner = ymlb_affiliate_partner_link_create($url2, $affiliateApiKey, $clid);
        if (!is_array($partner)) {
          $partnerReason = 'partner_not_created';
        } else {
          $partnerUrl = trim((string)($partner['url'] ?? ''));
          if ($partnerUrl !== '' && ymlb_is_market_url($partnerUrl)) {
            $baseLink = $partnerUrl;
            $linkMode = 'partner_url3';
            $partnerReason = 'ok';
          } else {
            $partnerReason = 'partner_url_invalid';
          }
        }
      }

      if ($linkMode !== 'partner_url3') {
        ymlb_stage_log('pipeline_channel', 'warn', [
          'stage' => 'partner_link',
          'chat_id' => $chatId,
          'binding_id' => $bindingId,
          'site_id' => (int)($site['id'] ?? 0),
          'clid' => $clid,
          'reason' => $partnerReason,
          'url1_resolved' => $url1,
          'url2_clean' => $url2,
          'manual_clean_url' => $manualClean,
        ]);
      } else {
        ymlb_stage_log('pipeline_channel', 'info', [
          'stage' => 'partner_link',
          'chat_id' => $chatId,
          'binding_id' => $bindingId,
          'site_id' => (int)($site['id'] ?? 0),
          'clid' => $clid,
          'reason' => 'ok',
        ]);
      }

      $erid = null;
      if ($ordEnabled) {
        $erid = ymlb_ord_create_erid(
          $clid,
          $vid,
          $oauth,
          $ordText,
          (string)($photoPublic ?: $productPhoto)
        );
        if (!$erid) {
          $eridFailed = true;
          $eridFailedBindingId = (int)$bindingId;
          $eridFailedSite = (int)($site['id'] ?? 0);
          $eridFailedClid = $clid;
          $eridFailedOauthLikelyExpired = $oauthLikelyExpired;
          $eridFailedOauthAgeDays = ($oauthAgeDays ?? -1);
        }
      } else {
        ymlb_stage_log('pipeline_channel', 'info', [
          'stage' => 'ord_create_erid',
          'chat_id' => $chatId,
          'binding_id' => $bindingId,
          'site_id' => (int)($site['id'] ?? 0),
          'clid' => $clid,
          'erid_created' => 0,
          'reason' => 'ord_disabled_by_config',
        ]);
      }

      if ($ordEnabled) {
        ymlb_stage_log('pipeline_channel', ($erid ? 'info' : 'warn'), [
          'stage' => 'ord_create_erid',
          'chat_id' => $chatId,
          'binding_id' => $bindingId,
          'site_id' => (int)($site['id'] ?? 0),
          'clid' => $clid,
          'erid_created' => ($erid ? 1 : 0),
        ]);
        if ($eridFailed) {
          break;
        }
      }

      if ($linkMode === 'partner_url3') {
        $final = $baseLink;
        if ($erid !== null && trim($erid) !== '') {
          $final = ymlb_url_with_params($final, ['erid' => trim($erid)]);
        }
      } else {
        $final = ymlb_build_final_link(
          $baseLink,
          $clid,
          $vid,
          $forceHow,
          $erid,
          $linkStaticParams
        );
      }

      if ($targetKind === 'chat') {
        $rowText = $final;
      } else {
        $rowText = '<b>' . ymlb_tg_escape($siteName) . "</b>\n" . $final;
      }
      $rows[] = $rowText;

      ymlb_stage_log('pipeline_channel', 'info', [
        'stage' => 'final_link',
        'chat_id' => $chatId,
        'binding_id' => $bindingId,
        'site_id' => (int)($site['id'] ?? 0),
        'clid' => $clid,
        'has_erid' => ($erid ? 1 : 0),
        'link_mode' => $linkMode,
        'partner_reason' => $partnerReason,
        'message_mode' => 'link_only',
        'reply_text_source' => ($descriptionForReply !== '' ? 'description' : ($titleFromUrlForReply !== '' ? 'url_title' : 'none')),
      ]);
      }
      if ($ordEnabled && $eridFailed) {
        break;
      }
    }

    if (!$hasAnySites) {
      if ($token !== '') {
        tg_send_message($token, $chatId, 'Для вашего кабинета (CLID) не настроены активные площадки.');
      }
      return ['handled' => true, 'reason' => 'sites_empty'];
    }

    if ($ordEnabled && $eridFailed) {
      ymlb_stage_log('pipeline_channel', 'warn', [
        'stage' => 'final_link',
        'chat_id' => $chatId,
        'binding_id' => $eridFailedBindingId,
        'reason' => 'erid_not_received',
        'site_id' => $eridFailedSite,
        'clid' => $eridFailedClid,
        'oauth_likely_expired' => ($eridFailedOauthLikelyExpired ? 1 : 0),
        'oauth_age_days' => $eridFailedOauthAgeDays,
      ]);
      if ($token !== '') {
        if ($eridFailedOauthLikelyExpired) {
          $msg = 'Ошибка ERID: OAuth-ключ, вероятно, закончился. Обновите OAuth и повторите.';
        } else {
          $msg = 'Ошибка ERID: не удалось получить ERID. Проверьте OAuth-ключ и повторите.';
        }
        tg_send_message($token, $chatId, $msg);
      }
      return ['handled' => true, 'reason' => 'erid_not_received'];
    }

    if (!$rows) {
      ymlb_stage_log('pipeline_channel', 'error', [
        'stage' => 'final_link',
        'chat_id' => $chatId,
        'binding_id' => $bindingId,
        'reason' => 'links_not_built',
      ]);
      return ['handled' => false, 'reason' => 'links_not_built'];
    }

    if ($token !== '') {
      if ($targetKind === 'chat') {
        $chatHeader = '?????? ?? ??????????';
        $chatBody = $chatHeader . "\n" . implode("\n\n", $rows);
        $photoForSend = trim((string)($photoPublic ?: $productPhoto));

        if ($photoForSend !== '') {
          $sendResult = tg_request($token, 'sendPhoto', [
            'chat_id' => $chatId,
            'photo' => $photoForSend,
            'caption' => $chatBody,
          ]);
          if (empty($sendResult['ok'])) {
            $sendResult = tg_send_message($token, $chatId, $chatBody, [
              'disable_web_page_preview' => true,
            ]);
          }
        } else {
          $sendResult = tg_send_message($token, $chatId, $chatBody, [
            'disable_web_page_preview' => true,
          ]);
        }
      } else {
        $sendResult = tg_send_message($token, $chatId, implode("\n\n", $rows), [
          'parse_mode' => 'HTML',
          'disable_web_page_preview' => true,
        ]);
      }
      ymlb_stage_log('pipeline_channel', (!empty($sendResult['ok']) ? 'info' : 'warn'), [
        'stage' => 'send_result',
        'target_kind' => $targetKind,
        'chat_id' => $chatId,
        'binding_id' => $bindingId,
        'links_count' => count($rows),
        'tg_ok' => !empty($sendResult['ok']) ? 1 : 0,
        'tg_error_code' => (int)($sendResult['error_code'] ?? 0),
      ]);
    } else {
      ymlb_stage_log('pipeline_channel', 'warn', [
        'stage' => 'send_result',
        'chat_id' => $chatId,
        'binding_id' => $bindingId,
        'reason' => 'bot_token_empty',
        'links_count' => count($rows),
      ]);
    }

    ymlb_stage_log('pipeline_channel', 'info', [
      'stage' => 'done',
      'chat_id' => $chatId,
      'binding_id' => $bindingId,
      'reason' => 'links_sent',
      'links_count' => count($rows),
    ]);

    return [
      'handled' => true,
      'reason' => 'links_sent',
      'links_count' => count($rows),
    ];
  }

  /**
   * ymlb_webhook_process_core()
   *
   * @return array<string,mixed>
   */
  function ymlb_webhook_process_core(PDO $pdo, array $settings, string $scope = 'main'): array
  {
    $scope = strtolower(trim($scope));
    if ($scope !== 'chat') $scope = 'main';

    ymlb_ensure_schema($pdo);
    $tokenKey = 'bot_token';
    $secretKey = 'webhook_secret';
    $token = trim((string)($settings[$tokenKey] ?? ''));
    $secret = trim((string)($settings[$secretKey] ?? ''));

    ymlb_stage_log('webhook', 'info', [
      'stage' => 'start',
      'scope' => $scope,
      'enabled' => (int)($settings['enabled'] ?? 0),
      'has_bot_token' => ($token !== '' ? 1 : 0),
    ]);

    if ((int)($settings['enabled'] ?? 0) !== 1) {
      ymlb_stage_log('webhook', 'warn', [
        'stage' => 'precheck',
        'scope' => $scope,
        'reason' => 'disabled',
      ]);
      return [
        'ok' => false,
        'http' => 503,
        'reason' => 'disabled',
        'message' => 'Bot disabled',
      ];
    }

    if ((int)($settings['chat_mode_enabled'] ?? 1) !== 1) {
      ymlb_stage_log('webhook', 'warn', [
        'stage' => 'precheck',
        'scope' => $scope,
        'reason' => 'chat_mode_disabled',
      ]);
      return [
        'ok' => false,
        'http' => 503,
        'reason' => 'chat_mode_disabled',
        'message' => 'Chat mode disabled',
      ];
    }

    if ($token === '') {
      ymlb_stage_log('webhook', 'warn', [
        'stage' => 'precheck',
        'scope' => $scope,
        'reason' => 'bot_token_empty',
      ]);
      return [
        'ok' => false,
        'http' => 503,
        'reason' => 'bot_token_empty',
        'message' => 'Bot token empty',
      ];
    }

    if (!tg_verify_webhook_secret($secret)) {
      ymlb_stage_log('webhook', 'warn', [
        'stage' => 'precheck',
        'scope' => $scope,
        'reason' => 'bad_secret',
      ]);
      return [
        'ok' => false,
        'http' => 403,
        'reason' => 'bad_secret',
        'message' => 'Forbidden',
      ];
    }

    $update = tg_read_update();
    if (!$update) {
      ymlb_stage_log('webhook', 'info', [
        'stage' => 'read_update',
        'scope' => $scope,
        'reason' => 'empty_update',
      ]);
      return [
        'ok' => true,
        'http' => 200,
        'handled' => false,
        'reason' => 'empty_update',
      ];
    }

    $meta = tg_extract_update_meta($update);
    $updateId = (int)($update['update_id'] ?? 0);
    $chatId = (string)($meta['chat_id'] ?? '');
    $eventType = (string)($meta['type'] ?? 'unknown');
    ymlb_stage_log('webhook', 'info', [
      'stage' => 'meta',
      'scope' => $scope,
      'update_id' => $updateId,
      'chat_id' => $chatId,
      'event_type' => $eventType,
    ]);

    $isNew = ymlb_update_register_once($pdo, $updateId, $chatId, $eventType, $update, $scope);
    if (!$isNew) {
      ymlb_stage_log('webhook', 'info', [
        'stage' => 'dedupe',
        'scope' => $scope,
        'reason' => 'duplicate_update',
        'update_id' => $updateId,
      ]);
      return [
        'ok' => true,
        'http' => 200,
        'handled' => false,
        'reason' => 'duplicate_update',
        'update_id' => $updateId,
      ];
    }

    try {
      ymlb_stage_log('webhook', 'info', [
        'stage' => 'dispatch',
        'scope' => $scope,
        'update_id' => $updateId,
        'event_type' => $eventType,
      ]);

      if ($scope === 'chat') {
        if (isset($update['message']) && is_array($update['message'])) {
          $msg = (array)$update['message'];
          $chatType = strtolower(trim((string)(($msg['chat'] ?? [])['type'] ?? '')));
          if ($chatType === 'private') {
            $result = ymlb_process_private_message($pdo, $settings, $msg);
          } else {
            $result = ymlb_process_chat_message($pdo, $settings, $msg);
          }
        } else {
          $result = ['handled' => false, 'reason' => 'update_type_ignored'];
        }
      } else {
        if (isset($update['channel_post']) && is_array($update['channel_post'])) {
          $result = ymlb_process_channel_post($pdo, $settings, (array)$update['channel_post'], 'channel');
        } elseif (isset($update['message']) && is_array($update['message'])) {
          $msg = (array)$update['message'];
          $chatType = strtolower(trim((string)(($msg['chat'] ?? [])['type'] ?? '')));
          if ($chatType === 'group' || $chatType === 'supergroup') {
            $result = ymlb_process_chat_message($pdo, $settings, $msg);
          } else {
            $result = ymlb_process_private_message($pdo, $settings, $msg);
          }
        } else {
          $result = ['handled' => false, 'reason' => 'update_type_ignored'];
        }
      }

      $status = !empty($result['handled']) ? 'handled' : 'ignored';
      ymlb_update_finish($pdo, $updateId, $status, '', $scope);
      ymlb_stage_log('webhook', 'info', [
        'stage' => 'done',
        'scope' => $scope,
        'update_id' => $updateId,
        'status' => $status,
        'reason' => (string)($result['reason'] ?? ''),
      ]);

      return [
        'ok' => true,
        'http' => 200,
        'handled' => !empty($result['handled']),
        'reason' => (string)($result['reason'] ?? ''),
        'update_id' => $updateId,
        'scope' => $scope,
        'meta' => $meta,
        'result' => $result,
      ];
    } catch (Throwable $e) {
      ymlb_update_finish($pdo, $updateId, 'error', $e->getMessage(), $scope);
      ymlb_stage_log('webhook', 'error', [
        'stage' => 'exception',
        'scope' => $scope,
        'update_id' => $updateId,
        'error' => $e->getMessage(),
      ]);
      return [
        'ok' => false,
        'http' => 500,
        'handled' => false,
        'reason' => 'exception',
        'message' => $e->getMessage(),
        'update_id' => $updateId,
      ];
    }
  }

  /**
   * ymlb_webhook_process()
   *
   * @return array<string,mixed>
   */
  function ymlb_webhook_process(PDO $pdo): array
  {
    $settings = ymlb_settings_get($pdo);
    return ymlb_webhook_process_core($pdo, $settings, 'main');
  }

  /**
   * ymlb_webhook_process_chat()
   *
   * @return array<string,mixed>
   */
  function ymlb_webhook_process_chat(PDO $pdo): array
  {
    $settings = ymlb_chat_bot_effective_settings(ymlb_settings_get($pdo));
    return ymlb_webhook_process_core($pdo, $settings, 'chat');
  }

  /**
   * ymlb_webhook_info_scope()
   *
   * @return array<string,mixed>
   */
  function ymlb_webhook_info_scope(array $settings, string $scope = 'main'): array
  {
    $scope = strtolower(trim($scope));
    if ($scope !== 'chat') $scope = 'main';

    if ($scope === 'chat') {
      if ((int)($settings['chat_bot_separate'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'CHAT_BOT_NOT_SEPARATE'];
      }
      $effective = ymlb_chat_bot_effective_settings($settings);
      $token = trim((string)($effective['bot_token'] ?? ''));
      if ($token === '') {
        return ['ok' => false, 'error' => 'TG_TOKEN_EMPTY'];
      }
      $out = tg_get_webhook_info($token);
      $out['scope'] = 'chat';
      $out['separate'] = ((int)($settings['chat_bot_separate'] ?? 0) === 1) ? 1 : 0;
      return $out;
    }

    $token = trim((string)($settings['bot_token'] ?? ''));
    if ($token === '') {
      return ['ok' => false, 'error' => 'TG_TOKEN_EMPTY'];
    }
    $out = tg_get_webhook_info($token);
    $out['scope'] = 'main';
    return $out;
  }

  /**
   * ymlb_webhook_set_scope()
   *
   * @return array<string,mixed>
   */
  function ymlb_webhook_set_scope(array $settings, string $scope = 'main'): array
  {
    $scope = strtolower(trim($scope));
    if ($scope !== 'chat') $scope = 'main';

    if ($scope === 'chat') {
      if ((int)($settings['chat_bot_separate'] ?? 0) !== 1) {
        return ['ok' => false, 'error' => 'CHAT_BOT_NOT_SEPARATE'];
      }
      $effective = ymlb_chat_bot_effective_settings($settings);
      $token = trim((string)($effective['bot_token'] ?? ''));
      if ($token === '') {
        return ['ok' => false, 'error' => 'TG_TOKEN_EMPTY'];
      }

      $url = ymlb_chat_listener_url($settings);
      if ($url === '' || !preg_match('~^https?://~i', $url)) {
        return ['ok' => false, 'error' => 'WEBHOOK_URL_INVALID', 'url' => $url];
      }

      $params = [
        'allowed_updates' => ['message'],
        'drop_pending_updates' => false,
      ];

      $secret = trim((string)($effective['webhook_secret'] ?? ''));
      if ($secret !== '') {
        $params['secret_token'] = $secret;
      }

      ymlb_stage_log('webhook_set', 'info', [
        'scope' => 'chat',
        'separate' => 1,
        'url' => $url,
        'allowed_updates' => $params['allowed_updates'],
      ]);

      $out = tg_set_webhook($token, $url, $params);
      $out['scope'] = 'chat';
      $out['separate'] = ((int)($settings['chat_bot_separate'] ?? 0) === 1) ? 1 : 0;
      return $out;
    }

    $token = trim((string)($settings['bot_token'] ?? ''));
    if ($token === '') {
      return ['ok' => false, 'error' => 'TG_TOKEN_EMPTY'];
    }

    $url = ymlb_listener_url($settings);
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
      return ['ok' => false, 'error' => 'WEBHOOK_URL_INVALID', 'url' => $url];
    }

    $mainAllowed = ((int)($settings['chat_bot_separate'] ?? 0) === 1)
      ? ['channel_post']
      : ['message', 'channel_post'];
    $params = [
      'allowed_updates' => $mainAllowed,
      'drop_pending_updates' => false,
    ];

    $secret = trim((string)($settings['webhook_secret'] ?? ''));
    if ($secret !== '') {
      $params['secret_token'] = $secret;
    }

    ymlb_stage_log('webhook_set', 'info', [
      'scope' => 'main',
      'separate' => ((int)($settings['chat_bot_separate'] ?? 0) === 1) ? 1 : 0,
      'url' => $url,
      'allowed_updates' => $params['allowed_updates'],
    ]);

    $out = tg_set_webhook($token, $url, $params);
    $out['scope'] = 'main';
    return $out;
  }

  /**
   * ymlb_webhook_info()
   *
   * @return array<string,mixed>
   */
  function ymlb_webhook_info(array $settings): array
  {
    return ymlb_webhook_info_scope($settings, 'main');
  }

  /**
   * ymlb_webhook_set()
   *
   * @return array<string,mixed>
   */
  function ymlb_webhook_set(array $settings): array
  {
    return ymlb_webhook_set_scope($settings, 'main');
  }

  /**
   * ymlb_cleanup_photos()
   *
   * @return array<string,mixed>
   */
  function ymlb_cleanup_photos(PDO $pdo, string $fromDate, string $toDate): array
  {
    ymlb_ensure_schema($pdo);
    $fromDate = trim($fromDate);
    $toDate = trim($toDate);
    if (!preg_match('~^\d{4}-\d{2}-\d{2}$~', $fromDate) || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $toDate)) {
      throw new RuntimeException('Invalid date format, use YYYY-MM-DD');
    }
    if ($fromDate > $toDate) {
      throw new RuntimeException('Date range is invalid');
    }

    $photos = ymlb_qi(ymlb_table('photos'));
    $st = $pdo->prepare("
      SELECT id, local_path
      FROM {$photos}
      WHERE photo_date BETWEEN :d_from AND :d_to
    ");
    $st->execute([
      ':d_from' => $fromDate,
      ':d_to' => $toDate,
    ]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) $rows = [];

    $deletedFiles = 0;
    $missingFiles = 0;
    foreach ($rows as $row) {
      $path = trim((string)($row['local_path'] ?? ''));
      if ($path === '') continue;

      $real = realpath($path);
      $root = realpath(ymlb_photo_storage_root());
      if ($real === false || $root === false || strpos($real, $root) !== 0) {
        continue;
      }

      if (is_file($real)) {
        if (@unlink($real)) {
          $deletedFiles++;
        }
      } else {
        $missingFiles++;
      }
    }

    $stDel = $pdo->prepare("
      DELETE FROM {$photos}
      WHERE photo_date BETWEEN :d_from AND :d_to
    ");
    $stDel->execute([
      ':d_from' => $fromDate,
      ':d_to' => $toDate,
    ]);
    $deletedRows = (int)$stDel->rowCount();

    $rootDir = ymlb_photo_storage_root();
    if (is_dir($rootDir)) {
      $days = glob($rootDir . '/*', GLOB_ONLYDIR);
      if (is_array($days)) {
        foreach ($days as $dir) {
          $items = @scandir($dir);
          if (is_array($items) && count($items) <= 2) {
            @rmdir($dir);
          }
        }
      }
    }

    return [
      'deleted_rows' => $deletedRows,
      'deleted_files' => $deletedFiles,
      'missing_files' => $missingFiles,
      'from' => $fromDate,
      'to' => $toDate,
    ];
  }
}


