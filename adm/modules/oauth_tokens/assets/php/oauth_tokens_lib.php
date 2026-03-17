<?php
/**
 * FILE: /adm/modules/oauth_tokens/assets/php/oauth_tokens_lib.php
 * ROLE: Вспомогательные функции модуля oauth_tokens
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';

/**
 * oauth_tokens_is_admin()
 * Проверяет, является ли текущий пользователь админом.
 */
function oauth_tokens_is_admin(): bool
{
  /**
   * $role — текущая роль пользователя.
   */
  $role = (string)auth_user_role();

  return ($role === 'admin');
}

/**
 * oauth_tokens_redirect_uri()
 * Формирует redirect_uri для callback.
 */
function oauth_tokens_redirect_uri(): string
{
  /**
   * $https — признак HTTPS.
   */
  $https = (string)($_SERVER['HTTPS'] ?? '');

  /**
   * $scheme — схема URL.
   */
  $scheme = ($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';

  /**
   * $host — host текущего запроса.
   */
  $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

  /**
   * $path — относительный путь callback с учётом BASE_URL.
   */
  $path = url('/adm/index.php?m=oauth_tokens&do=callback');

  if ($host === '') {
    return $path;
  }

  return $scheme . '://' . $host . $path;
}

/**
 * oauth_tokens_get_token()
 * Возвращает запись токена по id.
 *
 * @return array<string,mixed>|null
 */
function oauth_tokens_get_token(PDO $pdo, int $tokenId): ?array
{
  /**
   * $stmt — запрос на токен.
   */
  $stmt = $pdo->prepare('SELECT * FROM ' . OAUTH_TOKENS_TABLE . ' WHERE id = :id LIMIT 1');
  $stmt->execute([':id' => $tokenId]);

  /**
   * $row — строка результата.
   */
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  return is_array($row) ? $row : null;
}

/**
 * oauth_tokens_user_token_id()
 * Возвращает id токена, назначенного пользователю.
 */
function oauth_tokens_user_token_id(PDO $pdo, int $userId): int
{
  /**
   * $stmt — запрос связки user -> token.
   */
  $stmt = $pdo->prepare('SELECT oauth_token_id FROM ' . OAUTH_TOKENS_USERS_TABLE . ' WHERE user_id = :uid LIMIT 1');
  $stmt->execute([':uid' => $userId]);

  return (int)($stmt->fetchColumn() ?: 0);
}

/**
 * oauth_tokens_user_can_manage()
 * Проверяет, может ли пользователь управлять указанным токеном.
 */
function oauth_tokens_user_can_manage(PDO $pdo, int $tokenId, int $userId): bool
{
  if (oauth_tokens_is_admin()) {
    return true;
  }

  /**
   * $assignedTokenId — токен, назначенный текущему пользователю.
   */
  $assignedTokenId = oauth_tokens_user_token_id($pdo, $userId);

  return ($assignedTokenId > 0 && $assignedTokenId === $tokenId);
}

/**
 * oauth_tokens_http_post_form()
 * Выполняет POST application/x-www-form-urlencoded и возвращает JSON-ответ.
 *
 * @return array<string,mixed>
 */
function oauth_tokens_http_post_form(string $url, array $fields): array
{
  if (!function_exists('curl_init')) {
    return [
      '_ok' => false,
      '_http' => 0,
      '_error' => 'cURL extension is not available',
    ];
  }

  /**
   * $ch — cURL-ресурс.
   */
  $ch = curl_init($url);
  if ($ch === false) {
    return [
      '_ok' => false,
      '_http' => 0,
      '_error' => 'curl_init failed',
    ];
  }

  /**
   * $payload — form-urlencoded тело запроса.
   */
  $payload = http_build_query($fields);

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT => 20,
  ]);

  /**
   * $raw — сырой ответ.
   */
  $raw = curl_exec($ch);

  /**
   * $err — текст ошибки cURL.
   */
  $err = curl_error($ch);

  /**
   * $httpCode — HTTP-код ответа.
   */
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

  curl_close($ch);

  if ($raw === false) {
    return [
      '_ok' => false,
      '_http' => $httpCode,
      '_error' => ($err !== '' ? $err : 'curl error'),
    ];
  }

  /**
   * $payloadJson — декодированный JSON.
   */
  $payloadJson = json_decode((string)$raw, true);

  if (!is_array($payloadJson)) {
    return [
      '_ok' => false,
      '_http' => $httpCode,
      '_error' => 'bad json',
      '_raw' => (string)$raw,
    ];
  }

  $payloadJson['_ok'] = ($httpCode >= 200 && $httpCode < 300);
  $payloadJson['_http'] = $httpCode;

  return $payloadJson;
}
