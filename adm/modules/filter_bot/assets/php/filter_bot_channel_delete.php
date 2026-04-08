<?php
/**
 * FILE: /adm/modules/filter_bot/assets/php/filter_bot_channel_delete.php
 * ROLE: Удаление чата из filter_bot.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/filter_bot_lib.php';

acl_guard(module_allowed_roles(FILTER_BOT_MODULE_CODE));

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  http_405('Method Not Allowed');
}

csrf_check((string)($_POST['csrf'] ?? ''));

try {
  $pdo = db();
  filter_bot_require_schema($pdo);
  filter_bot_channel_delete($pdo, (int)($_POST['id'] ?? 0));
  flash('Чат удалён.', 'ok');
} catch (Throwable $e) {
  flash('Ошибка удаления чата: ' . $e->getMessage(), 'danger', 1);
}

redirect('/adm/index.php?m=' . FILTER_BOT_MODULE_CODE);
