<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_file_get.php
 * ROLE: Отдача файла заметки/фото клиента
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php
 *  - /adm/modules/personal_file/assets/php/personal_file_lib.php
 *  - /core/response.php (http_404)
 *
 * NOTES:
 *  - Отдаём только файлы внутри storage/clients_file.
 *
 * СПИСОК ФУНКЦИЙ: (нет)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/personal_file_lib.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('personal_file'));

$pdo = db();

/**
 * Параметры
 */
$type = (string)($_GET['type'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$clientId = (int)($_GET['client_id'] ?? 0);

$pathRel = '';
$mime = '';

if ($type === 'photo') {
  if ($clientId <= 0) http_404('Bad client');
  $st = $pdo->prepare("
    SELECT photo_path
    FROM " . PERSONAL_FILE_CLIENTS_TABLE . "
    WHERE id = :id
    LIMIT 1
  ");
  $st->execute([':id' => $clientId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  $pathRel = (string)($row['photo_path'] ?? '');
} else {
  if ($id <= 0) http_404('Bad id');
  $st = $pdo->prepare("
    SELECT file_path, file_mime
    FROM " . PERSONAL_FILE_NOTE_FILES_TABLE . "
    WHERE id = :id
    LIMIT 1
  ");
  $st->execute([':id' => $id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    $pathRel = (string)($row['file_path'] ?? '');
    $mime = (string)($row['file_mime'] ?? '');
  }
}

if ($pathRel === '') {
  http_404('File not found');
}

$pathAbs = ROOT_PATH . '/' . ltrim($pathRel, '/\\');
$real = realpath($pathAbs);
$base = realpath(ROOT_PATH . '/storage/clients_file');

if ($real === false || $base === false || strpos($real, $base) !== 0) {
  http_404('File not found');
}

if (!is_file($real)) {
  http_404('File not found');
}

if ($mime === '' && function_exists('mime_content_type')) {
  $mime = (string)@mime_content_type($real);
}
if ($mime === '') $mime = 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)@filesize($real));
header('Cache-Control: private, max-age=0, no-cache, no-store');
readfile($real);
exit;
