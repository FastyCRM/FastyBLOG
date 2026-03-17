<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_api_accesses.php
 * ROLE: API — список доступов клиента (без логинов/паролей)
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php
 *  - /adm/modules/personal_file/assets/php/personal_file_lib.php
 *  - /core/response.php (json_ok/json_err)
 *
 * NOTES:
 *  - Без логинов/паролей.
 *
 * СПИСОК ФУНКЦИЙ: (нет)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/personal_file_lib.php';

acl_guard(module_allowed_roles('personal_file'));

$pdo = db();
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

$clientId = (int)($_GET['client_id'] ?? 0);
if ($clientId <= 0) {
  json_err('Bad client_id', 400);
}

if (!personal_file_user_can_access($pdo, $clientId, $uid, $roles)) {
  json_err('Forbidden', 403);
}

$rows = personal_file_get_accesses($pdo, $clientId);
$items = [];
foreach ($rows as $r) {
  $items[] = [
    'id' => (int)($r['id'] ?? 0),
    'type_name' => (string)($r['type_name'] ?? ''),
    'ttl_name' => (string)($r['ttl_name'] ?? ''),
    'expires_at' => (string)($r['expires_at'] ?? ''),
    'created_at' => (string)($r['created_at'] ?? ''),
  ];
}

json_ok([
  'client_id' => $clientId,
  'accesses' => $items,
]);
