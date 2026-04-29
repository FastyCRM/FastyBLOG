<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_channel_attach.php
 * ROLE: do=channel_attach — ручное добавление канала/чата к боту без bind-кода.
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
$chatId = trim((string)($_POST['chat_id'] ?? ''));
$chatTitle = trim((string)($_POST['chat_title'] ?? ''));
$chatType = trim((string)($_POST['chat_type'] ?? ''));

if ($botId <= 0) {
  flash(promobot_t('promobot.flash_bot_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);
}

if ($chatId === '') {
  flash(promobot_t('promobot.flash_channel_add_fail', ['error' => 'CHAT_ID_EMPTY']), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);
}

$pdo = db();
$bot = promobot_bot_get($pdo, $botId);
if (!$bot) {
  flash(promobot_t('promobot.flash_bot_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);
}

$platform = strtolower(trim((string)($bot['platform'] ?? PROMOBOT_PLATFORM_TG)));
if ($platform !== PROMOBOT_PLATFORM_MAX) $platform = PROMOBOT_PLATFORM_TG;

$upsert = promobot_channel_upsert($pdo, $botId, $platform, [
  'chat_id' => $chatId,
  'chat_title' => $chatTitle,
  'chat_type' => $chatType,
]);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if (($upsert['ok'] ?? false) !== true) {
  $err = trim((string)($upsert['error'] ?? 'CHANNEL_ATTACH_FAILED'));
  audit_log(PROMOBOT_MODULE_CODE, 'channel_attach', 'warn', [
    'bot_id' => $botId,
    'platform' => $platform,
    'chat_id' => $chatId,
    'error' => $err,
  ], PROMOBOT_MODULE_CODE, null, $uid, $role);

  flash(promobot_t('promobot.flash_channel_add_fail', ['error' => $err]), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);
}

audit_log(PROMOBOT_MODULE_CODE, 'channel_attach', 'info', [
  'bot_id' => $botId,
  'platform' => $platform,
  'chat_id' => $chatId,
], PROMOBOT_MODULE_CODE, null, $uid, $role);

flash(promobot_t('promobot.flash_channel_added'), 'ok');
redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);
