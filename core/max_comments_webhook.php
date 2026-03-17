<?php
/**
 * FILE: /core/max_comments_webhook.php
 * ROLE: Публичный webhook endpoint для модуля max_comments.
 * FLOW:
 *  - поднимает bootstrap;
 *  - проверяет, что модуль включен;
 *  - передает обработку в модульный webhook.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__));
}

require_once ROOT_PATH . '/core/bootstrap.php';
require_once ROOT_PATH . '/adm/modules/max_comments/settings.php';

if (function_exists('audit_log')) {
  audit_log(MAX_COMMENTS_MODULE_CODE, 'webhook_core_hit', 'info', [
    'method' => (string)($_SERVER['REQUEST_METHOD'] ?? ''),
    'content_type' => (string)($_SERVER['CONTENT_TYPE'] ?? ''),
    'content_length' => (int)($_SERVER['CONTENT_LENGTH'] ?? 0),
    'request_uri' => (string)($_SERVER['REQUEST_URI'] ?? ''),
  ]);
}

$moduleEnabled = function_exists('module_is_enabled') && module_is_enabled(MAX_COMMENTS_MODULE_CODE);
if (!$moduleEnabled) {
  if (function_exists('audit_log')) {
    audit_log(MAX_COMMENTS_MODULE_CODE, 'webhook_core_reject', 'warn', [
      'reason' => 'module_disabled',
    ]);
  }
  json_err('Module disabled', 404);
}

require ROOT_PATH . '/adm/modules/max_comments/webhook.php';
