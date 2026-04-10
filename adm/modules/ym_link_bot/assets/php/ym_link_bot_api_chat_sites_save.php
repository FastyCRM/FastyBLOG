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

  $chatId = (int)($_POST['chat_id'] ?? 0);
  if ($chatId <= 0) {
    json_err('target_id_required', 422);
  }

  if (!$canManage && !ymlb_channel_is_owned_by_crm_user($pdo, $chatId, $uid)) {
    json_err('forbidden', 403);
  }

  $chat = ymlb_channel_get($pdo, $chatId);
  if (!$chat) {
    json_err('target_not_found', 404);
  }

  $siteIds = ymlb_int_ids($_POST['site_ids'] ?? []);
  ymlb_channel_sites_sync($pdo, $chatId, $siteIds, $canManage);

  audit_log(ymlb_module_code(), 'chat_sites_save', 'info', [
    'target_id' => $chatId,
    'chat_kind' => (string)($chat['chat_kind'] ?? 'channel'),
    'binding_id' => (int)($chat['binding_id'] ?? 0),
    'site_ids' => $siteIds,
    'allow_any_sites' => ($canManage ? 1 : 0),
  ], null, null, $uid, $roles[0] ?? null);

  json_ok([
    'target_id' => $chatId,
    'site_ids' => $siteIds,
    'channels' => ymlb_channels_list_for_actor($pdo, $uid, $canManage),
    'chats' => ymlb_chats_list_for_actor($pdo, $uid, $canManage),
  ]);
} catch (Throwable $e) {
  json_err($e->getMessage(), 400);
}
