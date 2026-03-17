<?php
/**
 * FILE: /adm/modules/max_comments/assets/php/max_comments_test_updates.php
 * ROLE: Ручной тест чтения updates из MAX API.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/max_comments_lib.php';

acl_guard(module_allowed_roles(MAX_COMMENTS_MODULE_CODE));

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  http_405('Method Not Allowed');
}

csrf_check((string)($_POST['csrf'] ?? ''));

$pdo = db();

try {
  max_comments_require_schema($pdo);

  $bridge = max_comments_bridge_settings($pdo);
  $bridgeErr = '';
  if (!max_comments_bridge_ready($bridge, $bridgeErr)) {
    throw new RuntimeException($bridgeErr);
  }

  $postedChannels = $_POST['channels'] ?? [];
  if (!is_array($postedChannels)) $postedChannels = [];

  $chatId = '';
  foreach ($postedChannels as $raw) {
    $cid = trim((string)$raw);
    if ($cid === '') continue;
    $chatId = $cid;
    break;
  }
  if ($chatId === '') {
    foreach (max_comments_channels_get($pdo) as $row) {
      if ((int)($row['enabled'] ?? 0) !== 1) continue;
      $cid = trim((string)($row['chat_id'] ?? ''));
      if ($cid === '') continue;
      $chatId = $cid;
      break;
    }
  }

  $analyze = static function (array $updates, string $chatId): array {
    $total = count($updates);
    $created = 0;
    $forChat = 0;
    $types = [];
    $samples = [];

    foreach ($updates as $idx => $u) {
      if (!is_array($u)) continue;
      $meta = max_comments_extract_message_meta($u);
      $type = trim((string)($meta['update_type'] ?? ''));
      $cid = trim((string)($meta['chat_id'] ?? ''));
      $mid = trim((string)($meta['message_id'] ?? ''));

      if ($type !== '') $types[$type] = true;
      if ($type === 'message_created') $created++;
      if ($chatId !== '' && $cid === $chatId) $forChat++;

      if (count($samples) < 5) {
        $samples[] = [
          'i' => $idx,
          'type' => $type,
          'chat_id' => $cid,
          'message_id' => $mid,
          'keys' => array_keys($u),
        ];
      }
    }

    $typesList = array_keys($types);
    sort($typesList);

    return [
      'updates_total' => $total,
      'message_created_total' => $created,
      'for_chat_total' => $forChat,
      'types' => $typesList,
      'samples' => $samples,
    ];
  };

  $filtered = max_comments_bridge_get_updates($bridge, 30, 'message_created');
  if (($filtered['ok'] ?? false) !== true) {
    $err = trim((string)($filtered['error'] ?? 'UPDATES_FAILED'));
    if ((int)($filtered['http_code'] ?? 0) > 0) {
      $err .= ' (HTTP ' . (int)$filtered['http_code'] . ')';
    }
    throw new RuntimeException($err);
  }

  $all = max_comments_bridge_get_updates($bridge, 30, '');
  if (($all['ok'] ?? false) !== true) {
    $err = trim((string)($all['error'] ?? 'UPDATES_ALL_FAILED'));
    if ((int)($all['http_code'] ?? 0) > 0) {
      $err .= ' (HTTP ' . (int)$all['http_code'] . ')';
    }
    throw new RuntimeException($err);
  }

  $filteredStats = $analyze((array)($filtered['updates'] ?? []), $chatId);
  $allStats = $analyze((array)($all['updates'] ?? []), $chatId);

  if (function_exists('audit_log')) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'test_updates', ((int)$allStats['updates_total'] > 0) ? 'info' : 'warn', [
      'chat_id' => $chatId,
      'filtered' => [
        'query' => 'message_created',
        'url' => (string)($filtered['url'] ?? ''),
        'marker' => (string)($filtered['marker'] ?? ''),
        'updates_total' => (int)($filteredStats['updates_total'] ?? 0),
        'message_created_total' => (int)($filteredStats['message_created_total'] ?? 0),
        'for_chat_total' => (int)($filteredStats['for_chat_total'] ?? 0),
        'types' => (array)($filteredStats['types'] ?? []),
        'samples' => (array)($filteredStats['samples'] ?? []),
      ],
      'all' => [
        'query' => 'all',
        'url' => (string)($all['url'] ?? ''),
        'marker' => (string)($all['marker'] ?? ''),
        'updates_total' => (int)($allStats['updates_total'] ?? 0),
        'message_created_total' => (int)($allStats['message_created_total'] ?? 0),
        'for_chat_total' => (int)($allStats['for_chat_total'] ?? 0),
        'types' => (array)($allStats['types'] ?? []),
        'samples' => (array)($allStats['samples'] ?? []),
      ],
    ]);
  }

  flash(
    'Updates[message_created]: ' . (int)$filteredStats['updates_total']
    . ' | Updates[all]: ' . (int)$allStats['updates_total']
    . ', для канала: ' . (int)$allStats['for_chat_total']
    . ((array)$allStats['types'] ? ' | types: ' . implode(', ', (array)$allStats['types']) : ''),
    ((int)$allStats['updates_total'] > 0 ? 'ok' : 'danger'),
    ((int)$allStats['updates_total'] > 0 ? 0 : 1)
  );
} catch (Throwable $e) {
  if (function_exists('audit_log')) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'test_updates', 'error', [
      'error' => $e->getMessage(),
    ]);
  }
  flash('Тест updates MAX: ' . $e->getMessage(), 'danger', 1);
}

redirect('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE);
