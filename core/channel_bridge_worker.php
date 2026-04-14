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

if (!function_exists('module_is_enabled') || !module_is_enabled(CHANNEL_BRIDGE_MODULE_CODE)) {
  json_err('Module disabled', 404);
}

require ROOT_PATH . '/adm/modules/channel_bridge/assets/php/channel_bridge_worker.php';

