<?php
/**
 * FILE: /adm/modules/users/assets/php/main.php
 * ROLE: Приёмщик do для модуля users (единый паттерн)
 * CONNECTIONS:
 *  - settings.php (USERS_ALLOWED_DO)
 *
 * FLOW:
 *  /adm/index.php -> /adm/view/index.php -> этот файл -> users_<do>.php
 *
 * RULE:
 *  - Прямой доступ к action-файлам запрещён (guard: ROOT_PATH)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/users_i18n.php';

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
if (!in_array($do, USERS_ALLOWED_DO, true)) {
  http_404('Unknown action');
}

/**
 * $file — файл действия
 */
$file = __DIR__ . '/users_' . $do . '.php';

if (!is_file($file)) {
  http_404('Action file not found');
}

require $file;
