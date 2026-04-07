<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_bind_code_generate.php
 * ROLE: do=bind_code_generate — генерация кода привязки чата.
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

$botId = (int)($_POST['bot_id'] ?? 0);
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

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

$res = promobot_bind_code_generate($pdo, $botId, (string)($bot['platform'] ?? PROMOBOT_PLATFORM_TG), $uid);

if (($res['ok'] ?? false) === true) {
  audit_log(PROMOBOT_MODULE_CODE, 'bind_code_generate', 'info', [
    'bot_id' => $botId,
    'platform' => (string)($bot['platform'] ?? ''),
  ], PROMOBOT_MODULE_CODE, null, $uid, $role);

  $code = (string)($res['code'] ?? '');
  $expiresAt = (string)($res['expires_at'] ?? '');
  flash(promobot_t('promobot.flash_bind_code', [
    'code' => $code,
    'expires_at' => $expiresAt,
  ]), 'ok');
} else {
  audit_log(PROMOBOT_MODULE_CODE, 'bind_code_generate', 'error', [
    'bot_id' => $botId,
    'error' => (string)($res['error'] ?? 'CODE_GENERATE_FAILED'),
  ], PROMOBOT_MODULE_CODE, null, $uid, $role);

  flash(promobot_t('promobot.flash_bind_code_fail'), 'danger', 1);
}

redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);