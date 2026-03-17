<?php
/**
 * FILE: /core/session.php
 * ROLE: Работа с PHP-сессией (инициализация и базовые операции)
 * CONNECTIONS:
 *  - использует $GLOBALS['APP_CONFIG'] из /core/config.php
 *
 * NOTES:
 *  - Этот файл отвечает ТОЛЬКО за сессию.
 *  - Никакой авторизации, никакой БД, никакого CSRF.
 *  - session_start() вызывается один раз — из bootstrap.php.
 */

declare(strict_types=1);

/**
 * app_config()
 * Единая точка доступа к конфигурации приложения.
 *
 * Возвращает массив конфигурации из $GLOBALS['APP_CONFIG'].
 * Используется во всех core-файлах, где нужны параметры.
 */
function app_config(): array {
  return (array)($GLOBALS['APP_CONFIG'] ?? []);
}

/**
 * session_boot()
 * Инициализация PHP-сессии.
 *
 * Настраивает:
 *  - имя cookie сессии
 *  - флаги безопасности cookie
 *  - strict mode
 *
 * Вызывается ТОЛЬКО из bootstrap.php.
 */
function session_boot(): void {
  $cfg = app_config();

  $cookieName = (string)($cfg['security']['session_cookie_name'] ?? 'crm2026_sid');

  /**
   * Защита от фиксации сессии
   */
  ini_set('session.use_strict_mode', '1');

  /**
   * Cookie недоступна из JS
   */
  ini_set('session.cookie_httponly', '1');

  /**
   * SameSite=Lax — безопасно для форм и редиректов
   */
  ini_set('session.cookie_samesite', 'Lax');

  /**
   * Secure включается при HTTPS (включишь на проде)
   */
  // ini_set('session.cookie_secure', '1');

  session_name($cookieName);

  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
}

/**
 * session_get()
 * Получение значения из $_SESSION.
 *
 * ВАЖНО:
 *  - НЕ типизируем mixed в сигнатуре, чтобы не зависеть от версии/сборки PHP.
 *
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function session_get(string $key, $default = null) {
  return $_SESSION[$key] ?? $default;
}

/**
 * session_set()
 * Установка значения в $_SESSION.
 *
 * @param string $key
 * @param mixed $value
 */
function session_set(string $key, $value): void {
  $_SESSION[$key] = $value;
}

/**
 * session_del()
 * Удаление ключа из $_SESSION.
 */
function session_del(string $key): void {
  unset($_SESSION[$key]);
}

/**
 * session_regenerate()
 * Регенерация ID сессии (использовать после успешного логина).
 */
function session_regenerate(): void {
  if (session_status() === PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
  }
}
