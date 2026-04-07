<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_channel_toggle.php
 * ROLE: do=channel_toggle — переключение активности чата.
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

$channelId = (int)($_POST['id'] ?? 0);
$botId = (int)($_POST['bot_id'] ?? 0);

if ($channelId <= 0 || $botId <= 0) {
  flash(promobot_t('promobot.flash_channel_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);
}

$pdo = db();
$st = $pdo->prepare("SELECT * FROM " . PROMOBOT_TABLE_CHANNELS . " WHERE id = :id AND bot_id = :bid LIMIT 1");
$st->execute([':id' => $channelId, ':bid' => $botId]);
$channel = $st->fetch(PDO::FETCH_ASSOC);
if (!is_array($channel)) {
  flash(promobot_t('promobot.flash_channel_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);
}

$enabled = ((int)($channel['is_active'] ?? 0) === 1) ? 0 : 1;

$pdo->prepare("\n  UPDATE " . PROMOBOT_TABLE_CHANNELS . "
  SET is_active = :enabled
  WHERE id = :id
")->execute([
  ':enabled' => $enabled,
  ':id' => $channelId,
]);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

audit_log(PROMOBOT_MODULE_CODE, 'channel_toggle', 'info', [
  'channel_id' => $channelId,
  'enabled' => $enabled,
], PROMOBOT_MODULE_CODE, null, $uid, $role);

flash($enabled ? promobot_t('promobot.flash_channel_enabled') : promobot_t('promobot.flash_channel_disabled'), 'ok');

redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);