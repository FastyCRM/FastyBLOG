<?php
/**
 * FILE: /adm/modules/dashboard/assets/php/main.php
 * ROLE: Приёмщик do для модуля dashboard
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

if (!in_array($do, DASHBOARD_ALLOWED_DO, true)) {
  http_404('Unknown action');
}

/**
 * $file — action-файл
 */
$file = __DIR__ . '/dashboard_' . $do . '.php';

if (!is_file($file)) {
  http_404('Action file not found');
}

require $file;