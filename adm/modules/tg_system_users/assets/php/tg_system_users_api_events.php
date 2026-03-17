<?php
/**
 * FILE: /adm/modules/tg_system_users/assets/php/tg_system_users_api_events.php
 * ROLE: api_events — список системных событий и персональных флагов пользователя
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/tg_system_users_lib.php';

/**
 * $cfg — конфигурация приложения.
 */
$cfg = function_exists('app_config') ? app_config() : [];
/**
 * $internalKey — ключ внутреннего API.
 */
$internalKey = trim((string)($cfg['internal_api']['key'] ?? ''));
/**
 * $headerKey — ключ из заголовка.
 */
$headerKey = trim((string)(function_exists('tg_get_header') ? tg_get_header('X-Internal-Api-Key') : ''));
/**
 * $queryKey — ключ из query/body.
 */
$queryKey = trim((string)($_REQUEST['key'] ?? ''));
/**
 * $hasInternalKeyAccess — доступ по internal_api key.
 */
$hasInternalKeyAccess = ($internalKey !== '' && ($headerKey === $internalKey || $queryKey === $internalKey));

/**
 * $uid — текущий пользователь.
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);

if (!$hasInternalKeyAccess && $uid <= 0) {
  json_err('Forbidden', 403);
}

/**
 * $targetUserId — пользователь, чьи настройки нужны.
 */
$targetUserId = $hasInternalKeyAccess
  ? (int)($_REQUEST['user_id'] ?? $uid)
  : $uid;

if ($targetUserId <= 0) {
  json_err('Bad user_id', 400);
}

try {
  $pdo = db();
  $events = tg_system_users_user_preferences($pdo, $targetUserId);

  $st = $pdo->prepare("
    SELECT chat_id, username, linked_at, last_seen_at, is_active
    FROM " . TG_SYSTEM_USERS_TABLE_LINKS . "
    WHERE user_id = :uid
    LIMIT 1
  ");
  $st->execute([':uid' => $targetUserId]);
  $link = $st->fetch(PDO::FETCH_ASSOC);

  json_ok([
    'user_id' => $targetUserId,
    'events' => $events,
    'link' => is_array($link) ? $link : null,
  ]);
} catch (Throwable $e) {
  json_err('Internal error', 500);
}

