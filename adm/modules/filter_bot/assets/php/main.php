<?php
/**
 * FILE: /adm/modules/filter_bot/assets/php/main.php
 * ROLE: do-router модуля filter_bot.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';

$do = trim((string)($_GET['do'] ?? 'view'));
if ($do === '' || $do === 'view') {
  return;
}

if (!in_array($do, FILTER_BOT_ALLOWED_DO, true)) {
  http_404('Unknown action');
}

$file = __DIR__ . '/filter_bot_' . $do . '.php';
if (!is_file($file)) {
  http_404('Action file not found');
}

require $file;
