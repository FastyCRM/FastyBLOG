<?php
/**
 * FILE: /adm/modules/clients/assets/php/main.php
 * ROLE: Приёмщик do для модуля clients (единый паттерн)
 * CONNECTIONS:
 *  - settings.php (CLIENTS_ALLOWED_DO)
 *
 * СПИСОК ФУНКЦИЙ: (нет)
 *
 * FLOW:
 *  /adm/index.php -> /adm/view/index.php -> этот файл -> clients_<do>.php
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
 * allow-list do
 */
if (!in_array($do, CLIENTS_ALLOWED_DO, true)) {
  http_404('Unknown action');
}

/**
 * $file — файл действия
 */
$file = __DIR__ . '/clients_' . $do . '.php';

if (!is_file($file)) {
  http_404('Action file not found');
}

require $file;
