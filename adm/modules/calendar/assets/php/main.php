<?php
/**
 * FILE: /adm/modules/calendar/assets/php/main.php
 * ROLE: Приёмщик do для модуля calendar
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
 * API режимы (do=api_*)
 */
if (strpos($do, 'api_') === 0) {
  require __DIR__ . '/calendar_api_main.php';
  return;
}

/**
 * Проверяем allow-list
 */
if (!in_array($do, CALENDAR_ALLOWED_DO, true)) {
  http_404('Unknown action');
}

/**
 * $file — файл действия
 */
$file = __DIR__ . '/calendar_' . $do . '.php';

if (!is_file($file)) {
  http_404('Action file not found');
}

require $file;
