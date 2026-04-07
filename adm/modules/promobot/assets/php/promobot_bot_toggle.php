<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_bot_toggle.php
 * ROLE: do=bot_toggle — включение/выключение бота.
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

$enabled = ((int)($bot['enabled'] ?? 0) === 1) ? 0 : 1;

$pdo->prepare("\n  UPDATE " . PROMOBOT_TABLE_BOTS . "
  SET enabled = :enabled
  WHERE id = :id
")->execute([
  ':enabled' => $enabled,
  ':id' => $botId,
]);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

audit_log(PROMOBOT_MODULE_CODE, 'bot_toggle', 'info', [
  'bot_id' => $botId,
  'enabled' => $enabled,
], PROMOBOT_MODULE_CODE, null, $uid, $role);

flash($enabled ? promobot_t('promobot.flash_bot_enabled') : promobot_t('promobot.flash_bot_disabled'), 'ok');

redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);