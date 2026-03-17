<?php
/**
 * FILE: /adm/modules/clients/assets/php/clients_clear.php
 * ROLE: тестовая очистка всех клиентов
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/clients_i18n.php';

acl_guard(module_allowed_roles('clients'));

$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$actorRole = (string)(function_exists('auth_user_role') ? auth_user_role() : '');
$isAdmin = in_array('admin', $roles, true);

if (!$isAdmin) {
  audit_log('clients', 'clear', 'warn', [
    'reason' => 'forbidden_role',
  ], 'module', null, $uid, $actorRole);
  flash(clients_t('clients.error_access_denied'), 'danger', 1);
  redirect('/adm/index.php?m=clients');
}

$confirm = trim((string)($_POST['confirm_clear'] ?? ''));
if ($confirm !== 'yes') {
  audit_log('clients', 'clear', 'warn', [
    'reason' => 'confirm_required',
  ], 'module', null, $uid, $actorRole);
  flash(clients_t('clients.flash_confirm_required'), 'warn');
  redirect('/adm/index.php?m=clients');
}

try {
  $pdo = db();
  $pdo->beginTransaction();
  $deleted = (int)$pdo->exec("DELETE FROM " . CLIENTS_TABLE);
  $pdo->commit();

  audit_log('clients', 'clear', 'info', [
    'deleted_clients' => $deleted,
  ], 'module', null, $uid, $actorRole);
  flash(clients_t('clients.flash_clear_done', ['count' => $deleted]), 'ok');
} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  audit_log('clients', 'clear', 'error', [
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);
  flash(clients_t('clients.flash_clear_failed'), 'danger', 1);
}

redirect('/adm/index.php?m=clients');
