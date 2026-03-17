<?php
/**
 * FILE: /core/crypto.php
 * ROLE: Шифрование/дешифрование чувствительных данных (логины/пароли).
 * CONNECTIONS:
 *  - /core/config.php (APP_CONFIG)
 *
 * NOTES:
 *  - Используем AES-256-GCM (openssl).
 *  - Ключ берём из config.crypto.key или config.crypto.key_file.
 *  - Если ключ не настроен — выбрасываем исключение.
 *  - Шифротекст хранится в base64 (iv+tag+ciphertext).
 *
 * СПИСОК ФУНКЦИЙ:
 *  - crypto_is_configured(): bool — проверка наличия ключа.
 *  - crypto_encrypt(string $plain): string — шифрование строки.
 *  - crypto_decrypt(string $cipherB64): string — расшифровка строки.
 */

declare(strict_types=1);

/**
 * crypto_is_configured()
 * Проверяет наличие ключа в config или файле.
 */
function crypto_is_configured(): bool {
  $cfg = app_config();
  $key = (string)($cfg['crypto']['key'] ?? '');
  $keyFile = (string)($cfg['crypto']['key_file'] ?? '');

  if ($key !== '') return true;
  if ($keyFile === '') return false;
  if (is_file($keyFile)) {
    $raw = trim((string)@file_get_contents($keyFile));
    return $raw !== '';
  }
  return false;
}

/**
 * crypto_encrypt()
 * Шифрует строку и возвращает base64 (iv + tag + ciphertext).
 *
 * @param string $plain исходная строка
 */
function crypto_encrypt(string $plain): string {
  $key = crypto_get_key_bytes();
  $iv = random_bytes(12); // рекомендуемый размер IV для GCM
  $tag = '';

  $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  if ($cipher === false || $tag === '') {
    throw new RuntimeException('Encrypt failed');
  }

  return base64_encode($iv . $tag . $cipher);
}

/**
 * crypto_decrypt()
 * Расшифровывает base64 (iv + tag + ciphertext).
 *
 * @param string $cipherB64 шифротекст (base64)
 */
function crypto_decrypt(string $cipherB64): string {
  $bin = base64_decode($cipherB64, true);
  if ($bin === false || strlen($bin) < 12 + 16) {
    throw new RuntimeException('Decrypt failed');
  }

  $iv = substr($bin, 0, 12);
  $tag = substr($bin, 12, 16);
  $cipher = substr($bin, 28);

  $key = crypto_get_key_bytes();
  $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
  if ($plain === false) {
    throw new RuntimeException('Decrypt failed');
  }

  return $plain;
}

/**
 * crypto_get_key_bytes()
 * Внутренняя функция: получает ключ 32 байта.
 */
function crypto_get_key_bytes(): string {
  $cfg = app_config();

  $key = (string)($cfg['crypto']['key'] ?? '');
  $keyFile = (string)($cfg['crypto']['key_file'] ?? '');

  if ($key !== '') {
    return hash('sha256', $key, true);
  }

  if ($keyFile !== '') {
    if (!is_file($keyFile)) {
      $dir = dirname($keyFile);
      if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
      }
      $gen = base64_encode(random_bytes(32));
      @file_put_contents($keyFile, $gen, LOCK_EX);
    }

    $raw = trim((string)@file_get_contents($keyFile));
    if ($raw !== '') {
      $decoded = base64_decode($raw, true);
      if ($decoded !== false && strlen($decoded) === 32) {
        return $decoded;
      }
      return hash('sha256', $raw, true);
    }
  }

  throw new RuntimeException('Crypto key not configured');
}
