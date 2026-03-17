<?php
/**
 * FILE: /adm/modules/clients/assets/php/clients_update.php
 * ROLE: update - редактирование клиента
 * CONNECTIONS:
 *  - db()
 *  - csrf_check()
 *  - flash()
 *  - redirect()
 *  - module_allowed_roles(), acl_guard()
 *  - clients_t()
 *
 * СПИСОК ФУНКЦИЙ: (нет)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once ROOT_PATH . '/core/upload.php';
require_once __DIR__ . '/clients_i18n.php';

acl_guard(module_allowed_roles('clients'));

$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

$pdo = db();

/**
 * Actor
 */
$actorId = (int)auth_user_id();
$actorRole = (string)auth_user_role();

/**
 * $id - id клиента
 */
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  audit_log('clients', 'update', 'warn', [
    'reason' => 'invalid_id',
    'id' => $id,
  ], 'client', null, $actorId, $actorRole);
  flash(clients_t('clients.flash_invalid_id'), 'warn');
  redirect_return('/adm/index.php?m=clients');
}

/**
 * Входные поля
 */
$firstName  = trim((string)($_POST['first_name'] ?? ''));
$lastName   = trim((string)($_POST['last_name'] ?? ''));
$middleName = trim((string)($_POST['middle_name'] ?? ''));
$phone      = trim((string)($_POST['phone'] ?? ''));
$email      = trim((string)($_POST['email'] ?? ''));
$inn        = trim((string)($_POST['inn'] ?? ''));
$birthDate  = trim((string)($_POST['birth_date'] ?? ''));
$status     = (string)($_POST['status'] ?? 'active');

if ($firstName === '' || $phone === '') {
  audit_log('clients', 'update', 'warn', [
    'reason' => 'validation',
    'id' => $id,
    'first_name' => ($firstName !== ''),
    'phone' => ($phone !== '' ? $phone : null),
  ], 'client', $id, $actorId, $actorRole);
  flash(clients_t('clients.flash_required_name_phone'), 'warn');
  redirect_return('/adm/index.php?m=clients');
}

if (!in_array($status, ['active', 'blocked'], true)) $status = 'active';

/**
 * Проверка уникальности телефона (кроме текущего клиента)
 */
$st = $pdo->prepare("SELECT id FROM clients WHERE phone = ? AND id <> ? LIMIT 1");
$st->execute([$phone, $id]);
$dupId = (int)($st->fetchColumn() ?: 0);

if ($dupId > 0) {
  audit_log('clients', 'update', 'warn', [
    'reason' => 'duplicate_phone',
    'id' => $id,
    'phone' => $phone,
    'existing_id' => $dupId,
  ], 'client', $id, $actorId, $actorRole);
  flash(clients_t('clients.flash_phone_used_by_other', ['id' => $dupId]), 'warn');
  redirect_return('/adm/index.php?m=clients');
}

try {
  /**
   * Текущее фото
   */
  $currentPhoto = '';
  $stPhoto = $pdo->prepare("SELECT photo_path FROM " . CLIENTS_TABLE . " WHERE id = :id LIMIT 1");
  $stPhoto->execute([':id' => $id]);
  $currentPhoto = (string)($stPhoto->fetchColumn() ?: '');

  $photoPath = $currentPhoto;
  if (isset($_FILES['photo']) && (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $dirAbs = ROOT_PATH . '/storage/clients_file/' . $id . '/profile';
    $saved = upload_save($_FILES['photo'], $dirAbs);
    if (!empty($saved['ok'])) {
      $photoPath = 'storage/clients_file/' . $id . '/profile/' . $saved['name'];
    }
  }

  $st = $pdo->prepare("
    UPDATE " . CLIENTS_TABLE . "
    SET
      first_name = :first_name,
      last_name = :last_name,
      middle_name = :middle_name,
      phone = :phone,
      email = :email,
      inn = :inn,
      birth_date = :birth_date,
      photo_path = :photo_path,
      status = :status,
      updated_at = NOW()
    WHERE id = :id
    LIMIT 1
  ");

  $st->execute([
    ':first_name'  => $firstName,
    ':last_name'   => ($lastName !== '' ? $lastName : null),
    ':middle_name' => ($middleName !== '' ? $middleName : null),
    ':phone'       => $phone,
    ':email'       => ($email !== '' ? $email : null),
    ':inn'         => ($inn !== '' ? $inn : null),
    ':birth_date'  => ($birthDate !== '' ? $birthDate : null),
    ':photo_path'  => ($photoPath !== '' ? $photoPath : null),
    ':status'      => $status,
    ':id'          => $id,
  ]);

  audit_log('clients', 'update', 'info', [
    'id' => $id,
    'phone' => $phone,
    'status' => $status,
    'email' => ($email !== '' ? $email : null),
    'inn' => ($inn !== '' ? $inn : null),
  ], 'client', $id, $actorId, $actorRole);

  flash(clients_t('clients.flash_client_updated'), 'ok');
} catch (Throwable $e) {
  audit_log('clients', 'update', 'error', [
    'id' => $id,
    'phone' => $phone,
    'error' => $e->getMessage(),
  ], 'client', $id, $actorId, $actorRole);
  flash(clients_t('clients.flash_update_error'), 'danger', 1);
}

redirect_return('/adm/index.php?m=clients');
