<?php
/**
 * FILE: /adm/modules/bot_adv_calendar/assets/php/bot_adv_calendar_requests_clear.php
 * ROLE: тестовая очистка данных заявок для bot_adv_calendar
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

$isAdmin = in_array('admin', $roles, true);
if (!$isAdmin) {
  audit_log('bot_adv_calendar', 'requests_clear', 'warn', [
    'reason' => 'forbidden_role',
  ], 'module', null, $uid, $actorRole);
  flash('Доступ запрещен', 'danger', 1);
  redirect('/adm/index.php?m=bot_adv_calendar');
}

$confirm = trim((string)($_POST['confirm_clear'] ?? ''));
if ($confirm !== 'yes') {
  audit_log('bot_adv_calendar', 'requests_clear', 'warn', [
    'reason' => 'confirm_required',
  ], 'module', null, $uid, $actorRole);
  flash('Нужно подтверждение операции', 'warn');
  redirect('/adm/index.php?m=bot_adv_calendar');
}

try {
  $pdo = db();
  $result = bot_adv_calendar_requests_clear_test($pdo);
  $deleted = (array)($result['deleted'] ?? []);

  audit_log('bot_adv_calendar', 'requests_clear', 'info', [
    'deleted' => $deleted,
    'total' => (int)($result['total'] ?? 0),
  ], 'module', null, $uid, $actorRole);

  $msg = 'Тестовая очистка выполнена. Удалено строк: заявки=' . (int)($deleted[REQUESTS_TABLE] ?? 0)
    . ', история=' . (int)($deleted[REQUESTS_HISTORY_TABLE] ?? 0)
    . ', комментарии=' . (int)($deleted[REQUESTS_COMMENTS_TABLE] ?? 0)
    . ', счета=' . (int)($deleted[REQUESTS_INVOICES_TABLE] ?? 0)
    . ', позиции счетов=' . (int)($deleted[REQUESTS_INVOICE_ITEMS_TABLE] ?? 0) . '.';
  flash($msg, 'ok');
} catch (Throwable $e) {
  audit_log('bot_adv_calendar', 'requests_clear', 'error', [
    'error' => $e->getMessage(),
  ], 'module', null, $uid, $actorRole);
  flash('Не удалось очистить заявки в тестовом режиме', 'danger', 1);
}

redirect('/adm/index.php?m=bot_adv_calendar');
