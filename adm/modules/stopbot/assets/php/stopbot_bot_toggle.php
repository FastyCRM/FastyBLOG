<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_bot_toggle.php
 * ROLE: do=bot_toggle — включение/выключение бота.
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

$botId = (int)($_POST['id'] ?? 0);
if ($botId <= 0) {
  flash(stopbot_t('stopbot.flash_bot_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
}

$pdo = db();
$bot = stopbot_bot_get($pdo, $botId);
if (!$bot) {
  flash(stopbot_t('stopbot.flash_bot_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
}

$enabled = ((int)($bot['enabled'] ?? 0) === 1) ? 0 : 1;

$pdo->prepare("\n  UPDATE " . STOPBOT_TABLE_BOTS . "
  SET enabled = :enabled
  WHERE id = :id
")->execute([
  ':enabled' => $enabled,
  ':id' => $botId,
]);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

audit_log(STOPBOT_MODULE_CODE, 'bot_toggle', 'info', [
  'bot_id' => $botId,
  'enabled' => $enabled,
], STOPBOT_MODULE_CODE, null, $uid, $role);

flash($enabled ? stopbot_t('stopbot.flash_bot_enabled') : stopbot_t('stopbot.flash_bot_disabled'), 'ok');

redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);