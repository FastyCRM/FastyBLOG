<?php
/**
 * FILE: /core/mailer.php
 * ROLE: CORE — отправка почты (SMTP) для модулей
 * CONNECTIONS:
 *  - app_config() из /core/session.php (читает $GLOBALS['APP_CONFIG'])
 *  - audit_log() из /core/audit.php (если есть)
 *
 * СПИСОК ФУНКЦИЙ:
 *
 * mailer_config(): array
 *   Возвращает конфиг почты из app_config()['mail'].
 *
 * mailer_send(string $to, string $subject, string $body, array $opts = [], ?string &$error = null): bool
 *   Отправляет письмо через SMTP. Возвращает true/false. При ошибке пишет текст в $error.
 *
 * mailer_is_enabled(): bool
 *   Проверяет, включена ли почта в конфиге и есть ли обязательные поля.
 *
 * mailer_build_headers(string $to, string $subject, array $cfg, array $opts): array
 *   Формирует заголовки письма (From/To/Subject/MIME).
 *
 * mailer_smtp_send(array $cfg, string $fromEmail, string $toEmail, string $data, ?string &$error = null): bool
 *   Низкоуровневая отправка SMTP (connect → EHLO → AUTH → MAIL FROM → RCPT TO → DATA).
 *
 * mailer_smtp_expect($fp, array $codes, ?string &$error = null): bool
 *   Читает ответ SMTP и проверяет код.
 *
 * mailer_smtp_write($fp, string $line): void
 *   Пишет команду SMTP.
 *
 * mailer_str(string $s): string
 *   Нормализация строк (без \r).
 */

declare(strict_types=1);

function mailer_config(): array
{
  if (!function_exists('app_config')) return [];
  $cfg = app_config();
  return (array)($cfg['mail'] ?? []);
}

function mailer_is_enabled(): bool
{
  $cfg = mailer_config();
  if (!(bool)($cfg['enabled'] ?? false)) return false;

  $driver = (string)($cfg['driver'] ?? 'smtp');
  if ($driver !== 'smtp') return false;

  $host = (string)($cfg['host'] ?? '');
  $port = (int)($cfg['port'] ?? 0);
  $user = (string)($cfg['user'] ?? '');
  $pass = (string)($cfg['pass'] ?? '');
  $from = (string)($cfg['from_email'] ?? '');

  return ($host !== '' && $port > 0 && $user !== '' && $pass !== '' && $from !== '');
}

/**
 * Отправка письма через SMTP.
 * $opts:
 *  - from_email (string)
 *  - from_name  (string)
 *  - reply_to   (string)
 *  - content_type ('text/plain'|'text/html')
 */
function mailer_send(string $to, string $subject, string $body, array $opts = [], ?string &$error = null): bool
{
  $error = null;

  if (!mailer_is_enabled()) {
    $error = 'MAILER_DISABLED';
    if (function_exists('audit_log')) {
      // FIX: единый формат вызова audit_log(module, action, level, payload, ...)
      audit_log('core', 'mailer_disabled', 'warn', ['to' => $to]);
    }
    return false;
  }

  $cfg = mailer_config();

  $toEmail = trim($to);
  if ($toEmail === '') {
    $error = 'EMPTY_TO';
    return false;
  }

  $fromEmail = (string)($opts['from_email'] ?? $cfg['from_email'] ?? '');
  $fromEmail = trim($fromEmail);
  if ($fromEmail === '') {
    $error = 'EMPTY_FROM';
    return false;
  }

  $headers = mailer_build_headers($toEmail, $subject, $cfg, $opts);
  $data = implode("\r\n", $headers) . "\r\n\r\n" . mailer_str($body) . "\r\n";

  $ok = mailer_smtp_send($cfg, $fromEmail, $toEmail, $data, $error);

  if (function_exists('audit_log')) {
    // FIX: единый формат вызова audit_log(module, action, level, payload, ...)
    audit_log('core', $ok ? 'mailer_sent' : 'mailer_failed', $ok ? 'info' : 'error', [
      'to' => $toEmail,
      'subject' => $subject,
      'error' => $ok ? null : $error,
    ]);
  }

  return $ok;
}

function mailer_build_headers(string $to, string $subject, array $cfg, array $opts): array
{
  $fromEmail = (string)($opts['from_email'] ?? $cfg['from_email'] ?? '');
  $fromName  = (string)($opts['from_name']  ?? $cfg['from_name']  ?? '');
  $replyTo   = (string)($opts['reply_to']   ?? $cfg['reply_to']   ?? '');

  $contentType = (string)($opts['content_type'] ?? $cfg['content_type'] ?? 'text/plain');
  if ($contentType !== 'text/plain' && $contentType !== 'text/html') {
    $contentType = 'text/plain';
  }

  // Кодируем Subject в UTF-8 (RFC 2047)
  $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';

  // From: с именем (если задано)
  $fromHeader = $fromEmail;
  if ($fromName !== '') {
    $fromNameEnc = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $fromHeader = $fromNameEnc . ' <' . $fromEmail . '>';
  }

  $headers = [];
  $headers[] = 'Date: ' . date('r');
  $headers[] = 'From: ' . $fromHeader;
  $headers[] = 'To: ' . $to;
  $headers[] = 'Subject: ' . $subjectEnc;
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-Type: ' . $contentType . '; charset=UTF-8';
  $headers[] = 'Content-Transfer-Encoding: 8bit';

  if ($replyTo !== '') {
    $headers[] = 'Reply-To: ' . $replyTo;
  }

  return $headers;
}

function mailer_smtp_send(array $cfg, string $fromEmail, string $toEmail, string $data, ?string &$error = null): bool
{
  $error = null;

  $host = (string)($cfg['host'] ?? '');
  $port = (int)($cfg['port'] ?? 587);
  $timeout = (int)($cfg['timeout'] ?? 15);

  $secure = (string)($cfg['secure'] ?? 'tls'); // 'tls'|'ssl'|''

  $user = (string)($cfg['user'] ?? '');
  $pass = (string)($cfg['pass'] ?? '');

  $transport = $host;
  if ($secure === 'ssl') {
    $transport = 'ssl://' . $host;
  }

  $fp = @fsockopen($transport, $port, $errno, $errstr, $timeout);
  if (!$fp) {
    $error = 'CONNECT_FAIL: ' . $errstr . ' (#' . $errno . ')';
    return false;
  }

  stream_set_timeout($fp, $timeout);

  // 220 greeting
  if (!mailer_smtp_expect($fp, [220], $error)) {
    fclose($fp);
    return false;
  }

  $ehloHost = (string)($cfg['ehlo'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost'));
  $ehloHost = $ehloHost !== '' ? $ehloHost : 'localhost';

  mailer_smtp_write($fp, 'EHLO ' . $ehloHost);
  if (!mailer_smtp_expect($fp, [250], $error)) {
    // пробуем HELO (на некоторых серверах)
    mailer_smtp_write($fp, 'HELO ' . $ehloHost);
    if (!mailer_smtp_expect($fp, [250], $error)) {
      fclose($fp);
      return false;
    }
  }

  // STARTTLS (если tls)
  if ($secure === 'tls') {
    mailer_smtp_write($fp, 'STARTTLS');
    if (!mailer_smtp_expect($fp, [220], $error)) {
      fclose($fp);
      return false;
    }

    $cryptoOk = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    if ($cryptoOk !== true) {
      $error = 'TLS_FAIL';
      fclose($fp);
      return false;
    }

    // После TLS — повтор EHLO
    mailer_smtp_write($fp, 'EHLO ' . $ehloHost);
    if (!mailer_smtp_expect($fp, [250], $error)) {
      fclose($fp);
      return false;
    }
  }

  // AUTH LOGIN
  mailer_smtp_write($fp, 'AUTH LOGIN');
  if (!mailer_smtp_expect($fp, [334], $error)) {
    fclose($fp);
    return false;
  }

  mailer_smtp_write($fp, base64_encode($user));
  if (!mailer_smtp_expect($fp, [334], $error)) {
    fclose($fp);
    return false;
  }

  mailer_smtp_write($fp, base64_encode($pass));
  if (!mailer_smtp_expect($fp, [235], $error)) {
    fclose($fp);
    return false;
  }

  // MAIL FROM
  mailer_smtp_write($fp, 'MAIL FROM:<' . $fromEmail . '>');
  if (!mailer_smtp_expect($fp, [250], $error)) {
    fclose($fp);
    return false;
  }

  // RCPT TO
  mailer_smtp_write($fp, 'RCPT TO:<' . $toEmail . '>');
  if (!mailer_smtp_expect($fp, [250, 251], $error)) {
    fclose($fp);
    return false;
  }

  // DATA
  mailer_smtp_write($fp, 'DATA');
  if (!mailer_smtp_expect($fp, [354], $error)) {
    fclose($fp);
    return false;
  }

  // Тело письма. SMTP требует dot-stuffing: строки начинающиеся с "." -> ".."
  $lines = preg_split("~\r\n|\n|\r~", $data);
  foreach ($lines as $line) {
    if ($line !== '' && isset($line[0]) && $line[0] === '.') {
      $line = '.' . $line;
    }
    mailer_smtp_write($fp, $line);
  }

  // конец DATA
  mailer_smtp_write($fp, '.');
  if (!mailer_smtp_expect($fp, [250], $error)) {
    fclose($fp);
    return false;
  }

  mailer_smtp_write($fp, 'QUIT');
  // 221 можно не требовать строго
  @fgets($fp, 512);
  fclose($fp);

  return true;
}

function mailer_smtp_write($fp, string $line): void
{
  $line = mailer_str($line);
  fwrite($fp, $line . "\r\n");
}

function mailer_smtp_expect($fp, array $codes, ?string &$error = null): bool
{
  $error = null;

  $resp = '';
  while (!feof($fp)) {
    $line = fgets($fp, 512);
    if ($line === false) break;
    $resp .= $line;

    // Многострочные ответы: "250-" продолжаются, "250 " заканчиваются
    if (preg_match('~^\d{3}\s~', $line)) {
      break;
    }
  }

  $resp = trim($resp);
  if ($resp === '') {
    $error = 'SMTP_EMPTY_RESPONSE';
    return false;
  }

  $code = (int)substr($resp, 0, 3);
  if (!in_array($code, $codes, true)) {
    $error = 'SMTP_' . $code . ': ' . $resp;
    return false;
  }

  return true;
}

function mailer_str(string $s): string
{
  // убираем CR, чтобы не ломать протокол
  return str_replace("\r", '', $s);
}
