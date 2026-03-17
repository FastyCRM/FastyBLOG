<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_access_add.php
 * ROLE: Добавление доступа (логин/пароль)
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php
 *  - /adm/modules/personal_file/assets/php/personal_file_lib.php
 *  - /core/crypto.php (crypto_encrypt, crypto_is_configured)
 *  - /core/flash.php (flash)
 *  - /core/response.php (redirect)
 *
 * NOTES:
 *  - Логин/пароль сохраняются в шифрованном виде.
 *
 * СПИСОК ФУНКЦИЙ: (нет)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/personal_file_lib.php';
require_once ROOT_PATH . '/core/crypto.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('personal_file'));

/**
 * CSRF
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

$pdo = db();

/**
 * $uid — пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if (!personal_file_can_manage($roles)) {
  flash('Доступ запрещён', 'danger', 1);
  redirect_return('/adm/index.php?m=personal_file');
}

/**
 * Входные поля
 */
$clientId = (int)($_POST['client_id'] ?? 0);
$typeId = (int)($_POST['type_id'] ?? 0);
$ttlId = (int)($_POST['ttl_id'] ?? 0);
$login = trim((string)($_POST['login'] ?? ''));
$pass = trim((string)($_POST['password'] ?? ''));
$pass2 = trim((string)($_POST['password_confirm'] ?? ''));
$remindAt = trim((string)($_POST['remind_at'] ?? ''));

if ($clientId <= 0 || $typeId <= 0 || $ttlId <= 0) {
  audit_log('personal_file', 'access_add', 'warn', [
    'reason' => 'invalid_ids',
    'client_id' => $clientId,
    'type_id' => $typeId,
    'ttl_id' => $ttlId,
  ], 'personal_file_access', null, $uid, $actorRole);
  flash('Некорректные данные', 'warn');
  redirect_return('/adm/index.php?m=personal_file&client_id=' . $clientId);
}

if ($login === '' || $pass === '') {
  audit_log('personal_file', 'access_add', 'warn', [
    'reason' => 'empty_login_or_pass',
    'client_id' => $clientId,
  ], 'personal_file_access', null, $uid, $actorRole);
  flash('Логин и пароль обязательны', 'warn');
  redirect_return('/adm/index.php?m=personal_file&client_id=' . $clientId);
}

if ($pass !== $pass2) {
  audit_log('personal_file', 'access_add', 'warn', [
    'reason' => 'pass_mismatch',
    'client_id' => $clientId,
  ], 'personal_file_access', null, $uid, $actorRole);
  flash('Пароли не совпадают', 'warn');
  redirect_return('/adm/index.php?m=personal_file&client_id=' . $clientId);
}

if (!crypto_is_configured()) {
  audit_log('personal_file', 'access_add', 'error', [
    'reason' => 'crypto_not_configured',
    'client_id' => $clientId,
  ], 'personal_file_access', null, $uid, $actorRole);
  flash('Шифрование не настроено', 'danger', 1);
  redirect_return('/adm/index.php?m=personal_file&client_id=' . $clientId);
}

/**
 * Получаем TTL
 */
$ttl = null;
try {
  $st = $pdo->prepare("
    SELECT id, months, is_permanent
    FROM " . PERSONAL_FILE_ACCESS_TTLS_TABLE . "
    WHERE id = :id
    LIMIT 1
  ");
  $st->execute([':id' => $ttlId]);
  $ttl = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $ttl = null;
}

if (!$ttl) {
  audit_log('personal_file', 'access_add', 'warn', [
    'reason' => 'ttl_not_found',
    'ttl_id' => $ttlId,
    'client_id' => $clientId,
  ], 'personal_file_access', null, $uid, $actorRole);
  flash('Срок не найден', 'warn');
  redirect_return('/adm/index.php?m=personal_file&client_id=' . $clientId);
}

$isPermanent = ((int)($ttl['is_permanent'] ?? 0) === 1);
$months = (int)($ttl['months'] ?? 0);
$expiresAt = null;
if (!$isPermanent && $months > 0) {
  $dt = new DateTime();
  $dt->modify('+' . $months . ' months');
  $expiresAt = $dt->format('Y-m-d');
}

/**
 * Шифруем логин/пароль
 */
try {
  $loginEnc = crypto_encrypt($login);
  $passEnc = crypto_encrypt($pass);
} catch (Throwable $e) {
  audit_log('personal_file', 'access_add', 'error', [
    'reason' => 'encrypt_failed',
    'client_id' => $clientId,
    'error' => $e->getMessage(),
  ], 'personal_file_access', null, $uid, $actorRole);
  flash('Ошибка шифрования', 'danger', 1);
  redirect_return('/adm/index.php?m=personal_file&client_id=' . $clientId);
}

/**
 * Сохраняем доступ
 */
try {
  $st = $pdo->prepare("
    INSERT INTO " . PERSONAL_FILE_ACCESS_TABLE . "
      (client_id, type_id, ttl_id, login_enc, pass_enc, expires_at, created_by, created_at, updated_at)
    VALUES
      (:client_id, :type_id, :ttl_id, :login_enc, :pass_enc, :expires_at, :created_by, NOW(), NOW())
  ");
  $st->execute([
    ':client_id' => $clientId,
    ':type_id' => $typeId,
    ':ttl_id' => $ttlId,
    ':login_enc' => $loginEnc,
    ':pass_enc' => $passEnc,
    ':expires_at' => $expiresAt,
    ':created_by' => $uid,
  ]);
  $accessId = (int)$pdo->lastInsertId();

  if ($remindAt !== '') {
    try {
      $st2 = $pdo->prepare("
        INSERT INTO " . PERSONAL_FILE_ACCESS_REMINDERS_TABLE . "
          (access_id, client_id, remind_at, status, created_at)
        VALUES
          (:access_id, :client_id, :remind_at, 'pending', NOW())
      ");
      $st2->execute([
        ':access_id' => $accessId,
        ':client_id' => $clientId,
        ':remind_at' => $remindAt,
      ]);
    } catch (Throwable $e) {
      // Заглушка не критична
    }
  }

  audit_log('personal_file', 'access_add', 'info', [
    'client_id' => $clientId,
    'access_id' => $accessId,
    'type_id' => $typeId,
    'ttl_id' => $ttlId,
    'expires_at' => $expiresAt,
  ], 'personal_file_access', $accessId, $uid, $actorRole);
  flash('Доступ добавлен', 'ok');
} catch (Throwable $e) {
  audit_log('personal_file', 'access_add', 'error', [
    'client_id' => $clientId,
    'error' => $e->getMessage(),
  ], 'personal_file_access', null, $uid, $actorRole);
  flash('Ошибка добавления доступа', 'danger', 1);
}

redirect_return('/adm/index.php?m=personal_file&client_id=' . $clientId);
