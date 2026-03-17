<?php
/**
 * FILE: /adm/modules/tg_system_clients/assets/php/tg_system_clients_send_test.php
 * ROLE: send_test — отправка тестового Telegram-сообщения клиенту
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
  flash('Доступ запрещён', 'danger', 1);
  redirect('/adm/index.php?m=tg_system_clients');
}

$targetClientId = (int)($_POST['client_id'] ?? ($_POST['user_id'] ?? 0));
if ($targetClientId <= 0) {
  flash('Некорректный клиент', 'warn');
  redirect('/adm/index.php?m=tg_system_clients');
}

try {
  $pdo = db();

  $message = 'Тестовое уведомление CRM2026 для клиента. Время сервера: ' . date('Y-m-d H:i:s');
  $result = tg_system_clients_send_system($pdo, $message, TG_SYSTEM_CLIENTS_EVENT_GENERAL, [
    'client_ids' => [$targetClientId],
  ]);

  if (($result['ok'] ?? false) !== true) {
    audit_log('tg_system_clients', 'send_test', 'warn', [
      'target_client_id' => $targetClientId,
      'reason' => (string)($result['reason'] ?? ''),
      'message' => (string)($result['message'] ?? ''),
    ], 'module', null, $uid, $actorRole);

    flash('Тест не отправлен: ' . (string)($result['message'] ?? 'неизвестная ошибка'), 'warn');
    redirect('/adm/index.php?m=tg_system_clients');
  }

  $targets = (int)($result['targets'] ?? 0);
  $sent = (int)($result['sent'] ?? 0);
  $failed = (int)($result['failed'] ?? 0);

  audit_log('tg_system_clients', 'send_test', 'info', [
    'target_client_id' => $targetClientId,
    'targets' => $targets,
    'sent' => $sent,
    'failed' => $failed,
  ], 'module', null, $uid, $actorRole);

  if ($targets <= 0) {
    flash('Тест не отправлен: у клиента нет активной TG-привязки или событие отключено.', 'warn');
  } elseif ($sent > 0 && $failed === 0) {
    flash('Тестовое сообщение отправлено.', 'ok');
  } elseif ($sent > 0) {
    flash('Тест отправлен частично: успешно ' . $sent . ', ошибок ' . $failed . '.', 'warn');
  } else {
    flash('Тест не доставлен: ошибок ' . $failed . '.', 'warn');
  }
} catch (Throwable $e) {
  audit_log('tg_system_clients', 'send_test', 'error', [
    'target_client_id' => $targetClientId,
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);

  flash('Ошибка отправки тестового сообщения', 'danger', 1);
}

redirect('/adm/index.php?m=tg_system_clients');