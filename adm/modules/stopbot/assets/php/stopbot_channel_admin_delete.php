<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_channel_admin_delete.php
 * ROLE: do=channel_admin_delete — удаление админа из локального списка MAX-чата.
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

$adminId = (int)($_POST['id'] ?? 0);
$botId = (int)($_POST['bot_id'] ?? 0);
if ($adminId <= 0 || $botId <= 0) {
  flash(stopbot_t('stopbot.flash_channel_admin_delete_fail'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
}

$pdo = db();
$deleted = stopbot_chat_admin_delete($pdo, $botId, $adminId);

$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';
audit_log(STOPBOT_MODULE_CODE, 'channel_admin_delete', $deleted ? 'info' : 'warn', [
  'admin_id' => $adminId,
  'bot_id' => $botId,
  'deleted' => $deleted ? 1 : 0,
], STOPBOT_MODULE_CODE, null, $uid, $role);

flash($deleted ? stopbot_t('stopbot.flash_channel_admin_deleted') : stopbot_t('stopbot.flash_channel_admin_delete_fail'), $deleted ? 'ok' : 'danger', $deleted ? 0 : 1);

redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);

