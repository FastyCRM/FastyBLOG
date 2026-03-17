<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_api_services.php
 * ROLE: API — список услуг клиента
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php
 *  - /adm/modules/personal_file/assets/php/personal_file_lib.php
 *  - /core/response.php (json_ok/json_err)
 *
 * NOTES:
 *  - Источник данных: request_invoices + request_invoice_items.
 *
 * СПИСОК ФУНКЦИЙ: (нет)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/personal_file_lib.php';

acl_guard(module_allowed_roles('personal_file'));

$pdo = db();

$clientId = (int)($_GET['client_id'] ?? 0);
if ($clientId <= 0) {
  json_err('Bad client_id', 400);
}

$rows = personal_file_get_services($pdo, $clientId, 200);
$items = [];
foreach ($rows as $r) {
  $items[] = [
    'id' => (int)($r['id'] ?? 0),
    'service_name' => (string)($r['service_name'] ?? ''),
    'qty' => (int)($r['qty'] ?? 0),
    'price' => (int)($r['price'] ?? 0),
    'total' => (int)($r['total'] ?? 0),
    'created_at' => (string)($r['created_at'] ?? ''),
    'invoice_date' => (string)($r['invoice_date'] ?? ''),
    'request_id' => (int)($r['request_id'] ?? 0),
  ];
}

json_ok([
  'client_id' => $clientId,
  'services' => $items,
]);
