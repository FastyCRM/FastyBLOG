<?php
/**
 * FILE: /adm/modules/oauth_tokens/assets/php/main.php
 * ROLE: Приёмщик do для модуля oauth_tokens
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

if (!in_array($do, OAUTH_TOKENS_ALLOWED_DO, true)) {
  http_404('Unknown action');
}

/**
 * $file — файл обработчика действия.
 */
$file = __DIR__ . '/oauth_tokens_' . $do . '.php';

if (!is_file($file)) {
  http_404('Action file not found');
}

require $file;
