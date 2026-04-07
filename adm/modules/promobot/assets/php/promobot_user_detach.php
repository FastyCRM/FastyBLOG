<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_user_detach.php
 * ROLE: do=user_detach — снятие пользователя с бота.
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

$userId = (int)($_POST['user_id'] ?? 0);
$botId = (int)($_POST['bot_id'] ?? 0);

if ($userId <= 0 || $botId <= 0) {
  flash(promobot_t('promobot.flash_user_detach_fail'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);
}

$pdo = db();
$pdo->prepare("DELETE FROM " . PROMOBOT_TABLE_USER_ACCESS . " WHERE user_id = :uid AND bot_id = :bid")
  ->execute([':uid' => $userId, ':bid' => $botId]);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

audit_log(PROMOBOT_MODULE_CODE, 'user_detach', 'info', [
  'user_id' => $userId,
  'bot_id' => $botId,
], PROMOBOT_MODULE_CODE, null, $uid, $role);

flash(promobot_t('promobot.flash_user_detached'), 'ok');

redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);