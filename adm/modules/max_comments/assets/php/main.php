<?php
/**
 * FILE: /adm/modules/max_comments/assets/php/main.php
 * ROLE: do-router модуля max_comments.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';

$do = (string)($_GET['do'] ?? 'view');
if ($do === '' || $do === 'view') {
  return;
}

if (!in_array($do, MAX_COMMENTS_ALLOWED_DO, true)) {
  http_404('Unknown action');
}

$file = __DIR__ . '/max_comments_' . $do . '.php';
if (!is_file($file)) {
  http_404('Action file not found');
}

require $file;

