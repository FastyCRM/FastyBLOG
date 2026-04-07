<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_promo_toggle.php
 * ROLE: do=promo_toggle — включение/выключение промокода.
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

$promoId = (int)($_POST['id'] ?? 0);
$pdo = db();
$promo = promobot_promo_get($pdo, $promoId);
if (!$promo) {
  flash(promobot_t('promobot.flash_promo_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);
}

$botId = (int)($promo['bot_id'] ?? 0);
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

if (!promobot_user_has_bot_access($pdo, $uid, $botId, $roles)) {
  flash(promobot_t('promobot.flash_access_denied'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);
}

$enabled = ((int)($promo['is_active'] ?? 0) === 1) ? 0 : 1;

$pdo->prepare("\n  UPDATE " . PROMOBOT_TABLE_PROMOS . "
  SET is_active = :enabled
  WHERE id = :id
")->execute([
  ':enabled' => $enabled,
  ':id' => $promoId,
]);

$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

audit_log(PROMOBOT_MODULE_CODE, 'promo_toggle', 'info', [
  'promo_id' => $promoId,
  'bot_id' => $botId,
  'enabled' => $enabled,
], PROMOBOT_MODULE_CODE, null, $uid, $role);

flash($enabled ? promobot_t('promobot.flash_promo_enabled') : promobot_t('promobot.flash_promo_disabled'), 'ok');

redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);