<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_api_tg_sweep.php
 * ROLE: Internal async sweep/retry entrypoint for Telegram media_group recovery.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_lib.php';

try {
  if (!channel_bridge_is_internal_key_request() && !channel_bridge_is_tg_finalize_request()) {
    channel_bridge_require_manage_or_internal();
  }

  $pdo = db();
  $settings = channel_bridge_settings_get($pdo);
  $result = channel_bridge_tg_state_sweep_run($pdo, $settings);
  if (($result['ok'] ?? false) !== true) {
    json_err((string)($result['message'] ?? $result['reason'] ?? 'Sweep failed'), 500, $result);
  }

  json_ok($result);
} catch (Throwable $e) {
  if (function_exists('audit_log')) {
    audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'media_group_sweep', 'error', [
      'reason' => 'exception',
      'error' => $e->getMessage(),
      'error_file' => $e->getFile(),
      'error_line' => (int)$e->getLine(),
    ]);
  }
  json_err('Internal error', 500);
}
