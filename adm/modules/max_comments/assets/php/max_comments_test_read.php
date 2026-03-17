<?php
/**
 * FILE: /adm/modules/max_comments/assets/php/max_comments_test_read.php
 * ROLE: Ручной тест чтения последнего сообщения из выбранного канала MAX.
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

  if ($chatId === '') {
    throw new RuntimeException('Нет выбранного канала для теста чтения.');
  }

  $read = max_comments_bridge_get_last_message($bridge, $chatId);
  if (($read['ok'] ?? false) !== true) {
    $err = trim((string)($read['error'] ?? 'READ_FAILED'));
    if (function_exists('audit_log')) {
      audit_log(MAX_COMMENTS_MODULE_CODE, 'test_read', 'warn', [
        'chat_id' => $chatId,
        'error' => $err,
      ]);
    }
    throw new RuntimeException($err);
  }

  $message = (array)($read['message'] ?? []);
  $messageId = max_comments_message_id_from_message($message);
  $body = max_comments_message_body($message);
  $text = trim((string)($body['text'] ?? $message['text'] ?? ''));
  $attachments = (array)($body['attachments'] ?? $message['attachments'] ?? []);
  $bodyRaw = (array)($message['body'] ?? []);
  $statRaw = (array)($message['stat'] ?? []);

  if (function_exists('audit_log')) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'test_read', 'info', [
      'chat_id' => $chatId,
      'message_id' => $messageId,
      'text_len' => strlen($text),
      'attachments_count' => count($attachments),
      'message_keys' => array_keys($message),
      'body_keys' => array_keys($bodyRaw),
      'stat_keys' => array_keys($statRaw),
    ]);
  }

  $preview = $text;
  if ($preview !== '' && function_exists('mb_substr')) {
    $preview = (string)mb_substr($preview, 0, 120);
  } elseif ($preview !== '') {
    $preview = (string)substr($preview, 0, 120);
  }

  $msg = 'Чтение канала OK: chat_id=' . $chatId . ', message_id=' . ($messageId !== '' ? $messageId : 'n/a');
  if ($preview !== '') {
    $msg .= ', text="' . $preview . '"';
  }
  flash($msg, 'ok');
} catch (Throwable $e) {
  if (function_exists('audit_log')) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'test_read', 'error', [
      'error' => $e->getMessage(),
    ]);
  }
  flash('Тест чтения MAX: ' . $e->getMessage(), 'danger', 1);
}

redirect('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE);
