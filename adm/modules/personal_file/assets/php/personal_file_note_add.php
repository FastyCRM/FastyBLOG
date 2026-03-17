<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_note_add.php
 * ROLE: Добавление заметки клиента + файлы
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php
 *  - /adm/modules/personal_file/assets/php/personal_file_lib.php
 *  - /core/upload.php (upload_save)
 *  - /core/flash.php (flash)
 *  - /core/response.php (redirect)
 *
 * NOTES:
 *  - Файлы сохраняются в storage/clients_file/<id>/<date>/.
 *
 * СПИСОК ФУНКЦИЙ: (нет)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/personal_file_lib.php';
require_once ROOT_PATH . '/core/upload.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('personal_file'));

/**
 * CSRF
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

$pdo = db();

/**
 * $uid — пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

/**
 * Входные поля
 */
$clientId = (int)($_POST['client_id'] ?? 0);
$noteText = trim((string)($_POST['note_text'] ?? ''));

if ($clientId <= 0) {
  audit_log('personal_file', 'note_add', 'warn', [
    'reason' => 'invalid_client_id',
    'client_id' => $clientId,
  ], 'personal_file_note', null, $uid, $actorRole);
  flash('Клиент не выбран', 'warn');
  redirect('/adm/index.php?m=personal_file');
}

/**
 * Проверяем: есть ли текст или файлы
 */
$hasFiles = !empty($_FILES['note_files']) && is_array($_FILES['note_files']['name']);
if ($noteText === '' && !$hasFiles) {
  audit_log('personal_file', 'note_add', 'warn', [
    'reason' => 'empty_note',
    'client_id' => $clientId,
  ], 'personal_file_note', null, $uid, $actorRole);
  flash('Текст заметки или файл обязателен', 'warn');
  redirect('/adm/index.php?m=personal_file&client_id=' . $clientId);
}

/**
 * Создаём заметку
 */
try {
  $st = $pdo->prepare("
    INSERT INTO " . PERSONAL_FILE_NOTES_TABLE . " (client_id, user_id, note_text, created_at, updated_at)
    VALUES (:client_id, :user_id, :note_text, NOW(), NOW())
  ");
  $st->execute([
    ':client_id' => $clientId,
    ':user_id' => $uid,
    ':note_text' => $noteText,
  ]);
  $noteId = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
  audit_log('personal_file', 'note_add', 'error', [
    'client_id' => $clientId,
    'error' => $e->getMessage(),
  ], 'personal_file_note', null, $uid, $actorRole);
  flash('Ошибка добавления заметки', 'danger', 1);
  redirect('/adm/index.php?m=personal_file&client_id=' . $clientId);
}

/**
 * Загрузка файлов (если есть)
 */
$filesSaved = 0;
if ($hasFiles) {
  $names = $_FILES['note_files']['name'];
  $tmpNames = $_FILES['note_files']['tmp_name'];
  $errors = $_FILES['note_files']['error'];
  $sizes = $_FILES['note_files']['size'];

  $dateDir = date('Y-m-d');
  $dirAbs = personal_file_note_dir($clientId, $dateDir);

  foreach ($names as $i => $origName) {
    $file = [
      'name' => $origName,
      'tmp_name' => $tmpNames[$i] ?? '',
      'error' => $errors[$i] ?? UPLOAD_ERR_NO_FILE,
      'size' => $sizes[$i] ?? 0,
    ];

    $saved = upload_save($file, $dirAbs);
    if (empty($saved['ok'])) {
      continue;
    }

    $relPath = 'storage/clients_file/' . $clientId . '/' . $dateDir . '/' . $saved['name'];
    $mime = (string)($saved['mime'] ?? '');
    $isImage = (strpos($mime, 'image/') === 0) ? 1 : 0;

    try {
      $stf = $pdo->prepare("
        INSERT INTO " . PERSONAL_FILE_NOTE_FILES_TABLE . "
          (note_id, client_id, file_name, orig_name, file_path, file_ext, file_size, file_mime, is_image, created_at)
        VALUES
          (:note_id, :client_id, :file_name, :orig_name, :file_path, :file_ext, :file_size, :file_mime, :is_image, NOW())
      ");
      $stf->execute([
        ':note_id' => $noteId,
        ':client_id' => $clientId,
        ':file_name' => $saved['name'],
        ':orig_name' => $saved['orig_name'],
        ':file_path' => $relPath,
        ':file_ext' => $saved['ext'],
        ':file_size' => (int)($saved['size'] ?? 0),
        ':file_mime' => $mime,
        ':is_image' => $isImage,
      ]);
      $filesSaved++;
    } catch (Throwable $e) {
      // Игнорируем частичную ошибку, но фиксируем в аудите
      audit_log('personal_file', 'note_file_add', 'error', [
        'client_id' => $clientId,
        'note_id' => $noteId,
        'error' => $e->getMessage(),
      ], 'personal_file_note_file', null, $uid, $actorRole);
    }
  }
}

audit_log('personal_file', 'note_add', 'info', [
  'client_id' => $clientId,
  'note_id' => $noteId,
  'files' => $filesSaved,
], 'personal_file_note', $noteId, $uid, $actorRole);

flash('Заметка добавлена', 'ok');
redirect('/adm/index.php?m=personal_file&client_id=' . $clientId);
