<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_api_notes.php
 * ROLE: API — список заметок клиента
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php
 *  - /adm/modules/personal_file/assets/php/personal_file_lib.php
 *  - /core/response.php (json_ok/json_err)
 *
 * NOTES:
 *  - Возвращает заметки и список файлов без содержимого.
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

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$notes = personal_file_get_notes($pdo, $clientId, 200, $dateFrom !== '' ? $dateFrom : null, $dateTo !== '' ? $dateTo : null);
$noteIds = array_map(static function ($n) { return (int)($n['id'] ?? 0); }, $notes);
$noteFiles = $noteIds ? personal_file_get_note_files_map($pdo, $noteIds) : [];

$items = [];
foreach ($notes as $n) {
  $nid = (int)($n['id'] ?? 0);
  $files = $noteFiles[$nid] ?? [];
  $items[] = [
    'id' => $nid,
    'user_name' => (string)($n['user_name'] ?? ''),
    'note_text' => (string)($n['note_text'] ?? ''),
    'created_at' => (string)($n['created_at'] ?? ''),
    'files' => array_map(static function ($f) {
      return [
        'id' => (int)($f['id'] ?? 0),
        'orig_name' => (string)($f['orig_name'] ?? ''),
        'is_image' => ((int)($f['is_image'] ?? 0) === 1),
      ];
    }, $files),
  ];
}

json_ok([
  'client_id' => $clientId,
  'notes' => $items,
]);
