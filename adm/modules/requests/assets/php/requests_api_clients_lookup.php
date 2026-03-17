<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_api_clients_lookup.php
 * ROLE: Совместимый API поиска клиентов для requests (через единый поиск clients)
 * CONNECTIONS:
 *  - /adm/modules/requests/settings.php
 *  - /adm/modules/requests/assets/php/requests_lib.php
 *  - /adm/modules/clients/assets/php/clients_search_lib.php
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/requests_lib.php';
require_once ROOT_PATH . '/adm/modules/clients/settings.php';
require_once ROOT_PATH . '/adm/modules/clients/assets/php/clients_search_lib.php';

acl_guard(module_allowed_roles('requests'));

/**
 * $uid — ID пользователя.
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
/**
 * $roles — роли пользователя.
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

if (!requests_is_admin($roles) && !requests_is_manager($roles)) {
  json_err('Forbidden', 403);
}

/**
 * $q — строка поиска.
 */
$q = trim((string)($_GET['q'] ?? ''));
/**
 * $limit — лимит выдачи.
 */
$limit = (int)($_GET['limit'] ?? 10);
if ($limit < 1) $limit = 1;
if ($limit > 100) $limit = 100;

if ($q === '') {
  json_ok(['items' => []]);
}

/**
 * $pdo — соединение с БД.
 */
$pdo = db();
/**
 * $items — найденные клиенты.
 */
$items = clients_search_items($pdo, $q, $uid, $roles, $limit);

json_ok([
  'items' => $items,
]);
