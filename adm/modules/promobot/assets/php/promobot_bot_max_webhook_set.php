<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_bot_max_webhook_set.php
 * ROLE: do=bot_max_webhook_set — установка webhook-подписки MAX.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/promobot_lib.php';

acl_guard(module_allowed_roles(PROMOBOT_MODULE_CODE));

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
  http_405('Method Not Allowed');
}

csrf_check((string)($_POST['csrf'] ?? ''));

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!promobot_is_manage_role($roles)) {
  flash(promobot_t('promobot.flash_access_denied'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);
}

$botId = (int)($_POST['id'] ?? 0);
if ($botId <= 0) {
  flash(promobot_t('promobot.flash_bot_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);
}

$pdo = db();
$bot = promobot_bot_get($pdo, $botId);
if (!$bot) {
  flash(promobot_t('promobot.flash_bot_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);
}

if ((string)($bot['platform'] ?? '') !== PROMOBOT_PLATFORM_MAX) {
  flash(promobot_t('promobot.flash_bot_platform_mismatch'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);
}

$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';
$apply = promobot_max_webhook_set_for_bot($bot);
$info = promobot_max_webhook_info_for_bot($bot);

if (($apply['ok'] ?? false) === true && ($info['ok'] ?? false) === true) {
  $listenerUrl = trim((string)($info['expected_url'] ?? $apply['url'] ?? promobot_max_webhook_target_url($bot)));
  if ($listenerUrl !== '') {
    $pdo->prepare("\n      UPDATE " . PROMOBOT_TABLE_BOTS . "\n      SET webhook_url = :url\n      WHERE id = :id\n    ")->execute([
      ':url' => $listenerUrl,
      ':id' => $botId,
    ]);
  }

  audit_log(PROMOBOT_MODULE_CODE, 'bot_max_webhook_set', 'info', [
    'bot_id' => $botId,
    'listener_url' => $listenerUrl,
    'created' => (int)($apply['created'] ?? 0),
    'removed' => (int)($apply['removed'] ?? 0),
    'recreated' => (int)($apply['recreated'] ?? 0),
    'recreate_reason' => (string)($apply['recreate_reason'] ?? ''),
  ], PROMOBOT_MODULE_CODE, null, $uid, $role);

  flash(promobot_t('promobot.flash_max_webhook_set_ok'), 'ok');
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);
}

$error = trim((string)($apply['error'] ?? $info['error'] ?? 'MAX_WEBHOOK_SET_FAILED'));
if ($error === '') $error = 'MAX_WEBHOOK_SET_FAILED';

audit_log(PROMOBOT_MODULE_CODE, 'bot_max_webhook_set', 'warn', [
  'bot_id' => $botId,
  'listener_url' => promobot_max_webhook_target_url($bot),
  'error' => $error,
  'http_code' => (int)($apply['http_code'] ?? 0),
  'details' => (array)($apply['details'] ?? []),
  'recreate_reason' => (string)($info['recreate_reason'] ?? ''),
], PROMOBOT_MODULE_CODE, null, $uid, $role);

flash(promobot_t('promobot.flash_max_webhook_set_fail', ['error' => $error]), 'danger', 1);
redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);
