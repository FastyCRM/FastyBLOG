<?php
/**
 * FILE: /adm/modules/promobot/assets/php/main.php
 * ROLE: do-router модуля promobot.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';

$do = (string)($_GET['do'] ?? '');
$do = trim($do);
if ($do === '') $do = 'view';

if ($do === 'view') {
  require_once __DIR__ . '/../promobot.php';
  return;
}

if (!in_array($do, PROMOBOT_ALLOWED_DO, true)) {
  http_404();
}

$file = __DIR__ . '/promobot_' . $do . '.php';
if (!is_file($file)) {
  http_404();
}

require_once $file;