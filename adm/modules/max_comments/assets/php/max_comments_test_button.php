<?php
/**
 * FILE: /adm/modules/max_comments/assets/php/max_comments_test_button.php
 * ROLE: Ручной тест отправки open_app кнопки в выбранные каналы.
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
    throw new RuntimeException('Нет выбранных каналов для теста.');
  }

  $ok = [];
  $fail = [];
  foreach ($chatIds as $chatId) {
    $res = max_comments_bridge_send_test_button($bridge, $settings, $chatId);
    if (($res['ok'] ?? false) === true) {
      $ok[] = [
        'chat_id' => $chatId,
        'message_id' => (string)($res['message_id'] ?? ''),
      ];
    } else {
      $fail[] = [
        'chat_id' => $chatId,
        'error' => (string)($res['error'] ?? 'SEND_FAILED'),
        'http_code' => (int)($res['http_code'] ?? 0),
        'json' => (array)($res['json'] ?? []),
        'raw' => (string)($res['raw'] ?? ''),
        'request' => (array)($res['request'] ?? []),
      ];
    }
  }

  if (function_exists('audit_log')) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'test_button', $fail ? 'warn' : 'info', [
      'channels_total' => count($chatIds),
      'sent_ok' => count($ok),
      'sent_fail' => count($fail),
      'ok' => $ok,
      'fail' => $fail,
    ]);
  }

  if (!$fail) {
    flash('Тестовая кнопка отправлена в каналы: ' . count($ok) . '.', 'ok');
  } else {
    $parts = [];
    foreach ($fail as $f) {
      if (!is_array($f)) continue;
      $parts[] = (string)($f['chat_id'] ?? '?') . ' (' . (string)($f['error'] ?? 'error') . ')';
    }
    flash(
      'Тест кнопки: успешно ' . count($ok) . ', с ошибками ' . count($fail)
      . ($parts ? ' | ' . implode('; ', $parts) : ''),
      'danger',
      1
    );
  }
} catch (Throwable $e) {
  if (function_exists('audit_log')) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'test_button', 'error', [
      'error' => $e->getMessage(),
    ]);
  }
  flash('Тест кнопки MAX: ' . $e->getMessage(), 'danger', 1);
}

redirect('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE);
