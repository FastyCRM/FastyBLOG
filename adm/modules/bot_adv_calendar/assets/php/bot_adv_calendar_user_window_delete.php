<?php
/**
 * FILE: /adm/modules/bot_adv_calendar/assets/php/bot_adv_calendar_user_window_delete.php
 * ROLE: user_window_delete модуля bot_adv_calendar
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
$windowId = (int)($_POST['window_id'] ?? 0);

try {
  $pdo = db();
  if (!bot_adv_calendar_user_has_crm_access($pdo, $uid, $roles)) {
    audit_log('bot_adv_calendar', 'user_window_delete', 'warn', [
      'reason' => 'forbidden_user',
      'user_id' => $uid,
      'window_id' => $windowId,
    ], 'module', null, $uid, $actorRole);
    flash('Пользователь не подключен к bot_adv_calendar', 'danger', 1);
    redirect('/adm/index.php?m=bot_adv_calendar');
  }

  if ($windowId <= 0) {
    flash('Некорректное окно', 'warn');
    redirect('/adm/index.php?m=bot_adv_calendar');
  }

  $ok = bot_adv_calendar_user_window_delete($pdo, $uid, $windowId);
  if ($ok) {
    audit_log('bot_adv_calendar', 'user_window_delete', 'info', [
      'user_id' => $uid,
      'window_id' => $windowId,
    ], 'module', null, $uid, $actorRole);
    flash('Тарифное окно удалено', 'ok');
  } else {
    flash('Окно не найдено или уже удалено', 'warn');
  }
} catch (Throwable $e) {
  audit_log('bot_adv_calendar', 'user_window_delete', 'error', [
    'user_id' => $uid,
    'window_id' => $windowId,
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);
  flash('Ошибка удаления тарифного окна', 'danger', 1);
}

redirect('/adm/index.php?m=bot_adv_calendar');

