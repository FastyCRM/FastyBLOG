<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_settings_toggle_log.php
 * ROLE: do=settings_toggle_log — переключение логирования.
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

$pdo = db();
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$role = function_exists('auth_user_role') ? (string)auth_user_role() : '';

$res = stopbot_settings_toggle_log($pdo);
$auditStatus = ($res['ok'] ?? false) ? 'info' : 'warn';

$auditPayload = [
  'log_enabled' => (int)($res['log_enabled'] ?? 0),
];

audit_log(STOPBOT_MODULE_CODE, 'settings_toggle_log', $auditStatus, $auditPayload, STOPBOT_MODULE_CODE, null, $uid, $role);

flash(
  ((int)($res['log_enabled'] ?? 0) === 1)
    ? stopbot_t('stopbot.flash_log_enabled')
    : stopbot_t('stopbot.flash_log_disabled'),
  'ok'
);

redirect_return('/adm/index.php?m=' . STOPBOT_MODULE_CODE);