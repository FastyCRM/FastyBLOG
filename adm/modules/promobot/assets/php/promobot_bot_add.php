<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_bot_add.php
 * ROLE: do=bot_add — добавление бота.
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

$name = trim((string)($_POST['name'] ?? ''));
$platform = strtolower(trim((string)($_POST['platform'] ?? PROMOBOT_PLATFORM_TG)));
$enabled = ((int)($_POST['enabled'] ?? 0) === 1) ? 1 : 0;
$promoSourceBotId = (int)($_POST['promo_source_bot_id'] ?? 0);

if ($platform !== PROMOBOT_PLATFORM_MAX) $platform = PROMOBOT_PLATFORM_TG;

if ($name === '') {
  flash(promobot_t('promobot.flash_bot_name_required'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);
}

$pdo = db();
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if ($promoSourceBotId > 0) {
  $sourceBot = promobot_bot_get($pdo, $promoSourceBotId);
  if (!$sourceBot) {
    flash(promobot_t('promobot.flash_promo_source_not_found'), 'danger', 1);
    redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);
  }
}

$st = $pdo->prepare("\n  INSERT INTO " . PROMOBOT_TABLE_BOTS . "
    (name, platform, enabled, promo_source_bot_id, created_by, updated_by)
  VALUES
    (:name, :platform, :enabled, :promo_source_bot_id, :created_by, :updated_by)
");
$st->execute([
  ':name' => $name,
  ':platform' => $platform,
  ':enabled' => $enabled,
  ':promo_source_bot_id' => $promoSourceBotId > 0 ? $promoSourceBotId : 0,
  ':created_by' => $uid,
  ':updated_by' => $uid,
]);

$botId = (int)$pdo->lastInsertId();

// Автоматически назначаем создателя, если он не менеджер/админ.
if ($uid > 0 && !promobot_is_manage_role($roles)) {
  $pdo->prepare("\n    INSERT IGNORE INTO " . PROMOBOT_TABLE_USER_ACCESS . " (user_id, bot_id, created_by)
    VALUES (:uid, :bid, :created_by)
  ")->execute([
    ':uid' => $uid,
    ':bid' => $botId,
    ':created_by' => $uid,
  ]);
}

audit_log(PROMOBOT_MODULE_CODE, 'bot_add', 'info', [
  'bot_id' => $botId,
  'platform' => $platform,
  'promo_source_bot_id' => $promoSourceBotId > 0 ? $promoSourceBotId : 0,
], PROMOBOT_MODULE_CODE, null, $uid, $role);

flash(promobot_t('promobot.flash_bot_added'), 'ok');

redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&bot_id=' . $botId);
