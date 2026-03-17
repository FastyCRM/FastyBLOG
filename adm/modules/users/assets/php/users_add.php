<?php
/**
 * FILE: /adm/modules/users/assets/php/users_add.php
 * ROLE: add - создание пользователя
 * CONNECTIONS:
 *  - db()
 *  - csrf_check()
 *  - flash()
 *  - redirect()
 *  - auth_user_role(), auth_user_id()
 *  - module_allowed_roles(), acl_guard()
 *  - users_t()
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/users_i18n.php';

/**
 * ACL: доступ к модулю users
 */
acl_guard(module_allowed_roles('users'));

/**
 * CSRF: проверяем токен
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

/**
 * $pdo - соединение с БД
 */
$pdo = db();

/**
 * $actorId - кто выполняет действие
 */
$actorId = (int)auth_user_id();

/**
 * $actorRole - роль актёра
 */
$actorRole = (string)auth_user_role();

/**
 * $isAdmin - админ
 */
$isAdmin = ($actorRole === 'admin');

/**
 * Входные поля
 */
$name = trim((string)($_POST['name'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$status = (string)($_POST['status'] ?? 'active');
$uiTheme = (string)($_POST['ui_theme'] ?? 'dark');

/**
 * Мини-валидация
 */
if ($name === '' || $phone === '' || $email === '') {
  audit_log('users', 'create', 'warn', [
    'reason' => 'validation',
    'name' => ($name !== ''),
    'phone' => ($phone !== '' ? $phone : null),
    'email' => ($email !== '' ? $email : null),
  ], 'user', null, $actorId, $actorRole);
  flash(users_t('users.flash_required_fields'), 'warn');
  redirect('/adm/index.php?m=users');
}

/**
 * Нормализация
 */
if (!in_array($status, ['active', 'blocked'], true)) $status = 'active';
if (!in_array($uiTheme, ['dark', 'light', 'color'], true)) $uiTheme = 'dark';

/**
 * $roleIds - роли для назначения
 */
$roleIds = [];

if ($isAdmin) {
  /**
   * Admin: роли из формы
   */
  $in = $_POST['role_ids'] ?? [];
  if (is_array($in)) {
    foreach ($in as $rid) {
      $rid = (int)$rid;
      if ($rid > 0) $roleIds[] = $rid;
    }
  }
} else {
  /**
   * Manager: принудительно роль user (по коду)
   */
  $st = $pdo->prepare("SELECT id FROM roles WHERE code='user' LIMIT 1");
  $st->execute();
  $roleIds[] = (int)($st->fetchColumn() ?: 0);
}

/**
 * $roleIds - чистим
 */
$roleIds = array_values(array_unique(array_filter($roleIds)));
if (!$roleIds) {
  audit_log('users', 'create', 'error', [
    'reason' => 'no_roles',
    'phone' => $phone,
  ], 'user', null, $actorId, $actorRole);
  flash(users_t('users.flash_role_assign_failed'), 'danger', 1);
  redirect('/adm/index.php?m=users');
}

/**
 * $tmpPass - временный пароль
 */
$tmpPass = 'Crm' . bin2hex(random_bytes(4));

/**
 * $passHash - хэш пароля
 */
$passHash = password_hash($tmpPass, PASSWORD_DEFAULT);

try {
  $pdo->beginTransaction();

  /**
   * Создаём пользователя
   */
  $st = $pdo->prepare("
    INSERT INTO users (email, phone, pass_hash, name, status, ui_theme, created_at)
    VALUES (:email, :phone, :pass_hash, :name, :status, :ui_theme, NOW())
  ");

  $st->execute([
    ':email' => ($email !== '' ? $email : null),
    ':phone' => $phone,
    ':pass_hash' => $passHash,
    ':name' => $name,
    ':status' => $status,
    ':ui_theme' => $uiTheme,
  ]);

  /**
   * $newId - id созданного пользователя
   */
  $newId = (int)$pdo->lastInsertId();

  /**
   * Назначаем роли
   */
  $st = $pdo->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
  foreach ($roleIds as $rid) {
    $st->execute([$newId, (int)$rid]);
  }

  $pdo->commit();

  audit_log('users', 'create', 'info', [
    'id' => $newId,
    'phone' => $phone,
    'email' => ($email !== '' ? $email : null),
    'status' => $status,
    'roles' => $roleIds,
  ], 'user', $newId, $actorId, $actorRole);

  /**
   * Возвращаем временный пароль через flash (на dev достаточно)
   */
  flash(users_t('users.flash_user_created', ['password' => $tmpPass]), 'ok');
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  audit_log('users', 'create', 'error', [
    'phone' => $phone,
    'error' => $e->getMessage(),
  ], 'user', null, $actorId, $actorRole);
  flash(users_t('users.flash_user_create_error'), 'danger', 1);
}

redirect('/adm/index.php?m=users');
