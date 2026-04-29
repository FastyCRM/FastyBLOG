<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_bot_update.php
 * ROLE: do=bot_update — обновление бота.
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

$name = trim((string)($_POST['name'] ?? ''));
$enabled = ((int)($_POST['enabled'] ?? 0) === 1) ? 1 : 0;
$promoSourceBotId = (int)($_POST['promo_source_bot_id'] ?? 0);

if ($name === '') {
  flash(stopbot_t('stopbot.flash_bot_name_required'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);
}

if ($promoSourceBotId === $botId) {
  $promoSourceBotId = 0;
}

if ($promoSourceBotId > 0) {
  $sourceBot = stopbot_bot_get($pdo, $promoSourceBotId);
  if (!$sourceBot) {
    flash(stopbot_t('stopbot.flash_promo_source_not_found'), 'danger', 1);
    redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);
  }

  $sourceOwnerBotId = stopbot_bot_promo_owner_id($pdo, $promoSourceBotId);
  if ($sourceOwnerBotId === $botId) {
    flash(stopbot_t('stopbot.flash_promo_source_cycle'), 'danger', 1);
    redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);
  }
}

$platform = (string)($bot['platform'] ?? STOPBOT_PLATFORM_TG);

$fields = [
  'name' => $name,
  'enabled' => $enabled,
  'promo_source_bot_id' => $promoSourceBotId > 0 ? $promoSourceBotId : 0,
];

if ($platform === STOPBOT_PLATFORM_TG) {
  $fields['bot_token'] = trim((string)($_POST['bot_token'] ?? ''));
  $fields['webhook_secret'] = trim((string)($_POST['webhook_secret'] ?? ''));
  $fields['webhook_url'] = stopbot_bot_webhook_url($botId, $platform, true);
} else {
  $fields['max_api_key'] = trim((string)($_POST['max_api_key'] ?? ''));
  $fields['max_base_url'] = trim((string)($_POST['max_base_url'] ?? 'https://platform-api.max.ru'));
  $fields['max_send_path'] = trim((string)($_POST['max_send_path'] ?? '/messages'));
  if ($fields['max_base_url'] === '') $fields['max_base_url'] = 'https://platform-api.max.ru';
  if ($fields['max_send_path'] === '') $fields['max_send_path'] = '/messages';
  $fields['webhook_url'] = stopbot_bot_webhook_url($botId, $platform, true);
}

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

$st = $pdo->prepare("\n  UPDATE " . STOPBOT_TABLE_BOTS . "
  SET
    name = :name,
    enabled = :enabled,
    promo_source_bot_id = :promo_source_bot_id,
    bot_token = :bot_token,
    webhook_secret = :webhook_secret,
    webhook_url = :webhook_url,
    max_api_key = :max_api_key,
    max_base_url = :max_base_url,
    max_send_path = :max_send_path,
    updated_by = :updated_by
  WHERE id = :id
");

$st->execute([
  ':name' => $fields['name'],
  ':enabled' => $fields['enabled'],
  ':promo_source_bot_id' => (int)($fields['promo_source_bot_id'] ?? 0),
  ':bot_token' => (string)($fields['bot_token'] ?? ''),
  ':webhook_secret' => (string)($fields['webhook_secret'] ?? ''),
  ':webhook_url' => (string)($fields['webhook_url'] ?? ''),
  ':max_api_key' => (string)($fields['max_api_key'] ?? ''),
  ':max_base_url' => (string)($fields['max_base_url'] ?? 'https://platform-api.max.ru'),
  ':max_send_path' => (string)($fields['max_send_path'] ?? '/messages'),
  ':updated_by' => $uid,
  ':id' => $botId,
]);

audit_log(STOPBOT_MODULE_CODE, 'bot_update', 'info', [
  'bot_id' => $botId,
  'platform' => $platform,
  'promo_source_bot_id' => (int)($fields['promo_source_bot_id'] ?? 0),
], STOPBOT_MODULE_CODE, null, $uid, $role);

flash(stopbot_t('stopbot.flash_bot_updated'), 'ok');

redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);
