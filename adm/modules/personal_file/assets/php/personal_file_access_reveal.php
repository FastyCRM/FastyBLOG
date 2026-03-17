<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_access_reveal.php
 * ROLE: Раскрытие/копирование логина или пароля
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php
 *  - /adm/modules/personal_file/assets/php/personal_file_lib.php
 *  - /core/crypto.php (crypto_decrypt)
 *  - /core/response.php (json_ok/json_err)
 *
 * NOTES:
 *  - Логирует событие типа access.
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

$pdo = db();

/**
 * $uid — пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

/**
 * Входные поля
 */
$id = (int)($_GET['id'] ?? 0);
$field = (string)($_GET['field'] ?? '');
$mode = (string)($_GET['mode'] ?? 'view');

if ($id <= 0 || !in_array($field, ['login','password'], true)) {
  json_err('Bad request', 400);
}

/**
 * Достаём доступ
 */
$st = $pdo->prepare("
  SELECT id, client_id, login_enc, pass_enc
  FROM " . PERSONAL_FILE_ACCESS_TABLE . "
  WHERE id = :id
  LIMIT 1
");
$st->execute([':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
  json_err('Not found', 404);
}

$clientId = (int)($row['client_id'] ?? 0);

/**
 * Проверка доступа пользователя к вкладке "Доступы"
 */
if (!personal_file_user_can_access($pdo, $clientId, $uid, $roles)) {
  json_err('Forbidden', 403);
}

try {
  $value = '';
  if ($field === 'login') {
    $value = crypto_decrypt((string)($row['login_enc'] ?? ''));
  } else {
    $value = crypto_decrypt((string)($row['pass_enc'] ?? ''));
  }
} catch (Throwable $e) {
  audit_log('personal_file', 'access_reveal', 'error', [
    'id' => $id,
    'field' => $field,
    'error' => $e->getMessage(),
  ], 'personal_file_access', $id, $uid, $actorRole, 'access');
  json_err('Decrypt failed', 500);
}

audit_log('personal_file', $mode === 'copy' ? 'access_copy' : 'access_view', 'info', [
  'id' => $id,
  'client_id' => $clientId,
  'field' => $field,
  'mode' => $mode,
], 'personal_file_access', $id, $uid, $actorRole, 'access');

json_ok(['value' => $value]);
