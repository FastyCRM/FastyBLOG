<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_settings_update.php
 * ROLE: do=settings_update — сохранение настроек интеграций модуля channel_bridge.
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

try {
  $pdo = db();

  $ruleDomains = isset($_POST['rule_domain_root']) && is_array($_POST['rule_domain_root']) ? (array)$_POST['rule_domain_root'] : [];
  $ruleEnabled = isset($_POST['rule_enabled']) && is_array($_POST['rule_enabled']) ? (array)$_POST['rule_enabled'] : [];
  $ruleSort = isset($_POST['rule_sort']) && is_array($_POST['rule_sort']) ? (array)$_POST['rule_sort'] : [];

  $ruleRows = [];
  $rowsCount = max(count($ruleDomains), count($ruleEnabled), count($ruleSort));
  for ($i = 0; $i < $rowsCount; $i++) {
    $ruleRows[] = [
      'domain_root' => (string)($ruleDomains[$i] ?? ''),
      'suffix_text' => 'Репост',
      'enabled' => (int)($ruleEnabled[$i] ?? 0),
      'sort' => (int)($ruleSort[$i] ?? 100),
    ];
  }

  $saved = channel_bridge_settings_save($pdo, [
    'enabled' => (int)($_POST['enabled'] ?? 0),

    'tg_enabled' => (int)($_POST['tg_enabled'] ?? 0),
    'tg_bot_token' => (string)($_POST['tg_bot_token'] ?? ''),
    'tg_webhook_secret' => (string)($_POST['tg_webhook_secret'] ?? ''),
    'tg_parse_mode' => (string)($_POST['tg_parse_mode'] ?? 'HTML'),

    'vk_enabled' => (int)($_POST['vk_enabled'] ?? 0),
    'vk_group_token' => (string)($_POST['vk_group_token'] ?? ''),
    'vk_owner_id' => (string)($_POST['vk_owner_id'] ?? ''),
    'vk_api_version' => (string)($_POST['vk_api_version'] ?? '5.199'),

    'max_enabled' => (int)($_POST['max_enabled'] ?? 0),
    'max_api_key' => (string)($_POST['max_api_key'] ?? ''),
    'max_base_url' => (string)($_POST['max_base_url'] ?? ''),
    'max_send_path' => (string)($_POST['max_send_path'] ?? ''),

    'link_suffix_rules' => $ruleRows,
  ]);

  $applyWebhook = ((int)($_POST['apply_webhook'] ?? 0) === 1);
  $flashType = 'ok';
  $flashText = channel_bridge_t('channel_bridge.flash_settings_saved');

  if ($applyWebhook) {
    $tgEnabled = ((int)($saved['tg_enabled'] ?? 0) === 1);
    $tgToken = trim((string)($saved['tg_bot_token'] ?? ''));

    if ($tgEnabled && $tgToken !== '') {
      $apply = channel_bridge_apply_tg_webhook($saved);
      if (($apply['ok'] ?? false) === true) {
        audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'settings_update', 'info', [
          'status' => 'webhook_applied',
          'result' => 'ok',
        ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));
        $flashText = channel_bridge_t('channel_bridge.flash_settings_saved_webhook_applied');
      } else {
        $err = trim((string)($apply['description'] ?? ($apply['error'] ?? 'UNKNOWN')));
        audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'settings_update', 'warn', [
          'status' => 'webhook_applied',
          'result' => 'fail',
          'error' => $err,
        ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));
        $flashType = 'warn';
        $flashText = channel_bridge_t('channel_bridge.flash_settings_saved_webhook_failed', ['error' => $err]);
      }
    } else {
      audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'settings_update', 'warn', [
        'status' => 'webhook_applied',
        'result' => 'skipped',
        'reason' => 'tg_disabled_or_token_empty',
      ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));
      $flashType = 'warn';
      $flashText = channel_bridge_t('channel_bridge.flash_settings_saved_webhook_skipped');
    }
  }

  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'settings_update', 'info', [
    'status' => 'ok',
    'apply_webhook' => $applyWebhook ? 1 : 0,
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  flash($flashText, $flashType);
} catch (Throwable $e) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'settings_update', 'error', [
    'error' => $e->getMessage(),
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  flash(channel_bridge_t('channel_bridge.flash_settings_save_error', ['error' => $e->getMessage()]), 'danger', 1);
}

redirect_return('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE);
