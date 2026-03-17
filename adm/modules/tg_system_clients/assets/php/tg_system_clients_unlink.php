<?php
/**
 * FILE: /adm/modules/tg_system_clients/assets/php/tg_system_clients_unlink.php
 * ROLE: unlink — ручная отвязка Telegram у клиента
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/tg_system_clients_lib.php';

acl_guard(module_allowed_roles('tg_system_clients'));

$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if (!tg_system_clients_is_manage_role($roles)) {
  flash('Доступ запрещён', 'danger', 1);
  redirect('/adm/index.php?m=tg_system_clients');
}

$targetClientId = (int)($_POST['client_id'] ?? ($_POST['user_id'] ?? 0));
if ($targetClientId <= 0) {
  flash('Некорректный клиент', 'warn');
  redirect('/adm/index.php?m=tg_system_clients');
}

try {
  $pdo = db();
  $result = tg_system_clients_unlink_user($pdo, $targetClientId);

  $hadActiveLink = ((int)($result['had_active_link'] ?? 0) === 1);

  audit_log('tg_system_clients', 'unlink', 'info', [
    'target_client_id' => $targetClientId,
    'had_active_link' => $hadActiveLink ? 1 : 0,
  ], 'module', null, $uid, $actorRole);

  if ($hadActiveLink) {
    flash('Привязка Telegram снята.', 'ok');
  } else {
    flash('Активной привязки не было.', 'warn');
  }
} catch (Throwable $e) {
  audit_log('tg_system_clients', 'unlink', 'error', [
    'target_client_id' => $targetClientId,
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);
  flash('Ошибка отвязки Telegram', 'danger', 1);
}

redirect('/adm/index.php?m=tg_system_clients');