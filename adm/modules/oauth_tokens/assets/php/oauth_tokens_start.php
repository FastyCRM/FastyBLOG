<?php
/**
 * FILE: /adm/modules/oauth_tokens/assets/php/oauth_tokens_start.php
 * ROLE: start — запуск OAuth авторизации (admin + назначенный user)
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

/**
 * $tokenId — id токена для запуска OAuth.
 */
$tokenId = (int)($_POST['id'] ?? 0);
if ($tokenId <= 0) {
  http_403('Bad id');
}

/**
 * $popupMode — признак popup-режима.
 */
$popupMode = (int)($_POST['popup'] ?? 0) === 1 ? 1 : 0;

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

if (!oauth_tokens_user_can_manage($pdo, $tokenId, $actorId)) {
  audit_log('oauth_tokens', 'oauth_start', 'warn', [
    'reason' => 'forbidden',
    'token_id' => $tokenId,
  ], 'oauth_token', $tokenId, $actorId, $actorRole);
  http_403('Forbidden');
}

/**
 * $tokenRow — запись токена.
 */
$tokenRow = oauth_tokens_get_token($pdo, $tokenId);
if (!$tokenRow) {
  http_404('Token not found');
}

/**
 * $clientId — client_id для OAuth запроса.
 */
$clientId = trim((string)($tokenRow['client_id'] ?? ''));
if ($clientId === '') {
  flash('У токена пустой client_id', 'warn');
  redirect(url('/adm/index.php?m=oauth_tokens'));
}

/**
 * $state — случайный state для callback.
 */
$state = bin2hex(random_bytes(16));

session_set(OAUTH_TOKENS_STATE_SESSION_KEY, [
  'state' => $state,
  'oauth_token_id' => $tokenId,
  'uid' => $actorId,
  'ts' => time(),
  'popup' => $popupMode,
]);

/**
 * $authUrl — URL редиректа на страницу авторизации Яндекса.
 */
$authUrl = OAUTH_TOKENS_YANDEX_AUTH_URL . '?' . http_build_query([
  'response_type' => 'code',
  'client_id' => $clientId,
  'redirect_uri' => oauth_tokens_redirect_uri(),
  'state' => $state,
]);

audit_log('oauth_tokens', 'oauth_start', 'info', [
  'token_id' => $tokenId,
  'popup' => $popupMode,
], 'oauth_token', $tokenId, $actorId, $actorRole);

redirect($authUrl);
