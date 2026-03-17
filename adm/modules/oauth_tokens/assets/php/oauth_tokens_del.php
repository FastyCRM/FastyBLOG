<?php
/**
 * FILE: /adm/modules/oauth_tokens/assets/php/oauth_tokens_del.php
 * ROLE: del — удаление OAuth-токена (admin only)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/oauth_tokens_lib.php';

acl_guard(module_allowed_roles('oauth_tokens'));

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_405('Method Not Allowed');
}

/**
 * $csrf — CSRF-токен из формы.
 */
$csrf = (string)($_POST['csrf'] ?? '');
csrf_check($csrf);

if (!oauth_tokens_is_admin()) {
  json_err('Forbidden', 403);
}

/**
 * $id — id удаляемого токена.
 */
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  json_err('Bad id', 400);
}

/**
 * $pdo — соединение с БД.
 */
$pdo = db();

/**
 * $actorId — id текущего пользователя.
 */
$actorId = (int)auth_user_id();

/**
 * $actorRole — роль текущего пользователя.
 */
$actorRole = (string)auth_user_role();

try {
  $pdo->beginTransaction();

  /**
   * $stDelLink — удаляем привязку токена к пользователю.
   */
  $stDelLink = $pdo->prepare('DELETE FROM ' . OAUTH_TOKENS_USERS_TABLE . ' WHERE oauth_token_id = :oauth_token_id');
  $stDelLink->execute([':oauth_token_id' => $id]);

  /**
   * $stDel — удаляем токен.
   */
  $stDel = $pdo->prepare('DELETE FROM ' . OAUTH_TOKENS_TABLE . ' WHERE id = :id LIMIT 1');
  $stDel->execute([':id' => $id]);

  if ($stDel->rowCount() <= 0) {
    $pdo->rollBack();
    json_err('Token not found', 404);
  }

  $pdo->commit();

  audit_log('oauth_tokens', 'delete', 'info', [
    'id' => $id,
  ], 'oauth_token', $id, $actorId, $actorRole);

  json_ok(['id' => $id]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  audit_log('oauth_tokens', 'delete', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'oauth_token', $id, $actorId, $actorRole);

  json_err('Delete failed', 500);
}
