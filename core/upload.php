<?php
/**
 * FILE: /core/upload.php
 * ROLE: Загрузка файлов (safe filename + storage).
 * CONNECTIONS:
 *  - нет (используется из модулей)
 *
 * NOTES:
 *  - Только input[type=file], никаких сторонних библиотек.
 *  - Модуль сам решает ACL/CSRF.
 *  - Файл сохраняется только внутри проекта.
 *
 * СПИСОК ФУНКЦИЙ:
 *  - upload_safe_filename(string $name): string
 *  - upload_prepare_dir(string $dirAbs): void
 *  - upload_save(array $file, string $dirAbs): array
 */

declare(strict_types=1);

/**
 * upload_safe_filename()
 * Приводит имя файла к безопасному виду.
 */
function upload_safe_filename(string $name): string {
  $name = str_replace(["\r", "\n"], '', $name);
  $name = preg_replace('~[^\w\.\-]+~u', '_', $name);
  $name = trim($name, '._-');
  if ($name === '') $name = 'file';
  return $name;
}

/**
 * upload_prepare_dir()
 * Создаёт директорию (если нет).
 */
function upload_prepare_dir(string $dirAbs): void {
  if ($dirAbs === '') {
    throw new RuntimeException('Upload dir empty');
  }
  if (!is_dir($dirAbs)) {
    @mkdir($dirAbs, 0775, true);
  }
  if (!is_dir($dirAbs)) {
    throw new RuntimeException('Upload dir not created');
  }
}

/**
 * upload_save()
 * Сохраняет один файл из $_FILES.
 *
 * @param array  $file  элемент $_FILES
 * @param string $dirAbs абсолютный путь
 * @return array {ok, name, orig_name, path_abs, size, ext, mime}
 */
function upload_save(array $file, string $dirAbs): array {
  if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
    return ['ok' => false, 'error' => 'upload_error'];
  }
  if (empty($file['tmp_name']) || !is_uploaded_file((string)$file['tmp_name'])) {
    return ['ok' => false, 'error' => 'tmp_missing'];
  }

  upload_prepare_dir($dirAbs);

  $orig = (string)($file['name'] ?? '');
  $safe = upload_safe_filename($orig);

  $ext = '';
  $pos = strrpos($safe, '.');
  if ($pos !== false) {
    $ext = strtolower(substr($safe, $pos + 1));
  }

  $base = ($pos !== false) ? substr($safe, 0, $pos) : $safe;
  $stamp = date('Ymd_His');
  $rand = bin2hex(random_bytes(3));
  $final = $base . '_' . $stamp . '_' . $rand;
  if ($ext !== '') $final .= '.' . $ext;

  $pathAbs = rtrim($dirAbs, '/\\') . DIRECTORY_SEPARATOR . $final;
  if (!@move_uploaded_file((string)$file['tmp_name'], $pathAbs)) {
    return ['ok' => false, 'error' => 'move_failed'];
  }

  $size = (int)@filesize($pathAbs);
  $mime = '';
  if (function_exists('mime_content_type')) {
    $mime = (string)@mime_content_type($pathAbs);
  }

  return [
    'ok' => true,
    'name' => $final,
    'orig_name' => $orig,
    'path_abs' => $pathAbs,
    'size' => $size,
    'ext' => $ext,
    'mime' => $mime,
  ];
}
