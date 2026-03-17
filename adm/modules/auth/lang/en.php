<?php
/**
 * FILE: /adm/modules/auth/lang/en.php
 * ROLE: Словарь модуля auth (EN).
 * USAGE:
 *  - Загружается из /core/i18n.php при активном модуле auth.
 *  - Используется через t('auth.*').
 */

declare(strict_types=1);

return [
  'auth.login_card_title' => 'Sign in to CRM2026',
  'auth.login_card_hint' => 'Enter your login and password',
  'auth.login_label' => 'Login (phone)',
  'auth.password_label' => 'Password',
  'auth.login_submit' => 'Sign in',

  'auth.flash_fill_login_password' => 'Fill in login and password.',
  'auth.flash_auth_core_missing' => 'core/auth.php: function auth_login_by_phone() is missing.',
  'auth.flash_invalid_credentials' => 'Invalid login or password.',
];
