<?php
/**
 * FILE: /adm/modules/tg_system_clients/assets/php/tg_system_clients_api_events.php
 * ROLE: api_events — список событий и персональных флагов по клиенту
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/tg_system_clients_lib.php';

$cfg = function_exists('app_config') ? app_config() : [];
$internalKey = trim((string)($cfg['internal_api']['key'] ?? ''));
$headerKey = trim((string)(function_exists('tg_get_header') ? tg_get_header('X-Internal-Api-Key') : ''));
$queryKey = trim((string)($_REQUEST['key'] ?? ''));
$hasInternalKeyAccess = ($internalKey !== '' && ($headerKey === $internalKey || $queryKey === $internalKey));

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$canManage = tg_system_clients_is_manage_role($roles);

if (!$hasInternalKeyAccess && !$canManage) {
  json_err('Forbidden', 403);
}

$targetClientId = (int)($_REQUEST['client_id'] ?? ($_REQUEST['user_id'] ?? 0));
if ($targetClientId <= 0) {
  json_err('Bad client_id', 400);
}

try {
  $pdo = db();
  $events = tg_system_clients_user_preferences($pdo, $targetClientId);

  $st = $pdo->prepare("\n    SELECT chat_id, username, linked_at, last_seen_at, is_active\n    FROM " . TG_SYSTEM_CLIENTS_TABLE_LINKS . "\n    WHERE client_id = :cid\n    LIMIT 1\n  ");
  $st->execute([':cid' => $targetClientId]);
  $link = $st->fetch(PDO::FETCH_ASSOC);

  json_ok([
    'client_id' => $targetClientId,
    'events' => $events,
    'link' => is_array($link) ? $link : null,
  ]);
} catch (Throwable $e) {
  json_err('Internal error', 500);
}