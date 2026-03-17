<?php
/**
 * FILE: /adm/modules/bot_adv_calendar/assets/php/bot_adv_calendar_user_detach.php
 * ROLE: user_detach модуля bot_adv_calendar
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
  audit_log('bot_adv_calendar', 'user_detach', 'warn', [
    'reason' => 'forbidden_role',
  ], 'module', null, $uid, $actorRole);
  flash('Доступ запрещен', 'danger', 1);
  redirect('/adm/index.php?m=bot_adv_calendar');
}

$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
  audit_log('bot_adv_calendar', 'user_detach', 'warn', [
    'reason' => 'bad_input',
    'user_id' => $userId,
  ], 'module', null, $uid, $actorRole);
  flash('Некорректный пользователь CRM', 'warn');
  redirect('/adm/index.php?m=bot_adv_calendar');
}

try {
  $pdo = db();
  $ok = bot_adv_calendar_user_detach($pdo, $userId);

  if ($ok) {
    audit_log('bot_adv_calendar', 'user_detach', 'info', [
      'user_id' => $userId,
    ], 'module', null, $uid, $actorRole);
    flash('Пользователь CRM #' . $userId . ' отключен от bot_adv_calendar. TG-привязка и коды деактивированы.', 'ok');
  } else {
    audit_log('bot_adv_calendar', 'user_detach', 'warn', [
      'reason' => 'detach_failed',
      'user_id' => $userId,
    ], 'module', null, $uid, $actorRole);
    flash('Не удалось отключить пользователя CRM', 'warn');
  }
} catch (Throwable $e) {
  audit_log('bot_adv_calendar', 'user_detach', 'error', [
    'user_id' => $userId,
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);
  flash('Ошибка отключения пользователя CRM', 'danger', 1);
}

redirect('/adm/index.php?m=bot_adv_calendar');

