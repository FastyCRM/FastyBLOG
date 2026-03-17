<?php
/**
 * FILE: /adm/modules/bot_adv_calendar/assets/php/bot_adv_calendar_webhook_set.php
 * ROLE: webhook_set модуля bot_adv_calendar
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
  audit_log('bot_adv_calendar', 'webhook_set', 'warn', [
    'reason' => 'forbidden_role',
  ], 'module', null, $uid, $actorRole);
  flash('Доступ запрещен', 'danger', 1);
  redirect('/adm/index.php?m=bot_adv_calendar');
}

$mode = trim((string)($_POST['mode'] ?? 'info'));
if (!in_array($mode, ['info', 'set'], true)) {
  $mode = 'info';
}

try {
  $pdo = db();
  $settings = bot_adv_calendar_settings_get($pdo);
  $res = ($mode === 'set')
    ? bot_adv_calendar_apply_webhook($settings)
    : bot_adv_calendar_webhook_info($settings);

  if (($res['ok'] ?? false) === true) {
    if ($mode === 'set') {
      flash('Webhook успешно установлен', 'ok');
    } else {
      $url = trim((string)($res['result']['url'] ?? ''));
      $pending = (int)($res['result']['pending_update_count'] ?? 0);
      flash('Webhook: ' . ($url !== '' ? $url : 'не задан') . ', pending=' . $pending, 'ok');
    }

    audit_log('bot_adv_calendar', 'webhook_set', 'info', [
      'mode' => $mode,
      'ok' => 1,
    ], 'module', null, $uid, $actorRole);
  } else {
    $err = trim((string)($res['description'] ?? $res['error'] ?? 'Telegram API error'));
    flash('Ошибка webhook: ' . $err, 'warn');

    audit_log('bot_adv_calendar', 'webhook_set', 'warn', [
      'mode' => $mode,
      'ok' => 0,
      'error' => $err,
    ], 'module', null, $uid, $actorRole);
  }
} catch (Throwable $e) {
  audit_log('bot_adv_calendar', 'webhook_set', 'error', [
    'mode' => $mode,
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);
  flash('Ошибка при работе с webhook', 'danger', 1);
}

redirect('/adm/index.php?m=bot_adv_calendar');

