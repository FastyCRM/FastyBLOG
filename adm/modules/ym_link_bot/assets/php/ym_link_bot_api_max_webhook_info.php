<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

$moduleDir = basename(dirname(__DIR__, 2));
$moduleRoot = ROOT_PATH . '/adm/modules/' . $moduleDir;
require_once $moduleRoot . '/settings.php';
require_once $moduleRoot . '/assets/php/ym_link_bot_lib.php';

acl_guard(module_allowed_roles(ymlb_module_code()));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
  http_405('Method Not Allowed');
}

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!ymlb_is_manage_role($roles)) json_err('forbidden', 403);

try {
  $pdo = db();
  ymlb_sync_module_roles($pdo);
  $settings = ymlb_settings_get($pdo);
  $remote = ymlb_max_webhook_info($settings);
  if (($remote['ok'] ?? false) !== true) {
    audit_log(ymlb_module_code(), 'max_webhook_info', 'warn', [
      'listener_url' => ymlb_max_listener_url($settings),
      'error' => (string)($remote['error'] ?? ''),
      'recreate_reason' => (string)($remote['recreate_reason'] ?? ''),
    ]);
  }
  json_ok([
    'listener_url' => ymlb_max_listener_url($settings),
    'remote' => $remote,
  ]);
} catch (Throwable $e) {
  json_err($e->getMessage(), 400);
}
