<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_rule_delete.php
 * ROLE: do=rule_delete — удаление записи из слов/корней/доменов.
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

$botId = (int)($_POST['bot_id'] ?? 0);
$kind = trim((string)($_POST['kind'] ?? ''));
$value = trim((string)($_POST['value'] ?? ''));

if ($botId <= 0 || $kind === '' || $value === '') {
  flash(stopbot_t('stopbot.flash_rule_delete_fail'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);
}

$pdo = db();
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!stopbot_user_has_bot_access($pdo, $uid, $botId, $roles)) {
  flash(stopbot_t('stopbot.flash_access_denied'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);
}

$res = stopbot_rule_delete($pdo, $botId, $kind, $value);
if (($res['ok'] ?? false) !== true) {
  flash(stopbot_t('stopbot.flash_rule_delete_fail'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);
}

$deleted = ((int)($res['deleted'] ?? 0) === 1);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';
audit_log(STOPBOT_MODULE_CODE, 'rule_delete', $deleted ? 'info' : 'warn', [
  'bot_id' => $botId,
  'kind' => $kind,
  'value' => $value,
  'token_hits' => (int)($res['token_hits'] ?? 0),
  'promos_changed' => (int)($res['promos_changed'] ?? 0),
  'promos_removed' => (int)($res['promos_removed'] ?? 0),
  'settings_words_changed' => (int)($res['settings_words_changed'] ?? 0),
  'settings_domains_changed' => (int)($res['settings_domains_changed'] ?? 0),
], STOPBOT_MODULE_CODE, null, $uid, $role);

if ($deleted) {
  flash(stopbot_t('stopbot.flash_rule_deleted'), 'ok');
} else {
  flash(stopbot_t('stopbot.flash_rule_not_found'), 'warn');
}

redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&bot_id=' . $botId);
