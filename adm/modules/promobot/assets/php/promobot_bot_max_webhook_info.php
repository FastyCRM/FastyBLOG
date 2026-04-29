<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_bot_max_webhook_info.php
 * ROLE: do=bot_max_webhook_info — проверка статуса webhook-подписки MAX.
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
$info = promobot_max_webhook_info_for_bot($bot);

if (($info['ok'] ?? false) === true) {
  audit_log(PROMOBOT_MODULE_CODE, 'bot_max_webhook_info', 'info', [
    'bot_id' => $botId,
    'listener_url' => (string)($info['expected_url'] ?? ''),
    'subscription_version' => (string)($info['subscription_version'] ?? ''),
    'items_count' => (int)($info['items_count'] ?? 0),
    'recreate_needed' => (int)($info['recreate_needed'] ?? 0),
  ], PROMOBOT_MODULE_CODE, null, $uid, $role);

  flash(promobot_t('promobot.flash_max_webhook_info_ok'), 'ok');
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);
}

$error = trim((string)($info['error'] ?? 'MAX_WEBHOOK_NOT_READY'));
if ($error === '') $error = 'MAX_WEBHOOK_NOT_READY';

audit_log(PROMOBOT_MODULE_CODE, 'bot_max_webhook_info', 'warn', [
  'bot_id' => $botId,
  'listener_url' => (string)($info['expected_url'] ?? ''),
  'error' => $error,
  'items_count' => (int)($info['items_count'] ?? 0),
  'recreate_needed' => (int)($info['recreate_needed'] ?? 0),
  'recreate_reason' => (string)($info['recreate_reason'] ?? ''),
], PROMOBOT_MODULE_CODE, null, $uid, $role);

flash(promobot_t('promobot.flash_max_webhook_info_fail', ['error' => $error]), 'warn');
redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);
