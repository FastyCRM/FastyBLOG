<?php
/**
 * FILE: /adm/modules/tg_system_clients/assets/php/tg_system_clients_api_clients_search.php
 * ROLE: API поиска клиентов для модуля tg_system_clients
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/tg_system_clients_lib.php';
require_once ROOT_PATH . '/adm/modules/clients/settings.php';
require_once ROOT_PATH . '/adm/modules/clients/assets/php/clients_search_lib.php';

acl_guard(module_allowed_roles('tg_system_clients'));

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

if (!tg_system_clients_is_manage_role($roles)) {
  json_err('Forbidden', 403);
}

$q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 20);
if ($limit < 1) $limit = 1;
if ($limit > 200) $limit = 200;

if ($q === '') {
  json_ok(['items' => []]);
}

$pdo = db();
$baseItems = clients_search_items($pdo, $q, $uid, $roles, $limit);
if (!$baseItems) {
  json_ok(['items' => []]);
}

$clientIds = [];
foreach ($baseItems as $item) {
  $id = (int)($item['id'] ?? 0);
  if ($id > 0) $clientIds[] = $id;
}
$clientIds = array_values(array_unique($clientIds));

$linkMap = [];
if ($clientIds) {
  $in = implode(',', array_fill(0, count($clientIds), '?'));
  $st = $pdo->prepare("\n    SELECT client_id, chat_id, username, is_active, linked_at, last_seen_at\n    FROM " . TG_SYSTEM_CLIENTS_TABLE_LINKS . "\n    WHERE client_id IN ($in)\n  ");
  $st->execute($clientIds);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as $row) {
    $cid = (int)($row['client_id'] ?? 0);
    if ($cid <= 0) continue;
    $linkMap[$cid] = [
      'chat_id' => trim((string)($row['chat_id'] ?? '')),
      'username' => trim((string)($row['username'] ?? '')),
      'is_active' => ((int)($row['is_active'] ?? 0) === 1) ? 1 : 0,
      'linked_at' => trim((string)($row['linked_at'] ?? '')),
      'last_seen_at' => trim((string)($row['last_seen_at'] ?? '')),
    ];
  }
}

$items = [];
foreach ($baseItems as $item) {
  $cid = (int)($item['id'] ?? 0);
  if ($cid <= 0) continue;

  $link = $linkMap[$cid] ?? [
    'chat_id' => '',
    'username' => '',
    'is_active' => 0,
    'linked_at' => '',
    'last_seen_at' => '',
  ];

  $items[] = [
    'id' => $cid,
    'name' => (string)($item['display_name'] ?? ''),
    'first_name' => (string)($item['first_name'] ?? ''),
    'last_name' => (string)($item['last_name'] ?? ''),
    'middle_name' => (string)($item['middle_name'] ?? ''),
    'phone' => (string)($item['phone'] ?? ''),
    'email' => (string)($item['email'] ?? ''),
    'inn' => (string)($item['inn'] ?? ''),
    'status' => 'active',
    'chat_id' => (string)$link['chat_id'],
    'username' => (string)$link['username'],
    'is_active' => (int)$link['is_active'],
    'linked_at' => (string)$link['linked_at'],
    'last_seen_at' => (string)$link['last_seen_at'],
    'label' => (string)($item['label'] ?? ''),
  ];
}

json_ok(['items' => $items]);