<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_channel_admins_refresh.php
 * ROLE: do=channel_admins_refresh — обновление локального списка админов MAX-чата.
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

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!stopbot_is_manage_role($roles)) {
  flash(stopbot_t('stopbot.flash_access_denied'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
}

$channelId = (int)($_POST['id'] ?? 0);
$botId = (int)($_POST['bot_id'] ?? 0);
if ($channelId <= 0 || $botId <= 0) {
  flash(stopbot_t('stopbot.flash_channel_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
}

$pdo = db();
$st = $pdo->prepare("SELECT * FROM " . STOPBOT_TABLE_CHANNELS . " WHERE id = :id AND bot_id = :bid LIMIT 1");
$st->execute([':id' => $channelId, ':bid' => $botId]);
$channel = $st->fetch(PDO::FETCH_ASSOC);
if (!is_array($channel)) {
  flash(stopbot_t('stopbot.flash_channel_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);
}

$chatId = trim((string)($channel['chat_id'] ?? ''));
$res = stopbot_max_refresh_chat_admins($pdo, $botId, $chatId);

$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';
audit_log(STOPBOT_MODULE_CODE, 'channel_admins_refresh', (($res['ok'] ?? false) === true) ? 'info' : 'warn', [
  'channel_id' => $channelId,
  'bot_id' => $botId,
  'chat_id' => $chatId,
  'count' => (int)($res['count'] ?? 0),
  'error' => (string)($res['error'] ?? ''),
  'http_code' => (int)($res['http_code'] ?? 0),
  'raw_keys' => (array)($res['raw_keys'] ?? []),
  'result_keys' => (array)($res['result_keys'] ?? []),
  'raw_preview' => (string)($res['raw_preview'] ?? ''),
], STOPBOT_MODULE_CODE, null, $uid, $role);

if (($res['ok'] ?? false) === true) {
  flash(stopbot_t('stopbot.flash_channel_admins_refreshed', ['count' => (string)((int)($res['count'] ?? 0))]), 'ok');
} else {
  flash(stopbot_t('stopbot.flash_channel_admins_refresh_fail', ['error' => (string)($res['error'] ?? '')]), 'danger', 1);
}

redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);
