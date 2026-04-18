<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_vk_wall_delete.php
 * ROLE: do=vk_wall_delete — удаление последних постов со стены VK.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_lib.php';

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
  @set_time_limit(0);

  $pdo = db();
  $settings = channel_bridge_settings_get($pdo);

  $postedToken = trim((string)($_POST['vk_group_token'] ?? ''));
  if ($postedToken !== '') {
    $settings['vk_group_token'] = $postedToken;
  }

  $postedVersion = trim((string)($_POST['vk_api_version'] ?? ''));
  if ($postedVersion !== '') {
    $settings['vk_api_version'] = $postedVersion;
  }

  $ownerRaw = trim((string)($_POST['vk_delete_owner_id'] ?? ''));
  if ($ownerRaw === '') {
    $ownerRaw = trim((string)($_POST['vk_owner_id'] ?? ($settings['vk_owner_id'] ?? '')));
  }
  if ($ownerRaw === '') {
    throw new InvalidArgumentException(channel_bridge_t('channel_bridge.error_vk_delete_owner_required'));
  }
  if (!preg_match('/^-?\d+$/', $ownerRaw)) {
    throw new InvalidArgumentException(channel_bridge_t('channel_bridge.error_vk_delete_owner_invalid'));
  }
  $ownerId = (int)$ownerRaw;
  if ($ownerId === 0) {
    throw new InvalidArgumentException(channel_bridge_t('channel_bridge.error_vk_delete_owner_invalid'));
  }

  $countRaw = trim((string)($_POST['vk_delete_last_count'] ?? ''));
  if ($countRaw === '') {
    throw new InvalidArgumentException(channel_bridge_t('channel_bridge.error_vk_delete_count_required'));
  }
  if (!preg_match('/^\d+$/', $countRaw)) {
    throw new InvalidArgumentException(channel_bridge_t('channel_bridge.error_vk_delete_count_invalid'));
  }
  $deleteCount = (int)$countRaw;
  if ($deleteCount <= 0) {
    throw new InvalidArgumentException(channel_bridge_t('channel_bridge.error_vk_delete_count_invalid'));
  }

  $result = channel_bridge_vk_wall_delete_latest($settings, $ownerId, $deleteCount, true);
  if (($result['ok'] ?? false) !== true) {
    throw new RuntimeException(trim((string)($result['error'] ?? 'vk_wall_delete_failed')));
  }

  $requested = (int)($result['requested'] ?? $deleteCount);
  $scanned = (int)($result['scanned'] ?? 0);
  $matched = (int)($result['matched'] ?? 0);
  $deleted = (int)($result['deleted'] ?? 0);
  $failed = (int)($result['failed'] ?? 0);
  $pinned = (int)($result['pinned_skipped'] ?? 0);
  $failedPosts = isset($result['failed_posts']) && is_array($result['failed_posts']) ? array_slice($result['failed_posts'], 0, 10) : [];

  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'vk_wall_delete', $failed > 0 ? 'warn' : 'info', [
    'owner_id' => $ownerId,
    'requested' => $requested,
    'scanned' => $scanned,
    'matched' => $matched,
    'deleted' => $deleted,
    'failed' => $failed,
    'pinned_skipped' => $pinned,
    'stop_reason' => (string)($result['stop_reason'] ?? ''),
    'failed_posts' => $failedPosts,
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  if ($matched <= 0) {
    flash(channel_bridge_t('channel_bridge.flash_vk_delete_empty', [
      'requested' => (string)$requested,
      'scanned' => (string)$scanned,
      'pinned' => (string)$pinned,
    ]), 'warn');
  } elseif ($failed > 0) {
    flash(channel_bridge_t('channel_bridge.flash_vk_delete_partial', [
      'requested' => (string)$requested,
      'deleted' => (string)$deleted,
      'matched' => (string)$matched,
      'failed' => (string)$failed,
      'scanned' => (string)$scanned,
    ]), 'warn');
  } else {
    flash(channel_bridge_t('channel_bridge.flash_vk_delete_done', [
      'requested' => (string)$requested,
      'deleted' => (string)$deleted,
      'matched' => (string)$matched,
      'scanned' => (string)$scanned,
      'pinned' => (string)$pinned,
    ]), 'ok');
  }
} catch (Throwable $e) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'vk_wall_delete', 'error', [
    'error' => $e->getMessage(),
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  flash(channel_bridge_t('channel_bridge.flash_vk_delete_error', ['error' => $e->getMessage()]), 'danger', 1);
}

redirect_return($returnUrl);
