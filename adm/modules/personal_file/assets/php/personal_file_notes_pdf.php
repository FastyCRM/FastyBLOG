<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_notes_pdf.php
 * ROLE: Генерация PDF по заметкам клиента
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php
 *  - /adm/modules/personal_file/assets/php/personal_file_lib.php
 *  - /core/pdf.php (pdf_render, pdf_send)
 *  - /core/flash.php (flash)
 *  - /core/response.php (redirect)
 *
 * NOTES:
 *  - В PDF выводим только текст и список файлов.
 *  - Картинки вставляются как названия (без рендеринга).
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

/**
 * CSRF
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

if (!function_exists('pdf_is_enabled') || !pdf_is_enabled()) {
  flash('PDF отключён в настройках', 'warn');
  redirect('/adm/index.php?m=personal_file');
}

$pdo = db();
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

/**
 * Входные поля
 */
$clientId = (int)($_POST['client_id'] ?? 0);
$dateFrom = trim((string)($_POST['date_from'] ?? ''));
$dateTo = trim((string)($_POST['date_to'] ?? ''));

if ($clientId <= 0) {
  audit_log('personal_file', 'notes_pdf', 'warn', [
    'reason' => 'invalid_client_id',
    'client_id' => $clientId,
  ], 'personal_file_note', null, $uid, $actorRole);
  flash('Клиент не выбран', 'warn');
  redirect('/adm/index.php?m=personal_file');
}

$client = personal_file_get_client($pdo, $clientId);
if (!$client) {
  audit_log('personal_file', 'notes_pdf', 'warn', [
    'reason' => 'client_not_found',
    'client_id' => $clientId,
  ], 'personal_file_note', null, $uid, $actorRole);
  flash('Клиент не найден', 'warn');
  redirect('/adm/index.php?m=personal_file');
}

$notes = personal_file_get_notes($pdo, $clientId, 500, $dateFrom !== '' ? $dateFrom : null, $dateTo !== '' ? $dateTo : null);
$noteIds = array_map(static function ($n) { return (int)($n['id'] ?? 0); }, $notes);
$noteFiles = $noteIds ? personal_file_get_note_files_map($pdo, $noteIds) : [];

/**
 * Подготовка данных клиента
 */
$fio = trim((string)($client['last_name'] ?? '') . ' ' . (string)($client['first_name'] ?? '') . ' ' . (string)($client['middle_name'] ?? ''));
$phone = (string)($client['phone'] ?? '');
$email = (string)($client['email'] ?? '');
$inn = (string)($client['inn'] ?? '');
$birthDate = (string)($client['birth_date'] ?? '');

/**
 * Формирование DocumentSpec
 */
$content = [];
$y = 12.0;
$content[] = ['type' => 'text', 'x_mm' => 10, 'y_mm' => $y, 'text' => 'Личное дело клиента', 'size' => 16, 'bold' => true];
$y += 8;

$infoLines = [];
$infoLines[] = 'ФИО: ' . ($fio !== '' ? $fio : '—');
$infoLines[] = 'Телефон: ' . ($phone !== '' ? $phone : '—');
if ($email !== '') $infoLines[] = 'Email: ' . $email;
if ($inn !== '') $infoLines[] = 'ИНН: ' . $inn;
if ($birthDate !== '') $infoLines[] = 'Дата рождения: ' . $birthDate;
$infoText = implode("\n", $infoLines);

$content[] = ['type' => 'paragraph', 'x_mm' => 10, 'y_mm' => $y, 'w_mm' => 190, 'text' => $infoText, 'size' => 11];
$y += 16;

$titlePeriod = 'Заметки';
if ($dateFrom !== '' || $dateTo !== '') {
  $titlePeriod .= ' (период: ' . ($dateFrom !== '' ? $dateFrom : '...') . ' — ' . ($dateTo !== '' ? $dateTo : '...') . ')';
}
$content[] = ['type' => 'text', 'x_mm' => 10, 'y_mm' => $y, 'text' => $titlePeriod, 'size' => 13, 'bold' => true];
$y += 7;

/**
 * Простая раскладка заметок
 */
$lineHeight = 1.25;
$fontSize = 11;
$maxWidthPt = pdf_mm_to_pt(190);
$ptToMm = 1 / 2.83464567;
$pageBottomMm = 287; // A4 height 297 - margin 10

foreach ($notes as $n) {
  $nid = (int)($n['id'] ?? 0);
  $nDate = (string)($n['created_at'] ?? '');
  $nUser = (string)($n['user_name'] ?? '—');
  $nText = (string)($n['note_text'] ?? '');
  $files = $noteFiles[$nid] ?? [];

  $header = $nDate . ' — ' . $nUser;
  $content[] = ['type' => 'text', 'x_mm' => 10, 'y_mm' => $y, 'text' => $header, 'size' => 10, 'bold' => true];
  $y += 5;

  $lines = pdf_wrap_text($nText !== '' ? $nText : '—', $maxWidthPt, [
    'font_size' => $fontSize,
    'use_ttf' => false,
  ]);
  $textHeightPt = (count($lines) * $fontSize * $lineHeight);
  $textHeightMm = $textHeightPt * $ptToMm;

  $content[] = ['type' => 'paragraph', 'x_mm' => 10, 'y_mm' => $y, 'w_mm' => 190, 'text' => ($nText !== '' ? $nText : '—'), 'size' => 11];
  $y += $textHeightMm + 2;

  if ($files) {
    $fileNames = [];
    foreach ($files as $f) {
      $fileNames[] = (string)($f['orig_name'] ?? $f['file_name'] ?? '');
    }
    $fileLine = 'Файлы: ' . implode(', ', array_filter($fileNames));
    $content[] = ['type' => 'paragraph', 'x_mm' => 10, 'y_mm' => $y, 'w_mm' => 190, 'text' => $fileLine, 'size' => 10];
    $y += 6;
  }

  $y += 4;

  if ($y >= $pageBottomMm) {
    $content[] = ['type' => 'page_break'];
    $y = 12.0;
  }
}

if (!$notes) {
  $content[] = ['type' => 'paragraph', 'x_mm' => 10, 'y_mm' => $y, 'w_mm' => 190, 'text' => 'Заметок нет.', 'size' => 11];
}

$doc = [
  'meta' => [
    'title' => 'Личное дело: ' . ($fio !== '' ? $fio : ('Клиент #' . $clientId)),
    'author' => 'CRM2026',
    'subject' => 'Personal File Notes',
    'created_at' => time(),
  ],
  'page' => [
    'size' => 'A4',
    'orientation' => 'portrait',
    'margin_mm' => 10,
  ],
  'styles' => [
    'font' => 'DejaVuSans',
    'font_size' => 11,
    'line_height' => 1.25,
  ],
  'content' => $content,
];

try {
  $pdfBytes = pdf_render($doc, []);
  $filename = 'personal_file_notes_' . $clientId . '.pdf';

  audit_log('personal_file', 'notes_pdf', 'info', [
    'client_id' => $clientId,
    'notes_count' => count($notes),
  ], 'personal_file_note', null, $uid, $actorRole);

  pdf_send($pdfBytes, $filename, false);
} catch (Throwable $e) {
  audit_log('personal_file', 'notes_pdf', 'error', [
    'client_id' => $clientId,
    'error' => $e->getMessage(),
  ], 'personal_file_note', null, $uid, $actorRole);
  flash('Ошибка генерации PDF', 'danger', 1);
  redirect('/adm/index.php?m=personal_file&client_id=' . $clientId);
}
