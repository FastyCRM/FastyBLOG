<?php
/**
 * FILE: /core/telegram.php
 * ROLE: CORE - Telegram Bot API helpers.
 *
 * Rules:
 * - no UI
 * - functions only
 * - safe defaults from app_config()['telegram']
 */

declare(strict_types=1);

if (!function_exists('tg_config')) {

  /**
   * tg_excerpt()
   * Безопасно обрезает строку для логов без жёсткой зависимости от mbstring.
   */
  function tg_excerpt(string $text, int $maxLen = 1000): string
  {
    if ($maxLen < 1) return '';
    if (function_exists('mb_substr')) {
      return (string)mb_substr($text, 0, $maxLen);
    }
    return (string)substr($text, 0, $maxLen);
  }

  /**
   * tg_config()
   * Returns Telegram config block from core/config.php.
   */
  function tg_config(): array
  {
    if (!function_exists('app_config')) return [];
    $cfg = app_config();
    return (array)($cfg['telegram'] ?? []);
  }

  /**
   * tg_is_enabled()
   * Checks if Telegram integration is enabled and token exists.
   */
  function tg_is_enabled(): bool
  {
    $cfg = tg_config();
    return (bool)($cfg['enabled'] ?? false) && trim((string)($cfg['bot_token'] ?? '')) !== '';
  }

  /**
   * tg_token()
   * Resolves token from explicit argument or config.
   */
  function tg_token(string $token = ''): string
  {
    $token = trim($token);
    if ($token !== '') return $token;

    $cfg = tg_config();
    return trim((string)($cfg['bot_token'] ?? ''));
  }

  /**
   * tg_webhook_secret()
   * Returns webhook secret token from config.
   */
  function tg_webhook_secret(): string
  {
    $cfg = tg_config();
    return trim((string)($cfg['webhook_secret'] ?? ''));
  }

  /**
   * tg_webhook_url()
   * Returns explicit webhook URL from config.
   */
  function tg_webhook_url(): string
  {
    $cfg = tg_config();
    return trim((string)($cfg['webhook_url'] ?? ''));
  }

  /**
   * tg_webhook_handler_file()
   * Returns optional custom handler file path.
   */
  function tg_webhook_handler_file(): string
  {
    $cfg = tg_config();
    return trim((string)($cfg['webhook_handler_file'] ?? ''));
  }

  /**
   * tg_api_base()
   * Builds Telegram API base URL.
   */
  function tg_api_base(string $token): string
  {
    return 'https://api.telegram.org/bot' . trim($token) . '/';
  }

  /**
   * tg_str()
   * Scalar -> string conversion for request params.
   */
  function tg_str($value): string
  {
    if ($value === null) return '';
    if (is_bool($value)) return $value ? 'true' : 'false';
    if (is_scalar($value)) return (string)$value;

    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
  }

  /**
   * tg_bool()
   * Converts mixed value to bool.
   */
  function tg_bool($value): bool
  {
    if (is_bool($value)) return $value;
    $v = strtolower(trim((string)$value));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
  }

  /**
   * tg_get_header()
   * Reads header value in a case-insensitive way.
   */
  function tg_get_header(string $name): string
  {
    $need = strtolower(trim($name));
    if ($need === '') return '';

    if (function_exists('getallheaders')) {
      $headers = (array)getallheaders();
      foreach ($headers as $k => $v) {
        if (strtolower((string)$k) === $need) {
          return trim((string)$v);
        }
      }
    }

    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$serverKey] ?? ''));
  }

  /**
   * tg_read_input_json()
   * Reads raw JSON body from php://input.
   */
  function tg_read_input_json(): array
  {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') return [];

    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
  }

  /**
   * tg_read_update()
   * Alias for reading Telegram update payload.
   */
  function tg_read_update(): array
  {
    return tg_read_input_json();
  }

  /**
   * tg_verify_webhook_secret()
   * Verifies X-Telegram-Bot-Api-Secret-Token header.
   */
  function tg_verify_webhook_secret(string $expected = ''): bool
  {
    $expected = trim($expected);
    if ($expected === '') {
      $expected = tg_webhook_secret();
    }
    if ($expected === '') return true;

    $actual = tg_get_header('X-Telegram-Bot-Api-Secret-Token');
    if ($actual === '') return false;

    return hash_equals($expected, $actual);
  }

  /**
   * tg_prepare_params()
   * Normalizes request params for Telegram API.
   */
  function tg_prepare_params(array $params): array
  {
    foreach ($params as $k => $v) {
      if (is_array($v)) {
        $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      } else {
        $params[$k] = tg_str($v);
      }
    }

    return $params;
  }

  /**
   * tg_request()
   * Generic request to Telegram Bot API.
   */
  function tg_request(string $token, string $method, array $params = [], array $options = []): array
  {
    $token = tg_token($token);
    if ($token === '') {
      if (function_exists('audit_log')) {
        audit_log('core', 'telegram_token_empty', 'warn', ['method' => $method]);
      }
      return ['ok' => false, 'error' => 'TG_TOKEN_EMPTY'];
    }

    $method = trim($method, '/');
    if ($method === '') {
      return ['ok' => false, 'error' => 'TG_METHOD_EMPTY'];
    }

    $cfg = tg_config();
    $connectTimeout = (int)($options['connect_timeout'] ?? $cfg['connect_timeout'] ?? 5);
    $timeout = (int)($options['timeout'] ?? $cfg['timeout'] ?? 20);
    if ($connectTimeout < 1) $connectTimeout = 1;
    if ($timeout < 1) $timeout = 1;

    $url = tg_api_base($token) . $method;
    $payload = tg_prepare_params($params);

    $raw = false;
    $httpCode = 0;

    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Expect:'],
      ]);

      $raw = curl_exec($ch);
      $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = curl_error($ch);
      curl_close($ch);

      if ($raw === false) {
        if (function_exists('audit_log')) {
          audit_log('core', 'telegram_curl_error', 'error', [
            'method' => $method,
            'error' => $curlError,
          ]);
        }
        return ['ok' => false, 'error' => 'TG_CURL_ERROR', 'detail' => $curlError];
      }
    } else {
      $context = stream_context_create([
        'http' => [
          'method' => 'POST',
          'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
          'content' => http_build_query($payload),
          'timeout' => $timeout,
        ],
      ]);

      $raw = @file_get_contents($url, false, $context);
      $meta = $http_response_header ?? [];
      if (is_array($meta) && isset($meta[0]) && preg_match('~\s(\d{3})\s~', (string)$meta[0], $m)) {
        $httpCode = (int)$m[1];
      }

      if ($raw === false) {
        if (function_exists('audit_log')) {
          audit_log('core', 'telegram_http_error', 'error', [
            'method' => $method,
          ]);
        }
        return ['ok' => false, 'error' => 'TG_HTTP_ERROR'];
      }
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
      if (function_exists('audit_log')) {
        audit_log('core', 'telegram_invalid_json', 'error', [
          'method' => $method,
          'http_code' => $httpCode,
          'raw' => tg_excerpt((string)$raw, 1000),
        ]);
      }
      return ['ok' => false, 'error' => 'TG_INVALID_JSON', 'http_code' => $httpCode, 'raw' => (string)$raw];
    }

    if (!($data['ok'] ?? false) && function_exists('audit_log')) {
      audit_log('core', 'telegram_api_error', 'warn', [
        'method' => $method,
        'http_code' => $httpCode,
        'description' => (string)($data['description'] ?? ''),
        'error_code' => (int)($data['error_code'] ?? 0),
      ]);
    }

    if (!isset($data['http_code'])) {
      $data['http_code'] = $httpCode;
    }

    return $data;
  }

  /**
   * tg_send_message()
   * Sends text message to chat/channel.
   */
  function tg_send_message(string $token, $chatId, string $text, array $options = []): array
  {
    $cfg = tg_config();
    $parseMode = (string)($options['parse_mode'] ?? $cfg['default_parse_mode'] ?? 'HTML');

    $params = array_merge([
      'chat_id' => $chatId,
      'text' => $text,
      'parse_mode' => $parseMode,
    ], $options);

    return tg_request($token, 'sendMessage', $params);
  }

  /**
   * tg_channel_post()
   * Alias for posting into channel chat_id.
   */
  function tg_channel_post(string $token, $chatId, string $text, array $options = []): array
  {
    return tg_send_message($token, $chatId, $text, $options);
  }

  /**
   * tg_edit_message_text()
   * Edits existing Telegram message text.
   */
  function tg_edit_message_text(string $token, array $params): array
  {
    return tg_request($token, 'editMessageText', $params);
  }

  /**
   * tg_answer_callback_query()
   * Answers callback query from inline buttons.
   */
  function tg_answer_callback_query(string $token, string $callbackId, array $options = []): array
  {
    return tg_request($token, 'answerCallbackQuery', array_merge([
      'callback_query_id' => $callbackId,
    ], $options));
  }

  /**
   * tg_get_me()
   * Returns bot account info.
   */
  function tg_get_me(string $token): array
  {
    return tg_request($token, 'getMe', []);
  }

  /**
   * tg_get_chat()
   * Returns chat/channel metadata.
   */
  function tg_get_chat(string $token, $chatId): array
  {
    return tg_request($token, 'getChat', [
      'chat_id' => $chatId,
    ]);
  }

  /**
   * tg_get_chat_member()
   * Returns chat member details.
   */
  function tg_get_chat_member(string $token, $chatId, $userId): array
  {
    return tg_request($token, 'getChatMember', [
      'chat_id' => $chatId,
      'user_id' => $userId,
    ]);
  }

  /**
   * tg_get_chat_administrators()
   * Returns list of chat/channel admins.
   */
  function tg_get_chat_administrators(string $token, $chatId): array
  {
    return tg_request($token, 'getChatAdministrators', [
      'chat_id' => $chatId,
    ]);
  }

  /**
   * tg_get_updates()
   * Long polling updates (fallback if webhook is not used).
   */
  function tg_get_updates(string $token, array $options = []): array
  {
    $params = [];
    if (isset($options['offset'])) $params['offset'] = (int)$options['offset'];
    if (isset($options['limit'])) $params['limit'] = (int)$options['limit'];
    if (isset($options['timeout'])) $params['timeout'] = (int)$options['timeout'];
    if (isset($options['allowed_updates']) && is_array($options['allowed_updates'])) {
      $params['allowed_updates'] = $options['allowed_updates'];
    }

    return tg_request($token, 'getUpdates', $params);
  }

  /**
   * tg_set_webhook()
   * Sets webhook URL.
   */
  function tg_set_webhook(string $token, string $url, array $options = []): array
  {
    $params = array_merge(['url' => $url], $options);
    return tg_request($token, 'setWebhook', $params);
  }

  /**
   * tg_set_webhook_auto()
   * Sets webhook using config URL + config secret by default.
   */
  function tg_set_webhook_auto(string $token = '', array $options = []): array
  {
    $url = trim((string)($options['url'] ?? tg_webhook_url()));
    if ($url === '') {
      return ['ok' => false, 'error' => 'TG_WEBHOOK_URL_EMPTY'];
    }

    if (!isset($options['secret_token'])) {
      $secret = tg_webhook_secret();
      if ($secret !== '') {
        $options['secret_token'] = $secret;
      }
    }

    unset($options['url']);
    return tg_set_webhook($token, $url, $options);
  }

  /**
   * tg_delete_webhook()
   * Deletes webhook.
   */
  function tg_delete_webhook(string $token, bool $dropPending = true): array
  {
    return tg_request($token, 'deleteWebhook', [
      'drop_pending_updates' => $dropPending,
    ]);
  }

  /**
   * tg_get_webhook_info()
   * Returns current webhook settings from Telegram.
   */
  function tg_get_webhook_info(string $token): array
  {
    return tg_request($token, 'getWebhookInfo', []);
  }

  /**
   * tg_extract_update_meta()
   * Returns short normalized update info for logs/business handlers.
   */
  function tg_extract_update_meta(array $update): array
  {
    $message = [];
    $type = '';

    if (isset($update['message']) && is_array($update['message'])) {
      $message = $update['message'];
      $type = 'message';
    } elseif (isset($update['edited_message']) && is_array($update['edited_message'])) {
      $message = $update['edited_message'];
      $type = 'edited_message';
    } elseif (isset($update['channel_post']) && is_array($update['channel_post'])) {
      $message = $update['channel_post'];
      $type = 'channel_post';
    } elseif (isset($update['edited_channel_post']) && is_array($update['edited_channel_post'])) {
      $message = $update['edited_channel_post'];
      $type = 'edited_channel_post';
    } elseif (isset($update['callback_query']) && is_array($update['callback_query'])) {
      $type = 'callback_query';
      $message = (array)($update['callback_query']['message'] ?? []);
    }

    $chat = (array)($message['chat'] ?? []);
    $from = (array)($message['from'] ?? []);

    if ($type === 'callback_query' && isset($update['callback_query']['from']) && is_array($update['callback_query']['from'])) {
      $from = (array)$update['callback_query']['from'];
    }

    return [
      'update_id' => (int)($update['update_id'] ?? 0),
      'type' => $type,
      'chat_id' => (string)($chat['id'] ?? ''),
      'chat_type' => (string)($chat['type'] ?? ''),
      'chat_title' => (string)($chat['title'] ?? ''),
      'from_id' => (string)($from['id'] ?? ''),
      'from_username' => (string)($from['username'] ?? ''),
      'text' => (string)($message['text'] ?? ($update['callback_query']['data'] ?? '')),
    ];
  }

  /**
   * tg_webhook_dispatch_update()
   * Runs optional custom webhook handler callback.
   */
  function tg_webhook_dispatch_update(array $update, ?callable $handler = null): array
  {
    $meta = tg_extract_update_meta($update);
    $handled = false;
    $result = null;

    if ($handler !== null) {
      $result = $handler($update);
      $handled = true;
    }

    return [
      'ok' => true,
      'handled' => $handled,
      'meta' => $meta,
      'handler_result' => $result,
    ];
  }

  /**
   * tg_api_call()
   * Unified action dispatcher that modules can call.
   */
  function tg_api_call(string $action, array $payload = [], string $token = ''): array
  {
    $action = strtolower(trim($action));

    if ($action === '') {
      return ['ok' => false, 'error' => 'TG_ACTION_EMPTY'];
    }

    switch ($action) {
      case 'send_message': {
        $chatId = $payload['chat_id'] ?? '';
        $text = (string)($payload['text'] ?? '');
        if ($chatId === '' || $text === '') {
          return ['ok' => false, 'error' => 'TG_PARAMS_REQUIRED'];
        }
        unset($payload['chat_id'], $payload['text']);
        return tg_send_message($token, $chatId, $text, $payload);
      }

      case 'channel_post': {
        $chatId = $payload['chat_id'] ?? '';
        $text = (string)($payload['text'] ?? '');
        if ($chatId === '' || $text === '') {
          return ['ok' => false, 'error' => 'TG_PARAMS_REQUIRED'];
        }
        unset($payload['chat_id'], $payload['text']);
        return tg_channel_post($token, $chatId, $text, $payload);
      }

      case 'get_me':
        return tg_get_me($token);

      case 'get_chat': {
        $chatId = $payload['chat_id'] ?? '';
        if ($chatId === '') {
          return ['ok' => false, 'error' => 'TG_CHAT_ID_REQUIRED'];
        }
        return tg_get_chat($token, $chatId);
      }

      case 'get_chat_member': {
        $chatId = $payload['chat_id'] ?? '';
        $userId = $payload['user_id'] ?? '';
        if ($chatId === '' || $userId === '') {
          return ['ok' => false, 'error' => 'TG_PARAMS_REQUIRED'];
        }
        return tg_get_chat_member($token, $chatId, $userId);
      }

      case 'get_chat_administrators': {
        $chatId = $payload['chat_id'] ?? '';
        if ($chatId === '') {
          return ['ok' => false, 'error' => 'TG_CHAT_ID_REQUIRED'];
        }
        return tg_get_chat_administrators($token, $chatId);
      }

      case 'get_updates':
        return tg_get_updates($token, $payload);

      case 'set_webhook': {
        $url = trim((string)($payload['url'] ?? ''));
        if ($url === '') {
          return tg_set_webhook_auto($token, $payload);
        }
        unset($payload['url']);
        return tg_set_webhook($token, $url, $payload);
      }

      case 'delete_webhook': {
        $dropPending = tg_bool($payload['drop_pending_updates'] ?? true);
        return tg_delete_webhook($token, $dropPending);
      }

      case 'get_webhook_info':
        return tg_get_webhook_info($token);

      case 'read_update':
        return ['ok' => true, 'result' => tg_read_update()];

      default:
        return ['ok' => false, 'error' => 'TG_ACTION_UNKNOWN'];
    }
  }

  /**
   * sendSystemTG()
   * Унифицированный вызов отправки системных Telegram-уведомлений для сотрудников.
   *
   * @param string $message
   * @param string $eventCode
   * @param array<string,mixed> $options
   * @return array<string,mixed>
   */
  function sendSystemTG(string $message, string $eventCode = 'general', array $options = []): array
  {
    if (!defined('ROOT_PATH')) {
      return ['ok' => false, 'reason' => 'root_path_missing', 'message' => 'ROOT_PATH is not defined'];
    }

    $settingsFile = ROOT_PATH . '/adm/modules/tg_system_users/settings.php';
    $libFile = ROOT_PATH . '/adm/modules/tg_system_users/assets/php/tg_system_users_lib.php';

    if (!is_file($settingsFile) || !is_file($libFile)) {
      return ['ok' => false, 'reason' => 'module_files_missing', 'message' => 'tg_system_users module files not found'];
    }

    require_once $settingsFile;
    require_once $libFile;

    if (!function_exists('tg_system_users_send_system')) {
      return ['ok' => false, 'reason' => 'module_send_missing', 'message' => 'tg_system_users_send_system() is not available'];
    }

    if (!function_exists('db')) {
      return ['ok' => false, 'reason' => 'db_missing', 'message' => 'db() is not available'];
    }

    if (function_exists('module_is_enabled') && !module_is_enabled('tg_system_users')) {
      return ['ok' => false, 'reason' => 'module_disabled', 'message' => 'tg_system_users module is disabled'];
    }

    try {
      $pdo = db();
      return tg_system_users_send_system($pdo, $message, $eventCode, $options);
    } catch (Throwable $e) {
      if (function_exists('audit_log')) {
        audit_log('tg_system_users', 'sendSystemTG', 'error', [
          'event_code' => $eventCode,
          'error' => $e->getMessage(),
        ]);
      }
      return ['ok' => false, 'reason' => 'exception', 'message' => $e->getMessage()];
    }
  }

  /**
   * sendClientTG()
   * Unified helper for Telegram notifications to CRM clients.
   *
   * @param string $message
   * @param string $eventCode
   * @param array<string,mixed> $options
   * @return array<string,mixed>
   */
  function sendClientTG(string $message, string $eventCode = 'general', array $options = []): array
  {
    if (!defined('ROOT_PATH')) {
      return ['ok' => false, 'reason' => 'root_path_missing', 'message' => 'ROOT_PATH is not defined'];
    }

    $settingsFile = ROOT_PATH . '/adm/modules/tg_system_clients/settings.php';
    $libFile = ROOT_PATH . '/adm/modules/tg_system_clients/assets/php/tg_system_clients_lib.php';

    if (!is_file($settingsFile) || !is_file($libFile)) {
      return ['ok' => false, 'reason' => 'module_files_missing', 'message' => 'tg_system_clients module files not found'];
    }

    require_once $settingsFile;
    require_once $libFile;

    if (!function_exists('tg_system_clients_send_system')) {
      return ['ok' => false, 'reason' => 'module_send_missing', 'message' => 'tg_system_clients_send_system() is not available'];
    }

    if (!function_exists('db')) {
      return ['ok' => false, 'reason' => 'db_missing', 'message' => 'db() is not available'];
    }

    if (function_exists('module_is_enabled') && !module_is_enabled('tg_system_clients')) {
      return ['ok' => false, 'reason' => 'module_disabled', 'message' => 'tg_system_clients module is disabled'];
    }

    try {
      $pdo = db();
      return tg_system_clients_send_system($pdo, $message, $eventCode, $options);
    } catch (Throwable $e) {
      if (function_exists('audit_log')) {
        audit_log('tg_system_clients', 'sendClientTG', 'error', [
          'event_code' => $eventCode,
          'error' => $e->getMessage(),
        ]);
      }
      return ['ok' => false, 'reason' => 'exception', 'message' => $e->getMessage()];
    }
  }
}
