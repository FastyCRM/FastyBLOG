<?php
/**
 * FILE: /adm/modules/max_comments/assets/php/max_comments_lib.php
 * ROLE: Бизнес-логика модуля max_comments.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once ROOT_PATH . '/adm/modules/channel_bridge/settings.php';
require_once ROOT_PATH . '/adm/modules/channel_bridge/assets/php/channel_bridge_lib.php';

if (!function_exists('max_comments_now')) {
  function max_comments_now(): string
  {
    return date('Y-m-d H:i:s');
  }

  function max_comments_public_root_url(): string
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

  function max_comments_webhook_path(): string
  {
    $rootPath = '/max-comments-webhook.php';
    if (is_file(ROOT_PATH . $rootPath)) {
      return $rootPath;
    }

    $modulePath = '/adm/modules/max_comments/webhook.php';
    if (is_file(ROOT_PATH . $modulePath)) {
      return $modulePath;
    }

    // Fallback when public endpoints are unavailable in current deployment.
    return '/core/max_comments_webhook.php';
  }

  function max_comments_webhook_url(bool $absolute = true): string
  {
    $resolvedPath = max_comments_webhook_path();
    $path = function_exists('url')
      ? (string)url($resolvedPath)
      : $resolvedPath;

    if (!$absolute) return $path;

    $root = max_comments_public_root_url();
    if ($root === '') return $path;

    return $root . $path;
  }

  function max_comments_miniapp_url(bool $absolute = true): string
  {
    $path = function_exists('url')
      ? (string)url('/adm/modules/max_comments/miniapp/index.html')
      : '/adm/modules/max_comments/miniapp/index.html';

    if (!$absolute) return $path;

    $root = max_comments_public_root_url();
    if ($root === '') return $path;

    return $root . $path;
  }

  function max_comments_defaults(): array
  {
    return [
      'id' => 1,
      'enabled' => 0,
      'button_text' => 'Комментарии',
      'max_api_key' => '',
    ];
  }

  function max_comments_is_list_array(array $value): bool
  {
    $i = 0;
    foreach (array_keys($value) as $k) {
      if ($k !== $i) return false;
      $i++;
    }
    return true;
  }

  function max_comments_require_schema(PDO $pdo): void
  {
    $dbName = trim((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
    if ($dbName === '') {
      throw new RuntimeException('Не удалось определить текущую БД.');
    }

    $need = [
      MAX_COMMENTS_TABLE_SETTINGS,
      MAX_COMMENTS_TABLE_CHANNELS,
      MAX_COMMENTS_TABLE_PROCESSED,
    ];

    $placeholders = implode(',', array_fill(0, count($need), '?'));
    $sql = "
      SELECT table_name
      FROM information_schema.tables
      WHERE table_schema = ?
        AND table_name IN ($placeholders)
    ";
    $params = array_merge([$dbName], $need);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

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
        'Не применен SQL модуля max_comments. Отсутствуют таблицы: ' . implode(', ', $missing)
      );
    }
  }

  function max_comments_table_has_column(PDO $pdo, string $table, string $column): bool
  {
    $table = trim($table);
    $column = trim($column);
    if ($table === '' || $column === '') return false;

    static $cache = [];
    $dbName = trim((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
    if ($dbName === '') return false;

    $cacheKey = $dbName . ':' . $table . ':' . $column;
    if (array_key_exists($cacheKey, $cache)) {
      return (bool)$cache[$cacheKey];
    }

    $stmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.columns
      WHERE table_schema = :db
        AND table_name = :table_name
        AND column_name = :column_name
    ");
    $stmt->execute([
      ':db' => $dbName,
      ':table_name' => $table,
      ':column_name' => $column,
    ]);
    $exists = ((int)$stmt->fetchColumn() > 0);
    $cache[$cacheKey] = $exists ? 1 : 0;
    return $exists;
  }

  function max_comments_settings_get(PDO $pdo): array
  {
    $base = max_comments_defaults();
    $hasApiKey = max_comments_table_has_column($pdo, MAX_COMMENTS_TABLE_SETTINGS, 'max_api_key');
    $select = $hasApiKey ? 'id, enabled, button_text, max_api_key' : 'id, enabled, button_text';
    $stmt = $pdo->query("
      SELECT $select
      FROM " . MAX_COMMENTS_TABLE_SETTINGS . "
      WHERE id = 1
      LIMIT 1
    ");
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!$row) return $base;

    $base['enabled'] = ((int)($row['enabled'] ?? 0) === 1) ? 1 : 0;
    $base['button_text'] = trim((string)($row['button_text'] ?? 'Комментарии'));
    if ($base['button_text'] === '') $base['button_text'] = 'Комментарии';
    if ($hasApiKey) {
      $apiKey = trim((string)($row['max_api_key'] ?? ''));
      if (stripos($apiKey, 'Bearer ') === 0) {
        $apiKey = trim((string)substr($apiKey, 7));
      }
      if (stripos($apiKey, 'OAuth ') === 0) {
        $apiKey = trim((string)substr($apiKey, 6));
      }
      $base['max_api_key'] = $apiKey;
    }

    return $base;
  }

  function max_comments_settings_save(PDO $pdo, array $input): void
  {
    $enabled = ((int)($input['enabled'] ?? 0) === 1) ? 1 : 0;
    $buttonText = trim((string)($input['button_text'] ?? 'Комментарии'));
    $apiKey = trim((string)($input['max_api_key'] ?? ''));
    if (stripos($apiKey, 'Bearer ') === 0) {
      $apiKey = trim((string)substr($apiKey, 7));
    }
    if (stripos($apiKey, 'OAuth ') === 0) {
      $apiKey = trim((string)substr($apiKey, 6));
    }

    if ($buttonText === '') $buttonText = 'Комментарии';
    if (function_exists('mb_substr')) {
      $buttonText = (string)mb_substr($buttonText, 0, 64);
      $apiKey = (string)mb_substr($apiKey, 0, 255);
    } else {
      $buttonText = (string)substr($buttonText, 0, 64);
      $apiKey = (string)substr($apiKey, 0, 255);
    }

    $hasApiKey = max_comments_table_has_column($pdo, MAX_COMMENTS_TABLE_SETTINGS, 'max_api_key');
    if ($hasApiKey) {
      $sql = "
        INSERT INTO " . MAX_COMMENTS_TABLE_SETTINGS . "
        (id, enabled, button_text, max_api_key)
        VALUES
        (1, :enabled, :button_text, :max_api_key)
        ON DUPLICATE KEY UPDATE
          enabled = VALUES(enabled),
          button_text = VALUES(button_text),
          max_api_key = VALUES(max_api_key)
      ";
      $stmt = $pdo->prepare($sql);
      $stmt->execute([
        ':enabled' => $enabled,
        ':button_text' => $buttonText,
        ':max_api_key' => $apiKey,
      ]);
      return;
    }

    $sql = "
      INSERT INTO " . MAX_COMMENTS_TABLE_SETTINGS . "
      (id, enabled, button_text)
      VALUES
      (1, :enabled, :button_text)
      ON DUPLICATE KEY UPDATE
        enabled = VALUES(enabled),
        button_text = VALUES(button_text)
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':enabled' => $enabled,
      ':button_text' => $buttonText,
    ]);
  }

  function max_comments_channels_get(PDO $pdo): array
  {
    $stmt = $pdo->query("
      SELECT chat_id, title, enabled
      FROM " . MAX_COMMENTS_TABLE_CHANNELS . "
      ORDER BY title ASC, chat_id ASC
    ");
    if (!$stmt) return [];

    return (array)$stmt->fetchAll(PDO::FETCH_ASSOC);
  }

  function max_comments_channels_replace(PDO $pdo, array $chatRows): void
  {
    $pdo->exec("DELETE FROM " . MAX_COMMENTS_TABLE_CHANNELS);

    if (!$chatRows) return;

    $sql = "
      INSERT INTO " . MAX_COMMENTS_TABLE_CHANNELS . "
      (chat_id, title, enabled)
      VALUES
      (:chat_id, :title, :enabled)
    ";
    $stmt = $pdo->prepare($sql);

    foreach ($chatRows as $row) {
      $chatId = trim((string)($row['chat_id'] ?? ''));
      if ($chatId === '') continue;

      $title = trim((string)($row['title'] ?? ''));
      if (function_exists('mb_substr')) {
        $title = (string)mb_substr($title, 0, 190);
      } else {
        $title = (string)substr($title, 0, 190);
      }

      $enabled = ((int)($row['enabled'] ?? 0) === 1) ? 1 : 0;
      $stmt->execute([
        ':chat_id' => $chatId,
        ':title' => $title,
        ':enabled' => $enabled,
      ]);
    }
  }

  function max_comments_channel_enabled(PDO $pdo, string $chatId): bool
  {
    $chatId = trim($chatId);
    if ($chatId === '') return false;

    $stmt = $pdo->prepare("
      SELECT id
      FROM " . MAX_COMMENTS_TABLE_CHANNELS . "
      WHERE chat_id = :chat_id
        AND enabled = 1
      LIMIT 1
    ");
    $stmt->execute([':chat_id' => $chatId]);
    return (bool)$stmt->fetchColumn();
  }

  function max_comments_processed_mark_new(PDO $pdo, string $chatId, string $messageId, string $raw): bool
  {
    $chatId = trim($chatId);
    $messageId = trim($messageId);
    if ($chatId === '' || $messageId === '') return false;

    if (function_exists('mb_substr')) {
      $raw = (string)mb_substr($raw, 0, 8000);
    } else {
      $raw = (string)substr($raw, 0, 8000);
    }

    try {
      $stmt = $pdo->prepare("
        INSERT INTO " . MAX_COMMENTS_TABLE_PROCESSED . "
        (chat_id, message_id, status, error_text, raw_update)
        VALUES
        (:chat_id, :message_id, 'new', '', :raw_update)
      ");
      $stmt->execute([
        ':chat_id' => $chatId,
        ':message_id' => $messageId,
        ':raw_update' => $raw,
      ]);
      return true;
    } catch (Throwable $e) {
      return false;
    }
  }

  function max_comments_processed_update(PDO $pdo, string $chatId, string $messageId, string $status, string $errorText = ''): void
  {
    $chatId = trim($chatId);
    $messageId = trim($messageId);
    if ($chatId === '' || $messageId === '') return;

    $status = trim($status);
    if ($status === '') $status = 'done';
    if (function_exists('mb_substr')) {
      $status = (string)mb_substr($status, 0, 16);
      $errorText = (string)mb_substr($errorText, 0, 255);
    } else {
      $status = (string)substr($status, 0, 16);
      $errorText = (string)substr($errorText, 0, 255);
    }

    $stmt = $pdo->prepare("
      UPDATE " . MAX_COMMENTS_TABLE_PROCESSED . "
      SET status = :status,
          error_text = :error_text
      WHERE chat_id = :chat_id
        AND message_id = :message_id
      LIMIT 1
    ");
    $stmt->execute([
      ':status' => $status,
      ':error_text' => $errorText,
      ':chat_id' => $chatId,
      ':message_id' => $messageId,
    ]);
  }

  function max_comments_bridge_settings(PDO $pdo): array
  {
    $settings = max_comments_settings_get($pdo);
    $apiKey = trim((string)($settings['max_api_key'] ?? ''));
    $baseUrl = 'https://platform-api.max.ru';

    return [
      'max_enabled' => ((int)($settings['enabled'] ?? 0) === 1) ? 1 : 0,
      'max_api_key' => $apiKey,
      'max_base_url' => $baseUrl,
    ];
  }

  function max_comments_bridge_route_chats(PDO $pdo): array
  {
    if (!defined('CHANNEL_BRIDGE_TABLE_ROUTES')) {
      return ['ok' => false, 'error' => 'CHANNEL_BRIDGE_DISABLED', 'items' => []];
    }

    $sql = "
      SELECT target_chat_id, title, enabled
      FROM " . CHANNEL_BRIDGE_TABLE_ROUTES . "
      WHERE target_platform = :platform
        AND target_chat_id <> ''
      ORDER BY id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':platform' => CHANNEL_BRIDGE_TARGET_MAX]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) $rows = [];

    $map = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      if ((int)($row['enabled'] ?? 0) !== 1) continue;

      $chatId = trim((string)($row['target_chat_id'] ?? ''));
      if ($chatId === '') continue;

      if (!isset($map[$chatId])) {
        $map[$chatId] = [
          'chat_id' => $chatId,
          'title' => trim((string)($row['title'] ?? '')),
          'type' => 'channel',
        ];
      }
    }

    return ['ok' => true, 'error' => '', 'items' => array_values($map)];
  }

  function max_comments_bridge_ready(array $bridge, ?string &$error = null): bool
  {
    if (trim((string)($bridge['max_api_key'] ?? '')) === '') {
      $error = 'MAX API key не заполнен в модуле max_comments.';
      return false;
    }
    if (trim((string)($bridge['max_base_url'] ?? '')) === '') {
      $error = 'MAX base URL не заполнен в модуле max_comments.';
      return false;
    }
    return true;
  }

  function max_comments_http_request_json(
    string $method,
    string $url,
    array $headers = [],
    ?array $payload = null,
    int $timeout = 20
  ): array {
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
      if (!is_string($jsonPayload)) {
        $jsonPayload = '{}';
      }
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
        return [
          'ok' => false,
          'error' => 'CURL_ERROR',
          'description' => $curlError,
          'http_code' => $httpCode,
          'json' => [],
          'raw' => '',
        ];
      }
      $raw = (string)$result;
    } else {
      $headersTxt = "Accept: application/json\r\n";
      foreach ($headers as $h) {
        $headersTxt .= trim((string)$h) . "\r\n";
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
        return [
          'ok' => false,
          'error' => 'HTTP_ERROR',
          'http_code' => $httpCode,
          'json' => [],
          'raw' => '',
        ];
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

  function max_comments_pick_max_chats(array $json): array
  {
    $list = [];
    if (isset($json['chats']) && is_array($json['chats'])) {
      $list = $json['chats'];
    } elseif (isset($json['items']) && is_array($json['items'])) {
      $list = $json['items'];
    } elseif (isset($json['result']['chats']) && is_array($json['result']['chats'])) {
      $list = $json['result']['chats'];
    } elseif (isset($json['result']) && is_array($json['result'])) {
      $list = $json['result'];
    }

    $out = [];
    foreach ($list as $item) {
      if (!is_array($item)) continue;
      $chat = (array)$item;
      $chatId = trim((string)($chat['chat_id'] ?? $chat['id'] ?? ''));
      if ($chatId === '') continue;

      $out[] = [
        'chat_id' => $chatId,
        'title' => trim((string)($chat['title'] ?? $chat['name'] ?? '')),
        'type' => trim((string)($chat['type'] ?? $chat['chat_type'] ?? '')),
      ];
    }

    return $out;
  }

  function max_comments_bridge_fetch_chats(array $bridge): array
  {
    $err = '';
    if (!max_comments_bridge_ready($bridge, $err)) {
      return ['ok' => false, 'error' => $err, 'items' => []];
    }

    $url = rtrim((string)$bridge['max_base_url'], '/') . '/chats?count=100';
    $res = max_comments_http_request_json('GET', $url, [
      'Authorization: ' . (string)$bridge['max_api_key'],
    ], null, 25);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($res['error'] ?? 'HTTP_ERROR'), 'items' => []];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    if ($httpCode < 200 || $httpCode >= 300) {
      $j = (array)($res['json'] ?? []);
      $apiErr = trim((string)($j['message'] ?? $j['error'] ?? ''));
      if ($apiErr === '') $apiErr = 'HTTP_' . $httpCode;
      return ['ok' => false, 'error' => $apiErr, 'items' => []];
    }

    return [
      'ok' => true,
      'error' => '',
      'items' => max_comments_pick_max_chats((array)($res['json'] ?? [])),
    ];
  }

  function max_comments_bridge_chat_me(array $bridge, string $chatId): array
  {
    $chatId = trim($chatId);
    if ($chatId === '') return ['ok' => false, 'error' => 'CHAT_ID_EMPTY'];

    $url = rtrim((string)$bridge['max_base_url'], '/')
      . '/chats/' . rawurlencode($chatId) . '/members/me';

    $res = max_comments_http_request_json('GET', $url, [
      'Authorization: ' . (string)$bridge['max_api_key'],
    ], null, 20);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($res['error'] ?? 'HTTP_ERROR')];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    $json = (array)($res['json'] ?? []);
    if ($httpCode < 200 || $httpCode >= 300) {
      $apiErr = trim((string)($json['message'] ?? $json['error'] ?? ''));
      if ($apiErr === '') $apiErr = 'HTTP_' . $httpCode;
      return ['ok' => false, 'error' => $apiErr, 'http_code' => $httpCode];
    }

    $member = (array)($json['member'] ?? $json['chat_member'] ?? []);
    $isOwner = ((int)($json['is_owner'] ?? $member['is_owner'] ?? 0) === 1);
    $isAdmin = ((int)($json['is_admin'] ?? $member['is_admin'] ?? 0) === 1) || $isOwner;
    $perms = $json['permissions'] ?? $member['permissions'] ?? [];
    if (!is_array($perms)) $perms = [];

    return [
      'ok' => true,
      'is_admin' => $isAdmin ? 1 : 0,
      'permissions' => $perms,
      'raw' => $json,
    ];
  }

  function max_comments_permissions_can_edit(array $permissions): bool
  {
    if (!$permissions) {
      // Если API не вернул permissions, не даем ложный negative.
      return true;
    }

    $need = [
      'post_edit_delete_message',
      'post_edit_message',
      'edit_message',
      'messages_edit',
      'message_edit',
    ];

    $isAssoc = array_keys($permissions) !== range(0, count($permissions) - 1);
    if ($isAssoc) {
      foreach ($permissions as $k => $v) {
        $key = strtolower(trim((string)$k));
        if (!$v) continue;
        if (in_array($key, $need, true)) return true;
        if (strpos($key, 'edit') !== false && strpos($key, 'message') !== false) return true;
      }
      return false;
    }

    $hasStructured = false;
    foreach ($permissions as $p) {
      if (is_scalar($p)) {
        $code = strtolower(trim((string)$p));
        if ($code === '') continue;
        if (in_array($code, $need, true)) return true;
        if (strpos($code, 'edit') !== false && strpos($code, 'message') !== false) return true;
        continue;
      }

      if (is_array($p)) {
        $hasStructured = true;
        $code = strtolower(trim((string)($p['permission'] ?? $p['name'] ?? $p['code'] ?? '')));
        $allowed = array_key_exists('allowed', $p) ? (bool)$p['allowed'] : true;
        if (!$allowed || $code === '') continue;
        if (in_array($code, $need, true)) return true;
        if (strpos($code, 'edit') !== false && strpos($code, 'message') !== false) return true;
      }
    }

    // Неизвестный формат списка: не блокируем.
    return !$hasStructured;
  }

  function max_comments_bridge_check_chat_access(array $bridge, array $chatIds): array
  {
    $uniq = [];
    foreach ($chatIds as $cid) {
      $v = trim((string)$cid);
      if ($v === '') continue;
      // Не используем numeric-string как ключ массива: PHP приведет его к int.
      $uniq['cid:' . $v] = $v;
    }
    $chatIds = array_values($uniq);
    if (!$chatIds) {
      return ['ok' => true, 'checked' => 0, 'not_admin' => [], 'no_edit_perm' => [], 'errors' => []];
    }

    $notAdmin = [];
    $noEditPerm = [];
    $errors = [];

    foreach ($chatIds as $chatId) {
      $chatId = trim((string)$chatId);
      if ($chatId === '') continue;
      $one = max_comments_bridge_chat_me($bridge, $chatId);
      if (($one['ok'] ?? false) !== true) {
        $errors[] = [
          'chat_id' => $chatId,
          'error' => trim((string)($one['error'] ?? 'CHECK_FAILED')),
        ];
        continue;
      }

      if ((int)($one['is_admin'] ?? 0) !== 1) {
        $notAdmin[] = $chatId;
        continue;
      }

      $raw = (array)($one['raw'] ?? []);
      if (array_key_exists('permissions', $raw)
        || (isset($raw['member']) && is_array($raw['member']) && array_key_exists('permissions', $raw['member']))
        || (isset($raw['chat_member']) && is_array($raw['chat_member']) && array_key_exists('permissions', $raw['chat_member']))) {
        $perms = (array)($one['permissions'] ?? []);
        if (!max_comments_permissions_can_edit($perms)) {
          $noEditPerm[] = $chatId;
        }
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

  function max_comments_pick_first_message(array $json): array
  {
    if (isset($json['message']) && is_array($json['message'])) {
      return (array)$json['message'];
    }

    $candidates = [];
    if (isset($json['messages']) && is_array($json['messages'])) $candidates[] = $json['messages'];
    if (isset($json['items']) && is_array($json['items'])) $candidates[] = $json['items'];
    if (isset($json['result']['messages']) && is_array($json['result']['messages'])) $candidates[] = $json['result']['messages'];
    if (isset($json['result']['items']) && is_array($json['result']['items'])) $candidates[] = $json['result']['items'];
    if (isset($json['result']) && is_array($json['result'])) $candidates[] = $json['result'];

    foreach ($candidates as $list) {
      if (!is_array($list)) continue;
      foreach ($list as $item) {
        if (!is_array($item)) continue;
        $body = $item['body'] ?? null;
        if (is_array($body) || isset($item['message_id']) || isset($item['id']) || isset($item['mid'])) {
          return (array)$item;
        }
      }
    }

    return [];
  }

  function max_comments_message_body(array $message): array
  {
    $body = (array)($message['body'] ?? []);

    $text = (string)($body['text'] ?? $message['text'] ?? '');
    $format = trim((string)($body['format'] ?? $message['format'] ?? ''));
    $attachments = $body['attachments'] ?? $message['attachments'] ?? [];
    if (!is_array($attachments)) $attachments = [];

    return [
      'text' => $text,
      'format' => $format,
      'attachments' => $attachments,
    ];
  }

  function max_comments_message_id_from_message(array $message, array $extra = []): string
  {
    $body = (array)($message['body'] ?? []);
    $stat = (array)($message['stat'] ?? []);

    $candidates = [
      $message['message_id'] ?? null,
      $message['id'] ?? null,
      $message['mid'] ?? null,
      $body['message_id'] ?? null,
      $body['id'] ?? null,
      $body['mid'] ?? null,
      $body['msg_id'] ?? null,
      $stat['message_id'] ?? null,
      $stat['id'] ?? null,
      $stat['mid'] ?? null,
      $extra['message_id'] ?? null,
      $extra['id'] ?? null,
      $extra['mid'] ?? null,
    ];

    foreach ($candidates as $raw) {
      if (!is_scalar($raw)) continue;
      $id = trim((string)$raw);
      if ($id !== '') return $id;
    }

    $links = [
      (string)($message['link'] ?? ''),
      (string)($body['link'] ?? ''),
      (string)($message['url'] ?? ''),
      (string)($body['url'] ?? ''),
    ];
    foreach ($links as $link) {
      $link = trim($link);
      if ($link === '') continue;
      if (preg_match('~(?:^|[/?=&])(-?\d{6,})(?:$|[/?&#])~', $link, $m)) {
        $id = trim((string)($m[1] ?? ''));
        if ($id !== '') return $id;
      }
    }

    return '';
  }

  function max_comments_bridge_bot_profile(array $bridge): array
  {
    $url = rtrim((string)($bridge['max_base_url'] ?? ''), '/');
    if ($url === '') return ['username' => '', 'user_id' => 0];
    $url .= '/me';

    $res = max_comments_http_request_json('GET', $url, [
      'Authorization: ' . (string)($bridge['max_api_key'] ?? ''),
    ], null, 15);
    if (($res['ok'] ?? false) !== true) return ['username' => '', 'user_id' => 0];

    $httpCode = (int)($res['http_code'] ?? 0);
    if ($httpCode < 200 || $httpCode >= 300) return ['username' => '', 'user_id' => 0];

    $json = (array)($res['json'] ?? []);
    $name = trim((string)($json['username'] ?? $json['user']['username'] ?? $json['result']['username'] ?? ''));
    if ($name === '') return ['username' => '', 'user_id' => 0];
    if (strpos($name, '@') === 0) $name = substr($name, 1);
    $userId = (int)($json['user_id'] ?? $json['user']['user_id'] ?? $json['result']['user_id'] ?? 0);

    return [
      'username' => trim((string)$name),
      'user_id' => ($userId > 0 ? $userId : 0),
    ];
  }

  function max_comments_bridge_bot_username(array $bridge): string
  {
    $p = max_comments_bridge_bot_profile($bridge);
    return trim((string)($p['username'] ?? ''));
  }

  function max_comments_open_app_button(array $settings, array $bridge = []): array
  {
    $text = trim((string)($settings['button_text'] ?? 'Комментарии'));
    if ($text === '') $text = 'Комментарии';
    $profile = max_comments_bridge_bot_profile($bridge);
    $botUsername = trim((string)($profile['username'] ?? ''));
    $botUserId = (int)($profile['user_id'] ?? 0);
    if ($botUsername === '' && $botUserId > 0) {
      // Stable fallback format for MAX bot usernames by numeric id.
      $botUsername = 'id' . $botUserId . '_bot';
    }

    $btn = ['type' => 'open_app', 'text' => $text];
    if ($botUsername !== '') {
      $btn['web_app'] = $botUsername;
    }
    if ($botUserId > 0) {
      $btn['contact_id'] = $botUserId;
    }

    return $btn;
  }

  function max_comments_open_app_button_valid(array $btn): bool
  {
    return trim((string)($btn['web_app'] ?? '')) !== '';
  }

  function max_comments_attachments_has_open_app(array $attachments): bool
  {
    foreach ($attachments as $attachment) {
      if (!is_array($attachment)) continue;
      if (trim((string)($attachment['type'] ?? '')) !== 'inline_keyboard') continue;

      $payload = (array)($attachment['payload'] ?? []);
      $rows = $payload['buttons'] ?? [];
      if (!is_array($rows)) continue;

      foreach ($rows as $row) {
        if (!is_array($row)) continue;
        foreach ($row as $btn) {
          if (!is_array($btn)) continue;
          if (trim((string)($btn['type'] ?? '')) === 'open_app') {
            return true;
          }
        }
      }
    }

    return false;
  }

  function max_comments_message_add_open_app_button(array $attachments, array $settings, bool &$changed, array $bridge = []): array
  {
    $changed = false;
    $btn = max_comments_open_app_button($settings, $bridge);
    if (!max_comments_open_app_button_valid($btn)) {
      return $attachments;
    }

    foreach ($attachments as $idx => $attachment) {
      if (!is_array($attachment)) continue;
      if (trim((string)($attachment['type'] ?? '')) !== 'inline_keyboard') continue;

      $payload = (array)($attachment['payload'] ?? []);
      $rows = $payload['buttons'] ?? [];
      if (!is_array($rows)) $rows = [];

      foreach ($rows as $rIdx => $row) {
        if (!is_array($row)) continue;
        foreach ($row as $bIdx => $item) {
          if (!is_array($item)) continue;
          if (trim((string)($item['type'] ?? '')) !== 'open_app') continue;

          if ($item !== $btn) {
            $rows[$rIdx][$bIdx] = $btn;
            $payload['buttons'] = $rows;
            $attachment['payload'] = $payload;
            $attachments[$idx] = $attachment;
            $changed = true;
          }
          return $attachments;
        }
      }

      $rows[] = [$btn];
      $payload['buttons'] = $rows;
      $attachment['payload'] = $payload;
      $attachments[$idx] = $attachment;
      $changed = true;
      return $attachments;
    }

    $attachments[] = [
      'type' => 'inline_keyboard',
      'payload' => [
        'buttons' => [
          [$btn],
        ],
      ],
    ];
    $changed = true;
    return $attachments;
  }

  function max_comments_bridge_get_message(array $bridge, string $chatId, string $messageId): array
  {
    $chatId = trim($chatId);
    $messageId = trim($messageId);
    if ($chatId === '' || $messageId === '') {
      return ['ok' => false, 'error' => 'CHAT_OR_MESSAGE_ID_EMPTY'];
    }

    $url = rtrim((string)$bridge['max_base_url'], '/')
      . '/messages?chat_id=' . rawurlencode($chatId)
      . '&message_ids=' . rawurlencode($messageId);

    $res = max_comments_http_request_json('GET', $url, [
      'Authorization: ' . (string)$bridge['max_api_key'],
    ], null, 25);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($res['error'] ?? 'HTTP_ERROR')];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    if ($httpCode < 200 || $httpCode >= 300) {
      $j = (array)($res['json'] ?? []);
      $apiErr = trim((string)($j['message'] ?? $j['error'] ?? ''));
      if ($apiErr === '') $apiErr = 'HTTP_' . $httpCode;
      return ['ok' => false, 'error' => $apiErr];
    }

    $message = max_comments_pick_first_message((array)($res['json'] ?? []));
    if (!$message) {
      return ['ok' => false, 'error' => 'MESSAGE_NOT_FOUND'];
    }

    return ['ok' => true, 'message' => $message];
  }

  function max_comments_bridge_get_last_message(array $bridge, string $chatId): array
  {
    $chatId = trim($chatId);
    if ($chatId === '') {
      return ['ok' => false, 'error' => 'CHAT_ID_EMPTY'];
    }

    $base = rtrim((string)$bridge['max_base_url'], '/') . '/messages?chat_id=' . rawurlencode($chatId);
    $urls = [
      $base . '&count=1',
      $base . '&limit=1',
      $base,
    ];

    $lastError = '';
    foreach ($urls as $url) {
      $res = max_comments_http_request_json('GET', $url, [
        'Authorization: ' . (string)$bridge['max_api_key'],
      ], null, 25);

      if (($res['ok'] ?? false) !== true) {
        $lastError = (string)($res['error'] ?? 'HTTP_ERROR');
        continue;
      }

      $httpCode = (int)($res['http_code'] ?? 0);
      if ($httpCode < 200 || $httpCode >= 300) {
        $j = (array)($res['json'] ?? []);
        $apiErr = trim((string)($j['message'] ?? $j['error'] ?? ''));
        if ($apiErr === '') $apiErr = 'HTTP_' . $httpCode;
        $lastError = $apiErr;
        continue;
      }

      $message = max_comments_pick_first_message((array)($res['json'] ?? []));
      if ($message) {
        return ['ok' => true, 'message' => $message];
      }

      $lastError = 'MESSAGE_NOT_FOUND';
    }

    return ['ok' => false, 'error' => ($lastError !== '' ? $lastError : 'MESSAGE_NOT_FOUND')];
  }

  function max_comments_pick_messages(array $json): array
  {
    if (isset($json['message']) && is_array($json['message'])) {
      return [(array)$json['message']];
    }

    $candidates = [];
    if (isset($json['messages']) && is_array($json['messages'])) $candidates[] = $json['messages'];
    if (isset($json['items']) && is_array($json['items'])) $candidates[] = $json['items'];
    if (isset($json['result']['messages']) && is_array($json['result']['messages'])) $candidates[] = $json['result']['messages'];
    if (isset($json['result']['items']) && is_array($json['result']['items'])) $candidates[] = $json['result']['items'];
    if (isset($json['result']) && is_array($json['result']) && max_comments_is_list_array($json['result'])) $candidates[] = $json['result'];
    if (max_comments_is_list_array($json)) $candidates[] = $json;

    foreach ($candidates as $list) {
      if (!is_array($list)) continue;
      $out = [];
      foreach ($list as $item) {
        if (!is_array($item)) continue;
        $out[] = (array)$item;
      }
      if ($out) return $out;
    }

    return [];
  }

  function max_comments_bridge_get_recent_messages(array $bridge, string $chatId, int $limit = 20): array
  {
    $chatId = trim($chatId);
    if ($chatId === '') {
      return ['ok' => false, 'error' => 'CHAT_ID_EMPTY', 'messages' => []];
    }

    $limit = max(1, min(50, $limit));
    $base = rtrim((string)$bridge['max_base_url'], '/') . '/messages?chat_id=' . rawurlencode($chatId);
    $urls = [
      $base . '&count=' . $limit,
      $base . '&limit=' . $limit,
      $base,
    ];

    $lastError = '';
    $lastHttp = 0;
    $lastUrl = '';
    foreach ($urls as $url) {
      $lastUrl = $url;
      $res = max_comments_http_request_json('GET', $url, [
        'Authorization: ' . (string)$bridge['max_api_key'],
      ], null, 25);

      if (($res['ok'] ?? false) !== true) {
        $lastError = (string)($res['error'] ?? 'HTTP_ERROR');
        $lastHttp = (int)($res['http_code'] ?? 0);
        continue;
      }

      $httpCode = (int)($res['http_code'] ?? 0);
      $json = (array)($res['json'] ?? []);
      if ($httpCode < 200 || $httpCode >= 300) {
        $apiErr = trim((string)($json['message'] ?? $json['error'] ?? ''));
        if ($apiErr === '') $apiErr = 'HTTP_' . $httpCode;
        $lastError = $apiErr;
        $lastHttp = $httpCode;
        continue;
      }

      return [
        'ok' => true,
        'messages' => max_comments_pick_messages($json),
        'url' => $url,
        'http_code' => $httpCode,
        'error' => '',
      ];
    }

    return [
      'ok' => false,
      'messages' => [],
      'url' => $lastUrl,
      'http_code' => $lastHttp,
      'error' => ($lastError !== '' ? $lastError : 'MESSAGES_FAILED'),
    ];
  }

  function max_comments_poll_chat(PDO $pdo, array $bridge, array $settings, string $chatId, int $limit = 20): array
  {
    $chatId = trim($chatId);
    if ($chatId === '') {
      return ['ok' => false, 'chat_id' => '', 'error' => 'CHAT_ID_EMPTY'];
    }

    $read = max_comments_bridge_get_recent_messages($bridge, $chatId, $limit);
    if (($read['ok'] ?? false) !== true) {
      return [
        'ok' => false,
        'chat_id' => $chatId,
        'error' => (string)($read['error'] ?? 'READ_MESSAGES_FAILED'),
        'http_code' => (int)($read['http_code'] ?? 0),
        'url' => (string)($read['url'] ?? ''),
      ];
    }

    $stats = [
      'ok' => true,
      'chat_id' => $chatId,
      'url' => (string)($read['url'] ?? ''),
      'scanned' => 0,
      'duplicates' => 0,
      'skipped_no_id' => 0,
      'already_with_button' => 0,
      'changed' => 0,
      'errors' => [],
    ];

    $messages = (array)($read['messages'] ?? []);
    foreach ($messages as $message) {
      if (!is_array($message)) continue;
      $stats['scanned']++;

      $messageId = max_comments_message_id_from_message((array)$message);
      if ($messageId === '') {
        $stats['skipped_no_id']++;
        continue;
      }

      $raw = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if (!is_string($raw) || $raw === '') $raw = '{}';

      $marked = max_comments_processed_mark_new($pdo, $chatId, $messageId, $raw);
      if (!$marked) {
        $stats['duplicates']++;
        continue;
      }

      $body = max_comments_message_body((array)$message);
      if (max_comments_attachments_has_open_app((array)$body['attachments'])) {
        max_comments_processed_update($pdo, $chatId, $messageId, 'done', '');
        $stats['already_with_button']++;
        continue;
      }

      $applied = max_comments_apply_button_to_message($bridge, $settings, $chatId, $messageId);
      if (($applied['ok'] ?? false) !== true) {
        $err = trim((string)($applied['error'] ?? 'APPLY_FAILED'));
        max_comments_processed_update($pdo, $chatId, $messageId, 'error', $err);
        $stats['errors'][] = [
          'message_id' => $messageId,
          'error' => $err,
        ];
        continue;
      }

      max_comments_processed_update($pdo, $chatId, $messageId, 'done', '');
      if ((int)($applied['changed'] ?? 0) === 1) {
        $stats['changed']++;
      } else {
        $stats['already_with_button']++;
      }
    }

    return $stats;
  }

  function max_comments_pick_updates(array $json): array
  {
    if (isset($json['updates']) && is_array($json['updates'])) {
      return $json['updates'];
    }
    if (isset($json['items']) && is_array($json['items'])) {
      return $json['items'];
    }

    $result = $json['result'] ?? null;
    if (is_array($result)) {
      if (isset($result['updates']) && is_array($result['updates'])) {
        return $result['updates'];
      }
      if (isset($result['items']) && is_array($result['items'])) {
        return $result['items'];
      }
      if (max_comments_is_list_array($result)) {
        return $result;
      }
    }

    if (max_comments_is_list_array($json)) {
      return $json;
    }

    return [];
  }

  function max_comments_bridge_get_updates(array $bridge, int $limit = 20, string $typeFilter = 'message_created'): array
  {
    $limit = max(1, min(100, $limit));
    $base = rtrim((string)$bridge['max_base_url'], '/') . '/updates';
    $typeFilter = trim($typeFilter);

    $queries = [];
    if ($typeFilter !== '') {
      $queries[] = ['count' => $limit, 'timeout' => 0, 'types' => $typeFilter];
      $queries[] = ['limit' => $limit, 'timeout' => 0, 'types' => $typeFilter];
      // Legacy fallback for older gateways/custom proxies.
      $queries[] = ['count' => $limit, 'timeout' => 0, 'update_types' => $typeFilter];
      $queries[] = ['limit' => $limit, 'timeout' => 0, 'update_types' => $typeFilter];
    }
    $queries[] = ['count' => $limit, 'timeout' => 0];
    $queries[] = ['limit' => $limit, 'timeout' => 0];

    $lastError = '';
    $lastHttp = 0;
    foreach ($queries as $q) {
      $url = $base . '?' . http_build_query($q);
      $res = max_comments_http_request_json('GET', $url, [
        'Authorization: ' . (string)$bridge['max_api_key'],
      ], null, 20);
      if (($res['ok'] ?? false) !== true) {
        $lastError = (string)($res['error'] ?? 'HTTP_ERROR');
        $lastHttp = (int)($res['http_code'] ?? 0);
        continue;
      }

      $httpCode = (int)($res['http_code'] ?? 0);
      $json = (array)($res['json'] ?? []);
      if ($httpCode < 200 || $httpCode >= 300) {
        $apiErr = trim((string)($json['message'] ?? $json['error'] ?? ''));
        if ($apiErr === '') $apiErr = 'HTTP_' . $httpCode;
        $lastError = $apiErr;
        $lastHttp = $httpCode;
        continue;
      }

      $updates = max_comments_pick_updates($json);
      $marker = trim((string)($json['marker'] ?? $json['result']['marker'] ?? ''));
      return [
        'ok' => true,
        'updates' => $updates,
        'marker' => $marker,
        'url' => $url,
        'http_code' => $httpCode,
      ];
    }

    return [
      'ok' => false,
      'error' => ($lastError !== '' ? $lastError : 'UPDATES_FAILED'),
      'http_code' => $lastHttp,
      'updates' => [],
      'marker' => '',
      'url' => '',
    ];
  }

  function max_comments_bridge_update_message(array $bridge, string $chatId, string $messageId, array $body): array
  {
    $url = rtrim((string)$bridge['max_base_url'], '/')
      . '/messages?chat_id=' . rawurlencode(trim($chatId))
      . '&message_id=' . rawurlencode(trim($messageId));

    $res = max_comments_http_request_json('PUT', $url, [
      'Authorization: ' . (string)$bridge['max_api_key'],
    ], $body, 25);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($res['error'] ?? 'HTTP_ERROR')];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    if ($httpCode < 200 || $httpCode >= 300) {
      $j = (array)($res['json'] ?? []);
      $apiErr = trim((string)($j['message'] ?? $j['error'] ?? ''));
      if ($apiErr === '') $apiErr = 'HTTP_' . $httpCode;
      return ['ok' => false, 'error' => $apiErr, 'http_code' => $httpCode];
    }

    return ['ok' => true];
  }

  function max_comments_apply_button_to_message(array $bridge, array $settings, string $chatId, string $messageId): array
  {
    $btn = max_comments_open_app_button($settings, $bridge);
    if (!max_comments_open_app_button_valid($btn)) {
      return ['ok' => false, 'error' => 'OPEN_APP_WEB_APP_EMPTY'];
    }

    $fetched = max_comments_bridge_get_message($bridge, $chatId, $messageId);
    if (($fetched['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($fetched['error'] ?? 'FETCH_FAILED')];
    }

    $message = (array)($fetched['message'] ?? []);
    $body = max_comments_message_body($message);

    $changed = false;
    $attachments = max_comments_message_add_open_app_button((array)$body['attachments'], $settings, $changed, $bridge);
    if (!$changed) {
      return ['ok' => true, 'changed' => 0, 'reason' => 'already_has_open_app'];
    }

    $updateBody = [
      'attachments' => $attachments,
    ];

    $updated = max_comments_bridge_update_message($bridge, $chatId, $messageId, $updateBody);
    if (($updated['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($updated['error'] ?? 'UPDATE_FAILED')];
    }

    return ['ok' => true, 'changed' => 1, 'reason' => 'button_attached'];
  }

  function max_comments_bridge_send_test_button(array $bridge, array $settings, string $chatId): array
  {
    $chatId = trim($chatId);
    if ($chatId === '') {
      return ['ok' => false, 'error' => 'CHAT_ID_EMPTY'];
    }

    $btn = max_comments_open_app_button($settings, $bridge);
    if (!max_comments_open_app_button_valid($btn)) {
      return [
        'ok' => false,
        'error' => 'OPEN_APP_WEB_APP_EMPTY',
        'request' => ['button' => $btn],
      ];
    }

    $url = rtrim((string)$bridge['max_base_url'], '/')
      . '/messages?chat_id=' . rawurlencode($chatId);

    $body = [
      'text' => 'Тест кнопки max_comments ' . max_comments_now(),
      'attachments' => [
        [
          'type' => 'inline_keyboard',
          'payload' => [
            'buttons' => [
              [$btn],
            ],
          ],
        ],
      ],
    ];

    $res = max_comments_http_request_json('POST', $url, [
      'Authorization: ' . (string)$bridge['max_api_key'],
    ], $body, 25);
    if (($res['ok'] ?? false) !== true) {
      return [
        'ok' => false,
        'error' => (string)($res['error'] ?? 'HTTP_ERROR'),
        'request' => $body,
      ];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    if ($httpCode < 200 || $httpCode >= 300) {
      $j = (array)($res['json'] ?? []);
      $apiErr = trim((string)($j['message'] ?? $j['error'] ?? ''));
      if ($apiErr === '') $apiErr = 'HTTP_' . $httpCode;
      return [
        'ok' => false,
        'error' => $apiErr,
        'http_code' => $httpCode,
        'raw' => (string)($res['raw'] ?? ''),
        'json' => $j,
        'request' => $body,
      ];
    }

    $json = (array)($res['json'] ?? []);
    $message = max_comments_pick_first_message($json);
    $messageId = max_comments_message_id_from_message($message, $json);

    return ['ok' => true, 'message_id' => $messageId];
  }

  function max_comments_extract_message_meta(array $payload): array
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

    $messageId = max_comments_message_id_from_message($message, $payload);

    return [
      'update_type' => $updateType,
      'chat_id' => $chatId,
      'message_id' => $messageId,
    ];
  }

  function max_comments_subscription_list(array $bridge): array
  {
    $url = rtrim((string)$bridge['max_base_url'], '/') . '/subscriptions?count=100';
    $res = max_comments_http_request_json('GET', $url, [
      'Authorization: ' . (string)$bridge['max_api_key'],
    ], null, 20);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($res['error'] ?? 'HTTP_ERROR'), 'items' => []];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    if ($httpCode < 200 || $httpCode >= 300) {
      $j = (array)($res['json'] ?? []);
      $apiErr = trim((string)($j['message'] ?? $j['error'] ?? ''));
      if ($apiErr === '') $apiErr = 'HTTP_' . $httpCode;
      return ['ok' => false, 'error' => $apiErr, 'items' => []];
    }

    $json = (array)($res['json'] ?? []);
    if (array_key_exists('success', $json) && (bool)$json['success'] === false) {
      $apiErr = trim((string)($json['message'] ?? $json['error'] ?? 'SUBSCRIPTIONS_LIST_FAILED'));
      if ($apiErr === '') $apiErr = 'SUBSCRIPTIONS_LIST_FAILED';
      return ['ok' => false, 'error' => $apiErr, 'items' => []];
    }

    $items = [];
    if (isset($json['subscriptions']) && is_array($json['subscriptions'])) {
      $items = $json['subscriptions'];
    } elseif (isset($json['items']) && is_array($json['items'])) {
      $items = $json['items'];
    } elseif (isset($json['result']['subscriptions']) && is_array($json['result']['subscriptions'])) {
      $items = $json['result']['subscriptions'];
    } elseif (isset($json['result']['items']) && is_array($json['result']['items'])) {
      $items = $json['result']['items'];
    } elseif (isset($json['result']) && is_array($json['result'])) {
      $items = $json['result'];
    }

    return ['ok' => true, 'error' => '', 'items' => is_array($items) ? $items : []];
  }

  function max_comments_api_version(): string
  {
    // Keep in sync with official MAX Bot API client default version.
    return '1.2.5';
  }

  function max_comments_subscription_match(array $subscription, string $endpoint): bool
  {
    $req = (array)($subscription['request'] ?? []);
    $url = trim((string)($subscription['url'] ?? $req['url'] ?? $subscription['endpoint_url'] ?? ''));
    if ($url === '' || $endpoint === '' || $url !== $endpoint) {
      return false;
    }

    $types = $subscription['update_types'] ?? [];
    // Empty update_types means "all updates".
    if (!is_array($types) || !$types) {
      return true;
    }

    foreach ($types as $t) {
      if (trim((string)$t) === 'message_created') {
        return true;
      }
    }
    return false;
  }

  function max_comments_subscription_url(array $subscription): string
  {
    $req = (array)($subscription['request'] ?? []);
    return trim((string)($subscription['url'] ?? $req['url'] ?? $subscription['endpoint_url'] ?? ''));
  }

  function max_comments_subscription_version(array $subscription): string
  {
    $req = (array)($subscription['request'] ?? []);
    return trim((string)($subscription['version'] ?? $req['version'] ?? ''));
  }

  function max_comments_subscription_is_legacy_url(string $url, string $endpointUrl): bool
  {
    $url = trim($url);
    $endpointUrl = trim($endpointUrl);
    if ($url === '' || $endpointUrl === '' || $url === $endpointUrl) return false;

    $u = @parse_url($url);
    $e = @parse_url($endpointUrl);
    if (!is_array($u) || !is_array($e)) return false;

    $uHost = strtolower(trim((string)($u['host'] ?? '')));
    $eHost = strtolower(trim((string)($e['host'] ?? '')));
    if ($uHost === '' || $eHost === '' || $uHost !== $eHost) return false;

    $path = trim((string)($u['path'] ?? ''));
    if ($path === '') return false;

    $legacyPaths = [
      '/adm/modules/max_comments/webhook.php',
      '/core/max_comments_webhook.php',
      '/core/max_webhook_max_post_comments.php',
      '/max-comments-webhook.php',
    ];

    return in_array($path, $legacyPaths, true);
  }

  function max_comments_subscription_delete(array $bridge, string $urlToDelete): array
  {
    $urlToDelete = trim($urlToDelete);
    if ($urlToDelete === '') return ['ok' => false, 'error' => 'SUBSCRIPTION_URL_EMPTY'];

    $url = rtrim((string)$bridge['max_base_url'], '/')
      . '/subscriptions?url=' . rawurlencode($urlToDelete);
    $res = max_comments_http_request_json('DELETE', $url, [
      'Authorization: ' . (string)$bridge['max_api_key'],
    ], null, 25);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($res['error'] ?? 'HTTP_ERROR')];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    $j = (array)($res['json'] ?? []);
    if ($httpCode < 200 || $httpCode >= 300) {
      $apiErr = trim((string)($j['message'] ?? $j['error'] ?? ''));
      if ($apiErr === '') $apiErr = 'HTTP_' . $httpCode;
      return ['ok' => false, 'error' => $apiErr];
    }

    if (array_key_exists('success', $j) && (bool)$j['success'] === false) {
      $apiErr = trim((string)($j['message'] ?? $j['error'] ?? 'UNSUBSCRIBE_FAILED'));
      if ($apiErr === '') $apiErr = 'UNSUBSCRIBE_FAILED';
      return ['ok' => false, 'error' => $apiErr];
    }

    return ['ok' => true];
  }

  function max_comments_ensure_subscription(array $bridge, string $endpointUrl): array
  {
    $endpointUrl = trim($endpointUrl);
    if ($endpointUrl === '') {
      return ['ok' => false, 'error' => 'ENDPOINT_URL_EMPTY'];
    }

    $listed = max_comments_subscription_list($bridge);
    $removed = 0;
    $removeErrors = [];
    $recreate = false;
    $recreateReason = '';
    if (($listed['ok'] ?? false) === true) {
      foreach ((array)($listed['items'] ?? []) as $sub) {
        if (!is_array($sub)) continue;

        $isMatched = max_comments_subscription_match($sub, $endpointUrl);
        if ($isMatched) {
          // Старые подписки без version иногда "висят" и не доставляют события.
          // В этом случае принудительно пересоздаем подписку.
          $ver = max_comments_subscription_version($sub);
          $subReq = (array)($sub['request'] ?? []);
          $subTypes = $sub['update_types'] ?? ($subReq['update_types'] ?? []);
          $isAllUpdates = !is_array($subTypes) || count($subTypes) === 0;
          if ($ver === '' || !$isAllUpdates) {
            $subUrl = max_comments_subscription_url($sub);
            if ($subUrl !== '') {
              $del = max_comments_subscription_delete($bridge, $subUrl);
              if (($del['ok'] ?? false) === true) {
                $removed++;
                $recreate = true;
                $recreateReason = ($ver === '') ? 'missing_version' : 'switch_to_all_updates';
                continue;
              }
              $removeErrors[] = [
                'url' => $subUrl,
                'error' => (string)($del['error'] ?? 'DELETE_FAILED'),
              ];
            }
          } else {
            return [
              'ok' => true,
              'created' => 0,
              'removed' => $removed,
              'remove_errors' => $removeErrors,
              'recreated' => 0,
              'recreate_reason' => '',
            ];
          }
          continue;
        }

        $subUrl = max_comments_subscription_url($sub);
        if (!max_comments_subscription_is_legacy_url($subUrl, $endpointUrl)) {
          continue;
        }

        $del = max_comments_subscription_delete($bridge, $subUrl);
        if (($del['ok'] ?? false) === true) {
          $removed++;
        } else {
          $removeErrors[] = [
            'url' => $subUrl,
            'error' => (string)($del['error'] ?? 'DELETE_FAILED'),
          ];
        }
      }

      // Re-check after cleanup.
      $listed = max_comments_subscription_list($bridge);
      if (($listed['ok'] ?? false) === true) {
        foreach ((array)($listed['items'] ?? []) as $sub) {
          if (is_array($sub) && max_comments_subscription_match($sub, $endpointUrl)) {
            if ($recreate) {
              break;
            }
            return [
              'ok' => true,
              'created' => 0,
              'removed' => $removed,
              'remove_errors' => $removeErrors,
              'recreated' => 0,
              'recreate_reason' => '',
            ];
          }
        }
      }
    }

    $url = rtrim((string)$bridge['max_base_url'], '/') . '/subscriptions';
    $payload = [
      // MAX API ожидает поле "url" в теле подписки.
      'url' => $endpointUrl,
      // No update_types => subscribe to all updates.
      'version' => max_comments_api_version(),
    ];

    $res = max_comments_http_request_json('POST', $url, [
      'Authorization: ' . (string)$bridge['max_api_key'],
    ], $payload, 25);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($res['error'] ?? 'HTTP_ERROR')];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    $j = (array)($res['json'] ?? []);
    if ($httpCode >= 200 && $httpCode < 300) {
      if (array_key_exists('success', $j) && (bool)$j['success'] === false) {
        $apiErr = trim((string)($j['message'] ?? $j['error'] ?? 'SUBSCRIBE_FAILED'));
        if ($apiErr === '') $apiErr = 'SUBSCRIBE_FAILED';
        return [
          'ok' => false,
          'error' => $apiErr,
          'removed' => $removed,
          'remove_errors' => $removeErrors,
          'recreated' => $recreate ? 1 : 0,
          'recreate_reason' => $recreateReason,
        ];
      }
      return [
        'ok' => true,
        'created' => 1,
        'removed' => $removed,
        'remove_errors' => $removeErrors,
        'recreated' => $recreate ? 1 : 0,
        'recreate_reason' => $recreateReason,
      ];
    }

    $apiErr = trim((string)($j['message'] ?? $j['error'] ?? ''));
    if ($apiErr === '') $apiErr = 'HTTP_' . $httpCode;

    return [
      'ok' => false,
      'error' => $apiErr,
      'removed' => $removed,
      'remove_errors' => $removeErrors,
      'recreated' => $recreate ? 1 : 0,
      'recreate_reason' => $recreateReason,
    ];
  }

  function max_comments_webhook_selfcheck(string $endpointUrl): array
  {
    $endpointUrl = trim($endpointUrl);
    if ($endpointUrl === '') {
      return ['ok' => false, 'error' => 'ENDPOINT_URL_EMPTY', 'http_code' => 0];
    }

    $payload = [
      'update_type' => 'healthcheck',
      'time' => max_comments_now(),
    ];

    $res = max_comments_http_request_json('POST', $endpointUrl, [], $payload, 15);
    if (($res['ok'] ?? false) !== true) {
      return [
        'ok' => false,
        'error' => (string)($res['error'] ?? 'HTTP_ERROR'),
        'http_code' => (int)($res['http_code'] ?? 0),
        'raw' => (string)($res['raw'] ?? ''),
      ];
    }

    $httpCode = (int)($res['http_code'] ?? 0);
    $json = (array)($res['json'] ?? []);
    if ($httpCode < 200 || $httpCode >= 300) {
      $apiErr = trim((string)($json['message'] ?? $json['error'] ?? ''));
      if ($apiErr === '') $apiErr = 'HTTP_' . $httpCode;
      return [
        'ok' => false,
        'error' => $apiErr,
        'http_code' => $httpCode,
        'json' => $json,
        'raw' => (string)($res['raw'] ?? ''),
      ];
    }

    return [
      'ok' => true,
      'http_code' => $httpCode,
      'json' => $json,
    ];
  }

  function max_comments_webhook_process(PDO $pdo, ?string $rawInput = null): array
  {
    max_comments_require_schema($pdo);

    $settings = max_comments_settings_get($pdo);
    if ((int)($settings['enabled'] ?? 0) !== 1) {
      return ['ok' => true, 'handled' => 0, 'reason' => 'disabled', 'http' => 200];
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'POST'));
    if ($method !== 'POST') {
      return ['ok' => true, 'handled' => 0, 'reason' => 'ignored_method', 'http' => 200, 'method' => $method];
    }

    $raw = is_string($rawInput) ? $rawInput : (string)file_get_contents('php://input');
    if (trim($raw) === '') {
      return ['ok' => true, 'handled' => 0, 'reason' => 'empty_body', 'http' => 200, 'raw_len' => 0];
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
      return ['ok' => true, 'handled' => 0, 'reason' => 'bad_json', 'http' => 200, 'raw_len' => strlen($raw)];
    }

    $meta = max_comments_extract_message_meta($payload);
    $updateType = trim((string)($meta['update_type'] ?? ''));
    if ($updateType !== '' && $updateType !== 'message_created') {
      return ['ok' => true, 'handled' => 0, 'reason' => 'ignored_update_type', 'update_type' => $updateType, 'http' => 200];
    }

    $chatId = trim((string)($meta['chat_id'] ?? ''));
    $messageId = trim((string)($meta['message_id'] ?? ''));
    if ($chatId === '' || $messageId === '') {
      return [
        'ok' => true,
        'handled' => 0,
        'reason' => 'meta_incomplete',
        'update_type' => $updateType,
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'http' => 200,
      ];
    }

    if (!max_comments_channel_enabled($pdo, $chatId)) {
      return ['ok' => true, 'handled' => 0, 'reason' => 'chat_not_selected', 'chat_id' => $chatId, 'http' => 200];
    }

    $marked = max_comments_processed_mark_new($pdo, $chatId, $messageId, $raw);
    if (!$marked) {
      return ['ok' => true, 'handled' => 0, 'reason' => 'duplicate', 'chat_id' => $chatId, 'message_id' => $messageId, 'http' => 200];
    }

    $bridge = max_comments_bridge_settings($pdo);
    $bridgeErr = '';
    if (!max_comments_bridge_ready($bridge, $bridgeErr)) {
      max_comments_processed_update($pdo, $chatId, $messageId, 'error', $bridgeErr);
      return ['ok' => false, 'handled' => 0, 'reason' => 'bridge_not_ready', 'message' => $bridgeErr, 'http' => 500];
    }

    $applied = max_comments_apply_button_to_message($bridge, $settings, $chatId, $messageId);
    if (($applied['ok'] ?? false) !== true) {
      $err = trim((string)($applied['error'] ?? 'APPLY_FAILED'));
      max_comments_processed_update($pdo, $chatId, $messageId, 'error', $err);
      return [
        'ok' => false,
        'handled' => 0,
        'reason' => 'apply_error',
        'message' => $err,
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'http' => 502,
      ];
    }

    max_comments_processed_update($pdo, $chatId, $messageId, 'done', '');
    return [
      'ok' => true,
      'handled' => 1,
      'reason' => (string)($applied['reason'] ?? 'done'),
      'changed' => (int)($applied['changed'] ?? 0),
      'chat_id' => $chatId,
      'message_id' => $messageId,
      'http' => 200,
    ];
  }
}
