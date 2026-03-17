<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_max_probe.php
 * ROLE: do=max_probe — диагностика MAX API и список чатов бота.
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
 * cb_max_get_json()
 * Выполняет GET-запрос к MAX API и пытается декодировать JSON.
 *
 * @param string $url
 * @param array<int,string> $headers
 * @param int $timeout
 * @return array<string,mixed>
 */
function cb_max_get_json(string $url, array $headers = [], int $timeout = 20): array
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
 * cb_max_pick_bot()
 * Нормализует объект бота/пользователя из ответа /me.
 *
 * @param array<string,mixed> $json
 * @return array<string,mixed>
 */
function cb_max_pick_bot(array $json): array
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
 * cb_max_pick_chats()
 * Нормализует список чатов из ответа /chats.
 *
 * @param array<string,mixed> $json
 * @return array<int,array<string,string>>
 */
function cb_max_pick_chats(array $json): array
{
  $rows = [];

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

  foreach ($list as $item) {
    if (!is_array($item)) continue;
    $chat = (array)$item;

    $id = trim((string)($chat['chat_id'] ?? $chat['id'] ?? ''));
    if ($id === '') continue;

    $rows[] = [
      'chat_id' => $id,
      'title' => trim((string)($chat['title'] ?? $chat['name'] ?? '')),
      'type' => trim((string)($chat['type'] ?? $chat['chat_type'] ?? '')),
      'status' => trim((string)($chat['status'] ?? '')),
      'link' => trim((string)($chat['link'] ?? $chat['url'] ?? '')),
    ];
  }

  return $rows;
}

try {
  $pdo = db();
  $settings = channel_bridge_settings_get($pdo);

  $maxEnabled = ((int)($settings['max_enabled'] ?? 0) === 1) ? 1 : 0;
  $baseUrl = rtrim(trim((string)($settings['max_base_url'] ?? 'https://platform-api.max.ru')), '/');
  if ($baseUrl === '' || $baseUrl === 'https://botapi.max.ru') {
    $baseUrl = 'https://platform-api.max.ru';
  }
  $sendPath = trim((string)($settings['max_send_path'] ?? '/messages'));
  if ($sendPath === '') $sendPath = '/messages';

  $apiKey = trim((string)($settings['max_api_key'] ?? ''));
  if (stripos($apiKey, 'Bearer ') === 0) {
    $apiKey = trim((string)substr($apiKey, 7));
  }
  if (stripos($apiKey, 'OAuth ') === 0) {
    $apiKey = trim((string)substr($apiKey, 6));
  }

  $headers = [];
  if ($apiKey !== '') {
    $headers[] = 'Authorization: ' . $apiKey;
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
  ];

  if ($apiKey === '') {
    $me['error'] = 'MAX_API_KEY_EMPTY';
    $chats['error'] = 'MAX_API_KEY_EMPTY';
  } else {
    $meResp = cb_max_get_json($baseUrl . '/me', $headers, 20);
    $me['http_code'] = (int)($meResp['http_code'] ?? 0);
    $me['ok'] = (($meResp['ok'] ?? false) === true) && ($me['http_code'] >= 200 && $me['http_code'] < 300);
    if ($me['ok']) {
      $me['bot'] = cb_max_pick_bot((array)($meResp['json'] ?? []));
    } else {
      $err = trim((string)(
        ($meResp['json']['message'] ?? '')
        ?: ($meResp['json']['error'] ?? '')
        ?: ($meResp['error'] ?? '')
      ));
      if ($err === '') $err = 'HTTP_' . $me['http_code'];
      $me['error'] = $err;
    }

    $chatsResp = cb_max_get_json($baseUrl . '/chats?count=100', $headers, 25);
    $chats['http_code'] = (int)($chatsResp['http_code'] ?? 0);
    $chats['ok'] = (($chatsResp['ok'] ?? false) === true) && ($chats['http_code'] >= 200 && $chats['http_code'] < 300);
    if ($chats['ok']) {
      $items = cb_max_pick_chats((array)($chatsResp['json'] ?? []));
      $chats['items'] = $items;
      $chats['count'] = count($items);
    } else {
      $err = trim((string)(
        ($chatsResp['json']['message'] ?? '')
        ?: ($chatsResp['json']['error'] ?? '')
        ?: ($chatsResp['error'] ?? '')
      ));
      if ($err === '') $err = 'HTTP_' . $chats['http_code'];
      $chats['error'] = $err;
    }
  }

  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'max_probe', ($me['ok'] && $chats['ok']) ? 'info' : 'warn', [
    'max_enabled' => $maxEnabled,
    'me_ok' => $me['ok'] ? 1 : 0,
    'me_http_code' => (int)$me['http_code'],
    'chats_ok' => $chats['ok'] ? 1 : 0,
    'chats_http_code' => (int)$chats['http_code'],
    'chats_count' => (int)$chats['count'],
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  json_ok([
    'checked_at' => channel_bridge_now(),
    'max_enabled' => $maxEnabled,
    'token_present' => ($apiKey !== '') ? 1 : 0,
    'base_url' => $baseUrl,
    'send_path' => $sendPath,
    'me' => $me,
    'chats' => $chats,
  ]);
} catch (Throwable $e) {
  audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'max_probe', 'error', [
    'error' => $e->getMessage(),
  ], CHANNEL_BRIDGE_MODULE_CODE, null, $uid, (string)(function_exists('auth_user_role') ? auth_user_role() : ''));

  json_err('Internal error', 500);
}

