<?php
/**
 * FILE: /core/csrf.php
 * ROLE: Защита от CSRF-атак (токен + проверка)
 * CONNECTIONS:
 *  - /core/session.php (session_get, session_set)
 *  - /core/response.php (http_403)
 *  - /core/audit.php (audit_log)
 *
 * NOTES:
 *  - CSRF-токен хранится ТОЛЬКО в $_SESSION.
 *  - Генерация — ленивая (по запросу).
 *  - Проверка всегда жёсткая: несовпадение = 403.
 */

declare(strict_types=1);

/**
 * csrf_token()
 * Возвращает текущий CSRF-токен.
 * Если токена нет — генерирует новый и сохраняет в сессии.
 */
function csrf_token(): string {
  $cfg = app_config();
  $key = (string)($cfg['security']['csrf_session_key'] ?? 'csrf_token');

  $token = (string)session_get($key, '');
  if ($token === '') {
    $token = bin2hex(random_bytes(32));
    session_set($key, $token);
  }

  return $token;
}

/**
 * csrf_check()
 * Проверяет CSRF-токен из запроса.
 *
 * @param string $token — значение из POST/HEADER
 *
 * При ошибке:
 *  - пишет событие в аудит
 *  - завершает запрос с 403
 */
function csrf_check(string $token): void {
  $cfg = app_config();
  $key = (string)($cfg['security']['csrf_session_key'] ?? 'csrf_token');

  $expected = (string)session_get($key, '');

  if ($expected === '' || !hash_equals($expected, (string)$token)) {
    /**
     * Логируем попытку CSRF
     * auth_user_id() может быть null — это нормально
     */
    $uid = function_exists('auth_user_id') ? auth_user_id() : null;
    $role = function_exists('auth_user_role') ? auth_user_role() : null;
    audit_log(
      'security',
      'csrf_fail',
      'warn',
      [
        'uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
      ],
      null,
      null,
      $uid,
      $role
    );

    http_403('CSRF token invalid');
  }
}
