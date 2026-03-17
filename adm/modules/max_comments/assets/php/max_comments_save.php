<?php
/**
 * FILE: /adm/modules/max_comments/assets/php/max_comments_save.php
 * ROLE: Сохранение настроек модуля max_comments.
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
  $hasOwnApiKeyColumn = max_comments_table_has_column($pdo, MAX_COMMENTS_TABLE_SETTINGS, 'max_api_key');
  $subscriptionMeta = [
    'status' => 'skip',
    'created' => 0,
    'recreated' => 0,
    'recreate_reason' => '',
    'removed' => 0,
    'remove_errors' => [],
    'error' => '',
    'endpoint' => '',
  ];
  $webhookCheck = [
    'ok' => null,
    'http_code' => 0,
    'error' => '',
  ];

  $enabled = ((int)($_POST['enabled'] ?? 0) === 1) ? 1 : 0;
  $buttonText = trim((string)($_POST['button_text'] ?? 'Комментарии'));
  $maxApiKey = trim((string)($_POST['max_api_key'] ?? ''));
  if ($buttonText === '') $buttonText = 'Комментарии';

  $postedChannels = $_POST['channels'] ?? [];
  if (!is_array($postedChannels)) $postedChannels = [];

  $titleMap = $_POST['channel_title'] ?? [];
  if (!is_array($titleMap)) $titleMap = [];

  $rows = [];
  $seen = [];
  foreach ($postedChannels as $rawChatId) {
    $chatId = trim((string)$rawChatId);
    if ($chatId === '' || isset($seen[$chatId])) continue;
    $seen[$chatId] = true;

    $title = trim((string)($titleMap[$chatId] ?? ''));
    $rows[] = [
      'chat_id' => $chatId,
      'title' => $title,
      'enabled' => 1,
    ];
  }

  $pdo->beginTransaction();
  max_comments_settings_save($pdo, [
    'enabled' => $enabled,
    'button_text' => $buttonText,
    'max_api_key' => $maxApiKey,
  ]);
  max_comments_channels_replace($pdo, $rows);
  $pdo->commit();

  if ($enabled === 1) {
    $chatIds = [];
    foreach ($rows as $r) {
      $cid = trim((string)($r['chat_id'] ?? ''));
      if ($cid !== '') $chatIds[] = $cid;
    }

    $bridge = max_comments_bridge_settings($pdo);
    $bridgeErr = '';
    if (!max_comments_bridge_ready($bridge, $bridgeErr)) {
      $subscriptionMeta['status'] = 'error';
      $subscriptionMeta['error'] = $bridgeErr;
      flash('Настройки сохранены, но подписка MAX не настроена: ' . $bridgeErr, 'danger', 1);
      redirect('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE);
    }

    $endpoint = max_comments_webhook_url(true);
    $subscriptionMeta['endpoint'] = $endpoint;
    if (!preg_match('~^https?://~i', $endpoint)) {
      $subscriptionMeta['status'] = 'error';
      $subscriptionMeta['error'] = 'WEBHOOK_URL_INVALID';
      flash('Настройки сохранены, но webhook URL некорректен: ' . $endpoint, 'danger', 1);
      redirect('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE);
    }

    $sub = max_comments_ensure_subscription($bridge, $endpoint);
    if (($sub['ok'] ?? false) !== true) {
      $err = trim((string)($sub['error'] ?? 'SUBSCRIBE_ERROR'));
      $subscriptionMeta['status'] = 'error';
      $subscriptionMeta['error'] = $err;
      flash('Настройки сохранены, но подписка MAX не применена: ' . $err . ' (webhook: ' . $endpoint . ')', 'danger', 1);
      redirect('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE);
    }
    $subscriptionMeta['status'] = 'ok';
    $subscriptionMeta['created'] = ((int)($sub['created'] ?? 0) === 1) ? 1 : 0;
    $subscriptionMeta['recreated'] = ((int)($sub['recreated'] ?? 0) === 1) ? 1 : 0;
    $subscriptionMeta['recreate_reason'] = trim((string)($sub['recreate_reason'] ?? ''));
    $subscriptionMeta['removed'] = (int)($sub['removed'] ?? 0);
    $subscriptionMeta['remove_errors'] = (array)($sub['remove_errors'] ?? []);

    $check = max_comments_webhook_selfcheck($endpoint);
    $webhookCheck['ok'] = ($check['ok'] ?? false) ? 1 : 0;
    $webhookCheck['http_code'] = (int)($check['http_code'] ?? 0);
    $webhookCheck['error'] = trim((string)($check['error'] ?? ''));
    if (($check['ok'] ?? false) !== true) {
      $checkErr = $webhookCheck['error'] !== '' ? $webhookCheck['error'] : ('HTTP_' . $webhookCheck['http_code']);
      flash('Настройки сохранены, но webhook URL недоступен: ' . $checkErr . ' (' . $endpoint . ')', 'danger', 1);
    }

    $access = max_comments_bridge_check_chat_access($bridge, $chatIds);
    $notAdmin = (array)($access['not_admin'] ?? []);
    $noEditPerm = (array)($access['no_edit_perm'] ?? []);
    $accessErrors = (array)($access['errors'] ?? []);

    if ($notAdmin || $noEditPerm || $accessErrors) {
      $warnParts = [];
      if ($notAdmin) {
        $warnParts[] = 'бот не админ в чатах: ' . implode(', ', $notAdmin);
      }
      if ($noEditPerm) {
        $warnParts[] = 'нет права post_edit_delete_message/edit_message: ' . implode(', ', $noEditPerm);
      }
      if ($accessErrors) {
        $x = [];
        foreach ($accessErrors as $e) {
          if (!is_array($e)) continue;
          $x[] = (string)($e['chat_id'] ?? '?') . ' (' . (string)($e['error'] ?? 'error') . ')';
        }
        if ($x) $warnParts[] = 'ошибки проверки: ' . implode('; ', $x);
      }

      if ($warnParts) {
        flash(
          'MAX: возможна причина отсутствия кнопки в канале: ' . implode(' | ', $warnParts)
          . '. Для событий channel/group бот должен быть админом.',
          'danger',
          1
        );
      }
    }
  }

  if (function_exists('audit_log')) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'settings_update', 'info', [
      'enabled' => $enabled,
      'button_text' => $buttonText,
      'max_api_key_set' => ($maxApiKey !== '') ? 1 : 0,
      'channels_selected' => count($rows),
      'subscription_status' => (string)($subscriptionMeta['status'] ?? 'skip'),
      'subscription_created' => (int)($subscriptionMeta['created'] ?? 0),
      'subscription_recreated' => (int)($subscriptionMeta['recreated'] ?? 0),
      'subscription_recreate_reason' => (string)($subscriptionMeta['recreate_reason'] ?? ''),
      'subscription_removed' => (int)($subscriptionMeta['removed'] ?? 0),
      'subscription_remove_errors' => (array)($subscriptionMeta['remove_errors'] ?? []),
      'subscription_error' => (string)($subscriptionMeta['error'] ?? ''),
      'webhook' => (string)($subscriptionMeta['endpoint'] ?? ''),
      'webhook_path' => max_comments_webhook_path(),
      'webhook_file_exists' => is_file(ROOT_PATH . max_comments_webhook_path()) ? 1 : 0,
      'webhook_selfcheck_ok' => $webhookCheck['ok'],
      'webhook_selfcheck_http' => $webhookCheck['http_code'],
      'webhook_selfcheck_error' => $webhookCheck['error'],
    ]);
  }

  flash('Настройки max_comments сохранены.', 'ok');
  if (!$hasOwnApiKeyColumn) {
    flash('Внимание: поле MAX API key не сохранено. Примените обновленный install.sql (колонка max_api_key).', 'danger', 1);
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }
  flash('Ошибка сохранения max_comments: ' . $e->getMessage(), 'danger', 1);
}

redirect('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE);
