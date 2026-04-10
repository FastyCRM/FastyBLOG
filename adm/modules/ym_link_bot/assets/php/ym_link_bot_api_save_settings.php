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
if ($csrf === '') {
  json_err('csrf_required', 403);
}
csrf_check($csrf);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!ymlb_is_manage_role($roles)) {
  json_err('forbidden', 403);
}

try {
  $pdo = db();
  ymlb_sync_module_roles($pdo);
  $settings = ymlb_settings_save($pdo, $_POST);

  audit_log(ymlb_module_code(), 'settings_update', 'info', [
    'enabled' => (int)$settings['enabled'],
    'chat_mode_enabled' => (int)($settings['chat_mode_enabled'] ?? 0),
    'chat_bot_separate' => (int)($settings['chat_bot_separate'] ?? 0),
    'max_enabled' => (int)($settings['max_enabled'] ?? 0),
    'bot_username' => (string)($settings['bot_username'] ?? ''),
    'chat_bot_username' => (string)($settings['chat_bot_username'] ?? ''),
    'listener_path' => (string)($settings['listener_path'] ?? ''),
    'chat_listener_path' => (string)($settings['chat_listener_path'] ?? ''),
    'max_listener_path' => (string)($settings['max_listener_path'] ?? ''),
  ], null, null, $uid, $roles[0] ?? null);

  json_ok([
    'settings' => $settings,
    'listener_url' => ymlb_listener_url($settings),
    'max_listener_url' => ymlb_max_listener_url($settings),
  ]);
} catch (Throwable $e) {
  audit_log(ymlb_module_code(), 'settings_update', 'error', [
    'error' => $e->getMessage(),
  ], null, null, $uid, $roles[0] ?? null);
  json_err($e->getMessage(), 400);
}
