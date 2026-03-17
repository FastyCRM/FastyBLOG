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
$canManage = ymlb_is_manage_role($roles);

try {
  $pdo = db();
  ymlb_sync_module_roles($pdo);

  $id = (int)($_POST['id'] ?? 0);
  $bindingId = (int)($_POST['binding_id'] ?? 0);

  if (!$canManage) {
    if ($id > 0 && !ymlb_site_is_owned_by_crm_user($pdo, $id, $uid)) {
      json_err('forbidden', 403);
    }
    if ($bindingId <= 0 || !ymlb_binding_is_owned_by_crm_user($pdo, $bindingId, $uid)) {
      json_err('forbidden', 403);
    }
  }

  $id = ymlb_site_save($pdo, $_POST);
  json_ok([
    'id' => $id,
    'sites' => ymlb_sites_list_for_actor($pdo, $uid, $canManage),
  ]);
} catch (Throwable $e) {
  json_err($e->getMessage(), 400);
}
