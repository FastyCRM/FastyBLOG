<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/main.php
 * ROLE: Приёмщик do для модуля personal_file
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php
 *
 * NOTES:
 *  - Разрешённые действия сверяются по allow-list.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';

/**
 * $do — действие
 */
$do = (string)($_GET['do'] ?? 'view');

if ($do === '' || $do === 'view') {
  return;
}

/**
 * allow-list
 */
if (!in_array($do, PERSONAL_FILE_ALLOWED_DO, true)) {
  http_404('Unknown action');
}

/**
 * $file — файл действия
 */
$file = __DIR__ . '/personal_file_' . $do . '.php';

if (!is_file($file)) {
  http_404('Action file not found');
}

require $file;
