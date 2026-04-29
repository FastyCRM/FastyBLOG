<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_user_attach.php
 * ROLE: do=user_attach — назначение пользователя на бота.
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

$userId = (int)($_POST['user_id'] ?? 0);
$botId = (int)($_POST['bot_id'] ?? 0);

if ($userId <= 0 || $botId <= 0) {
  flash(stopbot_t('stopbot.flash_user_attach_fail'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
}

$pdo = db();
$pdo->prepare("\n  INSERT INTO " . STOPBOT_TABLE_USER_ACCESS . " (user_id, bot_id, created_by)
  VALUES (:uid, :bid, :created_by)
  ON DUPLICATE KEY UPDATE created_by = VALUES(created_by), updated_at = CURRENT_TIMESTAMP
")->execute([
  ':uid' => $userId,
  ':bid' => $botId,
  ':created_by' => (int)(function_exists('auth_user_id') ? auth_user_id() : 0),
]);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

audit_log(STOPBOT_MODULE_CODE, 'user_attach', 'info', [
  'user_id' => $userId,
  'bot_id' => $botId,
], STOPBOT_MODULE_CODE, null, $uid, $role);

flash(stopbot_t('stopbot.flash_user_attached'), 'ok');

redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);