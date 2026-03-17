<?php
/**
 * FILE: /adm/modules/bot_adv_calendar/assets/php/bot_adv_calendar_user_settings_save.php
 * ROLE: user_settings_save модуля bot_adv_calendar
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

try {
  $pdo = db();
  if (!bot_adv_calendar_user_has_crm_access($pdo, $uid, $roles)) {
    audit_log('bot_adv_calendar', 'user_settings_save', 'warn', [
      'reason' => 'forbidden_user',
      'user_id' => $uid,
    ], 'module', null, $uid, $actorRole);
    flash('Пользователь не подключен к bot_adv_calendar', 'danger', 1);
    redirect('/adm/index.php?m=bot_adv_calendar');
  }

  bot_adv_calendar_user_options_save($pdo, $uid, $_POST, $uid);

  audit_log('bot_adv_calendar', 'user_settings_save', 'info', [
    'user_id' => $uid,
  ], 'module', null, $uid, $actorRole);
  flash('Настройки рекламного календаря сохранены', 'ok');
} catch (Throwable $e) {
  audit_log('bot_adv_calendar', 'user_settings_save', 'error', [
    'user_id' => $uid,
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);
  $err = trim((string)$e->getMessage());
  flash($err !== '' ? $err : 'Ошибка сохранения настроек', 'danger', 1);
}

redirect('/adm/index.php?m=bot_adv_calendar');

