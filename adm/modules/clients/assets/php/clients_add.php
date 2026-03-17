<?php
/**
 * FILE: /adm/modules/clients/assets/php/clients_add.php
 * ROLE: add - создание клиента
 * CONNECTIONS:
 *  - db()
 *  - csrf_check()
 *  - flash()
 *  - redirect()
 *  - module_allowed_roles(), acl_guard()
 *  - clients_t()
 *
 * СПИСОК ФУНКЦИЙ:
 * clients_gen_tmp_password(): string
 *   Генерация временного пароля
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once ROOT_PATH . '/core/upload.php';
require_once __DIR__ . '/clients_i18n.php';

acl_guard(module_allowed_roles('clients'));

/**
 * CSRF
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

$pdo = db();

/**
 * Actor
 */
$actorId = (int)auth_user_id();
$actorRole = (string)auth_user_role();

/**
 * clients_gen_tmp_password()
 * $pass - временный пароль (короткий, для первичного доступа)
 */
function clients_gen_tmp_password(): string
{
  return 'Crm' . bin2hex(random_bytes(4));
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

/**
 * Валидация
 */
if ($firstName === '' || $phone === '') {
  audit_log('clients', 'create', 'warn', [
    'reason' => 'validation',
    'first_name' => ($firstName !== ''),
    'phone' => ($phone !== '' ? $phone : null),
  ], 'client', null, $actorId, $actorRole);
  flash(clients_t('clients.flash_required_name_phone'), 'warn');
  redirect_return('/adm/index.php?m=clients');
}

if (!in_array($status, ['active', 'blocked'], true)) $status = 'active';

/**
 * Проверка уникальности телефона
 */
$st = $pdo->prepare("SELECT id FROM clients WHERE phone = ? LIMIT 1");
$st->execute([$phone]);
$existsId = (int)($st->fetchColumn() ?: 0);

if ($existsId > 0) {
  audit_log('clients', 'create', 'warn', [
    'reason' => 'duplicate_phone',
    'phone' => $phone,
    'existing_id' => $existsId,
  ], 'client', $existsId, $actorId, $actorRole);
  flash(clients_t('clients.flash_duplicate_phone', ['id' => $existsId]), 'warn');
  redirect_return('/adm/index.php?m=clients');
}

/**
 * Генерация временного пароля
 */
$tmpPass  = clients_gen_tmp_password();
$passHash = password_hash($tmpPass, PASSWORD_DEFAULT);

try {
  $st = $pdo->prepare("\n    INSERT INTO " . CLIENTS_TABLE . "\n      (first_name, last_name, middle_name, phone, email, inn, birth_date, pass_hash, pass_is_temp, status, created_at, updated_at)\n    VALUES\n      (:first_name, :last_name, :middle_name, :phone, :email, :inn, :birth_date, :pass_hash, 1, :status, NOW(), NOW())\n  ");

  $st->execute([
    ':first_name'  => $firstName,
    ':last_name'   => ($lastName !== '' ? $lastName : null),
    ':middle_name' => ($middleName !== '' ? $middleName : null),
    ':phone'       => $phone,
    ':email'       => ($email !== '' ? $email : null),
    ':inn'         => ($inn !== '' ? $inn : null),
    ':birth_date'  => ($birthDate !== '' ? $birthDate : null),
    ':pass_hash'   => $passHash,
    ':status'      => $status,
  ]);

  $newId = (int)$pdo->lastInsertId();

  /**
   * Фото (если загружено)
   */
  if (isset($_FILES['photo']) && (int)($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $dirAbs = ROOT_PATH . '/storage/clients_file/' . $newId . '/profile';
    $saved = upload_save($_FILES['photo'], $dirAbs);
    if (!empty($saved['ok'])) {
      $photoPath = 'storage/clients_file/' . $newId . '/profile/' . $saved['name'];
      $pdo->prepare("UPDATE " . CLIENTS_TABLE . " SET photo_path = :p WHERE id = :id LIMIT 1")
        ->execute([':p' => $photoPath, ':id' => $newId]);
    }
  }

  audit_log('clients', 'create', 'info', [
    'id' => $newId,
    'phone' => $phone,
    'status' => $status,
    'email' => ($email !== '' ? $email : null),
    'inn' => ($inn !== '' ? $inn : null),
  ], 'client', $newId, $actorId, $actorRole);

  /**
   * На текущем этапе SMTP нет в core, поэтому пароль отдаём через flash (одноразово).
   * Позже заменится на отправку на email.
   */
  if ($email !== '') {
    flash(clients_t('clients.flash_client_created_with_email', ['password' => $tmpPass]), 'ok');
  } else {
    flash(clients_t('clients.flash_client_created', ['password' => $tmpPass]), 'ok');
  }
} catch (Throwable $e) {
  audit_log('clients', 'create', 'error', [
    'phone' => $phone,
    'error' => $e->getMessage(),
  ], 'client', null, $actorId, $actorRole);
  flash(clients_t('clients.flash_create_error'), 'danger', 1);
}

redirect_return('/adm/index.php?m=clients');