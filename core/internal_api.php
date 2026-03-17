<?php
/**
 * FILE: /core/internal_api.php
 * ROLE: Внутренний API-контур (обмен между модулями и site)
 *
 * КАНОН:
 *  - только do=api_*
 *  - всё проходит через assets/php/main.php модуля
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__));
}

require_once ROOT_PATH . '/core/bootstrap.php';

/**
 * $cfg — конфигурация
 */
$cfg = app_config();

/**
 * $apiKey — ключ внутреннего API (если задан)
 */
$apiKey = (string)($cfg['internal_api']['key'] ?? '');

if ($apiKey !== '') {
  /**
   * $headerKey — ключ из заголовка
   */
  $headerKey = (string)($_SERVER['HTTP_X_INTERNAL_API_KEY'] ?? '');
  /**
   * $queryKey — ключ из query
   */
  $queryKey = (string)($_GET['key'] ?? '');

  if ($headerKey !== $apiKey && $queryKey !== $apiKey) {
    json_err('Forbidden', 403);
  }
}

/**
 * $module — модуль
 */
$module = trim((string)($_GET['m'] ?? ''));
/**
 * $do — действие
 */
$do = trim((string)($_GET['do'] ?? ''));

if ($module === '' || $do === '' || strpos($do, 'api_') !== 0) {
  json_err('Bad request', 400);
}

if (!module_is_enabled($module)) {
  json_err('Module disabled', 404);
}

/**
 * $main — main.php модуля
 */
$main = ROOT_PATH . '/adm/modules/' . $module . '/assets/php/main.php';

if (!is_file($main)) {
  json_err('Module not found', 404);
}

require $main;
