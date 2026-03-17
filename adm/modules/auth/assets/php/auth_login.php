<?php
/**
 * FILE: /adm/modules/auth/assets/php/auth_login.php
 * ROLE: ACTION (POST) — логин
 * CONNECTIONS:
 *  - csrf_check() из /core/csrf.php
 *  - auth_login_by_phone() из /core/auth.php
 *  - redirect() из /core/response.php
 *  - flash() из /core/flash.php
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  http_response_code(500);
  exit('Entrypoint required');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

$csrf = (string)($_POST['csrf'] ?? '');
if (function_exists('csrf_check')) {
  csrf_check($csrf);
}

$login = trim((string)($_POST['login'] ?? ''));
$password = (string)($_POST['password'] ?? '');

// MVP: телефон -> 7XXXXXXXXXX
$login = preg_replace('~\D+~', '', $login) ?? '';
if (strlen($login) === 11 && $login[0] === '8') {
  $login = '7' . substr($login, 1);
}
if (strlen($login) === 11 && $login[0] !== '7') {
  // Оставляем как есть — упадет как неверный логин.
}

if ($login === '' || $password === '') {
  if (function_exists('flash')) flash(t('auth.flash_fill_login_password'), 'warn', 1);
  redirect(url('/adm/index.php?m=auth'));
}

if (!function_exists('auth_login_by_phone')) {
  if (function_exists('flash')) flash(t('auth.flash_auth_core_missing'), 'danger', 1);
  redirect(url('/adm/index.php?m=auth'));
}

$ok = auth_login_by_phone($login, $password, false);

if (!$ok) {
  if (function_exists('flash')) flash(t('auth.flash_invalid_credentials'), 'danger', 1);
  redirect(url('/adm/index.php?m=auth'));
}

redirect(url('/adm/index.php?m=dashboard'));
