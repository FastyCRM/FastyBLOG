<?php
/**
 * FILE: /adm/modules/clients/assets/php/clients_reset_password.php
 * ROLE: reset_password - сброс пароля клиента и отправка на email
 * CONNECTIONS:
 *  - db()
 *  - csrf_check()
 *  - flash()
 *  - redirect()
 *  - module_allowed_roles(), acl_guard()
 *  - CLIENTS_TABLE (из /adm/modules/clients/settings.php)
 *  - mailer_send() из /core/mailer.php
 *  - clients_t()
 *
 * NOTES:
 *  - Отправка письма идёт через SMTP (core/mailer.php), а не через mail().
 *  - Если SMTP не настроен/не отправилось - показываем пароль через flash (dev fallback).
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once ROOT_PATH . '/core/mailer.php';
require_once __DIR__ . '/clients_i18n.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('clients'));

/**
 * CSRF
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

/**
 * $id - клиент
 */
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  audit_log('clients', 'reset_password', 'warn', [
    'reason' => 'invalid_id',
    'id' => $id,
  ], 'client', null, (int)auth_user_id(), (string)auth_user_role());
  flash(clients_t('clients.flash_invalid_client'), 'warn');
  redirect('/adm/index.php?m=clients');
}

/**
 * $pdo - соединение
 */
$pdo = db();

/**
 * Actor
 */
$actorId = (int)auth_user_id();
$actorRole = (string)auth_user_role();

try {
  /**
   * $st - берём email клиента
   */
  $st = $pdo->prepare("SELECT email FROM " . CLIENTS_TABLE . " WHERE id = ? LIMIT 1");
  $st->execute([$id]);

  /**
   * $email - email клиента
   */
  $email = trim((string)($st->fetchColumn() ?: ''));

  if ($email === '') {
    audit_log('clients', 'reset_password', 'warn', [
      'reason' => 'no_email',
      'id' => $id,
    ], 'client', $id, $actorId, $actorRole);
    flash(clients_t('clients.flash_no_email_for_reset'), 'warn');
    redirect('/adm/index.php?m=clients');
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
   * Обновляем пароль + отмечаем как временный
   */
  $st = $pdo->prepare("
    UPDATE " . CLIENTS_TABLE . "
    SET pass_hash = ?, pass_is_temp = 1, updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$hash, $id]);

  /**
   * Письмо
   */
  $subject = clients_t('clients.email_subject_new_password');
  $body = clients_t('clients.email_body_new_password', ['password' => $newPass]);

  /**
   * SMTP отправка через core/mailer.php
   */
  $mailErr = null;
  $sent = mailer_send($email, $subject, $body, [], $mailErr);

  if ($sent) {
    audit_log('clients', 'reset_password', 'info', [
      'id' => $id,
      'email' => $email,
      'mail_sent' => true,
    ], 'client', $id, $actorId, $actorRole);
    flash(clients_t('clients.flash_password_sent'), 'ok');
  } else {
    audit_log('clients', 'reset_password', 'warn', [
      'id' => $id,
      'email' => $email,
      'mail_sent' => false,
      'mail_error' => $mailErr,
    ], 'client', $id, $actorId, $actorRole);
    flash(clients_t('clients.flash_password_mail_failed', ['password' => $newPass]), 'warn');
  }

} catch (Throwable $e) {
  audit_log('clients', 'reset_password', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'client', $id, $actorId, $actorRole);
  flash(clients_t('clients.flash_password_reset_error'), 'danger', 1);
}

redirect('/adm/index.php?m=clients');
