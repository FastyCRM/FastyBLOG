<?php
/**
 * FILE: /adm/modules/services/assets/php/main.php
 * ROLE: Приёмщик do для модуля services
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
 * Проверяем allow-list
 */
if (!in_array($do, SERVICES_ALLOWED_DO, true)) {
  http_404('Unknown action');
}

/**
 * $file — файл действия
 */
$file = __DIR__ . '/services_' . $do . '.php';

if (!is_file($file)) {
  http_404('Action file not found');
}

require $file;
