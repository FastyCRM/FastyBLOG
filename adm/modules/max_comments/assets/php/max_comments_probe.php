<?php
/**
 * FILE: /adm/modules/max_comments/assets/php/max_comments_probe.php
 * ROLE: Ручная проверка подписки/доступов MAX из UI.
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

  $endpoint = max_comments_webhook_url(true);
  if (!preg_match('~^https?://~i', $endpoint)) {
    throw new RuntimeException('Некорректный webhook URL: ' . $endpoint);
  }

  $subscriptionError = '';
  $subscriptionFound = false;
  $subscriptionsView = [];
  $matchedSubscription = [];
  $list = max_comments_subscription_list($bridge);
  if (($list['ok'] ?? false) !== true) {
    $subscriptionError = trim((string)($list['error'] ?? 'SUBSCRIPTION_LIST_FAILED'));
  } else {
    foreach ((array)($list['items'] ?? []) as $sub) {
      if (is_array($sub)) {
        $req = (array)($sub['request'] ?? []);
        $subscriptionsView[] = [
          'url' => (string)($sub['url'] ?? $req['url'] ?? ''),
          'update_types' => (array)($sub['update_types'] ?? []),
          'version' => (string)($sub['version'] ?? $req['version'] ?? ''),
        ];
      }
      if (is_array($sub) && max_comments_subscription_match($sub, $endpoint)) {
        $subscriptionFound = true;
        $matchedSubscription = $sub;
        break;
      }
    }
  }

  $webhookCheck = max_comments_webhook_selfcheck($endpoint);

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
      $enabled = ((int)($row['enabled'] ?? 0) === 1);
      if (!$enabled) continue;
      $cid = trim((string)($row['chat_id'] ?? ''));
      if ($cid === '' || isset($seen[$cid])) continue;
      $seen[$cid] = true;
      $chatIds[] = $cid;
    }
  }

  $access = max_comments_bridge_check_chat_access($bridge, $chatIds);
  $notAdmin = (array)($access['not_admin'] ?? []);
  $noEditPerm = (array)($access['no_edit_perm'] ?? []);
  $accessErrors = (array)($access['errors'] ?? []);

  $problems = [];
  if ($subscriptionError !== '') {
    $problems[] = 'ошибка чтения подписок: ' . $subscriptionError;
  } elseif (!$subscriptionFound) {
    $problems[] = 'подписка на webhook не найдена';
  }
  if ($notAdmin) {
    $problems[] = 'бот не админ в чатах: ' . implode(', ', $notAdmin);
  }
  if ($noEditPerm) {
    $problems[] = 'нет права post_edit_delete_message/edit_message: ' . implode(', ', $noEditPerm);
  }
  if ($accessErrors) {
    $errs = [];
    foreach ($accessErrors as $e) {
      if (!is_array($e)) continue;
      $errs[] = (string)($e['chat_id'] ?? '?') . ' (' . (string)($e['error'] ?? 'error') . ')';
    }
    if ($errs) $problems[] = 'ошибки проверки чатов: ' . implode('; ', $errs);
  }
  if (($webhookCheck['ok'] ?? false) !== true) {
    $checkErr = trim((string)($webhookCheck['error'] ?? ''));
    if ($checkErr === '') {
      $checkErr = 'HTTP_' . (int)($webhookCheck['http_code'] ?? 0);
    }
    $problems[] = 'webhook URL недоступен: ' . $checkErr;
  }

  if (function_exists('audit_log')) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'probe', $problems ? 'warn' : 'info', [
      'subscription_found' => $subscriptionFound ? 1 : 0,
      'subscription_error' => $subscriptionError,
      'checked_channels' => count($chatIds),
      'subscriptions' => $subscriptionsView,
      'subscription_matched' => $matchedSubscription,
      'not_admin' => $notAdmin,
      'no_edit_perm' => $noEditPerm,
      'access_errors' => $accessErrors,
      'webhook_path' => max_comments_webhook_path(),
      'webhook_file_exists' => is_file(ROOT_PATH . max_comments_webhook_path()) ? 1 : 0,
      'webhook' => $endpoint,
      'webhook_selfcheck' => $webhookCheck,
    ]);
  }

  if ($problems) {
    flash('Проверка MAX: ' . implode(' | ', $problems), 'danger', 1);
  } else {
    flash('Проверка MAX успешна: подписка активна, каналов проверено: ' . count($chatIds) . '.', 'ok');
  }
} catch (Throwable $e) {
  if (function_exists('audit_log')) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'probe', 'error', [
      'error' => $e->getMessage(),
    ]);
  }
  flash('Проверка MAX: ' . $e->getMessage(), 'danger', 1);
}

redirect('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE);
