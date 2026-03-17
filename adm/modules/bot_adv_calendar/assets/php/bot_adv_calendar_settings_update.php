<?php
/**
 * FILE: /adm/modules/bot_adv_calendar/assets/php/bot_adv_calendar_settings_update.php
 * ROLE: settings_update модуля bot_adv_calendar
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/bot_adv_calendar_lib.php';

acl_guard(module_allowed_roles('bot_adv_calendar'));

$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if (!bot_adv_calendar_is_manage_role($roles)) {
  audit_log('bot_adv_calendar', 'settings_update', 'warn', [
    'reason' => 'forbidden_role',
  ], 'module', null, $uid, $actorRole);
  flash('Доступ запрещен', 'danger', 1);
  redirect('/adm/index.php?m=bot_adv_calendar');
}

$input = [
  'enabled' => (int)($_POST['enabled'] ?? 0) === 1 ? 1 : 0,
  'bot_token' => (string)($_POST['bot_token'] ?? ''),
  'webhook_secret' => (string)($_POST['webhook_secret'] ?? ''),
  'webhook_url' => (string)($_POST['webhook_url'] ?? ''),
  'default_parse_mode' => 'HTML',
  'token_ttl_minutes' => (int)($_POST['token_ttl_minutes'] ?? 15),
  'retention_days' => (int)($_POST['retention_days'] ?? 7),
];
$applyWebhook = ((int)($_POST['apply_webhook'] ?? 0) === 1);

try {
  $pdo = db();
  $saved = bot_adv_calendar_settings_save($pdo, $input);

  audit_log('bot_adv_calendar', 'settings_update', 'info', [
    'enabled' => (int)($saved['enabled'] ?? 0),
    'webhook_url' => (string)($saved['webhook_url'] ?? ''),
    'token_ttl_minutes' => (int)($saved['token_ttl_minutes'] ?? 15),
    'retention_days' => (int)($saved['retention_days'] ?? 7),
  ], 'module', null, $uid, $actorRole);

  if ($applyWebhook) {
    $res = bot_adv_calendar_apply_webhook($saved);
    if (($res['ok'] ?? false) === true) {
      flash('Настройки сохранены, webhook обновлен', 'ok');
    } else {
      $err = trim((string)($res['description'] ?? $res['error'] ?? 'Telegram API error'));
      flash('Настройки сохранены, но webhook не обновлен: ' . $err, 'warn');
    }
  } else {
    flash('Настройки bot_adv_calendar сохранены', 'ok');
  }
} catch (Throwable $e) {
  audit_log('bot_adv_calendar', 'settings_update', 'error', [
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);
  flash('Ошибка сохранения настроек', 'danger', 1);
}

redirect('/adm/index.php?m=bot_adv_calendar');
