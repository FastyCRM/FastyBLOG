<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/main.php
 * ROLE: do-router модуля stopbot.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';

$do = (string)($_GET['do'] ?? '');
$do = trim($do);
if ($do === '') $do = 'view';

if ($do === 'view') {
  require_once __DIR__ . '/../stopbot.php';
  return;
}

if (!in_array($do, STOPBOT_ALLOWED_DO, true)) {
  http_404();
}

$file = __DIR__ . '/stopbot_' . $do . '.php';
if (!is_file($file)) {
  http_404();
}

require_once $file;