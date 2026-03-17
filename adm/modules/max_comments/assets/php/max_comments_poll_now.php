<?php
/**
 * FILE: /adm/modules/max_comments/assets/php/max_comments_poll_now.php
 * ROLE: Ручной poll последних сообщений в выбранных каналах и добавление кнопки.
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
  $settings = max_comments_settings_get($pdo);

  $bridge = max_comments_bridge_settings($pdo);
  $bridgeErr = '';
  if (!max_comments_bridge_ready($bridge, $bridgeErr)) {
    throw new RuntimeException($bridgeErr);
  }

  $postedChannels = $_POST['channels'] ?? [];
  if (!is_array($postedChannels)) $postedChannels = [];

  $chatIds = [];
  $seen = [];
  foreach ($postedChannels as $raw) {
    $cid = trim((string)$raw);
    if ($cid === '' || isset($seen[$cid])) continue;
    $seen[$cid] = true;
    $chatIds[] = $cid;
  }

  if (!$chatIds) {
    foreach (max_comments_channels_get($pdo) as $row) {
      if ((int)($row['enabled'] ?? 0) !== 1) continue;
      $cid = trim((string)($row['chat_id'] ?? ''));
      if ($cid === '' || isset($seen[$cid])) continue;
      $seen[$cid] = true;
      $chatIds[] = $cid;
    }
  }

  if (!$chatIds) {
    throw new RuntimeException('Нет выбранных каналов для poll.');
  }

  $limit = 20;
  $sumScanned = 0;
  $sumChanged = 0;
  $sumAlready = 0;
  $sumDuplicates = 0;
  $sumNoId = 0;
  $sumErrors = 0;
  $fail = [];
  $details = [];

  foreach ($chatIds as $chatId) {
    $one = max_comments_poll_chat($pdo, $bridge, $settings, $chatId, $limit);
    if (($one['ok'] ?? false) !== true) {
      $fail[] = [
        'chat_id' => $chatId,
        'error' => (string)($one['error'] ?? 'POLL_FAILED'),
        'http_code' => (int)($one['http_code'] ?? 0),
        'url' => (string)($one['url'] ?? ''),
      ];
      continue;
    }

    $sumScanned += (int)($one['scanned'] ?? 0);
    $sumChanged += (int)($one['changed'] ?? 0);
    $sumAlready += (int)($one['already_with_button'] ?? 0);
    $sumDuplicates += (int)($one['duplicates'] ?? 0);
    $sumNoId += (int)($one['skipped_no_id'] ?? 0);
    $sumErrors += count((array)($one['errors'] ?? []));
    $details[] = $one;
  }

  if (function_exists('audit_log')) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'poll_now', ($fail || $sumErrors > 0) ? 'warn' : 'info', [
      'channels_total' => count($chatIds),
      'channels_failed' => count($fail),
      'scanned' => $sumScanned,
      'changed' => $sumChanged,
      'already_with_button' => $sumAlready,
      'duplicates' => $sumDuplicates,
      'skipped_no_id' => $sumNoId,
      'errors_total' => $sumErrors,
      'fail' => $fail,
      'details' => $details,
    ]);
  }

  $msg = 'Poll: чатов ' . count($chatIds)
    . ', проверено ' . $sumScanned
    . ', кнопка добавлена ' . $sumChanged
    . ', уже была ' . $sumAlready
    . ', дубликаты ' . $sumDuplicates
    . ', без id ' . $sumNoId
    . ', ошибок ' . $sumErrors;

  if ($fail) {
    $parts = [];
    foreach ($fail as $f) {
      if (!is_array($f)) continue;
      $parts[] = (string)($f['chat_id'] ?? '?') . ' (' . (string)($f['error'] ?? 'error') . ')';
    }
    if ($parts) $msg .= ' | fail: ' . implode('; ', $parts);
  }

  flash($msg, ($fail || $sumErrors > 0) ? 'danger' : 'ok', ($fail || $sumErrors > 0) ? 1 : 0);
} catch (Throwable $e) {
  if (function_exists('audit_log')) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'poll_now', 'error', [
      'error' => $e->getMessage(),
    ]);
  }
  flash('Poll MAX: ' . $e->getMessage(), 'danger', 1);
}

redirect('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE);

