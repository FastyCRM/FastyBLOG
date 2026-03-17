#!/usr/bin/env php
<?php
/**
 * FILE: /adm/modules/max_comments/cron_poll.php
 * ROLE: CLI poller — добавляет open_app кнопку к последним постам каналов MAX.
 *
 * Usage:
 *   php adm/modules/max_comments/cron_poll.php
 *   php adm/modules/max_comments/cron_poll.php --limit=20
 *   php adm/modules/max_comments/cron_poll.php --chat_id=-71061050586458
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__, 3));
}

require_once ROOT_PATH . '/core/bootstrap.php';
require_once ROOT_PATH . '/adm/modules/max_comments/settings.php';
require_once ROOT_PATH . '/adm/modules/max_comments/assets/php/max_comments_lib.php';

if (PHP_SAPI !== 'cli') {
  echo "CLI only\n";
  exit(1);
}

$limit = 20;
$singleChat = '';
foreach (array_slice($argv, 1) as $arg) {
  $arg = trim((string)$arg);
  if ($arg === '') continue;
  if (strpos($arg, '--limit=') === 0) {
    $limit = (int)substr($arg, 8);
    continue;
  }
  if (strpos($arg, '--chat_id=') === 0) {
    $singleChat = trim((string)substr($arg, 10));
    continue;
  }
}
$limit = max(1, min(50, $limit));

try {
  if (function_exists('module_is_enabled') && !module_is_enabled(MAX_COMMENTS_MODULE_CODE)) {
    echo "max_comments disabled\n";
    exit(0);
  }

  $pdo = db();
  max_comments_require_schema($pdo);

  $settings = max_comments_settings_get($pdo);
  if ((int)($settings['enabled'] ?? 0) !== 1) {
    echo "max_comments settings.enabled=0\n";
    exit(0);
  }

  $bridge = max_comments_bridge_settings($pdo);
  $bridgeErr = '';
  if (!max_comments_bridge_ready($bridge, $bridgeErr)) {
    throw new RuntimeException($bridgeErr);
  }

  $chatIds = [];
  if ($singleChat !== '') {
    $chatIds[] = $singleChat;
  } else {
    foreach (max_comments_channels_get($pdo) as $row) {
      if ((int)($row['enabled'] ?? 0) !== 1) continue;
      $cid = trim((string)($row['chat_id'] ?? ''));
      if ($cid === '') continue;
      $chatIds[] = $cid;
    }
  }

  if (!$chatIds) {
    echo "no enabled channels\n";
    exit(0);
  }

  $sumScanned = 0;
  $sumChanged = 0;
  $sumAlready = 0;
  $sumDuplicates = 0;
  $sumNoId = 0;
  $sumErrors = 0;
  $failed = [];
  $details = [];

  foreach ($chatIds as $chatId) {
    $one = max_comments_poll_chat($pdo, $bridge, $settings, $chatId, $limit);
    if (($one['ok'] ?? false) !== true) {
      $failed[] = [
        'chat_id' => $chatId,
        'error' => (string)($one['error'] ?? 'POLL_FAILED'),
        'http_code' => (int)($one['http_code'] ?? 0),
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

  $report = [
    'ok' => !$failed && $sumErrors === 0,
    'channels_total' => count($chatIds),
    'channels_failed' => count($failed),
    'scanned' => $sumScanned,
    'changed' => $sumChanged,
    'already_with_button' => $sumAlready,
    'duplicates' => $sumDuplicates,
    'skipped_no_id' => $sumNoId,
    'errors_total' => $sumErrors,
    'failed' => $failed,
  ];

  if (function_exists('audit_log')) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'cron_poll', $report['ok'] ? 'info' : 'warn', [
      'limit' => $limit,
      'single_chat' => $singleChat,
      'report' => $report,
      'details' => $details,
    ]);
  }

  echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
  exit($report['ok'] ? 0 : 2);
} catch (Throwable $e) {
  if (function_exists('audit_log')) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'cron_poll', 'error', [
      'error' => $e->getMessage(),
      'limit' => $limit,
      'single_chat' => $singleChat,
    ]);
  }
  fwrite(STDERR, "max_comments cron_poll error: " . $e->getMessage() . "\n");
  exit(1);
}
