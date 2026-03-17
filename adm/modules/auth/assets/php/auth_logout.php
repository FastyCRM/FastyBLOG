<?php
/**
 * FILE: /adm/modules/auth/assets/php/auth_logout.php
 * ROLE: ACTION — выход
 * CONNECTIONS:
 *  - auth_logout() из /core/auth.php
 *  - redirect() из /core/response.php
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  http_response_code(500);
  exit('Entrypoint required');
}

if (function_exists('auth_logout')) {
  auth_logout();
}

redirect(url('/adm/index.php?m=auth'));
