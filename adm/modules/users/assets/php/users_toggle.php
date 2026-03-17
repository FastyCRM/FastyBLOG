<?php
/**
 * FILE: /adm/modules/users/assets/php/users_toggle.php
 * ROLE: toggle - блок/разблок пользователя
 * CONNECTIONS:
 *  - db()
 *  - csrf_check()
 *  - flash()
 *  - redirect()
 *  - auth_user_id(), auth_user_role()
 *  - module_allowed_roles(), acl_guard()
 *  - users_t(), users_status_label()
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

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
  audit_log('users', 'toggle', 'warn', [
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
 * Нельзя блокировать самого себя
 */
if ($id === $actorId) {
  audit_log('users', 'toggle', 'warn', [
    'reason' => 'self_block',
    'id' => $id,
  ], 'user', $id, $actorId, $actorRole);
  flash(users_t('users.flash_self_block_forbidden'), 'warn');
  redirect('/adm/index.php?m=users');
}

/**
 * $pdo - соединение
 */
$pdo = db();

/**
 * Значения по умолчанию для контекста catch.
 */
$cur = 'active';
$next = 'blocked';

try {
  /**
   * $st - берём текущий статус
   */
  $st = $pdo->prepare("SELECT status FROM users WHERE id = ? LIMIT 1");
  $st->execute([$id]);

  /**
   * $cur - текущий статус
   */
  $cur = (string)($st->fetchColumn() ?: 'active');

  /**
   * $next - следующий статус
   */
  $next = ($cur === 'blocked') ? 'active' : 'blocked';

  /**
   * Обновляем
   */
  $st = $pdo->prepare("UPDATE users SET status = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
  $st->execute([$next, $id]);

  audit_log('users', 'toggle', 'info', [
    'id' => $id,
    'from' => $cur,
    'to' => $next,
  ], 'user', $id, $actorId, $actorRole);

  flash(users_t('users.flash_status_updated', ['status' => users_status_label($next)]), 'ok');
} catch (Throwable $e) {
  audit_log('users', 'toggle', 'error', [
    'id' => $id,
    'from' => $cur,
    'to' => $next,
    'error' => $e->getMessage(),
  ], 'user', $id, $actorId, $actorRole);
  flash(users_t('users.flash_status_change_error'), 'danger', 1);
}

redirect('/adm/index.php?m=users');
