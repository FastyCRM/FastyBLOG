<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_channel_probe.php
 * ROLE: do=channel_probe — поиск доступных чатов/каналов для привязки без bind-кода.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/stopbot_lib.php';

acl_guard(module_allowed_roles(STOPBOT_MODULE_CODE));

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
  http_405('Method Not Allowed');
}

csrf_check((string)($_POST['csrf'] ?? ''));

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!stopbot_is_manage_role($roles)) {
  json_err(stopbot_t('stopbot.flash_access_denied'), 403);
}

$botId = (int)($_POST['bot_id'] ?? 0);
if ($botId <= 0) {
  json_err(stopbot_t('stopbot.flash_bot_not_found'), 404);
}

/**
 * @param string $chatId
 * @return bool
 */
function stopbot_probe_tg_is_numeric_chat_id(string $chatId): bool
{
  return preg_match('~^-?\d+$~', trim($chatId)) === 1;
}

/**
 * @param string $url
 * @param array<int,string> $headers
 * @param int $timeout
 * @return array<string,mixed>
 */
function stopbot_probe_max_get_json(string $url, array $headers = [], int $timeout = 20): array
{
  $url = trim($url);
  if ($url === '') return ['ok' => false, 'error' => 'URL_EMPTY', 'http_code' => 0, 'json' => [], 'raw' => ''];
  if ($timeout < 1) $timeout = 20;

  $httpCode = 0;
  $raw = '';

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_HTTPGET => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ]);
    $result = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = trim((string)curl_error($ch));
    curl_close($ch);

    if ($result === false) {
      return ['ok' => false, 'error' => 'CURL_ERROR', 'description' => $curlError, 'http_code' => $httpCode, 'json' => [], 'raw' => ''];
    }

    $raw = (string)$result;
  } else {
    $headersTxt = "Accept: application/json\r\n";
    foreach ($headers as $h) {
      $headersTxt .= trim((string)$h) . "\r\n";
    }

    $ctx = stream_context_create([
      'http' => [
        'method' => 'GET',
        'header' => $headersTxt,
        'timeout' => $timeout,
      ],
    ]);
    $result = @file_get_contents($url, false, $ctx);
    $meta = $http_response_header ?? [];
    if (is_array($meta) && isset($meta[0]) && preg_match('~\s(\d{3})\s~', (string)$meta[0], $m)) {
      $httpCode = (int)$m[1];
    }
    if ($result === false) {
      return ['ok' => false, 'error' => 'HTTP_ERROR', 'http_code' => $httpCode, 'json' => [], 'raw' => ''];
    }

    $raw = (string)$result;
  }

  $json = json_decode($raw, true);
  if (!is_array($json)) $json = [];

  return ['ok' => true, 'http_code' => $httpCode, 'json' => $json, 'raw' => $raw];
}

/**
 * @param array<string,mixed> $json
 * @return array<string,mixed>
 */
function stopbot_probe_max_pick_me(array $json): array
{
  $node = [];
  if (isset($json['user']) && is_array($json['user'])) {
    $node = (array)$json['user'];
  } elseif (isset($json['result']) && is_array($json['result'])) {
    $node = (array)$json['result'];
  } else {
    $node = $json;
  }

  return [
    'id' => trim((string)($node['id'] ?? $node['user_id'] ?? '')),
    'username' => trim((string)($node['username'] ?? $node['nick'] ?? '')),
    'name' => trim((string)($node['name'] ?? $node['title'] ?? $node['first_name'] ?? '')),
    'is_bot' => isset($node['is_bot']) ? (((int)$node['is_bot'] === 1) ? 1 : 0) : null,
  ];
}

/**
 * @param array<string,mixed> $json
 * @return array<int,array<string,string>>
 */
function stopbot_probe_max_pick_chats(array $json): array
{
  $list = [];
  if (isset($json['chats']) && is_array($json['chats'])) {
    $list = $json['chats'];
  } elseif (isset($json['items']) && is_array($json['items'])) {
    $list = $json['items'];
  } elseif (isset($json['result']['chats']) && is_array($json['result']['chats'])) {
    $list = $json['result']['chats'];
  } elseif (isset($json['result']) && is_array($json['result'])) {
    $list = $json['result'];
  }

  $rows = [];
  foreach ($list as $item) {
    if (!is_array($item)) continue;

    $id = trim((string)($item['chat_id'] ?? $item['id'] ?? ''));
    if ($id === '') continue;

    $rows[] = [
      'chat_id' => $id,
      'title' => trim((string)($item['title'] ?? $item['name'] ?? '')),
      'type' => trim((string)($item['type'] ?? $item['chat_type'] ?? '')),
      'status' => trim((string)($item['status'] ?? '')),
      'link' => trim((string)($item['link'] ?? $item['url'] ?? '')),
    ];
  }

  return $rows;
}

try {
  $pdo = db();
  $bot = stopbot_bot_get($pdo, $botId);
  if (!$bot) {
    json_err(stopbot_t('stopbot.flash_bot_not_found'), 404);
  }

  $platform = strtolower(trim((string)($bot['platform'] ?? STOPBOT_PLATFORM_TG)));
  if ($platform !== STOPBOT_PLATFORM_MAX) $platform = STOPBOT_PLATFORM_TG;

  $existingRows = stopbot_channels_list($pdo, $botId);
  $existingByChatId = [];
  foreach ($existingRows as $row) {
    $cid = trim((string)($row['chat_id'] ?? ''));
    if ($cid === '') continue;
    $existingByChatId[$cid] = [
      'is_active' => ((int)($row['is_active'] ?? 0) === 1) ? 1 : 0,
      'chat_title' => trim((string)($row['chat_title'] ?? '')),
      'chat_type' => trim((string)($row['chat_type'] ?? '')),
      'last_seen_at' => trim((string)($row['last_seen_at'] ?? '')),
    ];
  }

  $me = [
    'ok' => false,
    'http_code' => 0,
    'error' => '',
    'bot' => [],
  ];
  $chats = [
    'ok' => false,
    'http_code' => 0,
    'error' => '',
    'count' => 0,
    'items' => [],
    'resolved_count' => 0,
    'errors_count' => 0,
  ];

  if ($platform === STOPBOT_PLATFORM_MAX) {
    $apiKey = trim((string)($bot['max_api_key'] ?? ''));
    $baseUrl = rtrim(trim((string)($bot['max_base_url'] ?? 'https://platform-api.max.ru')), '/');
    if ($baseUrl === '') {
      $baseUrl = 'https://platform-api.max.ru';
    }

    if ($apiKey === '') {
      $me['error'] = 'MAX_API_KEY_EMPTY';
      $chats['error'] = 'MAX_API_KEY_EMPTY';
    } else {
      $headers = ['Authorization: ' . $apiKey];

      $meResp = stopbot_probe_max_get_json($baseUrl . '/me', $headers, 20);
      $me['http_code'] = (int)($meResp['http_code'] ?? 0);
      $me['ok'] = (($meResp['ok'] ?? false) === true) && ($me['http_code'] >= 200 && $me['http_code'] < 300);
      if ($me['ok']) {
        $me['bot'] = stopbot_probe_max_pick_me((array)($meResp['json'] ?? []));
      } else {
        $me['error'] = trim((string)(
          ($meResp['json']['message'] ?? '')
          ?: ($meResp['json']['error'] ?? '')
          ?: ($meResp['error'] ?? '')
          ?: ('HTTP_' . $me['http_code'])
        ));
      }

      $chatsResp = stopbot_probe_max_get_json($baseUrl . '/chats?count=100', $headers, 25);
      $chats['http_code'] = (int)($chatsResp['http_code'] ?? 0);
      $chats['ok'] = (($chatsResp['ok'] ?? false) === true) && ($chats['http_code'] >= 200 && $chats['http_code'] < 300);
      if ($chats['ok']) {
        $items = stopbot_probe_max_pick_chats((array)($chatsResp['json'] ?? []));
        foreach ($items as &$item) {
          $cid = (string)($item['chat_id'] ?? '');
          $bound = $existingByChatId[$cid] ?? null;
          $item['is_bound'] = $bound ? 1 : 0;
          $item['is_active'] = $bound ? (int)($bound['is_active'] ?? 0) : 0;
          if (($item['title'] ?? '') === '' && $bound) {
            $item['title'] = (string)($bound['chat_title'] ?? '');
          }
          if (($item['type'] ?? '') === '' && $bound) {
            $item['type'] = (string)($bound['chat_type'] ?? '');
          }
        }
        unset($item);

        $chats['items'] = $items;
        $chats['count'] = count($items);
        $chats['resolved_count'] = count($items);
      } else {
        $chats['error'] = trim((string)(
          ($chatsResp['json']['message'] ?? '')
          ?: ($chatsResp['json']['error'] ?? '')
          ?: ($chatsResp['error'] ?? '')
          ?: ('HTTP_' . $chats['http_code'])
        ));
      }
    }
  } else {
    $token = trim((string)($bot['bot_token'] ?? ''));

    $known = [];
    foreach ($existingRows as $row) {
      $cid = trim((string)($row['chat_id'] ?? ''));
      if ($cid === '') continue;

      $known[$cid] = [
        'chat_id' => $cid,
        'title' => trim((string)($row['chat_title'] ?? '')),
        'type' => trim((string)($row['chat_type'] ?? '')),
        'status' => 'known',
        'error' => '',
        'link' => '',
        'is_bound' => 1,
        'is_active' => ((int)($row['is_active'] ?? 0) === 1) ? 1 : 0,
        'events_count' => 0,
        'last_seen_at' => trim((string)($row['last_seen_at'] ?? '')),
      ];
    }

    $st = $pdo->prepare("\n      SELECT chat_id, MAX(created_at) AS last_seen_at, COUNT(*) AS events_count\n      FROM " . STOPBOT_TABLE_LOGS . "\n      WHERE bot_id = :bot_id AND platform = :platform AND chat_id <> ''\n      GROUP BY chat_id\n      ORDER BY last_seen_at DESC\n      LIMIT 300\n    ");
    $st->execute([
      ':bot_id' => $botId,
      ':platform' => STOPBOT_PLATFORM_TG,
    ]);
    $logs = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($logs)) $logs = [];

    foreach ($logs as $row) {
      $cid = trim((string)($row['chat_id'] ?? ''));
      if ($cid === '') continue;

      if (!isset($known[$cid])) {
        $known[$cid] = [
          'chat_id' => $cid,
          'title' => '',
          'type' => '',
          'status' => 'known',
          'error' => '',
          'link' => '',
          'is_bound' => 0,
          'is_active' => 0,
          'events_count' => 0,
          'last_seen_at' => '',
        ];
      }

      $known[$cid]['events_count'] = (int)($row['events_count'] ?? 0);
      $lastSeenAt = trim((string)($row['last_seen_at'] ?? ''));
      if ($lastSeenAt !== '') {
        $known[$cid]['last_seen_at'] = $lastSeenAt;
      }
    }

    $items = array_values($known);
    usort($items, static function (array $a, array $b): int {
      $at = trim((string)($a['last_seen_at'] ?? ''));
      $bt = trim((string)($b['last_seen_at'] ?? ''));
      if ($at !== $bt) {
        return strcmp($bt, $at);
      }
      return strcmp((string)($a['chat_id'] ?? ''), (string)($b['chat_id'] ?? ''));
    });

    if ($token === '') {
      $me['error'] = 'TG_TOKEN_EMPTY';
      $chats['error'] = 'TG_TOKEN_EMPTY';
      foreach ($items as &$item) {
        $item['status'] = 'known';
      }
      unset($item);
    } elseif (!function_exists('tg_get_me')) {
      $me['error'] = 'TG_GET_ME_MISSING';
      $chats['error'] = 'TG_GET_CHAT_MISSING';
    } else {
      $meResp = tg_get_me($token);
      $me['http_code'] = (int)($meResp['http_code'] ?? 0);
      $me['ok'] = (($meResp['ok'] ?? false) === true);
      if ($me['ok']) {
        $res = is_array($meResp['result'] ?? null) ? (array)$meResp['result'] : [];
        $me['bot'] = [
          'id' => trim((string)($res['id'] ?? '')),
          'username' => trim((string)($res['username'] ?? '')),
          'name' => trim((string)(($res['first_name'] ?? '') . ' ' . ($res['last_name'] ?? ''))),
          'is_bot' => isset($res['is_bot']) ? (((int)$res['is_bot'] === 1) ? 1 : 0) : null,
        ];
      } else {
        $me['error'] = trim((string)(
          ($meResp['description'] ?? '')
          ?: ($meResp['error'] ?? '')
          ?: 'TG_GET_ME_FAILED'
        ));
      }

      if (function_exists('tg_get_chat')) {
        $maxResolve = 120;
        foreach ($items as $idx => &$item) {
          if ($idx >= $maxResolve) {
            $item['status'] = ($item['status'] === 'known') ? 'known' : (string)$item['status'];
            continue;
          }

          $cid = trim((string)($item['chat_id'] ?? ''));
          if (!stopbot_probe_tg_is_numeric_chat_id($cid)) {
            $item['status'] = 'known';
            continue;
          }

          $chatResp = tg_get_chat($token, $cid);
          if (($chatResp['ok'] ?? false) === true) {
            $res = is_array($chatResp['result'] ?? null) ? (array)$chatResp['result'] : [];
            $username = trim((string)($res['username'] ?? ''));
            $title = trim((string)($res['title'] ?? ''));
            if ($title === '') {
              $title = trim((string)(($res['first_name'] ?? '') . ' ' . ($res['last_name'] ?? '')));
            }

            if ($title !== '') $item['title'] = $title;
            $item['type'] = trim((string)($res['type'] ?? $item['type']));
            $item['link'] = $username !== '' ? ('https://t.me/' . $username) : trim((string)($res['invite_link'] ?? ''));
            $item['status'] = 'ok';
            $item['error'] = '';
            $chats['resolved_count']++;
          } else {
            $item['status'] = 'error';
            $item['error'] = trim((string)(
              ($chatResp['description'] ?? '')
              ?: ($chatResp['error'] ?? '')
              ?: 'TG_GET_CHAT_ERROR'
            ));
            $chats['errors_count']++;
          }
        }
        unset($item);
      }
    }

    $chats['ok'] = true;
    $chats['http_code'] = 200;
    $chats['items'] = $items;
    $chats['count'] = count($items);
  }

  $auditLevel = (($me['ok'] ?? false) === true && (($chats['error'] ?? '') === '')) ? 'info' : 'warn';
  audit_log(STOPBOT_MODULE_CODE, 'channel_probe', $auditLevel, [
    'bot_id' => $botId,
    'platform' => $platform,
    'me_ok' => (($me['ok'] ?? false) === true) ? 1 : 0,
    'me_http_code' => (int)($me['http_code'] ?? 0),
    'chats_count' => (int)($chats['count'] ?? 0),
    'errors_count' => (int)($chats['errors_count'] ?? 0),
    'chats_error' => trim((string)($chats['error'] ?? '')),
  ], STOPBOT_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  json_ok([
    'checked_at' => stopbot_now(),
    'bot_id' => $botId,
    'platform' => $platform,
    'token_present' => ($platform === STOPBOT_PLATFORM_MAX)
      ? (trim((string)($bot['max_api_key'] ?? '')) !== '' ? 1 : 0)
      : (trim((string)($bot['bot_token'] ?? '')) !== '' ? 1 : 0),
    'me' => $me,
    'chats' => $chats,
  ]);
} catch (Throwable $e) {
  audit_log(STOPBOT_MODULE_CODE, 'channel_probe', 'error', [
    'bot_id' => $botId,
    'error' => $e->getMessage(),
  ], STOPBOT_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  json_err('Internal error', 500);
}
