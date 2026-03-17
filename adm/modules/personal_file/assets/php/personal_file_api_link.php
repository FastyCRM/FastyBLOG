<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_api_link.php
 * ROLE: API — ссылка на личное дело клиента
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php
 *  - /adm/modules/personal_file/assets/php/personal_file_lib.php
 *  - /core/response.php (json_ok/json_err)
 *
 * NOTES:
 *  - Используется для быстрого построения ссылки на личное дело.
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

$client = personal_file_get_client($pdo, $clientId);
if (!$client) {
  json_err('Client not found', 404);
}

json_ok([
  'client_id' => $clientId,
  'url' => url('/adm/index.php?m=personal_file&client_id=' . $clientId),
]);
