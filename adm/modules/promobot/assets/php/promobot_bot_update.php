<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_bot_update.php
 * ROLE: do=bot_update — обновление бота.
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

$name = trim((string)($_POST['name'] ?? ''));
$enabled = ((int)($_POST['enabled'] ?? 0) === 1) ? 1 : 0;

if ($name === '') {
  flash(promobot_t('promobot.flash_bot_name_required'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);
}

$platform = (string)($bot['platform'] ?? PROMOBOT_PLATFORM_TG);

$fields = [
  'name' => $name,
  'enabled' => $enabled,
];

if ($platform === PROMOBOT_PLATFORM_TG) {
  $fields['bot_token'] = trim((string)($_POST['bot_token'] ?? ''));
  $fields['webhook_secret'] = trim((string)($_POST['webhook_secret'] ?? ''));
  $fields['webhook_url'] = promobot_bot_webhook_url($botId, $platform, true);
} else {
  $fields['max_api_key'] = trim((string)($_POST['max_api_key'] ?? ''));
  $fields['max_base_url'] = trim((string)($_POST['max_base_url'] ?? 'https://platform-api.max.ru'));
  $fields['max_send_path'] = trim((string)($_POST['max_send_path'] ?? '/messages'));
  if ($fields['max_base_url'] === '') $fields['max_base_url'] = 'https://platform-api.max.ru';
  if ($fields['max_send_path'] === '') $fields['max_send_path'] = '/messages';
  $fields['webhook_url'] = promobot_bot_webhook_url($botId, $platform, true);
}

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

$st = $pdo->prepare("\n  UPDATE " . PROMOBOT_TABLE_BOTS . "
  SET
    name = :name,
    enabled = :enabled,
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
  ':bot_token' => (string)($fields['bot_token'] ?? ''),
  ':webhook_secret' => (string)($fields['webhook_secret'] ?? ''),
  ':webhook_url' => (string)($fields['webhook_url'] ?? ''),
  ':max_api_key' => (string)($fields['max_api_key'] ?? ''),
  ':max_base_url' => (string)($fields['max_base_url'] ?? 'https://platform-api.max.ru'),
  ':max_send_path' => (string)($fields['max_send_path'] ?? '/messages'),
  ':updated_by' => $uid,
  ':id' => $botId,
]);

audit_log(PROMOBOT_MODULE_CODE, 'bot_update', 'info', [
  'bot_id' => $botId,
  'platform' => $platform,
], PROMOBOT_MODULE_CODE, null, $uid, $role);

flash(promobot_t('promobot.flash_bot_updated'), 'ok');

redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);