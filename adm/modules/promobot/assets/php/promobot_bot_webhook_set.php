<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_bot_webhook_set.php
 * ROLE: do=bot_webhook_set — установка Telegram webhook.
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

$roles = function_exists('auth_user_roles') ? (array)auth_user_roles((int)auth_user_id()) : [];
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

if ((string)($bot['platform'] ?? '') !== PROMOBOT_PLATFORM_TG) {
  flash(promobot_t('promobot.flash_bot_platform_mismatch'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);
}

$token = trim((string)($bot['bot_token'] ?? ''));
if ($token === '') {
  flash(promobot_t('promobot.flash_bot_token_empty'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);
}

$secret = trim((string)($bot['webhook_secret'] ?? ''));
$url = promobot_bot_webhook_url($botId, PROMOBOT_PLATFORM_TG, true);

$params = [
  'allowed_updates' => ['message', 'channel_post', 'my_chat_member'],
  'drop_pending_updates' => false,
];
if ($secret !== '') {
  $params['secret_token'] = $secret;
}

$set = function_exists('tg_set_webhook') ? tg_set_webhook($token, $url, $params) : ['ok' => false, 'error' => 'TG_SET_WEBHOOK_MISSING'];

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if (($set['ok'] ?? false) === true) {
  $pdo->prepare("\n    UPDATE " . PROMOBOT_TABLE_BOTS . "
    SET webhook_url = :url
    WHERE id = :id
  ")->execute([
    ':url' => $url,
    ':id' => $botId,
  ]);

  audit_log(PROMOBOT_MODULE_CODE, 'bot_webhook_set', 'info', [
    'bot_id' => $botId,
  ], PROMOBOT_MODULE_CODE, null, $uid, $role);

  flash(promobot_t('promobot.flash_webhook_set_ok'), 'ok');
} else {
  audit_log(PROMOBOT_MODULE_CODE, 'bot_webhook_set', 'warn', [
    'bot_id' => $botId,
    'error' => (string)($set['error'] ?? 'TG_SET_WEBHOOK_FAILED'),
  ], PROMOBOT_MODULE_CODE, null, $uid, $role);

  flash(promobot_t('promobot.flash_webhook_set_fail'), 'danger', 1);
}

redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);
