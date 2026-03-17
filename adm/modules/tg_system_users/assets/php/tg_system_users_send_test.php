<?php
/**
 * FILE: /adm/modules/tg_system_users/assets/php/tg_system_users_send_test.php
 * ROLE: send_test — отправка тестового Telegram-сообщения конкретному пользователю
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/tg_system_users_lib.php';

acl_guard(module_allowed_roles('tg_system_users'));

/**
 * $csrf — CSRF токен.
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

/**
 * $uid — текущий пользователь.
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
/**
 * $roles — роли текущего пользователя.
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
/**
 * $actorRole — роль для аудита.
 */
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

if (!tg_system_users_is_manage_role($roles)) {
  flash('Доступ запрещён', 'danger', 1);
  redirect('/adm/index.php?m=tg_system_users');
}

/**
 * $targetUserId — пользователь, которому отправляется тест.
 */
$targetUserId = (int)($_POST['user_id'] ?? 0);
if ($targetUserId <= 0) {
  flash('Некорректный пользователь', 'warn');
  redirect('/adm/index.php?m=tg_system_users');
}

try {
  $pdo = db();

  /**
   * $message — текст тестового уведомления.
   */
  $message = 'Тестовое системное уведомление CRM2026. Время сервера: ' . date('Y-m-d H:i:s');
  $result = tg_system_users_send_system($pdo, $message, TG_SYSTEM_USERS_EVENT_GENERAL, [
    'user_ids' => [$targetUserId],
  ]);

  if (($result['ok'] ?? false) !== true) {
    audit_log('tg_system_users', 'send_test', 'warn', [
      'target_user_id' => $targetUserId,
      'reason' => (string)($result['reason'] ?? ''),
      'message' => (string)($result['message'] ?? ''),
    ], 'module', null, $uid, $actorRole);

    flash('Тест не отправлен: ' . (string)($result['message'] ?? 'неизвестная ошибка'), 'warn');
    redirect('/adm/index.php?m=tg_system_users');
  }

  /**
   * $targets — количество найденных получателей.
   */
  $targets = (int)($result['targets'] ?? 0);
  /**
   * $sent — количество успешных отправок.
   */
  $sent = (int)($result['sent'] ?? 0);
  /**
   * $failed — количество ошибок отправки.
   */
  $failed = (int)($result['failed'] ?? 0);

  audit_log('tg_system_users', 'send_test', 'info', [
    'target_user_id' => $targetUserId,
    'targets' => $targets,
    'sent' => $sent,
    'failed' => $failed,
  ], 'module', null, $uid, $actorRole);

  if ($targets <= 0) {
    flash('Тест не отправлен: у пользователя нет активной TG-привязки или событие отключено.', 'warn');
  } elseif ($sent > 0 && $failed === 0) {
    flash('Тестовое сообщение отправлено.', 'ok');
  } elseif ($sent > 0) {
    flash('Тест отправлен частично: успешно ' . $sent . ', ошибок ' . $failed . '.', 'warn');
  } else {
    flash('Тест не доставлен: ошибок ' . $failed . '.', 'warn');
  }
} catch (Throwable $e) {
  audit_log('tg_system_users', 'send_test', 'error', [
    'target_user_id' => $targetUserId,
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);

  flash('Ошибка отправки тестового сообщения', 'danger', 1);
}

redirect('/adm/index.php?m=tg_system_users');

