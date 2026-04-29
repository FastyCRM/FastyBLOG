<?php
/**
 * FILE: /core/channel_bridge_worker.php
 * ROLE: Internal async entrypoint for channel_bridge DB worker loop.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__));
}

require_once ROOT_PATH . '/core/bootstrap.php';
require_once ROOT_PATH . '/adm/modules/channel_bridge/settings.php';

if (function_exists('audit_log')) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'worker_core_entry', 'info', [
    'trace' => trim((string)($_REQUEST['trace'] ?? '')),
    'method' => strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
    'uri' => trim((string)($_SERVER['REQUEST_URI'] ?? '')),
    'remote_addr' => trim((string)($_SERVER['REMOTE_ADDR'] ?? '')),
    'user_agent' => trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
    'token_present' => (trim((string)($_REQUEST['token'] ?? '')) !== '' ? 1 : 0),
  ]);
}

if (!function_exists('module_is_enabled') || !module_is_enabled(CHANNEL_BRIDGE_MODULE_CODE)) {
  if (function_exists('audit_log')) {
    audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'worker_core_entry', 'warn', [
      'trace' => trim((string)($_REQUEST['trace'] ?? '')),
      'reason' => 'module_disabled',
    ]);
  }
  json_err('Module disabled', 404);
}

require ROOT_PATH . '/adm/modules/channel_bridge/assets/php/channel_bridge_worker.php';
