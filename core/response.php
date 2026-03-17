<?php
/**
 * FILE: /core/response.php
 * ROLE: Единые ответы приложения (redirect, http-коды, JSON)
 * CONNECTIONS:
 *  - используется во всех core и modules
 *
 * NOTES:
 *  - Это инфраструктура. Никакой бизнес-логики.
 *  - Все выходы из скрипта — через exit, чтобы не было "дожития" кода ниже.
 */

declare(strict_types=1);

/**
 * redirect()
 * Безопасный редирект на URL.
 * Использовать вместо header('Location: ...') руками, чтобы стиль был единый.
 */
function redirect(string $url): void {
  header('Location: ' . $url);
  exit;
}

/**
 * safe_return_url()
 * Безопасная обработка return_url (только внутри текущего домена).
 */
function safe_return_url(string $url, string $fallback = '/adm/index.php'): string {
  $url = str_replace(["\r", "\n"], '', trim($url));
  if ($url === '') return $fallback;
  if (strpos($url, '//') === 0) return $fallback;

  $parts = @parse_url($url);
  if ($parts === false) return $fallback;

  $host = (string)($_SERVER['HTTP_HOST'] ?? '');
  if (isset($parts['scheme']) || isset($parts['host'])) {
    if ($host !== '' && (!isset($parts['host']) || strcasecmp($parts['host'], $host) !== 0)) {
      return $fallback;
    }
    $path = (string)($parts['path'] ?? '');
    if ($path === '' || $path[0] !== '/') return $fallback;
    $query = isset($parts['query']) && $parts['query'] !== '' ? ('?' . $parts['query']) : '';
    return $path . $query;
  }

  $path = (string)($parts['path'] ?? '');
  if ($path === '' || $path[0] !== '/') return $fallback;
  return $url;
}

/**
 * redirect_return()
 * Редирект на return_url (POST/GET/HTTP_REFERER) или fallback.
 */
function redirect_return(string $fallback = '/adm/index.php'): void {
  $candidate = (string)($_POST['return_url'] ?? $_GET['return_url'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
  $safe = safe_return_url($candidate, $fallback);
  redirect($safe);
}

/**
 * http_401()
 * Неавторизован. Обычно — редирект в auth, но иногда нужен "чистый" 401.
 */
function http_401(string $msg = 'Unauthorized'): void {
  http_response_code(401);
  exit($msg);
}

/**
 * http_403()
 * Доступ запрещён.
 */
function http_403(string $msg = 'Forbidden'): void {
  http_response_code(403);
  exit($msg);
}

/**
 * http_404()
 * Не найдено (модуль/действие/страница).
 */
function http_404(string $msg = 'Not Found'): void {
  http_response_code(404);
  exit($msg);
}

/**
 * http_405()
 * Метод не поддерживается (например, GET вместо POST).
 */
function http_405(string $msg = 'Method Not Allowed'): void {
  http_response_code(405);
  exit($msg);
}

/**
 * json_ok()
 * Успешный JSON-ответ.
 */
function json_ok(array $data = []): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok' => true,
    'data' => $data,
  ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

/**
 * json_err()
 * Ошибка JSON-ответа.
 * $code — HTTP статус.
 */
function json_err(string $msg, int $code = 400, array $data = []): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'ok' => false,
    'error' => $msg,
    'data' => $data,
  ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}
