<?php
/**
 * FILE: /adm/modules/oauth_tokens/assets/php/oauth_tokens_callback.php
 * ROLE: callback — завершение OAuth flow и сохранение access_token
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/oauth_tokens_lib.php';

acl_guard(module_allowed_roles('oauth_tokens'));

/**
 * $actorId — id текущего пользователя.
 */
$actorId = (int)auth_user_id();

/**
 * $actorRole — роль текущего пользователя.
 */
$actorRole = (string)auth_user_role();

/**
 * $code — authorization code из callback.
 */
$code = trim((string)($_GET['code'] ?? ''));

/**
 * $state — state из callback.
 */
$state = trim((string)($_GET['state'] ?? ''));

if ($code === '' || $state === '') {
  flash('Некорректный callback OAuth', 'warn');
  redirect(url('/adm/index.php?m=oauth_tokens'));
}

/**
 * $statePayload — сохранённый state из сессии.
 */
$statePayload = session_get(OAUTH_TOKENS_STATE_SESSION_KEY, null);
session_del(OAUTH_TOKENS_STATE_SESSION_KEY);

if (!is_array($statePayload)) {
  flash('OAuth state не найден', 'warn');
  redirect(url('/adm/index.php?m=oauth_tokens'));
}

/**
 * $savedState — state из сессии.
 */
$savedState = (string)($statePayload['state'] ?? '');

/**
 * $stateUid — пользователь, инициировавший OAuth start.
 */
$stateUid = (int)($statePayload['uid'] ?? 0);

/**
 * $tokenId — id токена из state.
 */
$tokenId = (int)($statePayload['oauth_token_id'] ?? 0);

/**
 * $stateTs — время генерации state.
 */
$stateTs = (int)($statePayload['ts'] ?? 0);

/**
 * $popupMode — флаг popup-режима.
 */
$popupMode = (int)($statePayload['popup'] ?? 0) === 1 ? 1 : 0;

/**
 * $stateAge — возраст state в секундах.
 */
$stateAge = time() - $stateTs;

if ($savedState === '' || !hash_equals($savedState, $state)) {
  audit_log('oauth_tokens', 'oauth_callback', 'warn', [
    'reason' => 'bad_state',
  ], 'oauth_token', $tokenId > 0 ? $tokenId : null, $actorId, $actorRole);
  flash('OAuth state не прошёл проверку', 'warn');
  redirect(url('/adm/index.php?m=oauth_tokens'));
}

if ($stateAge < 0 || $stateAge > OAUTH_TOKENS_STATE_TTL_SECONDS) {
  audit_log('oauth_tokens', 'oauth_callback', 'warn', [
    'reason' => 'state_expired',
    'state_age' => $stateAge,
  ], 'oauth_token', $tokenId > 0 ? $tokenId : null, $actorId, $actorRole);
  flash('OAuth state устарел, запусти обновление заново', 'warn');
  redirect(url('/adm/index.php?m=oauth_tokens'));
}

if ($tokenId <= 0 || $stateUid <= 0 || $stateUid !== $actorId) {
  audit_log('oauth_tokens', 'oauth_callback', 'warn', [
    'reason' => 'wrong_user_or_token',
    'token_id' => $tokenId,
    'state_uid' => $stateUid,
    'actor_id' => $actorId,
  ], 'oauth_token', $tokenId > 0 ? $tokenId : null, $actorId, $actorRole);
  flash('OAuth callback отклонён (неверный пользователь)', 'warn');
  redirect(url('/adm/index.php?m=oauth_tokens'));
}

/**
 * $pdo — соединение с БД.
 */
$pdo = db();

if (!oauth_tokens_user_can_manage($pdo, $tokenId, $actorId)) {
  audit_log('oauth_tokens', 'oauth_callback', 'warn', [
    'reason' => 'forbidden',
    'token_id' => $tokenId,
  ], 'oauth_token', $tokenId, $actorId, $actorRole);
  flash('Нет доступа к токену', 'warn');
  redirect(url('/adm/index.php?m=oauth_tokens'));
}

/**
 * $tokenRow — запись токена.
 */
$tokenRow = oauth_tokens_get_token($pdo, $tokenId);
if (!$tokenRow) {
  flash('Токен не найден', 'warn');
  redirect(url('/adm/index.php?m=oauth_tokens'));
}

/**
 * $clientId — client_id из карточки токена.
 */
$clientId = trim((string)($tokenRow['client_id'] ?? ''));

/**
 * $clientSecret — client_secret из карточки токена.
 */
$clientSecret = trim((string)($tokenRow['client_secret'] ?? ''));

if ($clientId === '' || $clientSecret === '') {
  flash('Не заполнены client_id/client_secret', 'warn');
  redirect(url('/adm/index.php?m=oauth_tokens'));
}

/**
 * $oauthResponse — ответ Яндекса при обмене code на токен.
 */
$oauthResponse = oauth_tokens_http_post_form(OAUTH_TOKENS_YANDEX_TOKEN_URL, [
  'grant_type' => 'authorization_code',
  'code' => $code,
  'client_id' => $clientId,
  'client_secret' => $clientSecret,
]);

if (($oauthResponse['_ok'] ?? false) !== true) {
  audit_log('oauth_tokens', 'oauth_callback', 'error', [
    'reason' => 'token_exchange_failed',
    'token_id' => $tokenId,
    'http' => (int)($oauthResponse['_http'] ?? 0),
    'error' => (string)($oauthResponse['_error'] ?? ''),
  ], 'oauth_token', $tokenId, $actorId, $actorRole);

  flash('Не удалось обменять code на access_token', 'danger', 1);
  redirect(url('/adm/index.php?m=oauth_tokens'));
}

/**
 * $accessToken — полученный access_token.
 */
$accessToken = trim((string)($oauthResponse['access_token'] ?? ''));
if ($accessToken === '') {
  flash('Яндекс вернул пустой access_token', 'danger', 1);
  redirect(url('/adm/index.php?m=oauth_tokens'));
}

try {
  /**
   * $stUp — обновление токена в БД.
   */
  $stUp = $pdo->prepare(
    'UPDATE ' . OAUTH_TOKENS_TABLE . ' '
    . 'SET access_token = :access_token, token_received_at = NOW(), updated_at = NOW(), updated_by = :updated_by '
    . 'WHERE id = :id LIMIT 1'
  );

  $stUp->execute([
    ':access_token' => $accessToken,
    ':updated_by' => $actorId,
    ':id' => $tokenId,
  ]);

  audit_log('oauth_tokens', 'oauth_callback', 'info', [
    'token_id' => $tokenId,
    'popup' => $popupMode,
  ], 'oauth_token', $tokenId, $actorId, $actorRole);
} catch (Throwable $e) {
  audit_log('oauth_tokens', 'oauth_callback', 'error', [
    'token_id' => $tokenId,
    'error' => $e->getMessage(),
  ], 'oauth_token', $tokenId, $actorId, $actorRole);

  flash('Ошибка сохранения access_token', 'danger', 1);
  redirect(url('/adm/index.php?m=oauth_tokens'));
}

/**
 * $okUrl — URL возврата в модуль.
 */
$okUrl = url('/adm/index.php?m=oauth_tokens&ok=1');

if ($popupMode === 1) {
  header('Content-Type: text/html; charset=utf-8');
  /**
   * $okUrlReload — URL с cache-bust, чтобы гарантировать обновление opener.
   */
  $okUrlReload = $okUrl . (strpos($okUrl, '?') !== false ? '&' : '?') . '_ts=' . time();

  echo '<!doctype html><html><head><meta charset="utf-8"><title>OAuth OK</title></head><body>';
  echo '<script>';
  echo '(function(){';
  echo '  var okUrl = ' . json_encode($okUrlReload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';';
  echo '  try {';
  echo '    if (window.opener && !window.opener.closed) {';
  echo '      try {';
  echo '        window.opener.postMessage({ type: "oauth_tokens_updated", url: okUrl }, window.location.origin);';
  echo '      } catch(e) {}';
  echo '      try { window.opener.location.reload(); } catch(e) {';
  echo '        try { window.opener.location.href = okUrl; } catch(e2) {}';
  echo '      }';
  echo '    }';
  echo '  } catch(e) {}';
  echo '  setTimeout(function(){ window.close(); }, 120);';
  echo '})();';
  echo '</script>';
  echo 'Токен сохранён. Можно закрыть окно.';
  echo '</body></html>';
  exit;
}

flash('Токен успешно обновлён', 'ok');
redirect($okUrl);
