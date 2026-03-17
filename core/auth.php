<?php
/**
 * FILE: /core/auth.php
 * ROLE: Авторизация, восстановление сессии, remember-сессии, попытки входа
 * CONNECTIONS:
 *  - /core/db.php        (db)
 *  - /core/session.php   (session_get/session_set/session_del/session_regenerate)
 *  - /core/cookies.php   (cookie_set_signed/cookie_get_signed/cookie_del)
 *  - /core/audit.php     (audit_log)
 *  - /core/response.php  (redirect)
 *
 * NOTES:
 *  - Модули НЕ работают напрямую с таблицами users/auth_sessions/login_attempts.
 *  - Любое событие логируем через audit_log().
 *  - auth_restore() вызывается из bootstrap и не должен "ронять" систему при проблемах с БД.
 */

declare(strict_types=1);

/**
 * auth_user_id()
 * Возвращает ID текущего пользователя или null.
 */
function auth_user_id(): ?int {
  $uid = session_get('uid');
  return is_int($uid) ? $uid : null;
}

/**
 * auth_is_logged_in()
 * Быстрая проверка авторизации.
 */
function auth_is_logged_in(): bool {
  return auth_user_id() !== null;
}

/**
 * auth_require_login()
 * Требование авторизации для админки.
 * Если пользователь не вошёл — редирект в модуль auth.
 */
function auth_require_login(): void {
  if (!auth_is_logged_in()) {
    redirect(url('/adm/index.php?m=auth'));
  }
}

/**
 * auth_login_by_phone()
 * Вход по телефону и паролю.
 *
 * @param string $phone
 * @param string $password
 * @param bool   $remember Если true — создаём remember-сессию (auth_sessions) и cookie
 *
 * @return bool true при успехе
 */
function auth_login_by_phone(string $phone, string $password, bool $remember = false): bool {
  $phone = trim($phone);
  $password = (string)$password;

  if ($phone === '' || $password === '') {
    audit_log('auth', 'login', 'warn', ['reason' => 'empty_credentials', 'phone' => $phone]);
    return false;
  }

  /**
   * 1) Проверяем блокировку по попыткам (login_attempts)
   * key_str канонически: "<phone>:<ip>"
   */
  $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
  $keyStr = auth_attempt_key($phone, $ip);

  if (auth_attempt_is_locked($keyStr)) {
    audit_log('auth', 'login_locked', 'warn', ['phone' => $phone, 'key_str' => $keyStr]);
    return false;
  }

  /**
   * 2) Ищем пользователя
   */
  $pdo = db();

  $stmt = $pdo->prepare("
    SELECT id, pass_hash, status
    FROM users
    WHERE phone = :phone
    LIMIT 1
  ");
  $stmt->execute([':phone' => $phone]);
  $user = $stmt->fetch();

  /**
   * 3) Проверяем статус и пароль
   * status по твоей БД — строка ('active')
   */
  if (!$user || (string)($user['status'] ?? '') !== 'active') {
    auth_attempt_fail($keyStr);
    audit_log('auth', 'login', 'warn', ['reason' => 'user_not_found_or_inactive', 'phone' => $phone]);
    return false;
  }

  if (!password_verify($password, (string)$user['pass_hash'])) {
    auth_attempt_fail($keyStr);
    audit_log('auth', 'login', 'warn', ['reason' => 'bad_password', 'phone' => $phone, 'user_id' => (int)$user['id']], 'user', (int)$user['id']);
    return false;
  }

  /**
   * 4) Успех: фиксируем сессию
   */
  $uid = (int)$user['id'];

  session_regenerate();
  session_set('uid', $uid);

  /**
   * 5) Очищаем попытки входа
   */
  auth_attempt_clear($keyStr);

  /**
   * 6) remember-сессия при необходимости
   */
  if ($remember) {
    auth_remember_create($uid);
  }

  audit_log('auth', 'login', 'info', ['phone' => $phone], 'user', $uid, $uid, null);

  return true;
}

/**
 * auth_logout()
 * Выход пользователя:
 *  - очищаем session uid
 *  - отзываем remember-сессию (если есть cookie)
 *  - удаляем cookie
 */
function auth_logout(): void {
  $uid = auth_user_id();

  auth_remember_revoke_from_cookie();

  session_del('uid');

  audit_log('auth', 'logout', 'info', [], null, null, $uid, null);
}

/**
 * auth_restore()
 * Восстановление авторизации:
 *  - если уже есть session uid — ничего не делаем
 *  - иначе пытаемся восстановить по remember-cookie (selector:validator)
 *
 * ВАЖНО:
 *  - функция должна быть "мягкой" — не падать при проблемах БД
 */
function auth_restore(): void {
  if (auth_is_logged_in()) {
    return;
  }

  $cfg = app_config();
  $cookieName = (string)($cfg['security']['remember_cookie_name'] ?? '');
  if ($cookieName === '') {
    return;
  }

  $packed = cookie_get_signed($cookieName);
  if ($packed === null) {
    return;
  }

  /**
   * Cookie хранит "selector:validator"
   */
  [$selector, $validator] = auth_remember_split($packed);
  if ($selector === '' || $validator === '') {
    return;
  }

  try {
    $stmt = db()->prepare("
      SELECT id, user_id, validator_hash, expires_at, revoked_at
      FROM auth_sessions
      WHERE selector = :selector
      LIMIT 1
    ");
    $stmt->execute([':selector' => $selector]);
    $row = $stmt->fetch();

    if (!$row) {
      return;
    }

    if ($row['revoked_at'] !== null) {
      return;
    }

    if (strtotime((string)$row['expires_at']) <= time()) {
      return;
    }

    $hash = hash('sha256', $validator);
    if (!hash_equals((string)$row['validator_hash'], $hash)) {
      /**
       * Если validator не совпал — отзываем сессию (защита)
       */
      auth_remember_revoke_by_id((int)$row['id']);
      return;
    }

    /**
     * Успех: устанавливаем uid
     */
    $uid = (int)$row['user_id'];
    session_set('uid', $uid);

    audit_log('auth', 'restore', 'info', ['selector' => $selector], 'user', $uid, $uid, null);
  } catch (Throwable $e) {
    // bootstrap не должен падать
    audit_log('core', 'error', 'error', ['where' => 'auth_restore', 'error' => $e->getMessage()]);
  }
}

/* ========================================================================
 * ПЫТКИ ВХОДА (login_attempts)
 * ======================================================================== */

/**
 * auth_attempt_key()
 * Формирует ключ для таблицы login_attempts.
 * Канон: "<phone>:<ip>"
 */
function auth_attempt_key(string $phone, string $ip): string {
  $phone = trim($phone);
  $ip = trim($ip);
  return $phone . ':' . ($ip !== '' ? $ip : '0.0.0.0');
}

/**
 * auth_attempt_is_locked()
 * Проверяет, заблокирован ли ключ (lock_until > NOW()).
 */
function auth_attempt_is_locked(string $keyStr): bool {
  try {
    $stmt = db()->prepare("
      SELECT lock_until
      FROM login_attempts
      WHERE key_str = :k
      LIMIT 1
    ");
    $stmt->execute([':k' => $keyStr]);
    $row = $stmt->fetch();

    if (!$row) return false;

    $lockUntil = $row['lock_until'];
    if ($lockUntil === null) return false;

    return (strtotime((string)$lockUntil) > time());
  } catch (Throwable $e) {
    // Если БД недоступна — не блокируем (иначе можно отрезать вход навсегда)
    return false;
  }
}

/**
 * auth_attempt_fail()
 * Увеличивает attempts и при превышении лимита ставит lock_until.
 *
 * Таблица: login_attempts(key_str, attempts, last_try_at, lock_until)
 */
function auth_attempt_fail(string $keyStr): void {
  $cfg = app_config();
  $limit = (int)($cfg['auth']['login_attempt_limit'] ?? 5);
  $lockSec = (int)($cfg['auth']['lockout_sec'] ?? 900);

  try {
    $pdo = db();

    /**
     * Достаём текущее состояние
     */
    $stmt = $pdo->prepare("
      SELECT id, attempts, lock_until
      FROM login_attempts
      WHERE key_str = :k
      LIMIT 1
    ");
    $stmt->execute([':k' => $keyStr]);
    $row = $stmt->fetch();

    if (!$row) {
      // первая попытка
      $pdo->prepare("
        INSERT INTO login_attempts (key_str, attempts, last_try_at, lock_until)
        VALUES (:k, 1, NOW(), NULL)
      ")->execute([':k' => $keyStr]);

      return;
    }

    // если уже заблокировано — не увеличиваем
    if ($row['lock_until'] !== null && strtotime((string)$row['lock_until']) > time()) {
      return;
    }

    $attempts = (int)$row['attempts'] + 1;

    if ($attempts >= $limit) {
      $pdo->prepare("
        UPDATE login_attempts
        SET attempts = :a,
            last_try_at = NOW(),
            lock_until = DATE_ADD(NOW(), INTERVAL :locksec SECOND)
        WHERE id = :id
      ")->execute([
        ':a' => $attempts,
        ':locksec' => $lockSec,
        ':id' => (int)$row['id'],
      ]);
    } else {
      $pdo->prepare("
        UPDATE login_attempts
        SET attempts = :a,
            last_try_at = NOW()
        WHERE id = :id
      ")->execute([
        ':a' => $attempts,
        ':id' => (int)$row['id'],
      ]);
    }
  } catch (Throwable $e) {
    // попытки не должны ломать систему
  }
}

/**
 * auth_attempt_clear()
 * Очищает запись о попытках (после успешного логина).
 */
function auth_attempt_clear(string $keyStr): void {
  try {
    db()->prepare("DELETE FROM login_attempts WHERE key_str = :k")->execute([':k' => $keyStr]);
  } catch (Throwable $e) {
    // игнор
  }
}

/* ========================================================================
 * REMEMBER СЕССИИ (auth_sessions selector/validator)
 * ======================================================================== */

/**
 * auth_remember_create()
 * Создаёт remember-сессию:
 *  - selector (24 chars)
 *  - validator (random)
 *  - validator_hash = sha256(validator)
 *  - сохраняет в БД
 *  - кладёт cookie (signed) со значением "selector:validator"
 */
function auth_remember_create(int $userId): void {
  $cfg = app_config();
  $cookieName = (string)($cfg['security']['remember_cookie_name'] ?? '');
  $ttl = (int)($cfg['security']['remember_lifetime_sec'] ?? (60 * 60 * 24 * 14));

  if ($cookieName === '') return;

  $selector = auth_random_selector(24);
  $validator = bin2hex(random_bytes(32)); // 64 hex chars
  $validatorHash = hash('sha256', $validator);

  $ipPacked = auth_ip_pack((string)($_SERVER['REMOTE_ADDR'] ?? ''));
  $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

  try {
    db()->prepare("
      INSERT INTO auth_sessions (user_id, selector, validator_hash, ip, user_agent, created_at, expires_at, revoked_at)
      VALUES (:uid, :selector, :vh, :ip, :ua, CURRENT_TIMESTAMP, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL :ttl SECOND), NULL)
    ")->execute([
      ':uid' => $userId,
      ':selector' => $selector,
      ':vh' => $validatorHash,
      ':ip' => $ipPacked,
      ':ua' => $ua,
      ':ttl' => $ttl,
    ]);

    // cookie value: selector:validator (потом ещё подписывается cookie_set_signed)
    $value = $selector . ':' . $validator;
    cookie_set_signed($cookieName, $value, $ttl);

    audit_log('auth', 'remember_create', 'info', ['selector' => $selector], 'user', $userId, $userId, null);
  } catch (Throwable $e) {
    audit_log('core', 'error', 'error', ['where' => 'auth_remember_create', 'error' => $e->getMessage()]);
  }
}

/**
 * auth_remember_revoke_from_cookie()
 * Если есть remember-cookie — отзываем запись в БД по selector и удаляем cookie.
 */
function auth_remember_revoke_from_cookie(): void {
  $cfg = app_config();
  $cookieName = (string)($cfg['security']['remember_cookie_name'] ?? '');
  if ($cookieName === '') return;

  $packed = cookie_get_signed($cookieName);
  if ($packed !== null) {
    [$selector, $validator] = auth_remember_split($packed);
    if ($selector !== '') {
      auth_remember_revoke_by_selector($selector);
    }
  }

  cookie_del($cookieName);
}

/**
 * auth_remember_revoke_by_selector()
 * Отзывает remember-сессию по selector (ставит revoked_at).
 */
function auth_remember_revoke_by_selector(string $selector): void {
  try {
    db()->prepare("
      UPDATE auth_sessions
      SET revoked_at = CURRENT_TIMESTAMP
      WHERE selector = :s AND revoked_at IS NULL
    ")->execute([':s' => $selector]);
  } catch (Throwable $e) {
    // игнор
  }
}

/**
 * auth_remember_revoke_by_id()
 * Отзывает remember-сессию по ID записи.
 */
function auth_remember_revoke_by_id(int $id): void {
  try {
    db()->prepare("
      UPDATE auth_sessions
      SET revoked_at = CURRENT_TIMESTAMP
      WHERE id = :id AND revoked_at IS NULL
    ")->execute([':id' => $id]);
  } catch (Throwable $e) {
    // игнор
  }
}

/**
 * auth_remember_split()
 * Разбирает строку remember-cookie "selector:validator".
 *
 * @return array{0:string,1:string}
 */
function auth_remember_split(string $packed): array {
  $packed = trim($packed);
  $pos = strpos($packed, ':');
  if ($pos === false) return ['', ''];

  $selector = substr($packed, 0, $pos);
  $validator = substr($packed, $pos + 1);

  $selector = trim($selector);
  $validator = trim($validator);

  // минимальная валидация по длинам
  if (strlen($selector) !== 24) return ['', ''];
  if (strlen($validator) < 32) return ['', ''];

  return [$selector, $validator];
}

/**
 * auth_random_selector()
 * Генерирует selector нужной длины (только [a-z0-9]).
 */
function auth_random_selector(int $len): string {
  $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
  $out = '';

  for ($i = 0; $i < $len; $i++) {
    $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
  }

  return $out;
}

/**
 * auth_ip_pack()
 * Пакует IP в формат varbinary(16) для хранения.
 * IPv4 и IPv6 поддерживаются.
 *
 * @return string|null бинарная строка или null если IP пустой/битый
 */
function auth_ip_pack(string $ip): ?string {
  $ip = trim($ip);
  if ($ip === '') return null;

  $packed = @inet_pton($ip);
  if ($packed === false) return null;

  return $packed;
}

/* ========================================================================
 * РОЛИ ПОЛЬЗОВАТЕЛЯ
 * ======================================================================== */

/**
 * auth_user_roles()
 * Возвращает коды ролей пользователя.
 *
 * Таблицы:
 *  - user_roles(user_id, role_id)
 *  - roles(id, code)
 *
 * @return array<int, string>
 */
function auth_user_roles(int $uid): array {
  $stmt = db()->prepare("
    SELECT r.code
    FROM roles r
    JOIN user_roles ur ON ur.role_id = r.id
    WHERE ur.user_id = :uid
    ORDER BY r.sort ASC, r.id ASC
  ");
  $stmt->execute([':uid' => $uid]);

  $rows = $stmt->fetchAll();
  $codes = [];

  foreach ($rows as $r) {
    $codes[] = (string)($r['code'] ?? '');
  }

  return array_values(array_filter($codes));
}

/* ========================================================================
 * ОСНОВНАЯ РОЛЬ ПОЛЬЗОВАТЕЛЯ
 * ======================================================================== */

/**
 * auth_user_role()
 * Возвращает одну «основную» роль текущего пользователя.
 *
 * ВАЖНО:
 *  - В системе поддерживаются несколько ролей (user_roles).
 *  - Для меню/ACL часто нужен один код роли.
 *  - Берём первую роль по приоритету (sort), как возвращает auth_user_roles().
 *
 * @return string $roleCode — код роли (admin|manager|user) или '' если роли нет
 */
function auth_user_role(): string {
  // $uid — id текущего пользователя
  $uid = auth_user_id();

  // Если нет авторизации — роли нет
  if ($uid <= 0) {
    return '';
  }

  // $roles — список ролей пользователя, уже отсортированный по sort
  $roles = auth_user_roles($uid);

  // Возвращаем первую роль или пустую строку
  return (string)($roles[0] ?? '');
}
