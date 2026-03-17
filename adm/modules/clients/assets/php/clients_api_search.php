<?php
/**
 * FILE: /adm/modules/clients/assets/php/clients_api_search.php
 * ROLE: API поиска клиентов (единый endpoint для модулей)
 * CONNECTIONS:
 *  - /adm/modules/clients/settings.php
 *  - /adm/modules/clients/assets/php/clients_search_lib.php
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/clients_search_lib.php';
require_once __DIR__ . '/clients_i18n.php';

/**
 * $uid — ID текущего пользователя.
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
/**
 * $roles — роли текущего пользователя.
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

/**
 * Доступ к API:
 *  - admin/manager (полный поиск)
 *  - user/specialist (ограниченный поиск только по назначенным клиентам)
 */
if ($uid <= 0) {
  json_err(clients_t('clients.error_forbidden'), 403);
}
if (!clients_search_can_manage($roles) && !clients_search_can_limited($roles)) {
  json_err(clients_t('clients.error_forbidden'), 403);
}

/**
 * $q — строка поиска.
 */
$q = trim((string)($_GET['q'] ?? ''));
/**
 * $limit — ограничение выдачи.
 */
$limit = (int)($_GET['limit'] ?? 10);
if ($limit < 1) $limit = 1;
if ($limit > 100) $limit = 100;

if ($q === '') {
  json_ok([
    'items' => [],
  ]);
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
