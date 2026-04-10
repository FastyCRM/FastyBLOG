<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

$moduleDir = basename(dirname(__DIR__, 2));
$moduleRoot = ROOT_PATH . '/adm/modules/' . $moduleDir;
require_once $moduleRoot . '/settings.php';
require_once $moduleRoot . '/assets/php/ym_link_bot_lib.php';

acl_guard(module_allowed_roles(ymlb_module_code()));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_405('Method Not Allowed');
}

$csrf = (string)($_POST['csrf'] ?? '');
if ($csrf === '') json_err('csrf_required', 403);
csrf_check($csrf);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!ymlb_is_manage_role($roles)) json_err('forbidden', 403);

try {
  $pdo = db();
  ymlb_sync_module_roles($pdo);
  $settings = ymlb_settings_get($pdo);
  $apply = ymlb_max_webhook_set($settings);
  $info = ymlb_max_webhook_info($settings);

  if (($apply['ok'] ?? false) !== true) {
    audit_log(ymlb_module_code(), 'max_webhook_set', 'warn', [
      'listener_url' => ymlb_max_listener_url($settings),
      'error' => (string)($apply['error'] ?? ''),
      'http_code' => (int)($apply['http_code'] ?? 0),
      'recreate_reason' => (string)($apply['recreate_reason'] ?? ''),
      'details' => (array)($apply['details'] ?? []),
    ]);
  } elseif (($info['ok'] ?? false) !== true) {
    audit_log(ymlb_module_code(), 'max_webhook_set', 'warn', [
      'listener_url' => ymlb_max_listener_url($settings),
      'error' => (string)($info['error'] ?? ''),
      'recreate_reason' => (string)($info['recreate_reason'] ?? ''),
    ]);
  } else {
    audit_log(ymlb_module_code(), 'max_webhook_set', 'info', [
      'listener_url' => ymlb_max_listener_url($settings),
      'created' => (int)($apply['created'] ?? 0),
      'removed' => (int)($apply['removed'] ?? 0),
      'recreated' => (int)($apply['recreated'] ?? 0),
    ]);
  }

  json_ok([
    'listener_url' => ymlb_max_listener_url($settings),
    'apply' => $apply,
    'remote' => $info,
  ]);
} catch (Throwable $e) {
  json_err($e->getMessage(), 400);
}
