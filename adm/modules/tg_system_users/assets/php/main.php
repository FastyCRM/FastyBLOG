<?php
/**
 * FILE: /adm/modules/tg_system_users/assets/php/main.php
 * ROLE: Приёмщик do для модуля tg_system_users
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';

/**
 * $do — действие модуля.
 */
$do = (string)($_GET['do'] ?? 'view');

if ($do === '' || $do === 'view') {
  return;
}

if (!in_array($do, TG_SYSTEM_USERS_ALLOWED_DO, true)) {
  http_404('Unknown action');
}

/**
 * $file — файл обработчика.
 */
$file = __DIR__ . '/tg_system_users_' . $do . '.php';

if (!is_file($file)) {
  http_404('Action file not found');
}

require $file;

