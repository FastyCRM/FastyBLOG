<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_client_update.php
 * ROLE: Обновление данных клиента в личном деле
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php
 *  - /adm/modules/personal_file/assets/php/personal_file_lib.php
 *  - /core/upload.php (upload_save)
 *  - /core/flash.php (flash)
 *  - /core/response.php (redirect)
 *
 * NOTES:
 *  - Изменение данных клиента доступно только admin/manager.
 *
 * СПИСОК ФУНКЦИЙ: (нет)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/personal_file_lib.php';
require_once ROOT_PATH . '/core/upload.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('personal_file'));

/**
 * CSRF
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

/**
 * $uid — пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
/**
 * $roles — роли
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!personal_file_can_manage($roles)) {
  flash('Доступ запрещён', 'danger', 1);
  redirect('/adm/index.php?m=personal_file');
}

/**
 * Входные поля
 */
$clientId = (int)($_POST['client_id'] ?? 0);
$firstName = trim((string)($_POST['first_name'] ?? ''));
$lastName = trim((string)($_POST['last_name'] ?? ''));
$middleName = trim((string)($_POST['middle_name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$inn = trim((string)($_POST['inn'] ?? ''));
$birthDate = trim((string)($_POST['birth_date'] ?? ''));

if ($clientId <= 0) {
  audit_log('personal_file', 'client_update', 'warn', [
    'reason' => 'invalid_client_id',
    'client_id' => $clientId,
  ], 'client', null, $uid, auth_user_role());
  flash('Клиент не найден', 'warn');
  redirect_return('/adm/index.php?m=personal_file');
}

if ($firstName === '' || $phone === '') {
  audit_log('personal_file', 'client_update', 'warn', [
    'reason' => 'validation',
    'client_id' => $clientId,
    'first_name' => ($firstName !== ''),
    'phone' => ($phone !== '' ? $phone : null),
  ], 'client', $clientId, $uid, auth_user_role());
  flash('Имя и телефон обязательны', 'warn');
  redirect_return('/adm/index.php?m=personal_file&client_id=' . $clientId);
}

/**
 * БД
 */
$pdo = db();

/**
 * Проверка клиента + текущего фото
 */
$st = $pdo->prepare("
  SELECT id, photo_path
  FROM " . PERSONAL_FILE_CLIENTS_TABLE . "
  WHERE id = :id
  LIMIT 1
");
$st->execute([':id' => $clientId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
  audit_log('personal_file', 'client_update', 'warn', [
    'reason' => 'client_not_found',
    'client_id' => $clientId,
  ], 'client', $clientId, $uid, auth_user_role());
  flash('Клиент не найден', 'warn');
  redirect_return('/adm/index.php?m=personal_file');
}

$currentPhoto = (string)($row['photo_path'] ?? '');

/**
 * Проверка уникальности телефона (кроме текущего клиента)
 */
$st = $pdo->prepare("SELECT id FROM " . PERSONAL_FILE_CLIENTS_TABLE . " WHERE phone = :phone AND id <> :id LIMIT 1");
$st->execute([':phone' => $phone, ':id' => $clientId]);
$dupId = (int)($st->fetchColumn() ?: 0);
if ($dupId > 0) {
  audit_log('personal_file', 'client_update', 'warn', [
    'reason' => 'duplicate_phone',
    'client_id' => $clientId,
    'phone' => $phone,
    'existing_id' => $dupId,
  ], 'client', $clientId, $uid, auth_user_role());
  flash('Телефон уже используется другим клиентом (ID: ' . $dupId . ')', 'warn');
  redirect_return('/adm/index.php?m=personal_file&client_id=' . $clientId);
}

/**
 * Фото (если загружено)
 */
$photoPath = $currentPhoto;
if (isset($_FILES['photo']) && (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
  $dirAbs = personal_file_storage_dir($clientId) . '/profile';
  $saved = upload_save($_FILES['photo'], $dirAbs);
  if (!empty($saved['ok'])) {
    $photoPath = 'storage/clients_file/' . $clientId . '/profile/' . $saved['name'];
  } else {
    audit_log('personal_file', 'client_update', 'warn', [
      'reason' => 'photo_upload_failed',
      'error' => $saved['error'] ?? 'upload_error',
      'client_id' => $clientId,
    ], 'client', $clientId, $uid, auth_user_role());
    flash('Фото не удалось загрузить', 'warn');
  }
}

try {
  $st = $pdo->prepare("
    UPDATE " . PERSONAL_FILE_CLIENTS_TABLE . "
    SET
      first_name = :first_name,
      last_name = :last_name,
      middle_name = :middle_name,
      phone = :phone,
      email = :email,
      inn = :inn,
      birth_date = :birth_date,
      photo_path = :photo_path,
      updated_at = NOW()
    WHERE id = :id
    LIMIT 1
  ");
  $st->execute([
    ':first_name' => $firstName,
    ':last_name' => ($lastName !== '' ? $lastName : null),
    ':middle_name' => ($middleName !== '' ? $middleName : null),
    ':phone' => $phone,
    ':email' => ($email !== '' ? $email : null),
    ':inn' => ($inn !== '' ? $inn : null),
    ':birth_date' => ($birthDate !== '' ? $birthDate : null),
    ':photo_path' => ($photoPath !== '' ? $photoPath : null),
    ':id' => $clientId,
  ]);

  audit_log('personal_file', 'client_update', 'info', [
    'client_id' => $clientId,
    'phone' => $phone,
    'email' => ($email !== '' ? $email : null),
    'inn' => ($inn !== '' ? $inn : null),
    'photo' => ($photoPath !== '' ? 1 : 0),
  ], 'client', $clientId, $uid, auth_user_role());
  flash('Данные сохранены', 'ok');
} catch (Throwable $e) {
  audit_log('personal_file', 'client_update', 'error', [
    'client_id' => $clientId,
    'error' => $e->getMessage(),
  ], 'client', $clientId, $uid, auth_user_role());
  flash('Ошибка сохранения', 'danger', 1);
}

redirect_return('/adm/index.php?m=personal_file&client_id=' . $clientId);
