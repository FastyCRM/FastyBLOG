<?php
/**
 * FILE: /adm/modules/oauth_tokens/assets/php/oauth_tokens_add.php
 * ROLE: add — создание OAuth-токена (admin only)
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
$name = trim((string)($_POST['name'] ?? ''));
$clientId = trim((string)($_POST['client_id'] ?? ''));
$clientSecret = trim((string)($_POST['client_secret'] ?? ''));
$assignUserId = (int)($_POST['assign_user_id'] ?? 0);

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
   * $stAssigned — проверка, что пользователь не занят другим токеном.
   */
  $stAssigned = $pdo->prepare('SELECT oauth_token_id FROM ' . OAUTH_TOKENS_USERS_TABLE . ' WHERE user_id = :uid LIMIT 1');
  $stAssigned->execute([':uid' => $assignUserId]);
  if ((int)($stAssigned->fetchColumn() ?: 0) > 0) {
    json_err('User already assigned to another token', 409);
  }
}

try {
  $pdo->beginTransaction();

  /**
   * $stIns — вставка токена.
   */
  $stIns = $pdo->prepare(
    'INSERT INTO ' . OAUTH_TOKENS_TABLE . ' '
    . '(name, client_id, client_secret, access_token, token_received_at, created_by, updated_by, created_at, updated_at) '
    . 'VALUES (:name, :client_id, :client_secret, NULL, NULL, :created_by, :updated_by, NOW(), NOW())'
  );

  $stIns->execute([
    ':name' => $name,
    ':client_id' => $clientId,
    ':client_secret' => $clientSecret,
    ':created_by' => $actorId,
    ':updated_by' => $actorId,
  ]);

  /**
   * $newId — id нового токена.
   */
  $newId = (int)$pdo->lastInsertId();

  if ($assignUserId > 0) {
    /**
     * $stLink — назначение пользователя на токен.
     */
    $stLink = $pdo->prepare(
      'INSERT INTO ' . OAUTH_TOKENS_USERS_TABLE . ' (oauth_token_id, user_id, created_at) '
      . 'VALUES (:oauth_token_id, :user_id, NOW())'
    );

    $stLink->execute([
      ':oauth_token_id' => $newId,
      ':user_id' => $assignUserId,
    ]);
  }

  $pdo->commit();

  audit_log('oauth_tokens', 'create', 'info', [
    'id' => $newId,
    'assigned_user_id' => ($assignUserId > 0 ? $assignUserId : null),
  ], 'oauth_token', $newId, $actorId, $actorRole);

  json_ok(['id' => $newId]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) {
    $pdo->rollBack();
  }

  audit_log('oauth_tokens', 'create', 'error', [
    'error' => $e->getMessage(),
  ], 'oauth_token', null, $actorId, $actorRole);

  json_err('Create failed', 500);
}
