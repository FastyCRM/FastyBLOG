<?php
/**
 * FILE: /adm/modules/auth/lang/ru.php
 * ROLE: Словарь модуля auth (RU).
 * USAGE:
 *  - Загружается из /core/i18n.php при активном модуле auth.
 *  - Используется через t('auth.*').
 */

declare(strict_types=1);

return [
  'auth.login_card_title' => 'Вход в CRM2026',
  'auth.login_card_hint' => 'Введите логин и пароль',
  'auth.login_label' => 'Логин (телефон)',
  'auth.password_label' => 'Пароль',
  'auth.login_submit' => 'Войти',

  'auth.flash_fill_login_password' => 'Заполните логин и пароль.',
  'auth.flash_auth_core_missing' => 'core/auth.php: нет функции auth_login_by_phone().',
  'auth.flash_invalid_credentials' => 'Неверный логин или пароль.',
];
