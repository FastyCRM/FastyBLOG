<?php
/**
 * FILE: /core/telegram_webhook_channel_bridge.php
 * ROLE: Внешний webhook endpoint для Telegram-бота модуля channel_bridge.
 * FLOW:
 *  - поднимает bootstrap ядра;
 *  - проверяет, что модуль включён;
 *  - передаёт обработку в do=api_tg_webhook модуля.
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

require ROOT_PATH . '/adm/modules/channel_bridge/assets/php/channel_bridge_api_tg_webhook.php';

