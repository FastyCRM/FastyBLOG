<?php
/**
 * FILE: /adm/modules/bot_adv_calendar/assets/php/main.php
 * ROLE: do-router модуля bot_adv_calendar
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';

$do = (string)($_GET['do'] ?? 'view');
if ($do === '' || $do === 'view') {
  return;
}

if (!in_array($do, BOT_ADV_CALENDAR_ALLOWED_DO, true)) {
  http_404('Unknown action');
}

$file = __DIR__ . '/bot_adv_calendar_' . $do . '.php';
if (!is_file($file)) {
  http_404('Action file not found');
}

require $file;

