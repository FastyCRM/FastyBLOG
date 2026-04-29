<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_channel_attach.php
 * ROLE: do=channel_attach — ручное добавление канала/чата к боту без bind-кода.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/stopbot_lib.php';

acl_guard(module_allowed_roles(STOPBOT_MODULE_CODE));

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
  http_405('Method Not Allowed');
}

csrf_check((string)($_POST['csrf'] ?? ''));

$roles = function_exists('auth_user_roles') ? (array)auth_user_roles((int)auth_user_id()) : [];
if (!stopbot_is_manage_role($roles)) {
  flash(stopbot_t('stopbot.flash_access_denied'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
}

$botId = (int)($_POST['bot_id'] ?? 0);
$chatId = trim((string)($_POST['chat_id'] ?? ''));
$chatTitle = trim((string)($_POST['chat_title'] ?? ''));
$chatType = trim((string)($_POST['chat_type'] ?? ''));

if ($botId <= 0) {
  flash(stopbot_t('stopbot.flash_bot_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
}

if ($chatId === '') {
  flash(stopbot_t('stopbot.flash_channel_add_fail', ['error' => 'CHAT_ID_EMPTY']), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);
}

$pdo = db();
$bot = stopbot_bot_get($pdo, $botId);
if (!$bot) {
  flash(stopbot_t('stopbot.flash_bot_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
}

$platform = strtolower(trim((string)($bot['platform'] ?? STOPBOT_PLATFORM_TG)));
if ($platform !== STOPBOT_PLATFORM_MAX) $platform = STOPBOT_PLATFORM_TG;

$upsert = stopbot_channel_upsert($pdo, $botId, $platform, [
  'chat_id' => $chatId,
  'chat_title' => $chatTitle,
  'chat_type' => $chatType,
]);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if (($upsert['ok'] ?? false) !== true) {
  $err = trim((string)($upsert['error'] ?? 'CHANNEL_ATTACH_FAILED'));
  audit_log(STOPBOT_MODULE_CODE, 'channel_attach', 'warn', [
    'bot_id' => $botId,
    'platform' => $platform,
    'chat_id' => $chatId,
    'error' => $err,
  ], STOPBOT_MODULE_CODE, null, $uid, $role);

  flash(stopbot_t('stopbot.flash_channel_add_fail', ['error' => $err]), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);
}

audit_log(STOPBOT_MODULE_CODE, 'channel_attach', 'info', [
  'bot_id' => $botId,
  'platform' => $platform,
  'chat_id' => $chatId,
], STOPBOT_MODULE_CODE, null, $uid, $role);

flash(stopbot_t('stopbot.flash_channel_added'), 'ok');
redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);
