<?php
/**
 * FILE: /adm/modules/oauth_tokens/assets/php/oauth_tokens_update.php
 * ROLE: update — обновление OAuth-токена (admin only)
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

/**
 * Входные поля.
 */
$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$clientId = trim((string)($_POST['client_id'] ?? ''));
$clientSecret = trim((string)($_POST['client_secret'] ?? ''));
$assignUserId = (int)($_POST['assign_user_id'] ?? 0);

if ($id <= 0) {
  json_err('Bad id', 400);
}

if ($name === '' || $clientId === '' || $clientSecret === '') {
  json_err('Empty required fields', 400);
}

if ($assignUserId > 0) {
  /**
   * $stUser — проверка, что назначаемый пользователь существует.
   */
  $stUser = $pdo->prepare('SELECT id FROM users WHERE id = :id LIMIT 1');
  $stUser->execute([':id' => $assignUserId]);
  if (!(int)($stUser->fetchColumn() ?: 0)) {
    json_err('Assigned user not found', 400);
  }

  /**
   * $stAssigned — проверка, что пользователь не назначен на другой токен.
   */
  $stAssigned = $pdo->prepare('SELECT oauth_token_id FROM ' . OAUTH_TOKENS_USERS_TABLE . ' WHERE user_id = :uid LIMIT 1');
  $stAssigned->execute([':uid' => $assignUserId]);
  $assignedTokenId = (int)($stAssigned->fetchColumn() ?: 0);
  if ($assignedTokenId > 0 && $assignedTokenId !== $id) {
    json_err('User already assigned to another token', 409);
  }
}

/**
 * $stExists — проверка существования токена.
 */
$stExists = $pdo->prepare('SELECT id FROM ' . OAUTH_TOKENS_TABLE . ' WHERE id = :id LIMIT 1');
$stExists->execute([':id' => $id]);
if (!(int)($stExists->fetchColumn() ?: 0)) {
  json_err('Token not found', 404);
}

try {
  $pdo->beginTransaction();

  /**
   * $stUp — обновление токена.
   */
  $stUp = $pdo->prepare(
    'UPDATE ' . OAUTH_TOKENS_TABLE . ' '
    . 'SET name = :name, client_id = :client_id, client_secret = :client_secret, updated_by = :updated_by, updated_at = NOW() '
    . 'WHERE id = :id LIMIT 1'
  );

  $stUp->execute([
    ':name' => $name,
    ':client_id' => $clientId,
    ':client_secret' => $clientSecret,
    ':updated_by' => $actorId,
    ':id' => $id,
  ]);

  /**
   * $stDelLink — очищаем старое назначение токена.
   */
  $stDelLink = $pdo->prepare('DELETE FROM ' . OAUTH_TOKENS_USERS_TABLE . ' WHERE oauth_token_id = :oauth_token_id');
  $stDelLink->execute([':oauth_token_id' => $id]);

  if ($assignUserId > 0) {
    /**
     * $stInsLink — вставляем новую привязку токена к пользователю.
     */
    $stInsLink = $pdo->prepare(
      'INSERT INTO ' . OAUTH_TOKENS_USERS_TABLE . ' (oauth_token_id, user_id, created_at) '
      . 'VALUES (:oauth_token_id, :user_id, NOW())'
    );
    $stInsLink->execute([
      ':oauth_token_id' => $id,
      ':user_id' => $assignUserId,
    ]);
  }

  $pdo->commit();

  audit_log('oauth_tokens', 'update', 'info', [
    'id' => $id,
    'assigned_user_id' => ($assignUserId > 0 ? $assignUserId : null),
  ], 'oauth_token', $id, $actorId, $actorRole);

  json_ok(['id' => $id]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  audit_log('oauth_tokens', 'update', 'error', [
    'id' => $id,
    'error' => $e->getMessage(),
  ], 'oauth_token', $id > 0 ? $id : null, $actorId, $actorRole);

  json_err('Update failed', 500);
}
