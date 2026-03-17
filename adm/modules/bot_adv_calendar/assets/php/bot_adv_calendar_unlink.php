<?php
/**
 * FILE: /adm/modules/bot_adv_calendar/assets/php/bot_adv_calendar_unlink.php
 * ROLE: unlink модуля bot_adv_calendar
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
  audit_log('bot_adv_calendar', 'unlink', 'warn', [
    'reason' => 'forbidden_role',
  ], 'module', null, $uid, $actorRole);
  flash('Доступ запрещен', 'danger', 1);
  redirect('/adm/index.php?m=bot_adv_calendar');
}

$actorType = trim((string)($_POST['actor_type'] ?? ''));
$actorId = (int)($_POST['actor_id'] ?? 0);

if (!bot_adv_calendar_is_actor_type($actorType) || $actorId <= 0) {
  audit_log('bot_adv_calendar', 'unlink', 'warn', [
    'reason' => 'bad_input',
    'actor_type' => $actorType,
    'actor_id' => $actorId,
  ], 'module', null, $uid, $actorRole);
  flash('Некорректные параметры отвязки', 'warn');
  redirect('/adm/index.php?m=bot_adv_calendar');
}

try {
  $pdo = db();
  $ok = bot_adv_calendar_unlink($pdo, $actorType, $actorId);

  if ($ok) {
    audit_log('bot_adv_calendar', 'unlink', 'info', [
      'actor_type' => $actorType,
      'actor_id' => $actorId,
    ], 'module', null, $uid, $actorRole);
    flash('Привязка отключена', 'ok');
  } else {
    audit_log('bot_adv_calendar', 'unlink', 'warn', [
      'reason' => 'link_not_found',
      'actor_type' => $actorType,
      'actor_id' => $actorId,
    ], 'module', null, $uid, $actorRole);
    flash('Активная привязка не найдена', 'warn');
  }
} catch (Throwable $e) {
  audit_log('bot_adv_calendar', 'unlink', 'error', [
    'actor_type' => $actorType,
    'actor_id' => $actorId,
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);
  flash('Ошибка отвязки Telegram', 'danger', 1);
}

redirect('/adm/index.php?m=bot_adv_calendar');

