<?php
/**
 * FILE: /core/cookies.php
 * ROLE: Работа с подписанными cookies (remember, служебные cookies)
 * CONNECTIONS:
 *  - /core/config.php (app_secret)
 *  - используется в /core/auth.php
 *
 * NOTES:
 *  - Cookie всегда подписана HMAC.
 *  - Cookie НЕ хранит чувствительные данные в чистом виде.
 *  - Этот файл не знает ничего про сессии и пользователей.
 */

declare(strict_types=1);

/**
 * cookie_secret()
 * Возвращает главный секрет приложения для подписи cookies.
 */
function cookie_secret(): string {
  $cfg = app_config();
  return (string)($cfg['security']['app_secret'] ?? '');
}

/**
 * cookie_set_signed()
 * Устанавливает подписанную cookie.
 *
 * Формат:
 *   base64(value|expires|signature)
 */
function cookie_set_signed(string $name, string $value, int $ttlSec): void {
  $expires = time() + $ttlSec;
  $payload = $value . '|' . $expires;
  $signature = hash_hmac('sha256', $payload, cookie_secret());

  $packed = base64_encode($payload . '|' . $signature);

  setcookie($name, $packed, [
    'expires'  => $expires,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    // 'secure' => true, // включается на HTTPS
  ]);
}

/**
 * cookie_get_signed()
 * Читает и проверяет подписанную cookie.
 *
 * @return string|null — значение cookie или null при ошибке подписи/срока
 */
function cookie_get_signed(string $name): ?string {
  $raw = $_COOKIE[$name] ?? '';
  if (!is_string($raw) || $raw === '') {
    return null;
  }

  $decoded = base64_decode($raw, true);
  if (!is_string($decoded)) {
    return null;
  }

  $parts = explode('|', $decoded);
  if (count($parts) !== 3) {
    return null;
  }

  [$value, $expires, $signature] = $parts;

  if (time() > (int)$expires) {
    return null;
  }

  $payload = $value . '|' . $expires;
  $check = hash_hmac('sha256', $payload, cookie_secret());

  if (!hash_equals($check, (string)$signature)) {
    return null;
  }

  return (string)$value;
}

/**
 * cookie_del()
 * Удаляет cookie.
 */
function cookie_del(string $name): void {
  setcookie($name, '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
}
