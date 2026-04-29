<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_channel_unbind.php
 * ROLE: do=channel_unbind — удаление привязки чата.
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

$channelId = (int)($_POST['id'] ?? 0);
$botId = (int)($_POST['bot_id'] ?? 0);

if ($channelId <= 0 || $botId <= 0) {
  flash(stopbot_t('stopbot.flash_channel_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
}

$pdo = db();

$st = $pdo->prepare("SELECT platform, chat_id FROM " . STOPBOT_TABLE_CHANNELS . " WHERE id = :id AND bot_id = :bid LIMIT 1");
$st->execute([':id' => $channelId, ':bid' => $botId]);
$channel = $st->fetch(PDO::FETCH_ASSOC);
$platform = is_array($channel) ? (string)($channel['platform'] ?? '') : '';
$chatId = is_array($channel) ? (string)($channel['chat_id'] ?? '') : '';
$removedAdmins = 0;

$pdo->prepare("DELETE FROM " . STOPBOT_TABLE_CHANNELS . " WHERE id = :id AND bot_id = :bid LIMIT 1")
  ->execute([':id' => $channelId, ':bid' => $botId]);

if ($platform === STOPBOT_PLATFORM_MAX && $chatId !== '') {
  $adminDelete = $pdo->prepare("
    DELETE FROM " . STOPBOT_TABLE_CHAT_ADMINS . "
    WHERE bot_id = :bid AND platform = :platform AND chat_id = :chat_id
  ");
  $adminDelete->execute([
    ':bid' => $botId,
    ':platform' => STOPBOT_PLATFORM_MAX,
    ':chat_id' => $chatId,
  ]);
  $removedAdmins = $adminDelete->rowCount();
}

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

audit_log(STOPBOT_MODULE_CODE, 'channel_unbind', 'info', [
  'channel_id' => $channelId,
  'removed_admins' => $removedAdmins,
], STOPBOT_MODULE_CODE, null, $uid, $role);

flash(stopbot_t('stopbot.flash_channel_unbound'), 'ok');

redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);
