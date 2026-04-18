<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_vk_cleanup.php
 * ROLE: VK wall cleanup tasks, items, progress and background helpers.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

if (!function_exists('channel_bridge_vk_cleanup_tables_available')) {
  /**
   * @param PDO $pdo
   * @return bool
   */
  function channel_bridge_vk_cleanup_tables_available(PDO $pdo): bool
  {
    return channel_bridge_schema_table_exists($pdo, CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS)
      && channel_bridge_schema_table_exists($pdo, CHANNEL_BRIDGE_TABLE_VK_CLEANUP_ITEMS);
  }

  /**
   * @param PDO $pdo
   * @return void
   */
  function channel_bridge_vk_cleanup_ensure_schema(PDO $pdo): void
  {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `" . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `route_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `route_title` VARCHAR(190) NOT NULL DEFAULT '',
        `owner_id` VARCHAR(64) NOT NULL DEFAULT '',
        `link_substring` VARCHAR(255) NOT NULL DEFAULT '',
        `requested_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `scanned_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `pinned_skipped` INT UNSIGNED NOT NULL DEFAULT 0,
        `scan_offset` INT UNSIGNED NOT NULL DEFAULT 0,
        `wall_total` INT UNSIGNED NOT NULL DEFAULT 0,
        `total_count` INT UNSIGNED NOT NULL DEFAULT 0,
        `status` VARCHAR(16) NOT NULL DEFAULT 'queued',
        `status_text` VARCHAR(255) NOT NULL DEFAULT '',
        `current_post_id` BIGINT NOT NULL DEFAULT 0,
        `current_sort` INT UNSIGNED NOT NULL DEFAULT 0,
        `started_by` INT UNSIGNED NOT NULL DEFAULT 0,
        `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `finished_at` DATETIME NULL DEFAULT NULL,
        `last_error` VARCHAR(255) NOT NULL DEFAULT '',
        `log_text` MEDIUMTEXT NOT NULL,
        `last_worker_spawn_at` DATETIME NULL DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_route_status` (`route_id`, `status`, `id`),
        KEY `idx_status` (`status`, `id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    if (!channel_bridge_schema_column_exists($pdo, CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS, 'link_substring')) {
      $pdo->exec("
        ALTER TABLE `" . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "`
        ADD COLUMN `link_substring` VARCHAR(255) NOT NULL DEFAULT '' AFTER `owner_id`
      ");
    }

    if (!channel_bridge_schema_column_exists($pdo, CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS, 'scan_offset')) {
      $pdo->exec("
        ALTER TABLE `" . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "`
        ADD COLUMN `scan_offset` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `pinned_skipped`
      ");
    }

    if (!channel_bridge_schema_column_exists($pdo, CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS, 'wall_total')) {
      $pdo->exec("
        ALTER TABLE `" . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "`
        ADD COLUMN `wall_total` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `scan_offset`
      ");
    }

    if (!channel_bridge_schema_column_exists($pdo, CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS, 'status_text')) {
      $pdo->exec("
        ALTER TABLE `" . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "`
        ADD COLUMN `status_text` VARCHAR(255) NOT NULL DEFAULT '' AFTER `status`
      ");
    }

    if (!channel_bridge_schema_column_exists($pdo, CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS, 'current_post_id')) {
      $pdo->exec("
        ALTER TABLE `" . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "`
        ADD COLUMN `current_post_id` BIGINT NOT NULL DEFAULT 0 AFTER `status_text`
      ");
    }

    if (!channel_bridge_schema_column_exists($pdo, CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS, 'current_sort')) {
      $pdo->exec("
        ALTER TABLE `" . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "`
        ADD COLUMN `current_sort` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `current_post_id`
      ");
    }

    if (!channel_bridge_schema_column_exists($pdo, CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS, 'log_text')) {
      $pdo->exec("
        ALTER TABLE `" . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "`
        ADD COLUMN `log_text` MEDIUMTEXT NOT NULL AFTER `last_error`
      ");
    }

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS `" . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_ITEMS . "` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `task_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
        `post_id` BIGINT NOT NULL DEFAULT 0,
        `post_date` DATETIME NULL DEFAULT NULL,
        `sort` INT UNSIGNED NOT NULL DEFAULT 0,
        `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
        `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
        `next_attempt_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `deleted_at` DATETIME NULL DEFAULT NULL,
        `last_error` VARCHAR(255) NOT NULL DEFAULT '',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_task_post` (`task_id`, `post_id`),
        KEY `idx_task_sort` (`task_id`, `sort`, `id`),
        KEY `idx_task_status` (`task_id`, `status`, `next_attempt_at`, `id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
  }

  /**
   * @param array<string,mixed> $settings
   * @param array<string,mixed> $route
   * @return int
   */
  function channel_bridge_vk_cleanup_route_owner_id(array $settings, array $route): int
  {
    $ownerId = trim((string)($route['target_chat_id'] ?? ''));
    if ($ownerId === '') {
      $ownerId = trim((string)($settings['vk_owner_id'] ?? ''));
    }
    if ($ownerId === '' || !preg_match('~^-?\d+$~', $ownerId)) {
      return 0;
    }

    return (int)$ownerId;
  }

  /**
   * @param PDO $pdo
   * @param array<string,mixed> $settings
   * @return array<int,array<string,mixed>>
   */
  function channel_bridge_vk_cleanup_routes_list(PDO $pdo, array $settings): array
  {
    channel_bridge_require_schema($pdo);

    $st = $pdo->prepare("
      SELECT id, title, target_chat_id, enabled
      FROM " . CHANNEL_BRIDGE_TABLE_ROUTES . "
      WHERE target_platform = :target_platform
      ORDER BY enabled DESC, id DESC
    ");
    $st->execute([':target_platform' => CHANNEL_BRIDGE_TARGET_VK]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
      return [];
    }

    $out = [];
    foreach ($rows as $row) {
      $route = channel_bridge_route_find($pdo, (int)($row['id'] ?? 0));
      if (!$route) {
        continue;
      }
      $ownerId = channel_bridge_vk_cleanup_route_owner_id($settings, $route);
      $title = trim((string)($route['title'] ?? ''));
      if ($title === '') {
        $title = 'VK route #' . (int)($route['id'] ?? 0);
      }
      $out[] = [
        'id' => (int)($route['id'] ?? 0),
        'title' => $title,
        'owner_id' => $ownerId,
        'enabled' => (int)($route['enabled'] ?? 0),
      ];
    }

    return $out;
  }

  /**
   * @param string $url
   * @return string
   */
  function channel_bridge_vk_cleanup_url_token_normalize(string $url): string
  {
    $url = trim($url);
    $url = rtrim($url, " \t\n\r\0\x0B,.;:!?)]}>\"'");
    return $url;
  }

  /**
   * @param mixed $value
   * @param array<string,bool> $out
   * @return void
   */
  function channel_bridge_vk_cleanup_collect_urls_from_value($value, array &$out): void
  {
    if (is_array($value)) {
      foreach ($value as $item) {
        channel_bridge_vk_cleanup_collect_urls_from_value($item, $out);
      }
      return;
    }

    if (!is_string($value)) {
      return;
    }

    $value = trim($value);
    if ($value === '') {
      return;
    }

    if (preg_match('~^https?://~iu', $value)) {
      $url = channel_bridge_vk_cleanup_url_token_normalize($value);
      if ($url !== '') {
        $out[$url] = true;
      }
    }

    if (preg_match_all('~https?://[^\s<>"\']+~iu', $value, $m)) {
      foreach ((array)($m[0] ?? []) as $rawUrl) {
        $url = channel_bridge_vk_cleanup_url_token_normalize((string)$rawUrl);
        if ($url !== '') {
          $out[$url] = true;
        }
      }
    }
  }

  /**
   * @param array<string,mixed> $item
   * @return array<int,string>
   */
  function channel_bridge_vk_cleanup_item_urls(array $item): array
  {
    $map = [];
    channel_bridge_vk_cleanup_collect_urls_from_value($item, $map);
    return array_keys($map);
  }

  /**
   * @param array<string,mixed> $item
   * @param string $linkSubstring
   * @return bool
   */
  function channel_bridge_vk_cleanup_item_matches_link_substring(array $item, string $linkSubstring): bool
  {
    $linkSubstring = trim($linkSubstring);
    if ($linkSubstring === '') {
      return true;
    }

    $urls = channel_bridge_vk_cleanup_item_urls($item);
    if (!$urls) {
      return false;
    }

    foreach ($urls as $url) {
      if ($url !== '' && stripos($url, $linkSubstring) !== false) {
        return true;
      }
    }

    return false;
  }

  /**
   * @param PDO $pdo
   * @param array<string,mixed> $settings
   * @param int $routeId
   * @return array<string,mixed>
   */
  function channel_bridge_vk_cleanup_route_context(PDO $pdo, array $settings, int $routeId): array
  {
    $route = channel_bridge_route_find($pdo, $routeId);
    if (!$route) {
      return ['ok' => false, 'error' => 'route_not_found'];
    }
    if (channel_bridge_norm_platform((string)($route['target_platform'] ?? '')) !== CHANNEL_BRIDGE_TARGET_VK) {
      return ['ok' => false, 'error' => 'route_not_vk'];
    }

    $ownerId = channel_bridge_vk_cleanup_route_owner_id($settings, $route);
    if ($ownerId === 0) {
      return ['ok' => false, 'error' => 'VK_OWNER_ID_EMPTY'];
    }

    return ['ok' => true, 'route' => $route, 'owner_id' => $ownerId];
  }

  /**
   * @param PDO $pdo
   * @param int $routeId
   * @return array<string,mixed>
   */
  function channel_bridge_vk_cleanup_find_active_task(PDO $pdo, int $routeId): array
  {
    if ($routeId <= 0 || !channel_bridge_vk_cleanup_tables_available($pdo)) {
      return [];
    }

    $st = $pdo->prepare("
      SELECT *
      FROM " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "
      WHERE route_id = :route_id
        AND status IN ('scanning', 'queued', 'running')
      ORDER BY id DESC
      LIMIT 1
    ");
    $st->execute([':route_id' => $routeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
  }

  /**
   * @param PDO $pdo
   * @param int $routeId
   * @return array<string,mixed>
   */
  function channel_bridge_vk_cleanup_find_latest_task(PDO $pdo, int $routeId): array
  {
    if ($routeId <= 0 || !channel_bridge_vk_cleanup_tables_available($pdo)) {
      return [];
    }

    $st = $pdo->prepare("
      SELECT *
      FROM " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "
      WHERE route_id = :route_id
      ORDER BY id DESC
      LIMIT 1
    ");
    $st->execute([':route_id' => $routeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
  }

  /**
   * @param PDO $pdo
   * @param int $taskId
   * @return array<string,mixed>
   */
  function channel_bridge_vk_cleanup_find_task(PDO $pdo, int $taskId): array
  {
    if ($taskId <= 0 || !channel_bridge_vk_cleanup_tables_available($pdo)) {
      return [];
    }

    $st = $pdo->prepare("
      SELECT *
      FROM " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "
      WHERE id = :id
      LIMIT 1
    ");
    $st->execute([':id' => $taskId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
  }

  /**
   * @param PDO $pdo
   * @param int $taskId
   * @return array<string,mixed>
   */
  function channel_bridge_vk_cleanup_counts(PDO $pdo, int $taskId): array
  {
    $counts = [
      'pending' => 0,
      'done' => 0,
      'failed' => 0,
      'retries' => 0,
    ];
    if ($taskId <= 0) {
      return $counts;
    }

    $st = $pdo->prepare("
      SELECT status, COUNT(*) AS cnt, COALESCE(SUM(CASE WHEN attempts > 1 THEN attempts - 1 ELSE 0 END), 0) AS retries
      FROM " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_ITEMS . "
      WHERE task_id = :task_id
      GROUP BY status
    ");
    $st->execute([':task_id' => $taskId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
      return $counts;
    }

    foreach ($rows as $row) {
      $status = trim((string)($row['status'] ?? ''));
      if (isset($counts[$status])) {
        $counts[$status] = (int)($row['cnt'] ?? 0);
      }
      $counts['retries'] += (int)($row['retries'] ?? 0);
    }

    return $counts;
  }

  /**
   * @param PDO $pdo
   * @param int $taskId
   * @param int $limit
   * @return array<int,array<string,mixed>>
   */
  function channel_bridge_vk_cleanup_recent_items(PDO $pdo, int $taskId, int $limit = 10): array
  {
    if ($taskId <= 0) {
      return [];
    }
    $limit = max(1, min(20, $limit));
    $st = $pdo->prepare("
      SELECT post_id, post_date, sort, status, attempts, next_attempt_at, deleted_at, last_error, updated_at
      FROM " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_ITEMS . "
      WHERE task_id = :task_id
      ORDER BY updated_at DESC, id DESC
      LIMIT " . $limit . "
    ");
    $st->execute([':task_id' => $taskId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  /**
   * @param string $key
   * @param mixed ...$args
   * @return string
   */
  function channel_bridge_vk_cleanup_text(string $key, ...$args): string
  {
    $text = (string)channel_bridge_t($key);
    if ($args) {
      return (string)vsprintf($text, $args);
    }
    return $text;
  }

  /**
   * @param int $requestedCount
   * @return bool
   */
  function channel_bridge_vk_cleanup_is_delete_all(int $requestedCount): bool
  {
    return $requestedCount <= 0;
  }

  /**
   * @param int $requestedCount
   * @return string
   */
  function channel_bridge_vk_cleanup_requested_label(int $requestedCount): string
  {
    return channel_bridge_vk_cleanup_is_delete_all($requestedCount) ? '*' : (string)$requestedCount;
  }

  /**
   * @param string $raw
   * @param int $limit
   * @return array<int,string>
   */
  function channel_bridge_vk_cleanup_log_lines(string $raw, int $limit = 12): array
  {
    $raw = trim($raw);
    if ($raw === '') {
      return [];
    }

    $lines = preg_split('~\R+~u', $raw) ?: [];
    $lines = array_values(array_filter(array_map('trim', $lines), static function ($line): bool {
      return $line !== '';
    }));
    if ($limit > 0 && count($lines) > $limit) {
      $lines = array_slice($lines, -$limit);
    }
    return $lines;
  }

  /**
   * @param PDO $pdo
   * @param int $taskId
   * @param string $message
   * @return void
   */
  function channel_bridge_vk_cleanup_task_append_log(PDO $pdo, int $taskId, string $message): void
  {
    $taskId = max(0, $taskId);
    $message = trim($message);
    if ($taskId <= 0 || $message === '') {
      return;
    }

    $task = channel_bridge_vk_cleanup_find_task($pdo, $taskId);
    if (!$task) {
      return;
    }

    $lines = channel_bridge_vk_cleanup_log_lines((string)($task['log_text'] ?? ''), 24);
    $lines[] = '[' . date('H:i:s') . '] ' . $message;
    if (count($lines) > 24) {
      $lines = array_slice($lines, -24);
    }

    $pdo->prepare("
      UPDATE " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "
      SET log_text = :log_text, updated_at = CURRENT_TIMESTAMP
      WHERE id = :id
    ")->execute([
      ':log_text' => implode("\n", $lines),
      ':id' => $taskId,
    ]);
  }

  /**
   * @param PDO $pdo
   * @param int $taskId
   * @param array<string,mixed> $fields
   * @return void
   */
  function channel_bridge_vk_cleanup_task_patch(PDO $pdo, int $taskId, array $fields): void
  {
    if ($taskId <= 0 || !$fields) {
      return;
    }

    $allowed = [
      'scanned_count',
      'pinned_skipped',
      'scan_offset',
      'wall_total',
      'total_count',
      'status',
      'status_text',
      'current_post_id',
      'current_sort',
      'last_error',
      'last_worker_spawn_at',
      'finished_at',
      'log_text',
    ];

    $sets = [];
    $bind = [':id' => $taskId];
    foreach ($fields as $name => $value) {
      if (!in_array($name, $allowed, true)) {
        continue;
      }
      $sets[] = "`" . $name . "` = :" . $name;
      $bind[':' . $name] = $value;
    }

    if (!$sets) {
      return;
    }

    $sets[] = "updated_at = CURRENT_TIMESTAMP";
    $pdo->prepare("
      UPDATE " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "
      SET " . implode(', ', $sets) . "
      WHERE id = :id
    ")->execute($bind);
  }

  /**
   * @param PDO $pdo
   * @param array<string,mixed> $task
   * @return array<string,mixed>
   */
  function channel_bridge_vk_cleanup_task_snapshot(PDO $pdo, array $task): array
  {
    if (!$task) {
      return [];
    }

    $taskId = (int)($task['id'] ?? 0);
    $total = (int)($task['total_count'] ?? 0);
    $counts = channel_bridge_vk_cleanup_counts($pdo, $taskId);
    $done = (int)($counts['done'] ?? 0);
    $failed = (int)($counts['failed'] ?? 0);
    $pending = max(0, $total - $done - $failed);
    $percent = ($total > 0) ? (int)floor((($done + $failed) / $total) * 100) : 0;

    return [
      'id' => $taskId,
      'route_id' => (int)($task['route_id'] ?? 0),
      'route_title' => (string)($task['route_title'] ?? ''),
      'owner_id' => (string)($task['owner_id'] ?? ''),
      'link_substring' => (string)($task['link_substring'] ?? ''),
      'status' => (string)($task['status'] ?? ''),
      'status_text' => (string)($task['status_text'] ?? ''),
      'requested_count' => (int)($task['requested_count'] ?? 0),
      'requested_display' => channel_bridge_vk_cleanup_requested_label((int)($task['requested_count'] ?? 0)),
      'scanned_count' => (int)($task['scanned_count'] ?? 0),
      'pinned_skipped' => (int)($task['pinned_skipped'] ?? 0),
      'scan_offset' => (int)($task['scan_offset'] ?? 0),
      'wall_total' => (int)($task['wall_total'] ?? 0),
      'total_count' => $total,
      'deleted_count' => $done,
      'failed_count' => $failed,
      'pending_count' => $pending,
      'retries_count' => (int)($counts['retries'] ?? 0),
      'percent' => $percent,
      'current_post_id' => (int)($task['current_post_id'] ?? 0),
      'current_sort' => (int)($task['current_sort'] ?? 0),
      'started_at' => (string)($task['started_at'] ?? ''),
      'finished_at' => (string)($task['finished_at'] ?? ''),
      'updated_at' => (string)($task['updated_at'] ?? ''),
      'last_error' => (string)($task['last_error'] ?? ''),
      'log_lines' => channel_bridge_vk_cleanup_log_lines((string)($task['log_text'] ?? ''), 16),
      'recent_items' => channel_bridge_vk_cleanup_recent_items($pdo, $taskId, 12),
      'is_active' => in_array((string)($task['status'] ?? ''), ['scanning', 'queued', 'running'], true),
    ];
  }

  /**
   * @param array<string,mixed> $settings
   * @param array<string,mixed> $route
   * @param int $ownerId
   * @param int $limit
   * @param bool $skipPinned
   * @param string $linkSubstring
   * @return array<string,mixed>
   */
  function channel_bridge_vk_cleanup_scan_latest_posts(array $settings, array $route, int $ownerId, int $limit, bool $skipPinned = true, string $linkSubstring = ''): array
  {
    if ($ownerId === 0 || $limit <= 0) {
      return ['ok' => false, 'error' => 'VK_SCAN_BAD_META'];
    }

    $tokenOverride = channel_bridge_vk_route_token_override($route, 'post');
    $linkSubstring = trim($linkSubstring);
    $offset = 0;
    $batchSize = 100;
    $total = null;
    $scanned = 0;
    $pinnedSkipped = 0;
    $itemsOut = [];

    while (count($itemsOut) < $limit) {
      $wall = channel_bridge_vk_api_call_with_token($settings, 'wall.get', [
        'owner_id' => $ownerId,
        'offset' => $offset,
        'count' => $batchSize,
      ], $tokenOverride);
      if (($wall['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => (string)($wall['error'] ?? 'VK_WALL_GET_FAILED'), 'raw' => $wall['raw'] ?? []];
      }

      $response = is_array($wall['response'] ?? null) ? (array)$wall['response'] : [];
      if ($total === null) {
        $total = max(0, (int)($response['count'] ?? 0));
      }
      $items = isset($response['items']) && is_array($response['items']) ? array_values($response['items']) : [];
      if (!$items) {
        break;
      }

      foreach ($items as $item) {
        if (!is_array($item)) {
          continue;
        }
        $postId = (int)($item['id'] ?? 0);
        $postTs = (int)($item['date'] ?? 0);
        if ($postId <= 0 || $postTs <= 0) {
          continue;
        }
        if (((int)($item['is_pinned'] ?? 0) === 1) && $skipPinned) {
          $pinnedSkipped++;
          continue;
        }

        $scanned++;
        if (!channel_bridge_vk_cleanup_item_matches_link_substring($item, $linkSubstring)) {
          continue;
        }
        $itemsOut[] = [
          'post_id' => $postId,
          'post_ts' => $postTs,
          'post_date' => date('Y-m-d H:i:s', $postTs),
          'sort' => count($itemsOut) + 1,
        ];
        if (count($itemsOut) >= $limit) {
          break;
        }
      }

      $offset += count($items);
      if (count($items) < $batchSize) {
        break;
      }
      if ($total !== null && $offset >= $total) {
        break;
      }
    }

    return [
      'ok' => true,
      'items' => $itemsOut,
      'scanned_count' => $scanned,
      'pinned_skipped' => $pinnedSkipped,
      'wall_total' => (int)$total,
    ];
  }

  /**
   * @param PDO $pdo
   * @param array<string,mixed> $settings
   * @param array<string,mixed> $task
   * @param array<string,mixed> $route
   * @param int $ownerId
   * @return array<string,mixed>
   */
  function channel_bridge_vk_cleanup_scan_step(PDO $pdo, array $settings, array $task, array $route, int $ownerId): array
  {
    $taskId = (int)($task['id'] ?? 0);
    $requestedCount = (int)($task['requested_count'] ?? 0);
    $deleteAll = channel_bridge_vk_cleanup_is_delete_all($requestedCount);
    $offset = max(0, (int)($task['scan_offset'] ?? 0));
    $scannedCount = max(0, (int)($task['scanned_count'] ?? 0));
    $pinnedSkipped = max(0, (int)($task['pinned_skipped'] ?? 0));
    $foundCount = max(0, (int)($task['total_count'] ?? 0));
    $linkSubstring = trim((string)($task['link_substring'] ?? ''));
    $batchSize = 100;
    $tokenOverride = channel_bridge_vk_route_token_override($route, 'post');

    $wall = channel_bridge_vk_api_call_with_token($settings, 'wall.get', [
      'owner_id' => $ownerId,
      'offset' => $offset,
      'count' => $batchSize,
    ], $tokenOverride);
    if (($wall['ok'] ?? false) !== true) {
      $error = (string)($wall['error'] ?? 'VK_WALL_GET_FAILED');
      channel_bridge_vk_cleanup_task_patch($pdo, $taskId, [
        'status' => 'failed',
        'status_text' => channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_failed', $error),
        'last_error' => mb_substr($error, 0, 250),
        'current_post_id' => 0,
        'current_sort' => 0,
        'finished_at' => channel_bridge_now(),
      ]);
      channel_bridge_vk_cleanup_task_append_log($pdo, $taskId, channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_log_scan_error', $error));
      return ['status' => 'done', 'reason' => 'scan_failed'];
    }

    $response = is_array($wall['response'] ?? null) ? (array)$wall['response'] : [];
    $wallTotal = max(0, (int)($response['count'] ?? 0));
    $items = isset($response['items']) && is_array($response['items']) ? array_values($response['items']) : [];
    $batchScanned = 0;
    $batchPinned = 0;
    $batchAdded = 0;

    if ($items) {
      $st = $pdo->prepare("
        INSERT INTO " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_ITEMS . "
        (
          task_id, post_id, post_date, sort, status, attempts, next_attempt_at, last_error
        ) VALUES (
          :task_id, :post_id, :post_date, :sort, 'pending', 0, :next_attempt_at, ''
        )
      ");

      foreach ($items as $item) {
        if (!is_array($item)) {
          continue;
        }
        $postId = (int)($item['id'] ?? 0);
        $postTs = (int)($item['date'] ?? 0);
        if ($postId <= 0 || $postTs <= 0) {
          continue;
        }
        if ((int)($item['is_pinned'] ?? 0) === 1) {
          $batchPinned++;
          continue;
        }

        $batchScanned++;
        if (!channel_bridge_vk_cleanup_item_matches_link_substring($item, $linkSubstring)) {
          continue;
        }
        if (!$deleteAll && ($foundCount + $batchAdded) >= $requestedCount) {
          break;
        }

        $batchAdded++;
        $st->execute([
          ':task_id' => $taskId,
          ':post_id' => $postId,
          ':post_date' => date('Y-m-d H:i:s', $postTs),
          ':sort' => $foundCount + $batchAdded,
          ':next_attempt_at' => channel_bridge_now(),
        ]);
      }
    }

    $scannedCount += $batchScanned;
    $pinnedSkipped += $batchPinned;
    $foundCount += $batchAdded;
    $nextOffset = $offset + count($items);
    $scanDone = false;

    if (!$deleteAll && $foundCount >= $requestedCount) {
      $scanDone = true;
    } elseif (!$items || count($items) < $batchSize) {
      $scanDone = true;
    } elseif ($wallTotal > 0 && $nextOffset >= $wallTotal) {
      $scanDone = true;
    }

    channel_bridge_vk_cleanup_task_patch($pdo, $taskId, [
      'scanned_count' => $scannedCount,
      'pinned_skipped' => $pinnedSkipped,
      'scan_offset' => $nextOffset,
      'wall_total' => $wallTotal,
      'total_count' => $foundCount,
      'current_post_id' => 0,
      'current_sort' => 0,
    ]);

    channel_bridge_vk_cleanup_task_append_log($pdo, $taskId, channel_bridge_vk_cleanup_text(
      'channel_bridge.vk_cleanup_log_scan_batch',
      $nextOffset,
      $scannedCount,
      $foundCount,
      channel_bridge_vk_cleanup_requested_label($requestedCount)
    ));

    if (!$scanDone) {
      channel_bridge_vk_cleanup_task_patch($pdo, $taskId, [
        'status' => 'scanning',
        'status_text' => $deleteAll
          ? channel_bridge_vk_cleanup_text(
            'channel_bridge.vk_cleanup_status_scanning_all',
            $scannedCount,
            $foundCount,
            $wallTotal
          )
          : channel_bridge_vk_cleanup_text(
            'channel_bridge.vk_cleanup_status_scanning',
            $scannedCount,
            $foundCount,
            $requestedCount,
            $wallTotal
          ),
      ]);
      channel_bridge_vk_cleanup_spawn_next_worker($pdo, $taskId);
      return ['status' => 'pending', 'reason' => 'scan_continue', 'delay_seconds' => 1];
    }

    if ($foundCount <= 0) {
      channel_bridge_vk_cleanup_task_patch($pdo, $taskId, [
        'status' => 'done',
        'status_text' => $deleteAll
          ? channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_scan_none_all')
          : channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_scan_none', $requestedCount),
        'finished_at' => channel_bridge_now(),
      ]);
      channel_bridge_vk_cleanup_task_append_log($pdo, $taskId, $deleteAll
        ? channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_scan_none_all')
        : channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_log_scan_none', $requestedCount));
      return ['status' => 'done', 'reason' => 'scan_empty'];
    }

    if ($deleteAll) {
      $statusText = channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_scan_ready_all', $foundCount);
    } else {
      $statusText = ($foundCount < $requestedCount)
        ? channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_scan_found_less', $foundCount, $requestedCount)
        : channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_scan_ready', $foundCount, $requestedCount);
    }

    channel_bridge_vk_cleanup_task_patch($pdo, $taskId, [
      'status' => 'queued',
      'status_text' => $statusText,
      'last_error' => '',
    ]);
    channel_bridge_vk_cleanup_task_append_log($pdo, $taskId, $statusText);
    channel_bridge_vk_cleanup_spawn_next_worker($pdo, $taskId);
    return ['status' => 'pending', 'reason' => 'scan_ready', 'delay_seconds' => 1];
  }

  /**
   * @param PDO $pdo
   * @param int $taskId
   * @param string $status
   * @param string $lastError
   * @param bool $markFinished
   * @return void
   */
  function channel_bridge_vk_cleanup_task_set_status(PDO $pdo, int $taskId, string $status, string $lastError = '', bool $markFinished = false): void
  {
    if ($taskId <= 0) {
      return;
    }

    $sql = "
      UPDATE " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "
      SET status = :status, last_error = :last_error, updated_at = CURRENT_TIMESTAMP
    ";
    if ($markFinished) {
      $sql .= ", finished_at = :finished_at";
    }
    $sql .= " WHERE id = :id";

    $bind = [
      ':status' => trim($status),
      ':last_error' => mb_substr(trim($lastError), 0, 250),
      ':id' => $taskId,
    ];
    if ($markFinished) {
      $bind[':finished_at'] = channel_bridge_now();
    }

    $pdo->prepare($sql)->execute($bind);
  }

  /**
   * @param PDO $pdo
   * @param int $taskId
   * @return void
   */
  function channel_bridge_vk_cleanup_finalize_task(PDO $pdo, int $taskId): void
  {
    $task = channel_bridge_vk_cleanup_find_task($pdo, $taskId);
    if (!$task) {
      return;
    }

    $counts = channel_bridge_vk_cleanup_counts($pdo, $taskId);
    $total = (int)($task['total_count'] ?? 0);
    $done = (int)($counts['done'] ?? 0);
    $failed = (int)($counts['failed'] ?? 0);
    $status = 'done';
    $statusText = channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_done', $done, max(0, $total));
    if ($failed > 0) {
      $status = ($done > 0) ? 'partial' : 'failed';
      $statusText = ($done > 0)
        ? channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_partial', $done, max(0, $total), $failed)
        : channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_failed', (string)($task['last_error'] ?? 'delete_failed'));
    }
    if ($total <= 0) {
      $status = 'done';
      $statusText = channel_bridge_vk_cleanup_is_delete_all((int)($task['requested_count'] ?? 0))
        ? channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_scan_none_all')
        : channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_scan_none', (int)($task['requested_count'] ?? 0));
    }

    channel_bridge_vk_cleanup_task_patch($pdo, $taskId, [
      'status' => $status,
      'status_text' => $statusText,
      'last_error' => (string)($task['last_error'] ?? ''),
      'current_post_id' => 0,
      'current_sort' => 0,
      'finished_at' => channel_bridge_now(),
    ]);
    channel_bridge_vk_cleanup_task_append_log($pdo, $taskId, $statusText);
  }

  /**
   * @param PDO $pdo
   * @param int $taskId
   * @param string $reason
   * @return void
   */
  function channel_bridge_vk_cleanup_fail_remaining_items(PDO $pdo, int $taskId, string $reason): void
  {
    if ($taskId <= 0) {
      return;
    }

    $pdo->prepare("
      UPDATE " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_ITEMS . "
      SET
        status = 'failed',
        last_error = :last_error,
        updated_at = CURRENT_TIMESTAMP
      WHERE task_id = :task_id
        AND status = 'pending'
    ")->execute([
      ':task_id' => $taskId,
      ':last_error' => mb_substr(trim($reason), 0, 250),
    ]);
  }

  /**
   * @param string $error
   * @return bool
   */
  function channel_bridge_vk_cleanup_is_global_fatal_error(string $error): bool
  {
    $error = strtolower(trim($error));
    if ($error === '') {
      return false;
    }

    foreach ([
      'vk_disabled',
      'vk_token_empty',
      'vk_owner_id_empty',
      'vk_owner_id_invalid',
      'access denied',
      'authorization failed',
      'invalid access_token',
      'group authorization failed',
    ] as $needle) {
      if ($needle !== '' && strpos($error, $needle) !== false) {
        return true;
      }
    }

    return false;
  }

  /**
   * @param string $error
   * @return bool
   */
  function channel_bridge_vk_cleanup_is_retryable_error(string $error): bool
  {
    $error = strtolower(trim($error));
    if ($error === '') {
      return false;
    }

    foreach ([
      'timeout',
      'timed out',
      'connection',
      'curl',
      'too many requests',
      'rate limit',
      'temporar',
      'http_5',
      'gateway',
      'internal server error',
    ] as $needle) {
      if ($needle !== '' && strpos($error, $needle) !== false) {
        return true;
      }
    }

    return false;
  }

  /**
   * @param array<string,mixed> $settings
   * @param array<string,mixed> $route
   * @param int $ownerId
   * @param int $postId
   * @return array<string,mixed>
   */
  function channel_bridge_vk_cleanup_delete_post(array $settings, array $route, int $ownerId, int $postId): array
  {
    $tokenOverride = channel_bridge_vk_route_token_override($route, 'post');
    return channel_bridge_vk_api_call_with_token($settings, 'wall.delete', [
      'owner_id' => $ownerId,
      'post_id' => $postId,
    ], $tokenOverride);
  }

  /**
   * @param PDO $pdo
   * @param int $routeId
   * @param int $requestedCount
   * @param int $startedBy
   * @param array<string,mixed> $settings
   * @param string $linkSubstring
   * @return array<string,mixed>
   */
  function channel_bridge_vk_cleanup_create_task(PDO $pdo, int $routeId, int $requestedCount, int $startedBy, array $settings, string $linkSubstring = ''): array
  {
    channel_bridge_vk_cleanup_ensure_schema($pdo);

    $active = channel_bridge_vk_cleanup_find_active_task($pdo, $routeId);
    if ($active) {
      return ['ok' => true, 'reason' => 'already_running', 'task' => channel_bridge_vk_cleanup_task_snapshot($pdo, $active)];
    }

    $ctx = channel_bridge_vk_cleanup_route_context($pdo, $settings, $routeId);
    if (($ctx['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($ctx['error'] ?? 'route_invalid')];
    }
    $route = (array)($ctx['route'] ?? []);
    $ownerId = (int)($ctx['owner_id'] ?? 0);
    $linkSubstring = trim($linkSubstring);
    $requestedCount = max(0, $requestedCount);
    $routeTitle = trim((string)($route['title'] ?? 'VK route #' . $routeId));
    $deleteAll = channel_bridge_vk_cleanup_is_delete_all($requestedCount);

    $pdo->prepare("
      INSERT INTO " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "
      (
        route_id, route_title, owner_id, link_substring, requested_count, status, status_text, started_by, started_at, log_text
      ) VALUES (
        :route_id, :route_title, :owner_id, :link_substring, :requested_count, 'scanning', :status_text, :started_by, :started_at, :log_text
      )
    ")->execute([
      ':route_id' => $routeId,
      ':route_title' => $routeTitle,
      ':owner_id' => (string)$ownerId,
      ':link_substring' => mb_substr($linkSubstring, 0, 255),
      ':requested_count' => $requestedCount,
      ':status_text' => $deleteAll
        ? channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_created_all')
        : channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_created', $requestedCount),
      ':started_by' => max(0, $startedBy),
      ':started_at' => channel_bridge_now(),
      ':log_text' => '[' . date('H:i:s') . '] ' . ($deleteAll
        ? channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_created_all')
        : channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_log_created', $requestedCount)),
    ]);
    $taskId = (int)$pdo->lastInsertId();

    $jobKey = 'vk_cleanup_task:' . $taskId;
    $payload = ['task_id' => $taskId];
    $enq = channel_bridge_jobs_enqueue($pdo, CHANNEL_BRIDGE_JOB_TYPE_VK_CLEANUP, $jobKey, $payload, 0);
    if (($enq['ok'] ?? false) !== true) {
      $error = (string)($enq['reason'] ?? 'enqueue_failed');
      channel_bridge_vk_cleanup_task_patch($pdo, $taskId, [
        'status' => 'failed',
        'status_text' => channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_failed', $error),
        'last_error' => $error,
        'finished_at' => channel_bridge_now(),
      ]);
      channel_bridge_vk_cleanup_task_append_log($pdo, $taskId, channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_log_scan_error', $error));
      return ['ok' => false, 'error' => $error];
    }

    $spawn = channel_bridge_jobs_spawn_worker_async([
      'max_seconds' => 4,
      'max_jobs' => 4,
      'timeout' => 0.20,
    ]);
    if (($spawn['ok'] ?? false) === true) {
      channel_bridge_vk_cleanup_task_patch($pdo, $taskId, [
        'last_worker_spawn_at' => channel_bridge_now(),
      ]);
    }

    $task = channel_bridge_vk_cleanup_find_task($pdo, $taskId);
    return ['ok' => true, 'reason' => 'created', 'task' => channel_bridge_vk_cleanup_task_snapshot($pdo, $task)];
  }

  /**
   * @param PDO $pdo
   * @param int $taskId
   * @return array<string,mixed>
   */
  function channel_bridge_vk_cleanup_find_next_due_item(PDO $pdo, int $taskId): array
  {
    if ($taskId <= 0) {
      return [];
    }

    $st = $pdo->prepare("
      SELECT *
      FROM " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_ITEMS . "
      WHERE task_id = :task_id
        AND status = 'pending'
        AND next_attempt_at <= :now_at
      ORDER BY sort ASC, id ASC
      LIMIT 1
    ");
    $st->execute([
      ':task_id' => $taskId,
      ':now_at' => channel_bridge_now(),
    ]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
  }

  /**
   * @param PDO $pdo
   * @param int $taskId
   * @return bool
   */
  function channel_bridge_vk_cleanup_has_pending_items(PDO $pdo, int $taskId): bool
  {
    if ($taskId <= 0) {
      return false;
    }
    $st = $pdo->prepare("
      SELECT 1
      FROM " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_ITEMS . "
      WHERE task_id = :task_id
        AND status = 'pending'
      LIMIT 1
    ");
    $st->execute([':task_id' => $taskId]);
    return ($st->fetchColumn() !== false);
  }

  /**
   * @param PDO $pdo
   * @param int $taskId
   * @return void
   */
  function channel_bridge_vk_cleanup_spawn_next_worker(PDO $pdo, int $taskId): void
  {
    if ($taskId <= 0) {
      return;
    }

    $spawn = channel_bridge_jobs_spawn_worker_async([
      'max_seconds' => 4,
      'max_jobs' => 4,
      'timeout' => 0.20,
    ]);
    if (($spawn['ok'] ?? false) === true) {
      $pdo->prepare("
        UPDATE " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "
        SET last_worker_spawn_at = :spawned_at
        WHERE id = :id
      ")->execute([
        ':spawned_at' => channel_bridge_now(),
        ':id' => $taskId,
      ]);
    }
  }

  /**
   * @param PDO $pdo
   * @param array<string,mixed> $payload
   * @param string $error
   * @return void
   */
  function channel_bridge_vk_cleanup_abort_task_from_payload(PDO $pdo, array $payload, string $error): void
  {
    $taskId = (int)($payload['task_id'] ?? 0);
    if ($taskId <= 0) {
      return;
    }

    channel_bridge_vk_cleanup_ensure_schema($pdo);
    channel_bridge_vk_cleanup_fail_remaining_items($pdo, $taskId, $error);
    channel_bridge_vk_cleanup_task_patch($pdo, $taskId, [
      'last_error' => mb_substr(trim($error), 0, 250),
      'status_text' => channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_failed', $error),
      'current_post_id' => 0,
      'current_sort' => 0,
    ]);
    channel_bridge_vk_cleanup_task_append_log($pdo, $taskId, channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_log_scan_error', $error));
    channel_bridge_vk_cleanup_finalize_task($pdo, $taskId);
  }

  /**
   * @param PDO $pdo
   * @param array<string,mixed> $settings
   * @param int $taskId
   * @return array<string,mixed>
   */
  function channel_bridge_vk_cleanup_handle_task_job(PDO $pdo, array $settings, int $taskId): array
  {
    $task = channel_bridge_vk_cleanup_find_task($pdo, $taskId);
    if (!$task) {
      return ['status' => 'failed', 'reason' => 'task_not_found', 'error' => 'cleanup task not found'];
    }

    $taskStatus = trim((string)($task['status'] ?? ''));
    if (in_array($taskStatus, ['done', 'partial', 'failed'], true)) {
      return ['status' => 'done', 'reason' => 'already_terminal'];
    }

    $routeId = (int)($task['route_id'] ?? 0);
    $ctx = channel_bridge_vk_cleanup_route_context($pdo, $settings, $routeId);
    if (($ctx['ok'] ?? false) !== true) {
      $err = (string)($ctx['error'] ?? 'route_invalid');
      channel_bridge_vk_cleanup_abort_task_from_payload($pdo, ['task_id' => $taskId], $err);
      return ['status' => 'done', 'reason' => 'task_failed'];
    }

    $route = (array)($ctx['route'] ?? []);
    $ownerId = (int)($ctx['owner_id'] ?? 0);
    if ($taskStatus === 'scanning') {
      return channel_bridge_vk_cleanup_scan_step($pdo, $settings, $task, $route, $ownerId);
    }

    $total = max(0, (int)($task['total_count'] ?? 0));
    $nextItem = channel_bridge_vk_cleanup_find_next_due_item($pdo, $taskId);
    if (!$nextItem) {
      if (channel_bridge_vk_cleanup_has_pending_items($pdo, $taskId)) {
        $counts = channel_bridge_vk_cleanup_counts($pdo, $taskId);
        channel_bridge_vk_cleanup_task_patch($pdo, $taskId, [
          'status' => 'running',
          'status_text' => channel_bridge_vk_cleanup_text(
            'channel_bridge.vk_cleanup_status_waiting_retry',
            (int)($counts['pending'] ?? 0)
          ),
          'current_post_id' => 0,
          'current_sort' => 0,
          'last_error' => (string)($task['last_error'] ?? ''),
        ]);
        channel_bridge_vk_cleanup_spawn_next_worker($pdo, $taskId);
        return ['status' => 'pending', 'reason' => 'await_next_attempt', 'delay_seconds' => CHANNEL_BRIDGE_VK_CLEANUP_DELETE_DELAY_SECONDS];
      }

      channel_bridge_vk_cleanup_finalize_task($pdo, $taskId);
      return ['status' => 'done', 'reason' => 'task_complete'];
    }

    $itemId = (int)($nextItem['id'] ?? 0);
    $postId = (int)($nextItem['post_id'] ?? 0);
    $sort = max(0, (int)($nextItem['sort'] ?? 0));
    $attempts = (int)($nextItem['attempts'] ?? 0) + 1;

    channel_bridge_vk_cleanup_task_patch($pdo, $taskId, [
      'status' => 'running',
      'status_text' => channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_deleting', $postId, $sort, $total),
      'current_post_id' => $postId,
      'current_sort' => $sort,
      'last_error' => '',
    ]);

    $delete = channel_bridge_vk_cleanup_delete_post($settings, $route, $ownerId, $postId);
    if (($delete['ok'] ?? false) === true) {
      $pdo->prepare("
        UPDATE " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_ITEMS . "
        SET
          status = 'done',
          attempts = :attempts,
          deleted_at = :deleted_at,
          last_error = '',
          updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
      ")->execute([
        ':attempts' => $attempts,
        ':deleted_at' => channel_bridge_now(),
        ':id' => $itemId,
      ]);
      channel_bridge_vk_cleanup_task_append_log($pdo, $taskId, channel_bridge_vk_cleanup_text(
        'channel_bridge.vk_cleanup_log_delete_ok',
        $postId,
        $sort,
        $total
      ));
      channel_bridge_vk_cleanup_task_patch($pdo, $taskId, [
        'current_post_id' => 0,
        'current_sort' => 0,
      ]);

      if (channel_bridge_vk_cleanup_has_pending_items($pdo, $taskId)) {
        channel_bridge_vk_cleanup_spawn_next_worker($pdo, $taskId);
        return ['status' => 'pending', 'reason' => 'next_delete', 'delay_seconds' => CHANNEL_BRIDGE_VK_CLEANUP_DELETE_DELAY_SECONDS];
      }

      channel_bridge_vk_cleanup_finalize_task($pdo, $taskId);
      return ['status' => 'done', 'reason' => 'task_complete'];
    }

    $error = trim((string)($delete['error'] ?? 'VK_WALL_DELETE_FAILED'));
    if (channel_bridge_vk_cleanup_is_global_fatal_error($error)) {
      $pdo->prepare("
        UPDATE " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_ITEMS . "
        SET
          status = 'failed',
          attempts = :attempts,
          last_error = :last_error,
          updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
      ")->execute([
        ':attempts' => $attempts,
        ':last_error' => mb_substr($error, 0, 250),
        ':id' => $itemId,
      ]);
      channel_bridge_vk_cleanup_task_append_log($pdo, $taskId, channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_log_delete_fail', $postId, $error));
      channel_bridge_vk_cleanup_abort_task_from_payload($pdo, ['task_id' => $taskId], $error);
      return ['status' => 'done', 'reason' => 'task_failed'];
    }

    if ($attempts < CHANNEL_BRIDGE_VK_CLEANUP_MAX_ATTEMPTS || channel_bridge_vk_cleanup_is_retryable_error($error)) {
      $pdo->prepare("
        UPDATE " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_ITEMS . "
        SET
          status = 'pending',
          attempts = :attempts,
          next_attempt_at = :next_attempt_at,
          last_error = :last_error,
          updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
      ")->execute([
        ':attempts' => $attempts,
        ':next_attempt_at' => channel_bridge_jobs_available_at(CHANNEL_BRIDGE_VK_CLEANUP_DELETE_DELAY_SECONDS),
        ':last_error' => mb_substr($error, 0, 250),
        ':id' => $itemId,
      ]);
      $pdo->prepare("
        UPDATE " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "
        SET last_error = :last_error, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
      ")->execute([
        ':last_error' => mb_substr($error, 0, 250),
        ':id' => $taskId,
      ]);
      channel_bridge_vk_cleanup_task_patch($pdo, $taskId, [
        'status' => 'running',
        'status_text' => channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_retrying', $postId, $attempts, $error),
        'current_post_id' => $postId,
        'current_sort' => $sort,
      ]);
      channel_bridge_vk_cleanup_task_append_log($pdo, $taskId, channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_log_delete_retry', $postId, $attempts, $error));

      channel_bridge_vk_cleanup_spawn_next_worker($pdo, $taskId);
      return ['status' => 'pending', 'reason' => 'retry_delete', 'delay_seconds' => CHANNEL_BRIDGE_VK_CLEANUP_DELETE_DELAY_SECONDS];
    }

    $pdo->prepare("
      UPDATE " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_ITEMS . "
      SET
        status = 'failed',
        attempts = :attempts,
        last_error = :last_error,
        updated_at = CURRENT_TIMESTAMP
      WHERE id = :id
    ")->execute([
      ':attempts' => $attempts,
      ':last_error' => mb_substr($error, 0, 250),
      ':id' => $itemId,
    ]);
    $pdo->prepare("
      UPDATE " . CHANNEL_BRIDGE_TABLE_VK_CLEANUP_TASKS . "
      SET last_error = :last_error, updated_at = CURRENT_TIMESTAMP
      WHERE id = :id
    ")->execute([
      ':last_error' => mb_substr($error, 0, 250),
      ':id' => $taskId,
    ]);
    channel_bridge_vk_cleanup_task_patch($pdo, $taskId, [
      'status' => 'running',
      'status_text' => channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_status_item_failed', $postId, $error),
      'current_post_id' => 0,
      'current_sort' => 0,
    ]);
    channel_bridge_vk_cleanup_task_append_log($pdo, $taskId, channel_bridge_vk_cleanup_text('channel_bridge.vk_cleanup_log_delete_fail', $postId, $error));

    if (channel_bridge_vk_cleanup_has_pending_items($pdo, $taskId)) {
      channel_bridge_vk_cleanup_spawn_next_worker($pdo, $taskId);
      return ['status' => 'pending', 'reason' => 'next_after_fail', 'delay_seconds' => CHANNEL_BRIDGE_VK_CLEANUP_DELETE_DELAY_SECONDS];
    }

    channel_bridge_vk_cleanup_finalize_task($pdo, $taskId);
    return ['status' => 'done', 'reason' => 'task_complete'];
  }
}
