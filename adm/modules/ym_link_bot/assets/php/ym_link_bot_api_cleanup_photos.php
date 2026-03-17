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

$from = trim((string)($_POST['date_from'] ?? ''));
$to = trim((string)($_POST['date_to'] ?? ''));

try {
  $pdo = db();
  ymlb_sync_module_roles($pdo);
  $result = ymlb_cleanup_photos($pdo, $from, $to);
  json_ok(['cleanup' => $result]);
} catch (Throwable $e) {
  json_err($e->getMessage(), 400);
}
