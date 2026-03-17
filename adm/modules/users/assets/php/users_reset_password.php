<?php
/**
 * FILE: /adm/modules/users/assets/php/users_reset_password.php
 * ROLE: reset_password - сброс пароля и отправка на email
 * CONNECTIONS:
 *  - db()
 *  - csrf_check()
 *  - flash()
 *  - redirect()
 *  - auth_user_id(), auth_user_role()
 *  - module_allowed_roles(), acl_guard()
 *  - mailer_send() из /core/mailer.php
 *  - users_t()
 *
 * NOTES:
 *  - Отправка письма идёт через SMTP (core/mailer.php), а не через mail()
 *  - Если SMTP не настроен/не отправилось - показываем пароль через flash (как dev fallback)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once ROOT_PATH . '/core/mailer.php';
require_once __DIR__ . '/users_i18n.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('users'));

/**
 * CSRF
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

/**
 * $id - пользователь
 */
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  audit_log('users', 'reset_password', 'warn', [
    'reason' => 'invalid_id',
    'id' => $id,
  ], 'user', null, (int)auth_user_id(), (string)auth_user_role());
  flash(users_t('users.flash_invalid_user'), 'warn');
  redirect('/adm/index.php?m=users');
}

/**
 * $actorId - кто нажал
 */
$actorId = (int)auth_user_id();
/**
 * $actorRole - роль актёра
 */
$actorRole = (string)auth_user_role();

/**
 * Защита: не сбрасываем себе (чтобы не запереть)
 */
if ($id === $actorId) {
  audit_log('users', 'reset_password', 'warn', [
    'reason' => 'self_reset_blocked',
    'id' => $id,
  ], 'user', $id, $actorId, $actorRole);
  flash(users_t('users.flash_self_reset_blocked'), 'warn');
  redirect('/adm/index.php?m=users');
}

/**
 * $pdo - соединение
 */
$pdo = db();

try {
  /**
   * $st - берём email
   */
  $st = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
  $st->execute([$id]);

  /**
   * $email - email пользователя
   */
  $email = trim((string)($st->fetchColumn() ?: ''));

  if ($email === '') {
    audit_log('users', 'reset_password', 'warn', [
      'reason' => 'no_email',
      'id' => $id,
    ], 'user', $id, $actorId, $actorRole);
    flash(users_t('users.flash_no_email_for_reset'), 'warn');
    redirect('/adm/index.php?m=users');
  }

  /**
   * $newPass - новый временный пароль
   */
  $newPass = 'Crm' . bin2hex(random_bytes(5));

  /**
   * $hash - хэш
   */
  $hash = password_hash($newPass, PASSWORD_DEFAULT);

  /**
   * Обновляем пароль
   */
  $st = $pdo->prepare("UPDATE users SET pass_hash = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
  $st->execute([$hash, $id]);

  /**
   * Письмо
   */
  $subject = users_t('users.email_subject_new_password');
  $body = users_t('users.email_body_new_password', ['password' => $newPass]);

  /**
   * SMTP отправка через core/mailer.php
   */
  $mailErr = null;
  $sent = mailer_send($email, $subject, $body, [], $mailErr);

  if ($sent) {
    audit_log('users', 'reset_password', 'info', [
      'id' => $id,
      'email' => $email,
      'mail_sent' => true,
    ], 'user', $id, $actorId, $actorRole);
    flash(users_t('users.flash_password_sent'), 'ok');
  } else {
    audit_log('users', 'reset_password', 'warn', [
      'id' => $id,
      'email' => $email,
      'mail_sent' => false,
      'mail_error' => $mailErr,
    ], 'user', $id, $actorId, $actorRole);
    // dev fallback: показываем пароль, чтобы не блокировать работу
    flash(users_t('users.flash_password_mail_failed', ['password' => $newPass]), 'warn');
  }
} catch (Throwable $e) {
  audit_log('users', 'reset_password', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'user', $id, $actorId, $actorRole);
  flash(users_t('users.flash_password_reset_error'), 'danger', 1);
}

redirect('/adm/index.php?m=users');
