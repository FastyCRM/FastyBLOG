<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_tg_probe.php
 * ROLE: do=tg_probe — диагностика TG API и список известных chat_id с названиями.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_lib.php';

acl_guard(module_allowed_roles(CHANNEL_BRIDGE_MODULE_CODE));

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!channel_bridge_can_manage($roles)) {
  json_err(channel_bridge_t('channel_bridge.error_forbidden'), 403);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
  http_405('Method Not Allowed');
}

csrf_check((string)($_POST['csrf'] ?? ''));

/**
 * cb_tg_is_numeric_chat_id()
 *
 * @param string $chatId
 * @return bool
 */
function cb_tg_is_numeric_chat_id(string $chatId): bool
{
  return preg_match('~^-?\d+$~', trim($chatId)) === 1;
}

/**
 * cb_tg_collect_known_chats()
 * Собирает кандидатов chat_id из inbox и маршрутов TG.
 *
 * @param PDO $pdo
 * @param int $limit
 * @return array<int,array<string,mixed>>
 */
function cb_tg_collect_known_chats(PDO $pdo, int $limit = 300): array
{
  if ($limit < 1) $limit = 300;
  if ($limit > 1000) $limit = 1000;

  $map = [];

  $seen = channel_bridge_seen_tg_chats($pdo, 1000);
  foreach ($seen as $row) {
    $chatId = channel_bridge_norm_chat_id((string)($row['chat_id'] ?? ''));
    if (!cb_tg_is_numeric_chat_id($chatId)) continue;

    $map[$chatId] = [
      'chat_id' => $chatId,
      'events_count' => (int)($row['events_count'] ?? 0),
      'last_seen_at' => trim((string)($row['last_seen_at'] ?? '')),
      'used_as_source' => ((int)($row['used_as_source'] ?? 0) === 1) ? 1 : 0,
      'used_as_target' => ((int)($row['used_as_target'] ?? 0) === 1) ? 1 : 0,
    ];
  }

  $st = $pdo->query("
    SELECT source_platform, source_chat_id, target_platform, target_chat_id
    FROM " . CHANNEL_BRIDGE_TABLE_ROUTES . "
  ");
  $routes = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
  if (is_array($routes)) {
    foreach ($routes as $r) {
      $sp = channel_bridge_norm_platform((string)($r['source_platform'] ?? ''));
      $tp = channel_bridge_norm_platform((string)($r['target_platform'] ?? ''));
      $sc = channel_bridge_norm_chat_id((string)($r['source_chat_id'] ?? ''));
      $tc = channel_bridge_norm_chat_id((string)($r['target_chat_id'] ?? ''));

      if ($sp === CHANNEL_BRIDGE_SOURCE_TG && cb_tg_is_numeric_chat_id($sc)) {
        if (!isset($map[$sc])) {
          $map[$sc] = [
            'chat_id' => $sc,
            'events_count' => 0,
            'last_seen_at' => '',
            'used_as_source' => 0,
            'used_as_target' => 0,
          ];
        }
        $map[$sc]['used_as_source'] = 1;
      }

      if ($tp === CHANNEL_BRIDGE_TARGET_TG && cb_tg_is_numeric_chat_id($tc)) {
        if (!isset($map[$tc])) {
          $map[$tc] = [
            'chat_id' => $tc,
            'events_count' => 0,
            'last_seen_at' => '',
            'used_as_source' => 0,
            'used_as_target' => 0,
          ];
        }
        $map[$tc]['used_as_target'] = 1;
      }
    }
  }

  $rows = array_values($map);
  usort($rows, static function (array $a, array $b): int {
    $at = trim((string)($a['last_seen_at'] ?? ''));
    $bt = trim((string)($b['last_seen_at'] ?? ''));
    if ($at !== $bt) {
      return strcmp($bt, $at);
    }
    return strcmp((string)($a['chat_id'] ?? ''), (string)($b['chat_id'] ?? ''));
  });

  if (count($rows) > $limit) {
    $rows = array_slice($rows, 0, $limit);
  }

  return $rows;
}

/**
 * cb_tg_pick_me()
 *
 * @param array<string,mixed> $resp
 * @return array<string,mixed>
 */
function cb_tg_pick_me(array $resp): array
{
  $ok = (($resp['ok'] ?? false) === true);
  $httpCode = (int)($resp['http_code'] ?? 0);
  $result = is_array($resp['result'] ?? null) ? (array)$resp['result'] : [];
  $err = trim((string)(
    ($resp['description'] ?? '')
    ?: ($resp['error'] ?? '')
  ));
  if (!$ok && $err === '' && $httpCode > 0) {
    $err = 'HTTP_' . $httpCode;
  }

  return [
    'ok' => $ok,
    'http_code' => $httpCode,
    'error' => $err,
    'bot' => [
      'id' => trim((string)($result['id'] ?? '')),
      'username' => trim((string)($result['username'] ?? '')),
      'name' => trim((string)(
        ($result['first_name'] ?? '')
        . ' '
        . ($result['last_name'] ?? '')
      )),
      'is_bot' => isset($result['is_bot']) ? (((int)$result['is_bot'] === 1) ? 1 : 0) : null,
    ],
  ];
}

try {
  $pdo = db();
  $settings = channel_bridge_settings_get($pdo);

  $tgEnabled = ((int)($settings['tg_enabled'] ?? 0) === 1) ? 1 : 0;
  $token = trim((string)($settings['tg_bot_token'] ?? ''));

  $me = [
    'ok' => false,
    'http_code' => 0,
    'error' => '',
    'bot' => [],
  ];

  $known = cb_tg_collect_known_chats($pdo, 300);
  $items = [];
  $resolvedCount = 0;
  $errorCount = 0;
  $skippedCount = 0;
  $maxResolve = 120;

  if ($token === '') {
    $me['error'] = 'TG_TOKEN_EMPTY';
    $errorCount = count($known);
  } else {
    $meResp = tg_get_me($token);
    $me = cb_tg_pick_me($meResp);

    foreach ($known as $idx => $row) {
      $chatId = (string)$row['chat_id'];
      $item = [
        'chat_id' => $chatId,
        'title' => '',
        'type' => '',
        'username' => '',
        'link' => '',
        'status' => '',
        'error' => '',
        'events_count' => (int)($row['events_count'] ?? 0),
        'last_seen_at' => (string)($row['last_seen_at'] ?? ''),
        'used_as_source' => (int)($row['used_as_source'] ?? 0),
        'used_as_target' => (int)($row['used_as_target'] ?? 0),
      ];

      if ($idx >= $maxResolve) {
        $skippedCount++;
        continue;
      }

      $chatResp = tg_get_chat($token, $chatId);
      if (($chatResp['ok'] ?? false) === true) {
        $result = is_array($chatResp['result'] ?? null) ? (array)$chatResp['result'] : [];
        $username = trim((string)($result['username'] ?? ''));
        $title = trim((string)($result['title'] ?? ''));
        if ($title === '') {
          $title = trim((string)(
            ($result['first_name'] ?? '')
            . ' '
            . ($result['last_name'] ?? '')
          ));
        }
        $item['title'] = $title;
        $item['type'] = trim((string)($result['type'] ?? ''));
        $item['username'] = $username;
        $item['link'] = $username !== '' ? ('https://t.me/' . $username) : trim((string)($result['invite_link'] ?? ''));
        $item['status'] = 'ok';
        $item['error'] = '';
        $resolvedCount++;
        $items[] = $item;
      } else {
        $item['error'] = trim((string)(
          ($chatResp['description'] ?? '')
          ?: ($chatResp['error'] ?? '')
          ?: 'TG_GET_CHAT_ERROR'
        ));
        $errorCount++;
      }
    }
  }

  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'tg_probe', ($token !== '' && $me['ok']) ? 'info' : 'warn', [
    'tg_enabled' => $tgEnabled,
    'token_present' => ($token !== '') ? 1 : 0,
    'me_ok' => ($me['ok'] ?? false) ? 1 : 0,
    'known_count' => count($known),
    'resolved_count' => $resolvedCount,
    'errors_count' => $errorCount,
    'skipped_count' => $skippedCount,
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  json_ok([
    'checked_at' => channel_bridge_now(),
    'tg_enabled' => $tgEnabled,
    'token_present' => ($token !== '') ? 1 : 0,
    'me' => $me,
    'chats' => [
      'known_count' => count($known),
      'resolved_count' => $resolvedCount,
      'errors_count' => $errorCount,
      'skipped_count' => $skippedCount,
      'resolve_limit' => $maxResolve,
      'items' => $items,
    ],
  ]);
} catch (Throwable $e) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'tg_probe', 'error', [
    'error' => $e->getMessage(),
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  json_err('Internal error', 500);
}
