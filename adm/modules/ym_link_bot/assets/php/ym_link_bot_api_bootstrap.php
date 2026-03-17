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

$pdo = db();
ymlb_ensure_schema($pdo);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$canManage = ymlb_is_manage_role($roles);

json_ok([
  'bootstrap' => ymlb_bootstrap_payload($pdo, $uid, $canManage),
  'can_manage' => $canManage,
  'can_manage_data' => true,
]);
