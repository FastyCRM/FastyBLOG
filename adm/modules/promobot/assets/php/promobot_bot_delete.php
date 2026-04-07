<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_bot_delete.php
 * ROLE: do=bot_delete — удаление бота и связей.
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

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

try {
  $pdo->beginTransaction();

  $pdo->prepare("DELETE FROM " . PROMOBOT_TABLE_PROMOS . " WHERE bot_id = :bid")->execute([':bid' => $botId]);
  $pdo->prepare("DELETE FROM " . PROMOBOT_TABLE_CHANNELS . " WHERE bot_id = :bid")->execute([':bid' => $botId]);
  $pdo->prepare("DELETE FROM " . PROMOBOT_TABLE_USER_ACCESS . " WHERE bot_id = :bid")->execute([':bid' => $botId]);
  $pdo->prepare("DELETE FROM " . PROMOBOT_TABLE_BIND_TOKENS . " WHERE bot_id = :bid")->execute([':bid' => $botId]);
  $pdo->prepare("DELETE FROM " . PROMOBOT_TABLE_LOGS . " WHERE bot_id = :bid")->execute([':bid' => $botId]);
  $pdo->prepare("DELETE FROM " . PROMOBOT_TABLE_BOTS . " WHERE id = :id LIMIT 1")->execute([':id' => $botId]);

  $pdo->commit();

  audit_log(PROMOBOT_MODULE_CODE, 'bot_delete', 'info', [
    'bot_id' => $botId,
  ], PROMOBOT_MODULE_CODE, null, $uid, $role);

  flash(promobot_t('promobot.flash_bot_deleted'), 'ok');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();

  audit_log(PROMOBOT_MODULE_CODE, 'bot_delete', 'error', [
    'bot_id' => $botId,
    'error' => $e->getMessage(),
  ], PROMOBOT_MODULE_CODE, null, $uid, $role);

  flash(promobot_t('promobot.flash_bot_delete_error', ['error' => $e->getMessage()]), 'danger', 1);
}

redirect_return('/adm/index.php?m=' . PROMOBOT_MODULE_CODE);