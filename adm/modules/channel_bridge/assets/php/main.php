<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/main.php
 * ROLE: Приёмщик do-действий модуля channel_bridge.
 * FLOW:
 *  /adm/index.php?m=channel_bridge&do=<action> -> этот файл -> channel_bridge_<action>.php
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';

/**
 * $do — запрошенное действие модуля.
 */
$do = (string)($_GET['do'] ?? 'view');

if ($do === '' || $do === 'view') {
  return;
}

if (!in_array($do, CHANNEL_BRIDGE_ALLOWED_DO, true)) {
  http_404('Unknown action');
}

/**
 * $file — путь к action-файлу.
 */
$file = __DIR__ . '/channel_bridge_' . $do . '.php';
if (!is_file($file)) {
  http_404('Action file not found');
}

require $file;

