<?php
/**
 * FILE: /adm/modules/auth/assets/php/main.php
 * ROLE: Router для do-действий модуля auth
 * CONNECTIONS:
 *  - do=login  -> /adm/modules/auth/assets/php/auth_login.php
 *  - do=logout -> /adm/modules/auth/assets/php/auth_logout.php
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  http_response_code(500);
  exit('Entrypoint required');
}

$do = (string)($_GET['do'] ?? '');
$do = preg_replace('~[^a-z0-9_]+~i', '', $do);

$allowed = ['login', 'logout'];
if ($do === '' || !in_array($do, $allowed, true)) {
  http_response_code(404);
  exit('Unknown action');
}

$actionFile = ROOT_PATH . '/adm/modules/auth/assets/php/auth_' . $do . '.php';
if (!is_file($actionFile)) {
  http_response_code(500);
  exit('Action file missing: ' . $do);
}

require $actionFile;
