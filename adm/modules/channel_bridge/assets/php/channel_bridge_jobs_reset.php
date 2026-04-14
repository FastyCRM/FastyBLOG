<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_jobs_reset.php
 * ROLE: do=jobs_reset — manual queue reset without worker execution.
 * DEPENDENCIES:
 *  - /adm/modules/channel_bridge/settings.php
 *  - /adm/modules/channel_bridge/assets/php/channel_bridge_lib.php
 *  - /adm/modules/channel_bridge/assets/php/channel_bridge_jobs.php
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_lib.php';
require_once __DIR__ . '/channel_bridge_jobs.php';

acl_guard(module_allowed_roles(CHANNEL_BRIDGE_MODULE_CODE));

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!channel_bridge_can_manage($roles)) {
  flash(channel_bridge_t('channel_bridge.error_forbidden'), 'danger', 1);
  redirect_return('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
  http_405('Method Not Allowed');
}

csrf_check((string)($_POST['csrf'] ?? ''));

$returnUrl = '/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE;

try {
  $pdo = db();
  $result = channel_bridge_jobs_manual_reset_pending($pdo, 'manual_reset_by_admin');
  if (($result['ok'] ?? false) !== true) {
    throw new RuntimeException(trim((string)($result['reason'] ?? 'jobs_reset_failed')));
  }

  $updated = (int)($result['updated'] ?? 0);
  $selected = (int)($result['selected'] ?? 0);
  $released = (int)($result['released_albums'] ?? 0);

  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'jobs_reset', 'warn', [
    'selected' => $selected,
    'updated' => $updated,
    'released_albums' => $released,
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  if ($updated > 0) {
    flash(channel_bridge_t('channel_bridge.flash_jobs_reset_done', [
      'count' => (string)$updated,
      'released' => (string)$released,
    ]), 'ok');
  } else {
    flash(channel_bridge_t('channel_bridge.flash_jobs_reset_empty'), 'warn');
  }
} catch (Throwable $e) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'jobs_reset', 'error', [
    'error' => $e->getMessage(),
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  flash(channel_bridge_t('channel_bridge.flash_jobs_reset_error', ['error' => $e->getMessage()]), 'danger', 1);
}

redirect_return($returnUrl);

