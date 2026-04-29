<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function diag_last_source_photo_url(string $logPath): string
{
  if (!is_file($logPath) || !is_readable($logPath)) {
    return '';
  }

  $size = filesize($logPath);
  if ($size === false || $size <= 0) {
    return '';
  }

  $readBytes = min($size, 2 * 1024 * 1024);
  $fp = fopen($logPath, 'rb');
  if ($fp === false) {
    return '';
  }

  if ($size > $readBytes) {
    fseek($fp, $size - $readBytes);
  }

  $chunk = stream_get_contents($fp);
  fclose($fp);
  if ($chunk === false || $chunk === '') {
    return '';
  }

  $lines = preg_split('/\R/', $chunk) ?: [];
  for ($i = count($lines) - 1; $i >= 0; $i--) {
    $line = trim($lines[$i]);
    if ($line === '' || strpos($line, 'source_photo_url') === false) {
      continue;
    }
    $decoded = json_decode($line, true);
    if (!is_array($decoded)) {
      continue;
    }
    $url = (string)($decoded['payload']['source_photo_url'] ?? '');
    if ($url !== '') {
      return $url;
    }
  }

  return '';
}

function diag_http_code(array $headers): int
{
  foreach ($headers as $h) {
    if (preg_match('~^HTTP/\d\.\d\s+(\d{3})~i', (string)$h, $m)) {
      return (int)$m[1];
    }
  }
  return 0;
}

$logPath = __DIR__ . '/logs/audit-fallback.log';
$inputUrl = trim((string)($_GET['url'] ?? ''));
$logUrl = diag_last_source_photo_url($logPath);
$url = $inputUrl !== '' ? $inputUrl : $logUrl;

$dir = __DIR__ . '/storage/ym_link_bot/test';
$mkdirOk = true;
$mkdirError = '';
if (!is_dir($dir)) {
  $mkdirOk = @mkdir($dir, 0775, true);
  if (!$mkdirOk) {
    $mkdirError = (string)((error_get_last()['message'] ?? 'mkdir failed'));
  }
}

$bin = false;
$downloadMethod = 'file_get_contents';
$downloadError = '';
$httpCode = 0;
$httpHeaders = [];
$curlErrno = 0;
$curlError = '';

if ($url !== '') {
  $ctx = stream_context_create([
    'http' => [
      'timeout' => 25,
      'ignore_errors' => true,
      'header' => "User-Agent: Mozilla/5.0 (diag_foto.php)\r\n",
    ],
    'ssl' => [
      'verify_peer' => true,
      'verify_peer_name' => true,
    ],
  ]);

  $bin = @file_get_contents($url, false, $ctx);
  $httpHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
  $httpCode = diag_http_code($httpHeaders);
  if ($bin === false || $bin === '') {
    $downloadError = (string)((error_get_last()['message'] ?? 'file_get_contents failed'));
  }

  if (($bin === false || $bin === '') && function_exists('curl_init')) {
    $downloadMethod = 'curl';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => 25,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_USERAGENT => 'Mozilla/5.0 (diag_foto.php curl)',
      CURLOPT_HTTPHEADER => ['Accept: */*'],
    ]);
    $curlBody = curl_exec($ch);
    $curlErrno = (int)curl_errno($ch);
    $curlError = (string)curl_error($ch);
    $curlCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($curlCode > 0) {
      $httpCode = $curlCode;
    }
    if ($curlBody !== false && $curlBody !== '') {
      $bin = $curlBody;
      $downloadError = '';
    } elseif ($curlErrno !== 0 && $downloadError === '') {
      $downloadError = 'curl error: ' . $curlError;
    }
  }
}

$savePath = $dir . '/test_' . date('Ymd_His') . '.jpg';
$save = false;
$saveError = '';
if ($bin !== false && $bin !== '') {
  $save = @file_put_contents($savePath, $bin);
  if ($save === false) {
    $saveError = (string)((error_get_last()['message'] ?? 'file_put_contents failed'));
  }
}

$host = parse_url($url, PHP_URL_HOST);
$resolvedIp = is_string($host) && $host !== '' ? gethostbyname($host) : '';
$dnsOk = ($resolvedIp !== '' && $resolvedIp !== $host);

echo json_encode([
  'url_source' => $inputUrl !== '' ? 'query' : 'log',
  'url' => $url,
  'log_path' => $logPath,
  'log_readable' => is_readable($logPath),
  'log_size' => (int)(is_file($logPath) ? filesize($logPath) : 0),
  'download_ok' => ($bin !== false && $bin !== ''),
  'download_method' => $downloadMethod,
  'download_error' => $downloadError,
  'http_code' => $httpCode,
  'bytes' => strlen((string)$bin),
  'save_ok' => ($save !== false),
  'save_bytes' => ($save === false ? 0 : $save),
  'save_path' => ($save === false ? '' : $savePath),
  'save_error' => $saveError,
  'dir' => $dir,
  'dir_exists' => is_dir($dir),
  'dir_writable' => is_writable($dir),
  'mkdir_ok' => $mkdirOk,
  'mkdir_error' => $mkdirError,
  'host' => $host,
  'dns_ok' => $dnsOk,
  'resolved_ip' => $resolvedIp,
  'allow_url_fopen' => (bool)ini_get('allow_url_fopen'),
  'openssl_loaded' => extension_loaded('openssl'),
  'curl_loaded' => extension_loaded('curl'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
