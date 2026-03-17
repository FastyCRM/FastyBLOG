<?php
/**
 * FILE: /adm/modules/clients/assets/php/clients_toggle.php
 * ROLE: toggle - блокировка/разблокировка клиента
 * CONNECTIONS:
 *  - db()
 *  - csrf_check()
 *  - flash()
 *  - redirect()
 *  - module_allowed_roles(), acl_guard()
 *  - clients_t(), clients_status_label()
 *
 * СПИСОК ФУНКЦИЙ: (нет)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
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
  audit_log('clients', 'toggle', 'warn', [
    'reason' => 'invalid_id',
    'id' => $id,
  ], 'client', null, $actorId, $actorRole);
  flash(clients_t('clients.flash_invalid_id'), 'warn');
  redirect('/adm/index.php?m=clients');
}

/**
 * $st - читаем текущий статус
 */
$st = $pdo->prepare("SELECT status FROM " . CLIENTS_TABLE . " WHERE id = ? LIMIT 1");
$st->execute([$id]);
$cur = (string)($st->fetchColumn() ?: '');

if ($cur === '') {
  audit_log('clients', 'toggle', 'warn', [
    'reason' => 'not_found',
    'id' => $id,
  ], 'client', $id, $actorId, $actorRole);
  flash(clients_t('clients.flash_client_not_found'), 'warn');
  redirect('/adm/index.php?m=clients');
}

/**
 * $next - следующий статус
 */
$next = ($cur === 'blocked') ? 'active' : 'blocked';

try {
  $st = $pdo->prepare("UPDATE " . CLIENTS_TABLE . " SET status = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
  $st->execute([$next, $id]);

  audit_log('clients', 'toggle', 'info', [
    'id' => $id,
    'from' => $cur,
    'to' => $next,
  ], 'client', $id, $actorId, $actorRole);

  flash(clients_t('clients.flash_client_status_updated', ['status' => clients_status_label($next)]), 'ok');
} catch (Throwable $e) {
  audit_log('clients', 'toggle', 'error', [
    'id' => $id,
    'from' => $cur,
    'to' => $next,
    'error' => $e->getMessage(),
  ], 'client', $id, $actorId, $actorRole);
  flash(clients_t('clients.flash_status_change_error'), 'danger', 1);
}

redirect('/adm/index.php?m=clients');
