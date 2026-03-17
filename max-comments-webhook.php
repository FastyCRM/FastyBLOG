<?php
/**
 * FILE: /max-comments-webhook.php
 * ROLE: Публичный webhook endpoint для модуля max_comments.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', __DIR__);
}

require_once ROOT_PATH . '/core/bootstrap.php';
require_once ROOT_PATH . '/adm/modules/max_comments/settings.php';

if (!function_exists('module_is_enabled') || !module_is_enabled(MAX_COMMENTS_MODULE_CODE)) {
  if (function_exists('json_err')) {
    json_err('Module disabled', 404);
  }
  http_response_code(404);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'error' => 'Module disabled'], JSON_UNESCAPED_UNICODE);
  exit;
}

require ROOT_PATH . '/adm/modules/max_comments/webhook.php';

