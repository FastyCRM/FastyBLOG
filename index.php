<?php
/**
 * FILE: /index.php
 * ROLE: Root dispatcher (adm/site splitter) for CRM2026NEW
 * CONNECTIONS:
 *  - /adm/index.php
 *  - /site/index.php
 *
 * NOTES:
 *  - Никаких core в корне проекта.
 *  - Админка живёт в /adm (и сама уже внутри подключает /adm/core/bootstrap.php).
 */

declare(strict_types=1);

define('ROOT_PATH', __DIR__);

/**
 * 1) request path (без query)
 */
$reqPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$reqPath = $reqPath === '' ? '/' : $reqPath;

/**
 * 2) baseUri проекта (корень домена или подпапка)
 * SCRIPT_NAME:
 *  - /index.php -> baseUri = ''
 *  - /crm2026new/index.php -> baseUri = '/crm2026new'
 */
$script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
$baseUri = rtrim(str_replace('/index.php', '', $script), '/');
$baseUri = ($baseUri === '/') ? '' : $baseUri;

/**
 * 3) uri внутри проекта (снимаем baseUri)
 */
$uri = $reqPath;
if ($baseUri !== '' && strpos($uri, $baseUri) === 0) {
  $uri = substr($uri, strlen($baseUri));
}
$uri = '/' . ltrim($uri, '/');
$uri = rtrim($uri, '/') ?: '/';

/**
 * 4) routing
 */
if ($uri === '/adm' || strpos($uri, '/adm/') === 0) {
  require_once ROOT_PATH . '/adm/index.php';
  exit;
}

require_once ROOT_PATH . '/site/index.php';
