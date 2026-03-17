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

  $bindingId = (int)($_POST['binding_id'] ?? 0);
  ymlb_stage_log('channel_code', 'info', [
    'stage' => 'request',
    'binding_id' => $bindingId,
    'channel_username' => ltrim(trim((string)($_POST['channel_username'] ?? '')), '@'),
    'actor_user_id' => $uid,
  ]);
  if (!$canManage) {
    if ($bindingId > 0) {
      if (!ymlb_binding_is_owned_by_crm_user($pdo, $bindingId, $uid)) {
        json_err('forbidden', 403);
      }
    } else {
      $owned = ymlb_binding_ids_by_crm_user($pdo, $uid);
      $bindingId = (int)($owned[0] ?? 0);
      if ($bindingId <= 0) {
        json_err('forbidden', 403);
      }
      $_POST['binding_id'] = $bindingId;
    }
  }

  $code = ymlb_channel_generate_code($pdo, $_POST);
  ymlb_stage_log('channel_code', 'info', [
    'stage' => 'generated',
    'binding_id' => $bindingId,
    'channel_id' => (int)($code['channel_id'] ?? 0),
    'code' => (string)($code['code'] ?? ''),
  ]);
  json_ok([
    'generated' => $code,
    'channels' => ymlb_channels_list_for_actor($pdo, $uid, $canManage),
  ]);
} catch (Throwable $e) {
  ymlb_stage_log('channel_code', 'error', [
    'stage' => 'error',
    'error' => $e->getMessage(),
    'binding_id' => (int)($_POST['binding_id'] ?? 0),
  ]);
  json_err($e->getMessage(), 400);
}
