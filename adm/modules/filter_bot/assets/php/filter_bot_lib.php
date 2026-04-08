<?php
/**
 * FILE: /adm/modules/filter_bot/assets/php/filter_bot_lib.php
 * ROLE: Бизнес-логика модуля filter_bot.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once ROOT_PATH . '/core/telegram.php';

if (!function_exists('filter_bot_now')) {
  function filter_bot_now(): string
  {
    return date('Y-m-d H:i:s');
  }

  function filter_bot_public_root_url(): string
  {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') return '';

    $xfProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $xfProto = strtolower((string)explode(',', $xfProto)[0]);
    $xfSsl = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));

    if ($xfProto === 'https' || $xfSsl === 'on') {
      $scheme = 'https';
    } elseif (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
      $scheme = 'https';
    } else {
      $isLocal = (stripos($host, 'localhost') !== false) || (stripos($host, '127.0.0.1') !== false);
      $scheme = $isLocal ? 'http' : 'https';
    }

    return $scheme . '://' . $host;
  }

  function filter_bot_tg_webhook_url(bool $absolute = true): string
  {
    $path = function_exists('url')
      ? (string)url('/adm/modules/filter_bot/tg_webhook.php')
      : '/adm/modules/filter_bot/tg_webhook.php';

    if (!$absolute) return $path;

    $root = filter_bot_public_root_url();
    return $root !== '' ? ($root . $path) : $path;
  }

  function filter_bot_max_webhook_url(bool $absolute = true): string
  {
    $path = function_exists('url')
      ? (string)url('/adm/modules/filter_bot/max_webhook.php')
      : '/adm/modules/filter_bot/max_webhook.php';

    if (!$absolute) return $path;

    $root = filter_bot_public_root_url();
    return $root !== '' ? ($root . $path) : $path;
  }

  function filter_bot_defaults(): array
  {
    return [
      'id' => 1,
      'enabled' => 0,
      'log_enabled' => 1,
      'tg_enabled' => 0,
      'tg_bot_token' => '',
      'tg_webhook_secret' => '',
      'tg_allow_private' => 0,
      'tg_skip_admins' => 1,
      'max_enabled' => 0,
      'max_api_key' => '',
      'max_base_url' => 'https://platform-api.max.ru',
      'max_skip_admins' => 1,
      'warn_badword_text' => '{mention}, пожалуйста, без мата.',
      'warn_link_text' => 'Данная ссылка запрещена в этом чате, {mention}.',
      'max_warn_badword_text' => 'Сообщение скрыто модерацией: запрещённая лексика.',
      'max_warn_link_text' => 'Сообщение скрыто модерацией: запрещённая ссылка.',
      'badwords_list' => '',
      'allowed_domains_list' => '',
    ];
  }

  function filter_bot_require_schema(PDO $pdo): void
  {
    $dbName = trim((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
    if ($dbName === '') {
      throw new RuntimeException('Не удалось определить текущую БД.');
    }

    $need = FILTER_BOT_REQUIRED_TABLES;
    $placeholders = implode(',', array_fill(0, count($need), '?'));
    $sql = "
      SELECT table_name
      FROM information_schema.tables
      WHERE table_schema = ?
        AND table_name IN ($placeholders)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$dbName], $need));

    $exists = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $name = trim((string)($row['table_name'] ?? ''));
      if ($name !== '') $exists[$name] = true;
    }

    $missing = [];
    foreach ($need as $table) {
      if (!isset($exists[$table])) $missing[] = $table;
    }

    if ($missing) {
      throw new RuntimeException(
        'Не применен SQL модуля filter_bot. Отсутствуют таблицы: ' . implode(', ', $missing)
      );
    }
  }

  function filter_bot_substr(string $text, int $maxLen): string
  {
    if ($maxLen < 1) return '';
    if (function_exists('mb_substr')) {
      return (string)mb_substr($text, 0, $maxLen, 'UTF-8');
    }
    return (string)substr($text, 0, $maxLen);
  }

  function filter_bot_strtolower(string $text): string
  {
    if (function_exists('mb_strtolower')) {
      return (string)mb_strtolower($text, 'UTF-8');
    }
    return strtolower($text);
  }

  function filter_bot_settings_get(PDO $pdo): array
  {
    filter_bot_require_schema($pdo);

    $row = $pdo->query("SELECT * FROM " . FILTER_BOT_TABLE_SETTINGS . " WHERE id = 1 LIMIT 1")
      ->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
      return filter_bot_defaults();
    }

    $base = filter_bot_defaults();
    foreach ($base as $key => $default) {
      if (!array_key_exists($key, $row)) continue;
      if (is_int($default)) {
        $base[$key] = ((int)$row[$key] === 1) ? 1 : 0;
      } else {
        $base[$key] = (string)$row[$key];
      }
    }

    if (stripos((string)$base['max_api_key'], 'Bearer ') === 0) {
      $base['max_api_key'] = trim((string)substr((string)$base['max_api_key'], 7));
    }
    if (stripos((string)$base['max_api_key'], 'OAuth ') === 0) {
      $base['max_api_key'] = trim((string)substr((string)$base['max_api_key'], 6));
    }

    return $base;
  }

  function filter_bot_settings_save(PDO $pdo, array $input): array
  {
    filter_bot_require_schema($pdo);

    $data = filter_bot_defaults();
    foreach ($data as $key => $default) {
      if (!array_key_exists($key, $input)) continue;
      if ($key === 'id') continue;
      if (is_int($default)) {
        $data[$key] = ((int)$input[$key] === 1) ? 1 : 0;
      } else {
        $data[$key] = trim((string)$input[$key]);
      }
    }

    if ($data['max_base_url'] === '') {
      $data['max_base_url'] = 'https://platform-api.max.ru';
    }
    if (stripos((string)$data['max_api_key'], 'Bearer ') === 0) {
      $data['max_api_key'] = trim((string)substr((string)$data['max_api_key'], 7));
    }
    if (stripos((string)$data['max_api_key'], 'OAuth ') === 0) {
      $data['max_api_key'] = trim((string)substr((string)$data['max_api_key'], 6));
    }

    foreach (['tg_bot_token', 'tg_webhook_secret', 'max_api_key', 'max_base_url'] as $key) {
      $data[$key] = filter_bot_substr((string)$data[$key], 255);
    }
    foreach (['warn_badword_text', 'warn_link_text', 'max_warn_badword_text', 'max_warn_link_text'] as $key) {
      $data[$key] = filter_bot_substr((string)$data[$key], 255);
    }

    $sql = "
      INSERT INTO " . FILTER_BOT_TABLE_SETTINGS . "
      (
        id, enabled, log_enabled, tg_enabled, tg_bot_token, tg_webhook_secret,
        tg_allow_private, tg_skip_admins, max_enabled, max_api_key, max_base_url, max_skip_admins,
        warn_badword_text, warn_link_text, max_warn_badword_text, max_warn_link_text,
        badwords_list, allowed_domains_list
      )
      VALUES
      (
        1, :enabled, :log_enabled, :tg_enabled, :tg_bot_token, :tg_webhook_secret,
        :tg_allow_private, :tg_skip_admins, :max_enabled, :max_api_key, :max_base_url, :max_skip_admins,
        :warn_badword_text, :warn_link_text, :max_warn_badword_text, :max_warn_link_text,
        :badwords_list, :allowed_domains_list
      )
      ON DUPLICATE KEY UPDATE
        enabled = VALUES(enabled),
        log_enabled = VALUES(log_enabled),
        tg_enabled = VALUES(tg_enabled),
        tg_bot_token = VALUES(tg_bot_token),
        tg_webhook_secret = VALUES(tg_webhook_secret),
        tg_allow_private = VALUES(tg_allow_private),
        tg_skip_admins = VALUES(tg_skip_admins),
        max_enabled = VALUES(max_enabled),
        max_api_key = VALUES(max_api_key),
        max_base_url = VALUES(max_base_url),
        max_skip_admins = VALUES(max_skip_admins),
        warn_badword_text = VALUES(warn_badword_text),
        warn_link_text = VALUES(warn_link_text),
        max_warn_badword_text = VALUES(max_warn_badword_text),
        max_warn_link_text = VALUES(max_warn_link_text),
        badwords_list = VALUES(badwords_list),
        allowed_domains_list = VALUES(allowed_domains_list)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':enabled' => (int)$data['enabled'],
      ':log_enabled' => (int)$data['log_enabled'],
      ':tg_enabled' => (int)$data['tg_enabled'],
      ':tg_bot_token' => (string)$data['tg_bot_token'],
      ':tg_webhook_secret' => (string)$data['tg_webhook_secret'],
      ':tg_allow_private' => (int)$data['tg_allow_private'],
      ':tg_skip_admins' => (int)$data['tg_skip_admins'],
      ':max_enabled' => (int)$data['max_enabled'],
      ':max_api_key' => (string)$data['max_api_key'],
      ':max_base_url' => (string)$data['max_base_url'],
      ':max_skip_admins' => (int)$data['max_skip_admins'],
      ':warn_badword_text' => (string)$data['warn_badword_text'],
      ':warn_link_text' => (string)$data['warn_link_text'],
      ':max_warn_badword_text' => (string)$data['max_warn_badword_text'],
      ':max_warn_link_text' => (string)$data['max_warn_link_text'],
      ':badwords_list' => (string)$data['badwords_list'],
      ':allowed_domains_list' => (string)$data['allowed_domains_list'],
    ]);

    return filter_bot_settings_get($pdo);
  }

  function filter_bot_norm_platform(string $platform): string
  {
    $platform = strtolower(trim($platform));
    return ($platform === FILTER_BOT_PLATFORM_MAX) ? FILTER_BOT_PLATFORM_MAX : FILTER_BOT_PLATFORM_TG;
  }

  function filter_bot_channels_list(PDO $pdo, string $platform = ''): array
  {
    filter_bot_require_schema($pdo);

    $platform = trim($platform);
    if ($platform !== '') {
      $platform = filter_bot_norm_platform($platform);
      $stmt = $pdo->prepare("
        SELECT *
        FROM " . FILTER_BOT_TABLE_CHANNELS . "
        WHERE platform = :platform
        ORDER BY platform ASC, chat_title ASC, id DESC
      ");
      $stmt->execute([':platform' => $platform]);
      $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
      return is_array($rows) ? $rows : [];
    }

    $rows = $pdo->query("
      SELECT *
      FROM " . FILTER_BOT_TABLE_CHANNELS . "
      ORDER BY platform ASC, chat_title ASC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
  }

  function filter_bot_channel_get(PDO $pdo, string $platform, string $chatId): ?array
  {
    $platform = filter_bot_norm_platform($platform);
    $chatId = trim($chatId);
    if ($chatId === '') return null;

    $stmt = $pdo->prepare("
      SELECT *
      FROM " . FILTER_BOT_TABLE_CHANNELS . "
      WHERE platform = :platform AND chat_id = :chat_id
      LIMIT 1
    ");
    $stmt->execute([
      ':platform' => $platform,
      ':chat_id' => $chatId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
  }

  function filter_bot_channel_upsert(PDO $pdo, array $input): array
  {
    filter_bot_require_schema($pdo);

    $platform = filter_bot_norm_platform((string)($input['platform'] ?? ''));
    $chatId = trim((string)($input['chat_id'] ?? ''));
    $chatTitle = filter_bot_substr(trim((string)($input['chat_title'] ?? '')), 190);
    $chatType = filter_bot_substr(trim((string)($input['chat_type'] ?? '')), 32);
    $enabled = ((int)($input['enabled'] ?? 1) === 1) ? 1 : 0;

    if ($chatId === '') {
      throw new InvalidArgumentException('Пустой chat_id.');
    }

    $stmt = $pdo->prepare("
      INSERT INTO " . FILTER_BOT_TABLE_CHANNELS . "
      (platform, chat_id, chat_title, chat_type, enabled)
      VALUES
      (:platform, :chat_id, :chat_title, :chat_type, :enabled)
      ON DUPLICATE KEY UPDATE
        chat_title = VALUES(chat_title),
        chat_type = VALUES(chat_type),
        enabled = VALUES(enabled)
    ");
    $stmt->execute([
      ':platform' => $platform,
      ':chat_id' => $chatId,
      ':chat_title' => $chatTitle,
      ':chat_type' => $chatType,
      ':enabled' => $enabled,
    ]);

    $row = filter_bot_channel_get($pdo, $platform, $chatId);
    if (!is_array($row)) {
      throw new RuntimeException('Не удалось сохранить чат.');
    }

    return $row;
  }

  function filter_bot_channel_toggle(PDO $pdo, int $id, int $enabled): void
  {
    filter_bot_require_schema($pdo);

    $stmt = $pdo->prepare("
      UPDATE " . FILTER_BOT_TABLE_CHANNELS . "
      SET enabled = :enabled
      WHERE id = :id
      LIMIT 1
    ");
    $stmt->execute([
      ':enabled' => ($enabled === 1 ? 1 : 0),
      ':id' => $id,
    ]);
  }

  function filter_bot_channel_delete(PDO $pdo, int $id): void
  {
    filter_bot_require_schema($pdo);

    $stmt = $pdo->prepare("
      DELETE FROM " . FILTER_BOT_TABLE_CHANNELS . "
      WHERE id = :id
      LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
  }

  function filter_bot_channel_touch(PDO $pdo, string $platform, string $chatId, string $chatTitle = '', string $chatType = ''): void
  {
    $platform = filter_bot_norm_platform($platform);
    $chatId = trim($chatId);
    if ($chatId === '') return;

    $stmt = $pdo->prepare("
      UPDATE " . FILTER_BOT_TABLE_CHANNELS . "
      SET
        chat_title = CASE WHEN :chat_title <> '' THEN :chat_title ELSE chat_title END,
        chat_type = CASE WHEN :chat_type <> '' THEN :chat_type ELSE chat_type END,
        last_seen_at = :last_seen_at
      WHERE platform = :platform AND chat_id = :chat_id
      LIMIT 1
    ");
    $stmt->execute([
      ':chat_title' => filter_bot_substr($chatTitle, 190),
      ':chat_type' => filter_bot_substr($chatType, 32),
      ':last_seen_at' => filter_bot_now(),
      ':platform' => $platform,
      ':chat_id' => $chatId,
    ]);
  }

  function filter_bot_channels_enabled_ids(PDO $pdo, string $platform): array
  {
    filter_bot_require_schema($pdo);

    $stmt = $pdo->prepare("
      SELECT chat_id
      FROM " . FILTER_BOT_TABLE_CHANNELS . "
      WHERE platform = :platform AND enabled = 1
      ORDER BY id ASC
    ");
    $stmt->execute([':platform' => filter_bot_norm_platform($platform)]);

    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!is_array($rows)) return [];

    $out = [];
    foreach ($rows as $row) {
      $chatId = trim((string)$row);
      if ($chatId === '') continue;
      $out[] = $chatId;
    }
    return $out;
  }

  function filter_bot_logs_recent(PDO $pdo, int $limit = 50): array
  {
    filter_bot_require_schema($pdo);

    $limit = max(1, min(500, $limit));
    $rows = $pdo->query("
      SELECT *
      FROM " . FILTER_BOT_TABLE_LOGS . "
      ORDER BY id DESC
      LIMIT " . (int)$limit
    )->fetchAll(PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
  }

  function filter_bot_log_event(PDO $pdo, array $row): void
  {
    $settings = filter_bot_settings_get($pdo);
    if ((int)($settings['log_enabled'] ?? 1) !== 1) return;

    $stmt = $pdo->prepare("
      INSERT INTO " . FILTER_BOT_TABLE_LOGS . "
      (
        platform, chat_id, chat_title, chat_type, message_id, from_id, from_name,
        message_text, rule_code, action_code, status, error_text, raw_meta
      )
      VALUES
      (
        :platform, :chat_id, :chat_title, :chat_type, :message_id, :from_id, :from_name,
        :message_text, :rule_code, :action_code, :status, :error_text, :raw_meta
      )
    ");
    $stmt->execute([
      ':platform' => filter_bot_norm_platform((string)($row['platform'] ?? '')),
      ':chat_id' => filter_bot_substr(trim((string)($row['chat_id'] ?? '')), 64),
      ':chat_title' => filter_bot_substr(trim((string)($row['chat_title'] ?? '')), 190),
      ':chat_type' => filter_bot_substr(trim((string)($row['chat_type'] ?? '')), 32),
      ':message_id' => filter_bot_substr(trim((string)($row['message_id'] ?? '')), 64),
      ':from_id' => filter_bot_substr(trim((string)($row['from_id'] ?? '')), 64),
      ':from_name' => filter_bot_substr(trim((string)($row['from_name'] ?? '')), 190),
      ':message_text' => (string)($row['message_text'] ?? ''),
      ':rule_code' => filter_bot_substr(trim((string)($row['rule_code'] ?? '')), 32),
      ':action_code' => filter_bot_substr(trim((string)($row['action_code'] ?? '')), 32),
      ':status' => filter_bot_substr(trim((string)($row['status'] ?? '')), 16),
      ':error_text' => filter_bot_substr(trim((string)($row['error_text'] ?? '')), 255),
      ':raw_meta' => json_encode($row['raw_meta'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
    ]);
  }

  function filter_bot_mark_update(PDO $pdo, string $platform, string $updateKey, string $chatId = '', string $messageId = ''): bool
  {
    filter_bot_require_schema($pdo);

    $updateKey = trim($updateKey);
    if ($updateKey === '') return false;

    $stmt = $pdo->prepare("
      INSERT IGNORE INTO " . FILTER_BOT_TABLE_UPDATES . "
      (platform, update_key, chat_id, message_id)
      VALUES
      (:platform, :update_key, :chat_id, :message_id)
    ");
    $stmt->execute([
      ':platform' => filter_bot_norm_platform($platform),
      ':update_key' => filter_bot_substr($updateKey, 191),
      ':chat_id' => filter_bot_substr(trim($chatId), 64),
      ':message_id' => filter_bot_substr(trim($messageId), 64),
    ]);

    return ($stmt->rowCount() > 0);
  }

  function filter_bot_text_lines(string $text): array
  {
    $parts = preg_split('~\R+~u', $text);
    if (!is_array($parts)) return [];

    $out = [];
    foreach ($parts as $line) {
      $line = trim((string)$line);
      if ($line === '' || strpos($line, '#') === 0) continue;
      $out[] = $line;
    }
    return $out;
  }

  function filter_bot_normalize_text(string $text): string
  {
    $text = filter_bot_strtolower($text);
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
    $text = trim((string)preg_replace('~\s+~u', ' ', $text));
    return $text;
  }

  function filter_bot_badwords_parse(string $text): array
  {
    $words = [];
    $roots = [];

    foreach (filter_bot_text_lines($text) as $line) {
      if (stripos($line, 'word:') === 0) {
        $value = filter_bot_normalize_text((string)substr($line, 5));
        if ($value !== '') $words[$value] = true;
        continue;
      }
      if (stripos($line, 'flex:') === 0) {
        $value = filter_bot_normalize_text((string)substr($line, 5));
        if ($value !== '') $roots[$value] = true;
        continue;
      }

      $value = filter_bot_normalize_text($line);
      if ($value !== '') $words[$value] = true;
    }

    return [
      'words' => array_keys($words),
      'roots' => array_keys($roots),
    ];
  }

  function filter_bot_allowed_domains_parse(string $text): array
  {
    $out = [];
    foreach (filter_bot_text_lines($text) as $line) {
      $host = filter_bot_domain_from_value($line);
      if ($host === '') continue;
      $out[$host] = true;
    }
    return array_keys($out);
  }

  function filter_bot_domain_from_value(string $value): string
  {
    $value = trim($value);
    if ($value === '') return '';

    if (strpos($value, '://') === false) {
      $value = 'https://' . ltrim($value, '/');
    }

    $host = trim((string)parse_url($value, PHP_URL_HOST));
    $host = strtolower($host);
    $host = trim($host, '.');
    if ($host === '') return '';
    if (strpos($host, 'www.') === 0) {
      $host = substr($host, 4);
    }
    return $host;
  }

  function filter_bot_domain_allowed(string $host, array $allowedDomains): bool
  {
    $host = filter_bot_domain_from_value($host);
    if ($host === '') return true;
    if (!$allowedDomains) return false;

    foreach ($allowedDomains as $allowed) {
      $allowed = filter_bot_domain_from_value((string)$allowed);
      if ($allowed === '') continue;
      if ($host === $allowed) return true;
      if (substr($host, -strlen('.' . $allowed)) === '.' . $allowed) return true;
    }

    return false;
  }

  function filter_bot_extract_urls_from_text(string $text): array
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

  function filter_bot_find_badword_hit(string $text, array $rules): string
  {
    $normalized = filter_bot_normalize_text($text);
    if ($normalized === '') return '';

    $tokens = preg_split('~\s+~u', $normalized);
    if (!is_array($tokens)) $tokens = [];

    foreach ((array)($rules['words'] ?? []) as $word) {
      $word = trim((string)$word);
      if ($word === '') continue;
      foreach ($tokens as $token) {
        if ((string)$token === $word) return $word;
      }
    }

    foreach ((array)($rules['roots'] ?? []) as $root) {
      $root = trim((string)$root);
      if ($root === '') continue;
      foreach ($tokens as $token) {
        if ((string)$token !== '' && strpos((string)$token, $root) !== false) return $root;
      }
    }

    return '';
  }

  function filter_bot_pick_rule_hit(string $text, array $urls, array $settings): array
  {
    $badwords = filter_bot_badwords_parse((string)($settings['badwords_list'] ?? ''));
    $badwordHit = filter_bot_find_badword_hit($text, $badwords);
    if ($badwordHit !== '') {
      return [
        'rule_code' => 'badword',
        'match' => $badwordHit,
        'matched_url' => '',
      ];
    }

    $allowedDomains = filter_bot_allowed_domains_parse((string)($settings['allowed_domains_list'] ?? ''));
    $allUrls = [];
    foreach (filter_bot_extract_urls_from_text($text) as $url) {
      $allUrls[$url] = true;
    }
    foreach ($urls as $url) {
      $url = trim((string)$url);
      if ($url === '') continue;
      $allUrls[$url] = true;
    }

    foreach (array_keys($allUrls) as $url) {
      $host = filter_bot_domain_from_value($url);
      if ($host === '') continue;
      if (!filter_bot_domain_allowed($host, $allowedDomains)) {
        return [
          'rule_code' => 'forbidden_link',
          'match' => $host,
          'matched_url' => $url,
        ];
      }
    }

    return [
      'rule_code' => '',
      'match' => '',
      'matched_url' => '',
    ];
  }

  function filter_bot_tg_verify_webhook(array $settings): bool
  {
    return tg_verify_webhook_secret(trim((string)($settings['tg_webhook_secret'] ?? '')));
  }

  function filter_bot_tg_apply_webhook(array $settings): array
  {
    $token = trim((string)($settings['tg_bot_token'] ?? ''));
    if ($token === '') return ['ok' => false, 'error' => 'TG_TOKEN_EMPTY'];

    $url = filter_bot_tg_webhook_url(true);
    if ($url === '') return ['ok' => false, 'error' => 'TG_WEBHOOK_URL_EMPTY'];

    $params = [
      'allowed_updates' => ['message', 'channel_post'],
    ];
    $secret = trim((string)($settings['tg_webhook_secret'] ?? ''));
    if ($secret !== '') {
      $params['secret_token'] = $secret;
    }

    $res = tg_set_webhook($token, $url, $params);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => trim((string)($res['description'] ?? $res['error'] ?? 'TG_SET_WEBHOOK_FAILED'))];
    }

    return ['ok' => true];
  }

  function filter_bot_tg_delete_message(string $token, string $chatId, string $messageId): array
  {
    return tg_request($token, 'deleteMessage', [
      'chat_id' => $chatId,
      'message_id' => $messageId,
    ]);
  }

  function filter_bot_tg_reply(string $token, string $chatId, string $messageId, string $text): array
  {
    if (trim($text) === '') return ['ok' => true, 'skipped' => 1];

    return tg_send_message($token, $chatId, $text, [
      'reply_to_message_id' => $messageId,
      'allow_sending_without_reply' => true,
      'parse_mode' => 'HTML',
    ]);
  }

  function filter_bot_tg_entity_urls(array $entities): array
  {
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

  function filter_bot_tg_extract_message(array $update): array
  {
    $message = [];
    $updateType = '';

    if (isset($update['message']) && is_array($update['message'])) {
      $message = (array)$update['message'];
      $updateType = 'message';
    } elseif (isset($update['channel_post']) && is_array($update['channel_post'])) {
      $message = (array)$update['channel_post'];
      $updateType = 'channel_post';
    }

    if (!$message) {
      return [];
    }

    $chat = (array)($message['chat'] ?? []);
    $from = (array)($message['from'] ?? []);
    $senderChat = (array)($message['sender_chat'] ?? []);
    $text = (string)($message['text'] ?? $message['caption'] ?? '');
    $entities = [];
    if (isset($message['entities']) && is_array($message['entities'])) {
      $entities = $message['entities'];
    } elseif (isset($message['caption_entities']) && is_array($message['caption_entities'])) {
      $entities = $message['caption_entities'];
    }

    $fromName = trim((string)(
      $from['username']
      ?? trim((string)($from['first_name'] ?? '')) . ' ' . trim((string)($from['last_name'] ?? ''))
    ));
    if ($fromName === '') {
      $fromName = trim((string)($senderChat['title'] ?? $senderChat['username'] ?? ''));
    }

    return [
      'update_type' => $updateType,
      'update_id' => (string)($update['update_id'] ?? ''),
      'chat_id' => trim((string)($chat['id'] ?? '')),
      'chat_title' => trim((string)($chat['title'] ?? $chat['username'] ?? '')),
      'chat_type' => trim((string)($chat['type'] ?? '')),
      'message_id' => trim((string)($message['message_id'] ?? '')),
      'message_text' => $text,
      'from_id' => trim((string)($from['id'] ?? $senderChat['id'] ?? '')),
      'from_name' => $fromName,
      'from_is_bot' => ((int)($from['is_bot'] ?? 0) === 1) ? 1 : 0,
      'sender_chat' => $senderChat,
      'message' => $message,
      'text_link_urls' => filter_bot_tg_entity_urls($entities),
    ];
  }

  function filter_bot_tg_is_group_like(string $chatType): bool
  {
    return in_array(trim($chatType), ['group', 'supergroup', 'channel'], true);
  }

  function filter_bot_tg_is_admin_sender(string $token, array $meta): bool
  {
    if (!empty($meta['sender_chat']) && is_array($meta['sender_chat'])) {
      return true;
    }
    if ((string)($meta['update_type'] ?? '') === 'channel_post') {
      return true;
    }

    $chatId = trim((string)($meta['chat_id'] ?? ''));
    $userId = trim((string)($meta['from_id'] ?? ''));
    $chatType = trim((string)($meta['chat_type'] ?? ''));
    if ($chatId === '' || $userId === '' || $chatType === 'private') {
      return false;
    }

    static $adminsCache = [];
    $cacheKey = $chatId;
    if (!array_key_exists($cacheKey, $adminsCache)) {
      $admins = tg_get_chat_administrators($token, $chatId);
      $map = [];
      if (($admins['ok'] ?? false) === true && is_array($admins['result'] ?? null)) {
        foreach ((array)$admins['result'] as $item) {
          if (!is_array($item)) continue;
          $adminUser = (array)($item['user'] ?? []);
          $id = trim((string)($adminUser['id'] ?? ''));
          if ($id !== '') $map[$id] = true;
        }
      }
      $adminsCache[$cacheKey] = $map;
    }

    if (isset($adminsCache[$cacheKey][$userId])) return true;

    $member = tg_get_chat_member($token, $chatId, $userId);
    $status = trim((string)($member['result']['status'] ?? ''));
    return in_array($status, ['creator', 'administrator'], true);
  }

  function filter_bot_tg_warn_text(array $settings, array $meta, string $ruleCode): string
  {
    $template = ($ruleCode === 'badword')
      ? (string)($settings['warn_badword_text'] ?? '')
      : (string)($settings['warn_link_text'] ?? '');

    $name = trim((string)($meta['from_name'] ?? ''));
    $fromId = trim((string)($meta['from_id'] ?? ''));
    $mention = $name !== '' ? htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'пользователь';
    if ($fromId !== '') {
      $mention = '<a href="tg://user?id=' . rawurlencode($fromId) . '">' . $mention . '</a>';
    }

    return str_replace('{mention}', $mention, $template);
  }

  function filter_bot_tg_webhook_process(PDO $pdo): array
  {
    filter_bot_require_schema($pdo);
    $settings = filter_bot_settings_get($pdo);

    if ((int)($settings['enabled'] ?? 0) !== 1 || (int)($settings['tg_enabled'] ?? 0) !== 1) {
      return ['ok' => true, 'http' => 200, 'message' => 'disabled'];
    }

    $update = tg_read_update();
    $meta = filter_bot_tg_extract_message($update);
    if (!$meta) {
      return ['ok' => true, 'http' => 200, 'message' => 'ignored_update'];
    }

    $updateKey = trim((string)($meta['update_id'] ?? ''));
    if ($updateKey === '') {
      $updateKey = 'tg:' . trim((string)($meta['chat_id'] ?? '')) . ':' . trim((string)($meta['message_id'] ?? ''));
    }
    if (!filter_bot_mark_update($pdo, FILTER_BOT_PLATFORM_TG, $updateKey, (string)$meta['chat_id'], (string)$meta['message_id'])) {
      return ['ok' => true, 'http' => 200, 'message' => 'duplicate'];
    }

    $channel = filter_bot_channel_get($pdo, FILTER_BOT_PLATFORM_TG, (string)$meta['chat_id']);
    if (!is_array($channel) || (int)($channel['enabled'] ?? 0) !== 1) {
      return ['ok' => true, 'http' => 200, 'message' => 'chat_not_bound'];
    }

    filter_bot_channel_touch(
      $pdo,
      FILTER_BOT_PLATFORM_TG,
      (string)$meta['chat_id'],
      (string)$meta['chat_title'],
      (string)$meta['chat_type']
    );

    if ((string)($meta['chat_type'] ?? '') === 'private' && (int)($settings['tg_allow_private'] ?? 0) !== 1) {
      return ['ok' => true, 'http' => 200, 'message' => 'private_not_allowed'];
    }
    if ((int)($meta['from_is_bot'] ?? 0) === 1) {
      return ['ok' => true, 'http' => 200, 'message' => 'sender_bot'];
    }

    $token = trim((string)($settings['tg_bot_token'] ?? ''));
    if ($token === '') {
      return ['ok' => false, 'http' => 500, 'message' => 'tg_token_empty'];
    }

    if ((int)($settings['tg_skip_admins'] ?? 1) === 1 && filter_bot_tg_is_admin_sender($token, $meta)) {
      return ['ok' => true, 'http' => 200, 'message' => 'admin_skipped'];
    }

    $rule = filter_bot_pick_rule_hit((string)($meta['message_text'] ?? ''), (array)($meta['text_link_urls'] ?? []), $settings);
    if ((string)($rule['rule_code'] ?? '') === '') {
      return ['ok' => true, 'http' => 200, 'message' => 'clean'];
    }

    $delete = filter_bot_tg_delete_message($token, (string)$meta['chat_id'], (string)$meta['message_id']);
    $deleteOk = (($delete['ok'] ?? false) === true);
    $warnSent = false;
    if ((string)($meta['chat_type'] ?? '') !== 'channel') {
      $warn = filter_bot_tg_reply(
        $token,
        (string)$meta['chat_id'],
        (string)$meta['message_id'],
        filter_bot_tg_warn_text($settings, $meta, (string)$rule['rule_code'])
      );
      $warnSent = (($warn['ok'] ?? false) === true);
    }

    filter_bot_log_event($pdo, [
      'platform' => FILTER_BOT_PLATFORM_TG,
      'chat_id' => (string)$meta['chat_id'],
      'chat_title' => (string)$meta['chat_title'],
      'chat_type' => (string)$meta['chat_type'],
      'message_id' => (string)$meta['message_id'],
      'from_id' => (string)$meta['from_id'],
      'from_name' => (string)$meta['from_name'],
      'message_text' => (string)$meta['message_text'],
      'rule_code' => (string)$rule['rule_code'],
      'action_code' => 'delete',
      'status' => $deleteOk ? 'ok' : 'error',
      'error_text' => $deleteOk ? '' : trim((string)($delete['description'] ?? $delete['error'] ?? 'TG_DELETE_FAILED')),
      'raw_meta' => [
        'matched_url' => (string)($rule['matched_url'] ?? ''),
        'match' => (string)($rule['match'] ?? ''),
        'warn_sent' => $warnSent ? 1 : 0,
      ],
    ]);

    audit_log(FILTER_BOT_MODULE_CODE, 'tg_moderation', $deleteOk ? 'info' : 'error', [
      'chat_id' => (string)$meta['chat_id'],
      'message_id' => (string)$meta['message_id'],
      'rule_code' => (string)$rule['rule_code'],
      'match' => (string)($rule['match'] ?? ''),
      'matched_url' => (string)($rule['matched_url'] ?? ''),
      'status' => $deleteOk ? 'ok' : 'error',
    ]);

    return [
      'ok' => $deleteOk,
      'http' => $deleteOk ? 200 : 500,
      'message' => $deleteOk ? 'moderated' : 'delete_failed',
      'warn_sent' => $warnSent ? 1 : 0,
    ];
  }

  function filter_bot_max_http_json(string $method, string $url, array $headers = [], ?array $payload = null, int $timeout = 20): array
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
      $requestHeaders = array_merge(['Accept: application/json'], $headers);
      if ($payload !== null) {
        $requestHeaders[] = 'Content-Type: application/json';
      }

      $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout > 0 ? $timeout : 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_CUSTOMREQUEST => $method,
      ];
      if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = $jsonPayload;
      }
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
      if ($payload !== null) {
        $headersTxt .= "Content-Type: application/json\r\n";
      }

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

  function filter_bot_max_api_version(): string
  {
    return '1.2.5';
  }

  function filter_bot_max_extract_markup_urls(array $markup): array
  {
    $urls = [];
    foreach ($markup as $item) {
      if (!is_array($item)) continue;
      $url = trim((string)($item['url'] ?? $item['href'] ?? ''));
      if ($url !== '') $urls[$url] = true;
    }
    return array_keys($urls);
  }

  function filter_bot_max_extract_message(array $payload): array
  {
    $updateType = trim((string)($payload['update_type'] ?? $payload['event_type'] ?? ''));

    $message = [];
    if (isset($payload['message']) && is_array($payload['message'])) {
      $message = (array)$payload['message'];
    } elseif (isset($payload['data']['message']) && is_array($payload['data']['message'])) {
      $message = (array)$payload['data']['message'];
    } elseif (isset($payload['data']) && is_array($payload['data'])) {
      $message = (array)$payload['data'];
    }
    if (!$message) return [];

    $body = (array)($message['body'] ?? []);
    $recipient = (array)($message['recipient'] ?? []);
    $recipientChat = (array)($recipient['chat'] ?? []);
    $sender = (array)($message['sender'] ?? $message['from'] ?? []);
    $chatId = trim((string)(
      $recipient['chat_id']
      ?? $recipient['id']
      ?? $recipientChat['chat_id']
      ?? $recipientChat['id']
      ?? $message['chat_id']
      ?? $payload['chat_id']
      ?? ''
    ));

    return [
      'update_type' => $updateType,
      'chat_id' => $chatId,
      'chat_title' => trim((string)($recipientChat['title'] ?? $recipient['title'] ?? $payload['chat_title'] ?? '')),
      'chat_type' => trim((string)($recipientChat['type'] ?? $recipient['type'] ?? $payload['chat_type'] ?? '')),
      'message_id' => trim((string)($message['message_id'] ?? $message['id'] ?? $payload['message_id'] ?? '')),
      'message_text' => trim((string)($body['text'] ?? $message['text'] ?? '')),
      'from_id' => trim((string)($sender['user_id'] ?? $sender['id'] ?? '')),
      'from_name' => trim((string)($sender['name'] ?? $sender['display_name'] ?? $sender['username'] ?? '')),
      'sender_type' => trim((string)($sender['type'] ?? $message['sender_type'] ?? 'user')),
      'markup_urls' => filter_bot_max_extract_markup_urls((array)($body['markup'] ?? [])),
      'message' => $message,
      'payload' => $payload,
    ];
  }

  function filter_bot_max_extract_error(array $res): string
  {
    $json = (array)($res['json'] ?? []);
    $err = trim((string)($json['message'] ?? $json['error'] ?? $res['description'] ?? $res['error'] ?? ''));
    if ($err === '') {
      $httpCode = (int)($res['http_code'] ?? 0);
      if ($httpCode > 0) $err = 'HTTP_' . $httpCode;
    }
    return $err !== '' ? $err : 'MAX_ERROR';
  }

  function filter_bot_max_update_message(array $settings, string $chatId, string $messageId, array $body): array
  {
    $chatId = trim($chatId);
    $messageId = trim($messageId);
    if ($chatId === '' || $messageId === '') {
      return ['ok' => false, 'error' => 'CHAT_OR_MESSAGE_EMPTY'];
    }

    $url = rtrim((string)$settings['max_base_url'], '/')
      . '/messages?chat_id=' . rawurlencode($chatId)
      . '&message_id=' . rawurlencode($messageId);

    $res = filter_bot_max_http_json('PUT', $url, [
      'Authorization: ' . (string)$settings['max_api_key'],
    ], $body, 25);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => filter_bot_max_extract_error($res)];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    if ($httpCode < 200 || $httpCode >= 300) {
      return ['ok' => false, 'error' => filter_bot_max_extract_error($res), 'http_code' => $httpCode];
    }

    return ['ok' => true];
  }

  function filter_bot_max_member_info(array $settings, string $chatId, string $memberId = 'me'): array
  {
    $chatId = trim($chatId);
    if ($chatId === '') return ['ok' => false, 'error' => 'CHAT_ID_EMPTY'];

    $memberId = trim($memberId);
    if ($memberId === '') $memberId = 'me';

    $url = rtrim((string)$settings['max_base_url'], '/')
      . '/chats/' . rawurlencode($chatId) . '/members/' . rawurlencode($memberId);

    $res = filter_bot_max_http_json('GET', $url, [
      'Authorization: ' . (string)$settings['max_api_key'],
    ], null, 20);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => filter_bot_max_extract_error($res)];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    if ($httpCode < 200 || $httpCode >= 300) {
      return ['ok' => false, 'error' => filter_bot_max_extract_error($res), 'http_code' => $httpCode];
    }

    $json = (array)($res['json'] ?? []);
    $member = (array)($json['member'] ?? $json['chat_member'] ?? $json);
    $permissions = $json['permissions'] ?? $member['permissions'] ?? [];
    if (!is_array($permissions)) $permissions = [];

    return [
      'ok' => true,
      'member' => $member,
      'permissions' => $permissions,
      'is_admin' => ((int)($json['is_admin'] ?? $member['is_admin'] ?? 0) === 1) ? 1 : 0,
      'is_owner' => ((int)($json['is_owner'] ?? $member['is_owner'] ?? 0) === 1) ? 1 : 0,
      'raw' => $json,
    ];
  }

  function filter_bot_max_permissions_can_edit(array $permissions): bool
  {
    if (!$permissions) return true;

    $need = [
      'post_edit_delete_message',
      'post_edit_message',
      'edit_message',
      'messages_edit',
      'message_edit',
    ];

    $isAssoc = array_keys($permissions) !== range(0, count($permissions) - 1);
    if ($isAssoc) {
      foreach ($permissions as $key => $value) {
        $key = strtolower(trim((string)$key));
        if (!$value) continue;
        if (in_array($key, $need, true)) return true;
        if (strpos($key, 'edit') !== false && strpos($key, 'message') !== false) return true;
      }
      return false;
    }

    $hasStructured = false;
    foreach ($permissions as $permission) {
      if (is_scalar($permission)) {
        $code = strtolower(trim((string)$permission));
        if ($code === '') continue;
        if (in_array($code, $need, true)) return true;
        if (strpos($code, 'edit') !== false && strpos($code, 'message') !== false) return true;
        continue;
      }

      if (is_array($permission)) {
        $hasStructured = true;
        $code = strtolower(trim((string)($permission['permission'] ?? $permission['name'] ?? $permission['code'] ?? '')));
        $allowed = array_key_exists('allowed', $permission) ? (bool)$permission['allowed'] : true;
        if (!$allowed || $code === '') continue;
        if (in_array($code, $need, true)) return true;
        if (strpos($code, 'edit') !== false && strpos($code, 'message') !== false) return true;
      }
    }

    return !$hasStructured;
  }

  function filter_bot_max_check_chat_access(array $settings, array $chatIds): array
  {
    $uniq = [];
    foreach ($chatIds as $cid) {
      $cid = trim((string)$cid);
      if ($cid === '') continue;
      $uniq['cid:' . $cid] = $cid;
    }
    $chatIds = array_values($uniq);

    $notAdmin = [];
    $noEditPerm = [];
    $errors = [];

    foreach ($chatIds as $chatId) {
      $me = filter_bot_max_member_info($settings, $chatId, 'me');
      if (($me['ok'] ?? false) !== true) {
        $errors[] = ['chat_id' => $chatId, 'error' => (string)($me['error'] ?? 'CHECK_FAILED')];
        continue;
      }

      $isAdmin = ((int)($me['is_admin'] ?? 0) === 1) || ((int)($me['is_owner'] ?? 0) === 1);
      if (!$isAdmin) {
        $notAdmin[] = $chatId;
        continue;
      }

      if (!filter_bot_max_permissions_can_edit((array)($me['permissions'] ?? []))) {
        $noEditPerm[] = $chatId;
      }
    }

    return [
      'ok' => !$errors,
      'checked' => count($chatIds),
      'not_admin' => $notAdmin,
      'no_edit_perm' => $noEditPerm,
      'errors' => $errors,
    ];
  }

  function filter_bot_max_subscription_list(array $settings): array
  {
    $url = rtrim((string)$settings['max_base_url'], '/') . '/subscriptions?count=100';
    $res = filter_bot_max_http_json('GET', $url, [
      'Authorization: ' . (string)$settings['max_api_key'],
    ], null, 20);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => filter_bot_max_extract_error($res), 'items' => []];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    if ($httpCode < 200 || $httpCode >= 300) {
      return ['ok' => false, 'error' => filter_bot_max_extract_error($res), 'items' => []];
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

  function filter_bot_max_subscription_url(array $subscription): string
  {
    $req = (array)($subscription['request'] ?? []);
    return trim((string)($subscription['url'] ?? $req['url'] ?? $subscription['endpoint_url'] ?? ''));
  }

  function filter_bot_max_subscription_match(array $subscription, string $endpointUrl): bool
  {
    $url = filter_bot_max_subscription_url($subscription);
    if ($url === '' || $endpointUrl === '' || $url !== $endpointUrl) return false;

    $types = $subscription['update_types'] ?? [];
    if (!is_array($types) || !$types) return true;

    foreach ($types as $type) {
      if (trim((string)$type) === 'message_created') return true;
    }

    return false;
  }

  function filter_bot_max_subscription_delete(array $settings, string $urlToDelete): array
  {
    $urlToDelete = trim($urlToDelete);
    if ($urlToDelete === '') return ['ok' => false, 'error' => 'SUBSCRIPTION_URL_EMPTY'];

    $url = rtrim((string)$settings['max_base_url'], '/')
      . '/subscriptions?url=' . rawurlencode($urlToDelete);

    $res = filter_bot_max_http_json('DELETE', $url, [
      'Authorization: ' . (string)$settings['max_api_key'],
    ], null, 25);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => filter_bot_max_extract_error($res)];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    if ($httpCode < 200 || $httpCode >= 300) {
      return ['ok' => false, 'error' => filter_bot_max_extract_error($res)];
    }

    return ['ok' => true];
  }

  function filter_bot_max_ensure_subscription(array $settings, string $endpointUrl): array
  {
    $endpointUrl = trim($endpointUrl);
    if ($endpointUrl === '') return ['ok' => false, 'error' => 'ENDPOINT_URL_EMPTY'];
    if (trim((string)($settings['max_api_key'] ?? '')) === '') return ['ok' => false, 'error' => 'MAX_API_KEY_EMPTY'];

    $listed = filter_bot_max_subscription_list($settings);
    if (($listed['ok'] ?? false) === true) {
      foreach ((array)($listed['items'] ?? []) as $sub) {
        if (is_array($sub) && filter_bot_max_subscription_match($sub, $endpointUrl)) {
          return ['ok' => true, 'created' => 0];
        }
      }
    }

    $url = rtrim((string)$settings['max_base_url'], '/') . '/subscriptions';
    $payload = [
      'url' => $endpointUrl,
      'version' => filter_bot_max_api_version(),
    ];

    $res = filter_bot_max_http_json('POST', $url, [
      'Authorization: ' . (string)$settings['max_api_key'],
    ], $payload, 25);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => filter_bot_max_extract_error($res)];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    if ($httpCode < 200 || $httpCode >= 300) {
      return ['ok' => false, 'error' => filter_bot_max_extract_error($res)];
    }

    return ['ok' => true, 'created' => 1];
  }

  function filter_bot_max_is_admin_sender(array $settings, array $meta): bool
  {
    $senderType = filter_bot_strtolower(trim((string)($meta['sender_type'] ?? '')));
    if (in_array($senderType, ['bot', 'channel'], true)) {
      return true;
    }

    $chatId = trim((string)($meta['chat_id'] ?? ''));
    $fromId = trim((string)($meta['from_id'] ?? ''));
    if ($chatId === '' || $fromId === '') return false;

    $member = filter_bot_max_member_info($settings, $chatId, $fromId);
    if (($member['ok'] ?? false) !== true) return false;

    return ((int)($member['is_admin'] ?? 0) === 1) || ((int)($member['is_owner'] ?? 0) === 1);
  }

  function filter_bot_max_warn_text(array $settings, string $ruleCode): string
  {
    return ($ruleCode === 'badword')
      ? (string)($settings['max_warn_badword_text'] ?? '')
      : (string)($settings['max_warn_link_text'] ?? '');
  }

  function filter_bot_max_webhook_process(PDO $pdo): array
  {
    filter_bot_require_schema($pdo);
    $settings = filter_bot_settings_get($pdo);

    if ((int)($settings['enabled'] ?? 0) !== 1 || (int)($settings['max_enabled'] ?? 0) !== 1) {
      return ['ok' => true, 'http' => 200, 'message' => 'disabled'];
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($payload)) $payload = [];

    $meta = filter_bot_max_extract_message($payload);
    if (!$meta) {
      return ['ok' => true, 'http' => 200, 'message' => 'ignored_update'];
    }

    $updateType = trim((string)($meta['update_type'] ?? ''));
    if ($updateType !== '' && $updateType !== 'message_created') {
      return ['ok' => true, 'http' => 200, 'message' => 'ignored_type'];
    }

    $updateKey = 'max:' . trim((string)($meta['chat_id'] ?? '')) . ':' . trim((string)($meta['message_id'] ?? '')) . ':' . $updateType;
    if (!filter_bot_mark_update($pdo, FILTER_BOT_PLATFORM_MAX, $updateKey, (string)$meta['chat_id'], (string)$meta['message_id'])) {
      return ['ok' => true, 'http' => 200, 'message' => 'duplicate'];
    }

    $channel = filter_bot_channel_get($pdo, FILTER_BOT_PLATFORM_MAX, (string)$meta['chat_id']);
    if (!is_array($channel) || (int)($channel['enabled'] ?? 0) !== 1) {
      return ['ok' => true, 'http' => 200, 'message' => 'chat_not_bound'];
    }

    filter_bot_channel_touch(
      $pdo,
      FILTER_BOT_PLATFORM_MAX,
      (string)$meta['chat_id'],
      (string)$meta['chat_title'],
      (string)$meta['chat_type']
    );

    if (trim((string)($settings['max_api_key'] ?? '')) === '') {
      return ['ok' => false, 'http' => 500, 'message' => 'max_api_key_empty'];
    }

    if ((int)($settings['max_skip_admins'] ?? 1) === 1 && filter_bot_max_is_admin_sender($settings, $meta)) {
      return ['ok' => true, 'http' => 200, 'message' => 'admin_skipped'];
    }

    $rule = filter_bot_pick_rule_hit((string)($meta['message_text'] ?? ''), (array)($meta['markup_urls'] ?? []), $settings);
    if ((string)($rule['rule_code'] ?? '') === '') {
      return ['ok' => true, 'http' => 200, 'message' => 'clean'];
    }

    $warningText = filter_bot_max_warn_text($settings, (string)$rule['rule_code']);
    $updateBody = [
      'text' => $warningText,
      'attachments' => [],
    ];

    $edited = filter_bot_max_update_message($settings, (string)$meta['chat_id'], (string)$meta['message_id'], $updateBody);
    $editOk = (($edited['ok'] ?? false) === true);

    filter_bot_log_event($pdo, [
      'platform' => FILTER_BOT_PLATFORM_MAX,
      'chat_id' => (string)$meta['chat_id'],
      'chat_title' => (string)$meta['chat_title'],
      'chat_type' => (string)$meta['chat_type'],
      'message_id' => (string)$meta['message_id'],
      'from_id' => (string)$meta['from_id'],
      'from_name' => (string)$meta['from_name'],
      'message_text' => (string)$meta['message_text'],
      'rule_code' => (string)$rule['rule_code'],
      'action_code' => 'edit',
      'status' => $editOk ? 'ok' : 'error',
      'error_text' => $editOk ? '' : trim((string)($edited['error'] ?? 'MAX_EDIT_FAILED')),
      'raw_meta' => [
        'matched_url' => (string)($rule['matched_url'] ?? ''),
        'match' => (string)($rule['match'] ?? ''),
      ],
    ]);

    audit_log(FILTER_BOT_MODULE_CODE, 'max_moderation', $editOk ? 'info' : 'error', [
      'chat_id' => (string)$meta['chat_id'],
      'message_id' => (string)$meta['message_id'],
      'rule_code' => (string)$rule['rule_code'],
      'match' => (string)($rule['match'] ?? ''),
      'matched_url' => (string)($rule['matched_url'] ?? ''),
      'status' => $editOk ? 'ok' : 'error',
    ]);

    return [
      'ok' => $editOk,
      'http' => $editOk ? 200 : 500,
      'message' => $editOk ? 'moderated' : 'edit_failed',
    ];
  }
}
