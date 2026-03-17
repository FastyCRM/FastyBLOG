<?php
/**
 * FILE: /adm/modules/ym_link_bot/assets/php/main.php
 * ROLE: do-router for ym_link_bot module.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

$moduleDir = basename(dirname(__DIR__, 2));
$moduleRoot = ROOT_PATH . '/adm/modules/' . $moduleDir;
require_once $moduleRoot . '/settings.php';

$do = (string)($_GET['do'] ?? 'view');
if ($do === '' || $do === 'view') {
  return;
}

if (!in_array($do, YM_LINK_BOT_ALLOWED_DO, true)) {
  http_404('Unknown action');
}

$file = $moduleRoot . '/assets/php/ym_link_bot_' . $do . '.php';
if (!is_file($file)) {
  http_404('Action file not found');
}

require $file;

