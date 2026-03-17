<?php
/**
 * FILE: /adm/modules/calendar/assets/php/calendar_api_main.php
 * ROLE: Приёмщик API do для модуля calendar
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';

/**
 * $do — действие
 */
$do = (string)($_GET['do'] ?? '');

/**
 * Проверяем allow-list API
 */
if ($do === '' || !in_array($do, CALENDAR_ALLOWED_API_DO, true)) {
  json_err('Unknown api action', 404);
}

/**
 * $file — файл API действия
 */
$file = __DIR__ . '/api/calendar_' . $do . '.php';

if (!is_file($file)) {
  json_err('Api file not found', 404);
}

require $file;
