<?php
/**
 * FILE: /adm/modules/tg_system_clients/assets/php/tg_system_clients_generate_link.php
 * ROLE: generate_link — генерация короткого кода привязки клиента
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
  $token = tg_system_clients_generate_link_token($pdo, $targetClientId, $uid);

  audit_log('tg_system_clients', 'generate_link', 'info', [
    'target_client_id' => $targetClientId,
  ], 'module', null, $uid, $actorRole);

  flash('Код создан: ' . $token . '. Клиенту нужно отправить этот код боту отдельным сообщением.', 'ok');
} catch (Throwable $e) {
  audit_log('tg_system_clients', 'generate_link', 'error', [
    'target_client_id' => $targetClientId,
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);
  flash('Ошибка генерации кода привязки', 'danger', 1);
}

redirect('/adm/index.php?m=tg_system_clients');