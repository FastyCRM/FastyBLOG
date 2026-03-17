<?php
/**
 * FILE: /core/pdf.php
 * ROLE: Универсальный PDF-сервис без сторонних библиотек (минимальный PDF writer).
 * CONNECTIONS:
 *  - подключается в /core/bootstrap.php
 *  - вызывается из модулей (генерация/отдача/сохранение)
 *  - использует audit_log() из /core/audit.php
 *  - использует APP_CONFIG через app_config() из /core/session.php
 *
 * NOTES:
 *  - HTML → PDF не поддерживается (только DocumentSpec на массивах/JSON).
 *  - Координаты в DocumentSpec задаются в миллиметрах (мм).
 *  - Встроенные PDF-шрифты: Helvetica, Times-Roman, Courier (и их Bold).
 *  - Для кириллицы используется TTF (если есть pdf.font_path); без TTF кириллица запрещена.
 *
 * FUNCTIONS:
 *  - pdf_is_enabled(): bool — проверка флага pdf.enabled.
 *  - pdf_render(array $docSpec, array $opts = []): string — генерация PDF-байтов.
 *  - pdf_send(string $pdfBytes, string $filename, bool $inline = false): void — выдача PDF в браузер.
 *  - pdf_save(string $pdfBytes, string $relativePath, array $meta = []): array — сохранение PDF в storage.
 *  - pdf_safe_filename(string $name): string — безопасное имя файла.
 *  - pdf_build_storage_path(string $module, string $entity, int $id, string $filename): string — унификация путей.
 */

declare(strict_types=1);

/**
 * pdf_is_enabled()
 * Проверка флага включения PDF в конфиге.
 */
function pdf_is_enabled(): bool {
  $cfg = app_config();
  return !empty($cfg['pdf']['enabled']);
}

/**
 * pdf_render()
 * Генерация PDF-байтов по DocumentSpec.
 *
 * @param array $docSpec Структура документа (meta/page/styles/content)
 * @param array $opts    Переопределения (page_size, orientation, debug)
 * @return string        PDF-байты
 */
function pdf_render(array $docSpec, array $opts = []): string {
  $cfg = app_config();
  $pdfCfg = (array)($cfg['pdf'] ?? []);

  if (!pdf_is_enabled()) {
    throw new RuntimeException('PDF отключен в конфигурации');
  }

  $meta = (array)($docSpec['meta'] ?? []);
  $page = (array)($docSpec['page'] ?? []);
  $styles = (array)($docSpec['styles'] ?? []);
  $content = (array)($docSpec['content'] ?? []);

  $pageSize = (string)($opts['page_size'] ?? $page['size'] ?? $pdfCfg['default_page_size'] ?? 'A4');
  $orientation = (string)($opts['orientation'] ?? $page['orientation'] ?? $pdfCfg['default_orientation'] ?? 'portrait');
  $marginMm = (float)($page['margin_mm'] ?? $pdfCfg['default_margin_mm'] ?? 10);

  $fontName = (string)($styles['font'] ?? $pdfCfg['default_font'] ?? 'Helvetica');
  $fontSize = (float)($styles['font_size'] ?? $pdfCfg['default_font_size'] ?? 11);
  $lineHeight = (float)($styles['line_height'] ?? 1.25);

  $texts = pdf_collect_texts($content);
  $hasCyrillic = pdf_has_cyrillic($texts);

  $fontPath = (string)($pdfCfg['font_path'] ?? '');
  $useTtf = false;

  if ($hasCyrillic) {
    if ($fontPath === '' || !is_file($fontPath)) {
      throw new RuntimeException('PDF: кириллица недоступна без TTF-шрифта');
    }
    $useTtf = true;
    $fontName = (string)($pdfCfg['default_font'] ?? $fontName);
  } else {
    if ($fontPath !== '' && is_file($fontPath) && $fontName === (string)($pdfCfg['default_font'] ?? $fontName)) {
      $useTtf = true;
    }
  }

  $ttf = $useTtf ? pdf_ttf_load($fontPath, $texts) : null;

  $pages = pdf_split_pages($content);
  if (count($pages) === 0) {
    $pages = [[]];
  }

  $pageSizePt = pdf_page_size_pt($pageSize, $orientation);
  $pageW = $pageSizePt[0];
  $pageH = $pageSizePt[1];

  $objects = [];
  $objId = 1;

  // Шрифты
  if ($useTtf) {
    $fontBuild = pdf_build_font_ttf($ttf, $fontName, $texts, $objId);
  } else {
    $fontBuild = pdf_build_font_builtin($fontName, $objId);
  }

  foreach ($fontBuild['objects'] as $id => $body) {
    $objects[(int)$id] = $body;
  }
  $objId = (int)$fontBuild['next_id'];
  $fontRefs = (array)$fontBuild['font_refs'];

  // Info
  $infoId = $objId++;
  $objects[$infoId] = pdf_build_info_object($meta, $useTtf || $hasCyrillic);

  // Pages root
  $pagesRootId = $objId++;

  $pageIds = [];

  foreach ($pages as $pageItems) {
    $contentId = $objId++;
    $pageId = $objId++;

    $pageIds[] = $pageId;

    $stream = pdf_build_page_stream($pageItems, [
      'page_w' => $pageW,
      'page_h' => $pageH,
      'margin_mm' => $marginMm,
      'font_size' => $fontSize,
      'line_height' => $lineHeight,
      'use_ttf' => $useTtf,
      'font_refs' => $fontRefs,
      'font_name' => $fontName,
      'ttf' => $ttf,
    ]);

    $objects[$contentId] = pdf_stream_object($stream);

    $fontRes = pdf_build_font_resources($fontRefs);

    $objects[$pageId] = "<< /Type /Page /Parent {$pagesRootId} 0 R /MediaBox [0 0 " . pdf_num($pageW) . " " . pdf_num($pageH) . "] /Resources << /Font {$fontRes} >> /Contents {$contentId} 0 R >>";
  }

  $kids = [];
  foreach ($pageIds as $pid) {
    $kids[] = $pid . ' 0 R';
  }
  $objects[$pagesRootId] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($pageIds) . " >>";

  $catalogId = $objId++;
  $objects[$catalogId] = "<< /Type /Catalog /Pages {$pagesRootId} 0 R >>";

  $pdf = pdf_compile_objects($objects, $catalogId, $infoId);

  pdf_audit('pdf_generate', [
    'page_count' => count($pageIds),
    'size' => strlen($pdf),
    'font' => $fontName,
    'ttf' => $useTtf ? 1 : 0,
    'has_cyrillic' => $hasCyrillic ? 1 : 0,
  ]);

  return $pdf;
}

/**
 * pdf_send()
 * Отдача PDF в браузер.
 */
function pdf_send(string $pdfBytes, string $filename, bool $inline = false): void {
  $safe = pdf_safe_filename($filename);
  $disp = $inline ? 'inline' : 'attachment';

  if (!headers_sent()) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . $disp . '; filename="' . $safe . '"');
    header('Content-Length: ' . strlen($pdfBytes));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
  }

  pdf_audit('pdf_send', [
    'filename' => $safe,
    'size' => strlen($pdfBytes),
    'inline' => $inline ? 1 : 0,
  ]);

  echo $pdfBytes;
  exit;
}

/**
 * pdf_save()
 * Сохранение PDF в storage/pdf.
 */
function pdf_save(string $pdfBytes, string $relativePath, array $meta = []): array {
  $cfg = app_config();
  $pdfCfg = (array)($cfg['pdf'] ?? []);

  if (empty($pdfCfg['allow_save'])) {
    return ['ok' => false, 'error' => 'save_disabled'];
  }

  $base = (string)($pdfCfg['storage_dir'] ?? (ROOT_PATH . '/storage/pdf'));
  $rel = ltrim((string)$relativePath, '/\\');

  $abs = rtrim($base, '/\\') . '/' . $rel;
  $dir = dirname($abs);

  if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
  }

  $ok = @file_put_contents($abs, $pdfBytes);
  if ($ok === false) {
    return ['ok' => false, 'error' => 'save_failed'];
  }

  $hash = sha1($pdfBytes);

  pdf_audit('pdf_save', [
    'path_rel' => $rel,
    'size' => strlen($pdfBytes),
    'hash' => $hash,
  ] + $meta);

  return [
    'ok' => true,
    'path_rel' => $rel,
    'path_abs' => $abs,
    'size' => strlen($pdfBytes),
    'hash' => $hash,
  ];
}

/**
 * pdf_safe_filename()
 * Безопасное имя файла без CRLF и запрещённых символов.
 */
function pdf_safe_filename(string $name): string {
  $name = trim($name);
  $name = str_replace(["\r", "\n"], '', $name);
  $name = preg_replace('~[\\/\\\\:*?"<>|]+~', '_', $name);
  $name = preg_replace('~\s+~', ' ', $name);
  if ($name === '' || $name === '.') {
    $name = 'document.pdf';
  }
  if (!preg_match('~\.pdf$~i', $name)) {
    $name .= '.pdf';
  }
  return $name;
}

/**
 * pdf_build_storage_path()
 * Унифицированный относительный путь для сохранения.
 */
function pdf_build_storage_path(string $module, string $entity, int $id, string $filename): string {
  $safeFile = pdf_safe_filename($filename);
  $mod = preg_replace('~[^a-z0-9_\-]+~i', '_', $module);
  $ent = preg_replace('~[^a-z0-9_\-]+~i', '_', $entity);
  $id = max(0, (int)$id);

  return $mod . '/' . $ent . '/' . $id . '/' . $safeFile;
}
/**
 * =========================
 * ВНУТРЕННИЕ ФУНКЦИИ
 * =========================
 */

/**
 * pdf_mm_to_pt()
 * Перевод миллиметров в PDF-поинты.
 */
function pdf_mm_to_pt(float $mm): float {
  return $mm * 72.0 / 25.4;
}

/**
 * pdf_num()
 * Форматирование числа для PDF (точка, минимум хвоста).
 */
function pdf_num(float $v): string {
  $s = number_format($v, 3, '.', '');
  $s = rtrim(rtrim($s, '0'), '.');
  return $s === '' ? '0' : $s;
}

/**
 * pdf_escape_text()
 * Экранирование текста для PDF-строк (ASCII).
 */
function pdf_escape_text(string $text): string {
  $text = str_replace('\\', '\\\\', $text);
  $text = str_replace('(', '\\(', $text);
  $text = str_replace(')', '\\)', $text);
  $text = str_replace("\r", '\\r', $text);
  $text = str_replace("\n", '\\n', $text);
  return $text;
}

/**
 * pdf_has_cyrillic()
 * Проверка наличия кириллицы в массиве строк.
 */
function pdf_has_cyrillic(array $texts): bool {
  foreach ($texts as $t) {
    if (preg_match('~[\x{0400}-\x{04FF}]~u', (string)$t)) {
      return true;
    }
  }
  return false;
}

/**
 * pdf_is_ascii()
 * Проверка: строка содержит только ASCII.
 */
function pdf_is_ascii(string $text): bool {
  return preg_match('~^[\x00-\x7F]*$~', $text) === 1;
}

/**
 * pdf_collect_texts()
 * Сбор всех текстов из DocumentSpec (для анализа и метрик).
 */
function pdf_collect_texts(array $content): array {
  $out = [];

  foreach ($content as $el) {
    if (!is_array($el)) continue;
    $type = (string)($el['type'] ?? '');

    if ($type === 'text' || $type === 'paragraph') {
      $out[] = (string)($el['text'] ?? '');
    }

    if ($type === 'table') {
      $header = (array)($el['header'] ?? []);
      foreach ($header as $h) $out[] = (string)$h;

      $rows = (array)($el['rows'] ?? []);
      foreach ($rows as $row) {
        if (!is_array($row)) continue;
        foreach ($row as $cell) $out[] = (string)$cell;
      }
    }
  }

  return $out;
}

/**
 * pdf_split_pages()
 * Разделение контента по page_break.
 */
function pdf_split_pages(array $content): array {
  $pages = [[]];
  $idx = 0;

  foreach ($content as $el) {
    if (!is_array($el)) continue;
    $type = (string)($el['type'] ?? '');

    if ($type === 'page_break') {
      $idx++;
      $pages[$idx] = [];
      continue;
    }

    $pages[$idx][] = $el;
  }

  return $pages;
}

/**
 * pdf_page_size_pt()
 * Размер страницы в поинтах.
 */
function pdf_page_size_pt(string $size, string $orientation): array {
  $size = strtoupper(trim($size));
  $orientation = strtolower(trim($orientation));

  $w = 210.0;
  $h = 297.0;

  if ($size === 'A5') {
    $w = 148.0;
    $h = 210.0;
  }

  $wPt = pdf_mm_to_pt($w);
  $hPt = pdf_mm_to_pt($h);

  if ($orientation === 'landscape') {
    return [$hPt, $wPt];
  }

  return [$wPt, $hPt];
}

/**
 * pdf_build_font_resources()
 * Формирует /Font ресурсы страницы.
 */
function pdf_build_font_resources(array $fontRefs): string {
  $parts = [];
  foreach ($fontRefs as $alias => $id) {
    $parts[] = '/' . $alias . ' ' . (int)$id . ' 0 R';
  }
  return '<< ' . implode(' ', $parts) . ' >>';
}

/**
 * pdf_build_font_builtin()
 * Объекты шрифтов для встроенных PDF-фонтов.
 */
function pdf_build_font_builtin(string $fontName, int $startId): array {
  $normal = pdf_builtin_font_name($fontName, false);
  $bold = pdf_builtin_font_name($fontName, true);

  $objects = [];
  $fontRefs = [];

  $objects[$startId] = "<< /Type /Font /Subtype /Type1 /BaseFont /{$normal} >>";
  $fontRefs['F1'] = $startId;

  $next = $startId + 1;

  if ($bold !== $normal) {
    $objects[$next] = "<< /Type /Font /Subtype /Type1 /BaseFont /{$bold} >>";
    $fontRefs['F1B'] = $next;
    $next++;
  } else {
    $fontRefs['F1B'] = $startId;
  }

  return [
    'objects' => $objects,
    'font_refs' => $fontRefs,
    'next_id' => $next,
  ];
}

/**
 * pdf_builtin_font_name()
 * Маппинг логического имени на PDF built-in шрифт.
 */
function pdf_builtin_font_name(string $fontName, bool $bold): string {
  $name = strtolower($fontName);

  if ($name === 'times' || $name === 'times-roman' || $name === 'timesroman') {
    return $bold ? 'Times-Bold' : 'Times-Roman';
  }

  if ($name === 'courier') {
    return $bold ? 'Courier-Bold' : 'Courier';
  }

  return $bold ? 'Helvetica-Bold' : 'Helvetica';
}

/**
 * pdf_build_font_ttf()
 * Создание объектов шрифтов для TTF (Type0 + CIDFontType2).
 */
function pdf_build_font_ttf(array $ttf, string $fontName, array $texts, int $startId): array {
  $objects = [];
  $fontRefs = [];

  $fontFileId = $startId;
  $fontDescId = $startId + 1;
  $cidMapId = $startId + 2;
  $cidFontId = $startId + 3;
  $type0Id = $startId + 4;
  $toUnicodeId = $startId + 5;

  $fontRefs['F1'] = $type0Id;
  $fontRefs['F1B'] = $type0Id;

  $fontFileStream = pdf_stream_object($ttf['raw']);
  $objects[$fontFileId] = $fontFileStream;

  $bbox = $ttf['bbox_1000'];

  $fontDesc = "<< /Type /FontDescriptor /FontName /{$fontName} /Flags 32 /FontBBox [{$bbox[0]} {$bbox[1]} {$bbox[2]} {$bbox[3]}] /ItalicAngle 0 /Ascent {$ttf['ascent_1000']} /Descent {$ttf['descent_1000']} /CapHeight {$ttf['ascent_1000']} /StemV 80 /FontFile2 {$fontFileId} 0 R >>";
  $objects[$fontDescId] = $fontDesc;

  $cidMapStream = pdf_stream_object($ttf['cid_to_gid_map']);
  $objects[$cidMapId] = $cidMapStream;

  $wArr = pdf_build_widths_array($ttf['widths']);

  $cidFont = "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /{$fontName} /CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> /FontDescriptor {$fontDescId} 0 R /W {$wArr} /DW {$ttf['dw']} /CIDToGIDMap {$cidMapId} 0 R >>";
  $objects[$cidFontId] = $cidFont;

  $toUnicode = pdf_build_tounicode_cmap($ttf['unicode_map']);
  $objects[$toUnicodeId] = pdf_stream_object($toUnicode);

  $type0 = "<< /Type /Font /Subtype /Type0 /BaseFont /{$fontName} /Encoding /Identity-H /DescendantFonts [{$cidFontId} 0 R] /ToUnicode {$toUnicodeId} 0 R >>";
  $objects[$type0Id] = $type0;

  return [
    'objects' => $objects,
    'font_refs' => $fontRefs,
    'next_id' => $startId + 6,
  ];
}

/**
 * pdf_build_widths_array()
 * Формирует /W массив для CID-шрифта.
 */
function pdf_build_widths_array(array $widthsByCid): string {
  if (empty($widthsByCid)) {
    return '[ ]';
  }

  ksort($widthsByCid);

  $parts = [];
  $rangeStart = null;
  $range = [];
  $prev = null;

  foreach ($widthsByCid as $cid => $w) {
    $cid = (int)$cid;
    $w = (int)$w;

    if ($rangeStart === null) {
      $rangeStart = $cid;
      $range = [$w];
      $prev = $cid;
      continue;
    }

    if ($cid === $prev + 1) {
      $range[] = $w;
      $prev = $cid;
      continue;
    }

    $parts[] = $rangeStart . ' [' . implode(' ', $range) . ']';
    $rangeStart = $cid;
    $range = [$w];
    $prev = $cid;
  }

  if ($rangeStart !== null) {
    $parts[] = $rangeStart . ' [' . implode(' ', $range) . ']';
  }

  return '[ ' . implode(' ', $parts) . ' ]';
}

/**
 * pdf_build_tounicode_cmap()
 * Минимальный ToUnicode CMap для корректного копирования текста.
 */
function pdf_build_tounicode_cmap(array $unicodeMap): string {
  $lines = [];
  $lines[] = "/CIDInit /ProcSet findresource begin";
  $lines[] = "12 dict begin";
  $lines[] = "begincmap";
  $lines[] = "/CIDSystemInfo << /Registry (Adobe) /Ordering (Identity) /Supplement 0 >> def";
  $lines[] = "/CMapName /Adobe-Identity-UCS def";
  $lines[] = "/CMapType 2 def";
  $lines[] = "1 begincodespacerange";
  $lines[] = "<0000> <FFFF>";
  $lines[] = "endcodespacerange";

  $chunks = array_chunk($unicodeMap, 100, true);
  foreach ($chunks as $chunk) {
    $lines[] = count($chunk) . " beginbfchar";
    foreach ($chunk as $cid => $uniHex) {
      $cidHex = str_pad(strtoupper(dechex((int)$cid)), 4, '0', STR_PAD_LEFT);
      $lines[] = "<{$cidHex}> <{$uniHex}>";
    }
    $lines[] = "endbfchar";
  }

  $lines[] = "endcmap";
  $lines[] = "CMapName currentdict /CMap defineresource pop";
  $lines[] = "end";
  $lines[] = "end";

  return implode("\n", $lines);
}

/**
 * pdf_build_info_object()
 * Метаданные PDF (Title/Author/Subject/Keywords).
 */
function pdf_build_info_object(array $meta, bool $unicode): string {
  $title = (string)($meta['title'] ?? '');
  $author = (string)($meta['author'] ?? '');
  $subject = (string)($meta['subject'] ?? '');
  $keywords = (string)($meta['keywords'] ?? '');

  $createdAt = (int)($meta['created_at'] ?? time());
  $dt = date('YmdHis', $createdAt);

  $parts = [];
  if ($title !== '') $parts[] = '/Title ' . pdf_info_string($title, $unicode);
  if ($author !== '') $parts[] = '/Author ' . pdf_info_string($author, $unicode);
  if ($subject !== '') $parts[] = '/Subject ' . pdf_info_string($subject, $unicode);
  if ($keywords !== '') $parts[] = '/Keywords ' . pdf_info_string($keywords, $unicode);

  $parts[] = '/Producer (CRM2026)';
  $parts[] = '/CreationDate (D:' . $dt . ')';

  return '<< ' . implode(' ', $parts) . ' >>';
}

/**
 * pdf_info_string()
 * PDF-строка для Info-словаря (ASCII или UTF-16BE).
 */
function pdf_info_string(string $text, bool $unicode): string {
  if (!$unicode && pdf_is_ascii($text)) {
    return '(' . pdf_escape_text($text) . ')';
  }

  $hex = pdf_utf16be_hex($text, true);
  return '<' . $hex . '>';
}

/**
 * pdf_stream_object()
 * Обёртка stream с корректной длиной.
 */
function pdf_stream_object(string $data): string {
  $len = strlen($data);
  return "<< /Length {$len} >>\nstream\n{$data}\nendstream";
}

/**
 * pdf_compile_objects()
 * Компиляция объектов PDF + xref/trailer.
 */
function pdf_compile_objects(array $objects, int $catalogId, int $infoId): string {
  ksort($objects);

  $buf = "%PDF-1.4\n";
  $offsets = [0 => 0];

  foreach ($objects as $id => $body) {
    $offsets[$id] = strlen($buf);
    $buf .= $id . " 0 obj\n" . $body . "\nendobj\n";
  }

  $maxId = (int)max(array_keys($objects));
  $xrefPos = strlen($buf);

  $buf .= "xref\n";
  $buf .= "0 " . ($maxId + 1) . "\n";
  $buf .= "0000000000 65535 f \n";

  for ($i = 1; $i <= $maxId; $i++) {
    $off = $offsets[$i] ?? 0;
    $buf .= sprintf('%010d 00000 n ', $off) . "\n";
  }

  $buf .= "trailer\n";
  $buf .= "<< /Size " . ($maxId + 1) . " /Root {$catalogId} 0 R /Info {$infoId} 0 R >>\n";
  $buf .= "startxref\n";
  $buf .= $xrefPos . "\n%%EOF";

  return $buf;
}

/**
 * pdf_build_page_stream()
 * Построение content stream для страницы.
 */
function pdf_build_page_stream(array $items, array $ctx): string {
  $pageW = (float)$ctx['page_w'];
  $pageH = (float)$ctx['page_h'];
  $fontSize = (float)$ctx['font_size'];
  $lineHeight = (float)$ctx['line_height'];
  $useTtf = (bool)$ctx['use_ttf'];
  $fontRefs = (array)$ctx['font_refs'];
  $ttf = $ctx['ttf'] ?? null;

  $out = [];

  foreach ($items as $el) {
    if (!is_array($el)) continue;
    $type = (string)($el['type'] ?? '');

    if ($type === 'text') {
      $x = pdf_mm_to_pt((float)($el['x_mm'] ?? 0));
      $yTop = pdf_mm_to_pt((float)($el['y_mm'] ?? 0));
      $size = (float)($el['size'] ?? $fontSize);
      $bold = !empty($el['bold']);
      $fontAlias = $useTtf ? 'F1' : ($bold ? 'F1B' : 'F1');

      $text = (string)($el['text'] ?? '');
      $y = $pageH - $yTop;

      $out[] = pdf_text_cmd($x, $y, $text, $fontAlias, $size, $useTtf, $ttf);
      continue;
    }

    if ($type === 'paragraph') {
      $x = pdf_mm_to_pt((float)($el['x_mm'] ?? 0));
      $yTop = pdf_mm_to_pt((float)($el['y_mm'] ?? 0));
      $w = pdf_mm_to_pt((float)($el['w_mm'] ?? 0));
      $size = (float)($el['size'] ?? $fontSize);
      $text = (string)($el['text'] ?? '');

      $lines = pdf_wrap_text($text, $w, [
        'use_ttf' => $useTtf,
        'ttf' => $ttf,
        'font_size' => $size,
      ]);

      $linePt = $size * $lineHeight;
      $i = 0;
      foreach ($lines as $line) {
        $y = $pageH - $yTop - ($i * $linePt);
        $out[] = pdf_text_cmd($x, $y, $line, 'F1', $size, $useTtf, $ttf);
        $i++;
      }
      continue;
    }

    if ($type === 'line') {
      $x1 = pdf_mm_to_pt((float)($el['x1_mm'] ?? 0));
      $y1 = $pageH - pdf_mm_to_pt((float)($el['y1_mm'] ?? 0));
      $x2 = pdf_mm_to_pt((float)($el['x2_mm'] ?? 0));
      $y2 = $pageH - pdf_mm_to_pt((float)($el['y2_mm'] ?? 0));
      $w = pdf_mm_to_pt((float)($el['width'] ?? 0.3));

      $out[] = pdf_num($w) . " w";
      $out[] = pdf_num($x1) . " " . pdf_num($y1) . " m " . pdf_num($x2) . " " . pdf_num($y2) . " l S";
      continue;
    }

    if ($type === 'rect') {
      $x = pdf_mm_to_pt((float)($el['x_mm'] ?? 0));
      $yTop = pdf_mm_to_pt((float)($el['y_mm'] ?? 0));
      $w = pdf_mm_to_pt((float)($el['w_mm'] ?? 0));
      $h = pdf_mm_to_pt((float)($el['h_mm'] ?? 0));
      $y = $pageH - $yTop - $h;
      $lw = pdf_mm_to_pt((float)($el['width'] ?? 0.3));

      $out[] = pdf_num($lw) . " w";
      $out[] = pdf_num($x) . " " . pdf_num($y) . " " . pdf_num($w) . " " . pdf_num($h) . " re S";
      continue;
    }

    if ($type === 'table') {
      $x0 = (float)($el['x_mm'] ?? 0);
      $y0 = (float)($el['y_mm'] ?? 0);
      $cols = (array)($el['cols'] ?? []);
      $header = (array)($el['header'] ?? []);
      $rows = (array)($el['rows'] ?? []);
      $style = (array)($el['style'] ?? []);

      $tFont = (float)($style['font_size'] ?? $fontSize);
      $border = pdf_mm_to_pt((float)($style['border'] ?? 0.2));
      $padMm = (float)($style['padding_mm'] ?? 2);

      $linePt = $tFont * $lineHeight;
      $rowH = $linePt + pdf_mm_to_pt($padMm * 2);

      $curYTop = pdf_mm_to_pt($y0);

      $allRows = [];
      if (!empty($header)) $allRows[] = $header;
      foreach ($rows as $r) $allRows[] = (array)$r;

      $rowIndex = 0;
      foreach ($allRows as $row) {
        $xCursor = pdf_mm_to_pt($x0);
        $yTopPt = $pageH - $curYTop - ($rowIndex * $rowH);
        $yRect = $yTopPt - $rowH;

        foreach ($cols as $ci => $cwMm) {
          $cw = pdf_mm_to_pt((float)$cwMm);

          $out[] = pdf_num($border) . " w";
          $out[] = pdf_num($xCursor) . " " . pdf_num($yRect) . " " . pdf_num($cw) . " " . pdf_num($rowH) . " re S";

          $cellText = (string)($row[$ci] ?? '');
          if ($cellText !== '') {
            $tx = $xCursor + pdf_mm_to_pt($padMm);
            $ty = $yTopPt - pdf_mm_to_pt($padMm) - ($tFont * 0.8);
            $out[] = pdf_text_cmd($tx, $ty, $cellText, 'F1', $tFont, $useTtf, $ttf);
          }

          $xCursor += $cw;
        }

        $rowIndex++;
      }

      continue;
    }
  }

  return implode("\n", $out);
}

/**
 * pdf_text_cmd()
 * Команда вывода текста в content stream.
 */
function pdf_text_cmd(float $xPt, float $yPt, string $text, string $fontAlias, float $fontSize, bool $useTtf, ?array $ttf): string {
  if ($useTtf) {
    $hex = pdf_unicode_hex($text);
    return "BT /{$fontAlias} " . pdf_num($fontSize) . " Tf 1 0 0 1 " . pdf_num($xPt) . " " . pdf_num($yPt) . " Tm <{$hex}> Tj ET";
  }

  if (!pdf_is_ascii($text)) {
    throw new RuntimeException('PDF: не-ASCII текст в built-in шрифте');
  }

  $txt = pdf_escape_text($text);
  return "BT /{$fontAlias} " . pdf_num($fontSize) . " Tf 1 0 0 1 " . pdf_num($xPt) . " " . pdf_num($yPt) . " Tm ({$txt}) Tj ET";
}

/**
 * pdf_wrap_text()
 * Перенос текста по ширине.
 */
function pdf_wrap_text(string $text, float $maxWidthPt, array $metrics): array {
  $text = trim($text);
  if ($text === '') return [''];

  $words = preg_split('~\s+~u', $text);
  $lines = [];
  $line = '';

  foreach ($words as $w) {
    $test = $line === '' ? $w : ($line . ' ' . $w);
    $wPt = pdf_text_width($test, $metrics);

    if ($wPt <= $maxWidthPt || $line === '') {
      $line = $test;
      continue;
    }

    $lines[] = $line;
    $line = $w;
  }

  if ($line !== '') $lines[] = $line;

  return $lines;
}

/**
 * pdf_text_width()
 * Расчёт ширины текста в поинтах.
 */
function pdf_text_width(string $text, array $metrics): float {
  $fontSize = (float)($metrics['font_size'] ?? 11);
  $useTtf = !empty($metrics['use_ttf']);

  if ($useTtf && isset($metrics['ttf']) && is_array($metrics['ttf'])) {
    $ttf = $metrics['ttf'];
    $sum = 0;
    $codes = pdf_utf8_to_codepoints($text);
    foreach ($codes as $cp) {
      $sum += (int)($ttf['widths'][$cp] ?? $ttf['dw']);
    }
    return ($sum / 1000.0) * $fontSize;
  }

  return strlen($text) * $fontSize * 0.5;
}

/**
 * pdf_unicode_hex()
 * Перевод UTF-8 строки в hex-строку UTF-16BE (без BOM).
 */
function pdf_unicode_hex(string $text): string {
  $hex = '';
  $codes = pdf_utf8_to_codepoints($text);
  foreach ($codes as $cp) {
    if ($cp > 0xFFFF) {
      throw new RuntimeException('PDF: символ вне BMP');
    }
    $hex .= str_pad(strtoupper(dechex($cp)), 4, '0', STR_PAD_LEFT);
  }
  return $hex;
}

/**
 * pdf_utf16be_hex()
 * UTF-16BE hex-строка (с BOM, если требуется).
 */
function pdf_utf16be_hex(string $text, bool $bom): string {
  $hex = $bom ? 'FEFF' : '';
  $hex .= pdf_unicode_hex($text);
  return $hex;
}

/**
 * pdf_utf8_to_codepoints()
 * Разбор UTF-8 строки в кодпоинты.
 */
function pdf_utf8_to_codepoints(string $text): array {
  $out = [];
  $len = strlen($text);
  $i = 0;

  while ($i < $len) {
    $c = ord($text[$i]);

    if ($c < 0x80) {
      $out[] = $c;
      $i++;
      continue;
    }

    if (($c & 0xE0) === 0xC0 && $i + 1 < $len) {
      $c2 = ord($text[$i + 1]);
      $out[] = (($c & 0x1F) << 6) | ($c2 & 0x3F);
      $i += 2;
      continue;
    }

    if (($c & 0xF0) === 0xE0 && $i + 2 < $len) {
      $c2 = ord($text[$i + 1]);
      $c3 = ord($text[$i + 2]);
      $out[] = (($c & 0x0F) << 12) | (($c2 & 0x3F) << 6) | ($c3 & 0x3F);
      $i += 3;
      continue;
    }

    if (($c & 0xF8) === 0xF0 && $i + 3 < $len) {
      $c2 = ord($text[$i + 1]);
      $c3 = ord($text[$i + 2]);
      $c4 = ord($text[$i + 3]);
      $out[] = (($c & 0x07) << 18) | (($c2 & 0x3F) << 12) | (($c3 & 0x3F) << 6) | ($c4 & 0x3F);
      $i += 4;
      continue;
    }

    $i++;
  }

  return $out;
}

/**
 * pdf_audit()
 * Безопасный вызов audit_log().
 */
function pdf_audit(string $action, array $payload): void {
  if (!function_exists('audit_log')) return;
  $uid = function_exists('auth_user_id') ? auth_user_id() : null;
  $role = function_exists('auth_user_role') ? auth_user_role() : null;
  audit_log('core', $action, 'info', $payload, 'pdf', null, $uid, $role);
}
/**
 * =========================
 * TTF ПАРСЕР
 * =========================
 */

/**
 * pdf_ttf_load()
 * Загрузка TTF и подготовка метрик/маппингов.
 */
function pdf_ttf_load(string $path, array $texts): array {
  $raw = @file_get_contents($path);
  if ($raw === false) {
    throw new RuntimeException('PDF: не удалось прочитать TTF');
  }

  $tables = pdf_ttf_tables($raw);

  $head = pdf_ttf_read_head($raw, $tables);
  $hhea = pdf_ttf_read_hhea($raw, $tables);
  $maxp = pdf_ttf_read_maxp($raw, $tables);
  $hmtx = pdf_ttf_read_hmtx($raw, $tables, $hhea['num_h_metrics'], $maxp['num_glyphs']);
  $cmap = pdf_ttf_read_cmap($raw, $tables);

  $used = [];
  foreach ($texts as $t) {
    $codes = pdf_utf8_to_codepoints((string)$t);
    foreach ($codes as $cp) {
      if ($cp <= 0xFFFF) $used[$cp] = true;
    }
  }

  $widthsByCid = [];
  $unicodeMap = [];
  $maxCid = 0;

  foreach (array_keys($used) as $cp) {
    $gid = pdf_ttf_glyph_index($cmap, (int)$cp);
    $aw = $hmtx['advance_widths'][$gid] ?? $hmtx['default_width'];
    $w1000 = (int)round($aw * 1000 / $head['units_per_em']);

    $widthsByCid[$cp] = $w1000;
    $unicodeMap[$cp] = str_pad(strtoupper(dechex($cp)), 4, '0', STR_PAD_LEFT);

    if ($cp > $maxCid) $maxCid = $cp;
  }

  $cidToGid = pdf_ttf_build_cid_to_gid($cmap, $maxCid);

  $bbox = [
    (int)round($head['x_min'] * 1000 / $head['units_per_em']),
    (int)round($head['y_min'] * 1000 / $head['units_per_em']),
    (int)round($head['x_max'] * 1000 / $head['units_per_em']),
    (int)round($head['y_max'] * 1000 / $head['units_per_em']),
  ];

  $ascent1000 = (int)round($hhea['ascent'] * 1000 / $head['units_per_em']);
  $descent1000 = (int)round($hhea['descent'] * 1000 / $head['units_per_em']);
  $dw = (int)round($hmtx['default_width'] * 1000 / $head['units_per_em']);

  return [
    'raw' => $raw,
    'bbox_1000' => $bbox,
    'ascent_1000' => $ascent1000,
    'descent_1000' => $descent1000,
    'widths' => $widthsByCid,
    'unicode_map' => $unicodeMap,
    'cid_to_gid_map' => $cidToGid,
    'dw' => $dw,
  ];
}

/**
 * pdf_ttf_tables()
 * Чтение directory таблиц TTF.
 */
function pdf_ttf_tables(string $bin): array {
  $numTables = pdf_ttf_uint16($bin, 4);
  $tables = [];

  $offset = 12;
  for ($i = 0; $i < $numTables; $i++) {
    $tag = substr($bin, $offset, 4);
    $off = pdf_ttf_uint32($bin, $offset + 8);
    $len = pdf_ttf_uint32($bin, $offset + 12);
    $tables[$tag] = ['offset' => $off, 'length' => $len];
    $offset += 16;
  }

  return $tables;
}

/**
 * pdf_ttf_read_head()
 */
function pdf_ttf_read_head(string $bin, array $tables): array {
  $t = $tables['head'] ?? null;
  if (!$t) throw new RuntimeException('PDF: TTF head не найден');

  $o = $t['offset'];
  return [
    'units_per_em' => pdf_ttf_uint16($bin, $o + 18),
    'x_min' => pdf_ttf_int16($bin, $o + 36),
    'y_min' => pdf_ttf_int16($bin, $o + 38),
    'x_max' => pdf_ttf_int16($bin, $o + 40),
    'y_max' => pdf_ttf_int16($bin, $o + 42),
  ];
}

/**
 * pdf_ttf_read_hhea()
 */
function pdf_ttf_read_hhea(string $bin, array $tables): array {
  $t = $tables['hhea'] ?? null;
  if (!$t) throw new RuntimeException('PDF: TTF hhea не найден');

  $o = $t['offset'];
  return [
    'ascent' => pdf_ttf_int16($bin, $o + 4),
    'descent' => pdf_ttf_int16($bin, $o + 6),
    'num_h_metrics' => pdf_ttf_uint16($bin, $o + 34),
  ];
}

/**
 * pdf_ttf_read_maxp()
 */
function pdf_ttf_read_maxp(string $bin, array $tables): array {
  $t = $tables['maxp'] ?? null;
  if (!$t) throw new RuntimeException('PDF: TTF maxp не найден');

  $o = $t['offset'];
  return [
    'num_glyphs' => pdf_ttf_uint16($bin, $o + 4),
  ];
}

/**
 * pdf_ttf_read_hmtx()
 */
function pdf_ttf_read_hmtx(string $bin, array $tables, int $numHMetrics, int $numGlyphs): array {
  $t = $tables['hmtx'] ?? null;
  if (!$t) throw new RuntimeException('PDF: TTF hmtx не найден');

  $o = $t['offset'];
  $advance = [];

  $lastWidth = 0;
  for ($i = 0; $i < $numHMetrics; $i++) {
    $w = pdf_ttf_uint16($bin, $o + ($i * 4));
    $advance[$i] = $w;
    $lastWidth = $w;
  }

  for ($i = $numHMetrics; $i < $numGlyphs; $i++) {
    $advance[$i] = $lastWidth;
  }

  return [
    'advance_widths' => $advance,
    'default_width' => $lastWidth,
  ];
}

/**
 * pdf_ttf_read_cmap()
 */
function pdf_ttf_read_cmap(string $bin, array $tables): array {
  $t = $tables['cmap'] ?? null;
  if (!$t) throw new RuntimeException('PDF: TTF cmap не найден');

  $o = $t['offset'];
  $numTables = pdf_ttf_uint16($bin, $o + 2);

  $best = null;

  for ($i = 0; $i < $numTables; $i++) {
    $platform = pdf_ttf_uint16($bin, $o + 4 + ($i * 8));
    $encoding = pdf_ttf_uint16($bin, $o + 6 + ($i * 8));
    $subOffset = pdf_ttf_uint32($bin, $o + 8 + ($i * 8));

    $format = pdf_ttf_uint16($bin, $o + $subOffset);

    if ($platform === 3 && $format === 4) {
      $best = $o + $subOffset;
      break;
    }
  }

  if ($best === null) {
    throw new RuntimeException('PDF: cmap format 4 не найден');
  }

  $segCount = pdf_ttf_uint16($bin, $best + 6) / 2;
  $endCodeOffset = $best + 14;
  $startCodeOffset = $endCodeOffset + (2 * $segCount) + 2;
  $idDeltaOffset = $startCodeOffset + (2 * $segCount);
  $idRangeOffset = $idDeltaOffset + (2 * $segCount);
  $glyphArrayOffset = $idRangeOffset + (2 * $segCount);

  $endCode = [];
  $startCode = [];
  $idDelta = [];
  $idRange = [];

  for ($i = 0; $i < $segCount; $i++) {
    $endCode[$i] = pdf_ttf_uint16($bin, $endCodeOffset + ($i * 2));
    $startCode[$i] = pdf_ttf_uint16($bin, $startCodeOffset + ($i * 2));
    $idDelta[$i] = pdf_ttf_int16($bin, $idDeltaOffset + ($i * 2));
    $idRange[$i] = pdf_ttf_uint16($bin, $idRangeOffset + ($i * 2));
  }

  $glyphArrayLen = ($t['offset'] + $t['length']) - $glyphArrayOffset;
  $glyphIdArray = [];
  $count = (int)floor($glyphArrayLen / 2);
  for ($i = 0; $i < $count; $i++) {
    $glyphIdArray[$i] = pdf_ttf_uint16($bin, $glyphArrayOffset + ($i * 2));
  }

  return [
    'seg_count' => $segCount,
    'end_code' => $endCode,
    'start_code' => $startCode,
    'id_delta' => $idDelta,
    'id_range' => $idRange,
    'glyph_array' => $glyphIdArray,
  ];
}

/**
 * pdf_ttf_glyph_index()
 */
function pdf_ttf_glyph_index(array $cmap, int $code): int {
  $segCount = (int)($cmap['seg_count'] ?? 0);

  for ($i = 0; $i < $segCount; $i++) {
    $start = $cmap['start_code'][$i];
    $end = $cmap['end_code'][$i];

    if ($code < $start || $code > $end) continue;

    $delta = $cmap['id_delta'][$i];
    $range = $cmap['id_range'][$i];

    if ($range === 0) {
      return (int)(($code + $delta) & 0xFFFF);
    }

    $idx = ($range / 2) + ($code - $start) + ($i - $segCount);
    $idx = (int)$idx;

    if (!isset($cmap['glyph_array'][$idx])) {
      return 0;
    }

    $glyphId = (int)$cmap['glyph_array'][$idx];
    if ($glyphId === 0) return 0;

    return (int)(($glyphId + $delta) & 0xFFFF);
  }

  return 0;
}

/**
 * pdf_ttf_build_cid_to_gid()
 */
function pdf_ttf_build_cid_to_gid(array $cmap, int $maxCid): string {
  $bytes = '';
  for ($cid = 0; $cid <= $maxCid; $cid++) {
    $gid = pdf_ttf_glyph_index($cmap, $cid);
    $bytes .= chr(($gid >> 8) & 0xFF) . chr($gid & 0xFF);
  }
  return $bytes;
}

/**
 * pdf_ttf_uint16()
 */
function pdf_ttf_uint16(string $bin, int $offset): int {
  $v = unpack('n', substr($bin, $offset, 2));
  return (int)$v[1];
}

/**
 * pdf_ttf_int16()
 */
function pdf_ttf_int16(string $bin, int $offset): int {
  $v = unpack('n', substr($bin, $offset, 2));
  $val = (int)$v[1];
  if ($val > 0x7FFF) $val -= 0x10000;
  return $val;
}

/**
 * pdf_ttf_uint32()
 */
function pdf_ttf_uint32(string $bin, int $offset): int {
  $v = unpack('N', substr($bin, $offset, 4));
  return (int)$v[1];
}
