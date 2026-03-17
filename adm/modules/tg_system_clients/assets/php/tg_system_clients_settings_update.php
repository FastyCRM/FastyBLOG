<?php
/**
 * FILE: /adm/modules/tg_system_clients/assets/php/tg_system_clients_settings_update.php
 * ROLE: settings_update — сохранение настроек TG-бота клиентов
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/tg_system_clients_lib.php';

acl_guard(module_allowed_roles('tg_system_clients'));

$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if (!tg_system_clients_is_manage_role($roles)) {
  audit_log('tg_system_clients', 'settings_update', 'warn', [
    'reason' => 'forbidden_role',
  ], 'module', null, $uid, $actorRole);
  flash('Доступ запрещён', 'danger', 1);
  redirect('/adm/index.php?m=tg_system_clients');
}

$pdo = db();
$input = [
  'enabled' => (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0,
  'bot_token' => (string)($_POST['bot_token'] ?? ''),
  'webhook_secret' => (string)($_POST['webhook_secret'] ?? ''),
  'webhook_url' => (string)($_POST['webhook_url'] ?? ''),
  'default_parse_mode' => (string)($_POST['default_parse_mode'] ?? 'HTML'),
  'token_ttl_minutes' => (int)($_POST['token_ttl_minutes'] ?? 15),
  'retention_days' => (int)($_POST['retention_days'] ?? 7),
];

$applyWebhook = ((int)($_POST['apply_webhook'] ?? 0) === 1);

try {
  $saved = tg_system_clients_settings_save($pdo, $input);

  audit_log('tg_system_clients', 'settings_update', 'info', [
    'enabled' => (int)($saved['enabled'] ?? 0),
    'webhook_url' => (string)($saved['webhook_url'] ?? ''),
    'token_ttl_minutes' => (int)($saved['token_ttl_minutes'] ?? 15),
    'retention_days' => (int)($saved['retention_days'] ?? 7),
  ], 'module', null, $uid, $actorRole);

  if ($applyWebhook) {
    $res = tg_system_clients_apply_webhook($saved);
    if (($res['ok'] ?? false) === true) {
      flash('Настройки сохранены, webhook обновлён', 'ok');
    } else {
      $err = trim((string)($res['description'] ?? $res['error'] ?? 'Telegram API error'));
      flash('Настройки сохранены, но webhook не обновлён: ' . $err, 'warn');
    }
  } else {
    flash('Настройки Telegram сохранены', 'ok');
  }
} catch (Throwable $e) {
  audit_log('tg_system_clients', 'settings_update', 'error', [
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);
  flash('Ошибка сохранения настроек Telegram', 'danger', 1);
}

redirect('/adm/index.php?m=tg_system_clients');