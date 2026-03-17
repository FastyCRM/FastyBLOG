<?php
/**
 * FILE: /adm/modules/requests/assets/php/requests_comment_add.php
 * ROLE: comment_add — добавление комментария
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/requests_lib.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('requests'));

/**
 * CSRF
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

/**
 * $pdo — БД
 */
$pdo = db();

/**
 * $uid — пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);

/**
 * $roles — роли
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

/**
 * $isAdmin — админ
 */
$isAdmin = requests_is_admin($roles);

/**
 * $isManager — менеджер
 */
$isManager = requests_is_manager($roles);

/**
 * $actorRole — роль
 */
$actorRole = function_exists('auth_user_role') ? (string)auth_user_role() : '';

/**
 * Входные поля
 */
/**
 * $id — заявка
 */
$id = (int)($_POST['id'] ?? 0);
/**
 * $text — текст
 */
$text = trim((string)($_POST['comment'] ?? ''));

if ($id <= 0 || $text === '') {
  audit_log('requests', 'comment', 'warn', [
    'reason' => 'validation',
    'id' => ($id > 0 ? $id : null),
    'has_text' => ($text !== ''),
  ], 'request', ($id > 0 ? $id : null), $uid, $actorRole);
  flash('Комментарий пустой', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

/**
 * $row — заявка
 */
/**
 * $st — запрос заявки
 */
$st = $pdo->prepare("SELECT status, specialist_user_id, taken_by, done_by, client_id FROM " . REQUESTS_TABLE . " WHERE id = :id LIMIT 1");
$st->execute([':id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);

if (!$row) {
  audit_log('requests', 'comment', 'warn', [
    'reason' => 'not_found',
    'id' => $id,
  ], 'request', $id, $uid, $actorRole);
  flash('Заявка не найдена', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

/**
 * Проверка доступа
 */
/**
 * $allow — флаг доступа
 */
$allow = false;

if ($isAdmin || $isManager) {
  $allow = true;
} else {
  /**
   * $specId — специалист
   */
  $specId = (int)($row['specialist_user_id'] ?? 0);
  /**
   * $takenBy — кто принял
   */
  $takenBy = (int)($row['taken_by'] ?? 0);
  /**
   * $doneBy — кто завершил
   */
  $doneBy = (int)($row['done_by'] ?? 0);

  if ($specId === $uid || $takenBy === $uid || $doneBy === $uid) {
    $allow = true;
  }
}

if (!$allow) {
  audit_log('requests', 'comment', 'warn', [
    'reason' => 'deny',
    'id' => $id,
  ], 'request', $id, $uid, $actorRole);
  flash('Нет доступа', 'warn');
  redirect_return('/adm/index.php?m=requests');
}

try {
  /**
   * $clientId — id клиента
   */
  $clientId = (int)($row['client_id'] ?? 0);
  requests_add_comment($pdo, $id, $uid, $clientId > 0 ? $clientId : null, 'user', $text);
  requests_add_history($pdo, $id, $uid, 'comment', null, null, []);

  audit_log('requests', 'comment', 'info', [
    'id' => $id,
  ], 'request', $id, $uid, $actorRole);

  flash('Комментарий добавлен', 'ok');
} catch (Throwable $e) {
  audit_log('requests', 'comment', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'request', $id, $uid, $actorRole);

  flash('Ошибка комментария', 'danger', 1);
}

redirect_return('/adm/index.php?m=requests');
