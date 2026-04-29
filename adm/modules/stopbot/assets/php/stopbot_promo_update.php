<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_promo_update.php
 * ROLE: do=promo_update — обновление промокода.
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

$promoId = (int)($_POST['id'] ?? 0);
$contextBotId = (int)($_POST['bot_id'] ?? 0);
$keywords = trim((string)($_POST['keywords'] ?? ''));
$responseText = trim((string)($_POST['response_text'] ?? ''));
$isActive = ((int)($_POST['is_active'] ?? 0) === 1) ? 1 : 0;

$pdo = db();
$promo = stopbot_promo_get($pdo, $promoId);
if (!$promo) {
  flash(stopbot_t('stopbot.flash_promo_not_found'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
}

$botId = (int)($promo['bot_id'] ?? 0);
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

$redirectBotId = $botId;
if ($contextBotId > 0) {
  $redirectBotId = $contextBotId;
  if (!stopbot_promo_belongs_to_context($pdo, $promo, $contextBotId)) {
    flash(stopbot_t('stopbot.flash_promo_not_found'), 'danger', 1);
    redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $redirectBotId);
  }
  if (!stopbot_user_has_bot_access($pdo, $uid, $contextBotId, $roles)) {
    flash(stopbot_t('stopbot.flash_access_denied'), 'danger', 1);
    redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $redirectBotId);
  }
} elseif (!stopbot_user_has_bot_access($pdo, $uid, $botId, $roles)) {
  flash(stopbot_t('stopbot.flash_access_denied'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
}

if ($keywords === '') {
  flash(stopbot_t('stopbot.flash_promo_required'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $redirectBotId);
}

$pdo->prepare("\n  UPDATE " . STOPBOT_TABLE_PROMOS . "
  SET
    keywords = :keywords,
    response_text = :response_text,
    is_active = :is_active,
    updated_by = :updated_by
  WHERE id = :id
")->execute([
  ':keywords' => $keywords,
  ':response_text' => $responseText,
  ':is_active' => $isActive,
  ':updated_by' => $uid,
  ':id' => $promoId,
]);

$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

audit_log(STOPBOT_MODULE_CODE, 'promo_update', 'info', [
  'promo_id' => $promoId,
  'bot_id' => $botId,
  'context_bot_id' => $redirectBotId,
], STOPBOT_MODULE_CODE, null, $uid, $role);

flash(stopbot_t('stopbot.flash_promo_updated'), 'ok');

redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $redirectBotId);
