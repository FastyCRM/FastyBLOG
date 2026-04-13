<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_lib_v2.php
 * VERSION: webhook include rollover to bypass stale opcode cache.
 * ROLE: Бизнес-логика модуля channel_bridge.
 * CONTAINS:
 *  - Проверка схемы БД модуля.
 *  - Чтение/сохранение настроек.
 *  - CRUD маршрутов "источник -> цель".
 *  - Приём входящих сообщений и отправка в TG/VK/MAX.
 *  - Журналирование отправок.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_i18n.php';
require_once ROOT_PATH . '/core/telegram.php';

if (!function_exists('channel_bridge_now')) {
  /**
   * channel_bridge_now()
   * Возвращает серверное время в формате DATETIME.
   *
   * @return string
   */
  function channel_bridge_now(): string
  {
    return date('Y-m-d H:i:s');
  }

  /**
   * channel_bridge_supported_sources()
   * Возвращает список поддерживаемых платформ-источников.
   *
   * @return array<int,string>
   */
  function channel_bridge_supported_sources(): array
  {
    return [CHANNEL_BRIDGE_SOURCE_TG];
  }

  /**
   * channel_bridge_supported_targets()
   * Возвращает список поддерживаемых платформ-целей.
   *
   * @return array<int,string>
   */
  function channel_bridge_supported_targets(): array
  {
    return [CHANNEL_BRIDGE_TARGET_TG, CHANNEL_BRIDGE_TARGET_VK, CHANNEL_BRIDGE_TARGET_MAX];
  }

  /**
   * channel_bridge_can_manage()
   * Проверяет, может ли текущий набор ролей менять настройки/маршруты модуля.
   *
   * @param array<int,string> $roles
   * @return bool
   */
  function channel_bridge_can_manage(array $roles): bool
  {
    return in_array('admin', $roles, true) || in_array('manager', $roles, true);
  }

  /**
   * channel_bridge_is_internal_key_request()
   * Проверяет авторизацию по internal API key (заголовок или query/body параметр).
   *
   * @return bool
   */
  function channel_bridge_is_internal_key_request(): bool
  {
    $cfg = function_exists('app_config') ? (array)app_config() : [];
    $internalKey = trim((string)($cfg['internal_api']['key'] ?? ''));
    if ($internalKey === '') {
      return false;
    }

    $headerKey = trim((string)($_SERVER['HTTP_X_INTERNAL_API_KEY'] ?? ''));
    $queryKey = trim((string)($_REQUEST['key'] ?? ''));

    return ($headerKey !== '' && hash_equals($internalKey, $headerKey))
      || ($queryKey !== '' && hash_equals($internalKey, $queryKey));
  }

  /**
   * channel_bridge_require_manage_or_internal()
   * Унифицированная проверка доступа для API-методов:
   *  - либо авторизация сессией (admin/manager),
   *  - либо internal API key.
   *
   * @return void
   */
  function channel_bridge_require_manage_or_internal(): void
  {
    if (channel_bridge_is_internal_key_request()) {
      return;
    }

    acl_guard(module_allowed_roles(CHANNEL_BRIDGE_MODULE_CODE));

    $uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
    $roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
    if (!channel_bridge_can_manage($roles)) {
      json_err('Forbidden', 403);
    }
  }

  /**
   * channel_bridge_bind_supported_sides()
   * Возвращает список поддерживаемых сторон маршрута для автопривязки.
   *
   * @return array<int,string>
   */
  function channel_bridge_bind_supported_sides(): array
  {
    return [CHANNEL_BRIDGE_BIND_SIDE_SOURCE, CHANNEL_BRIDGE_BIND_SIDE_TARGET];
  }

  /**
   * channel_bridge_make_pending_chat_id()
   * Генерирует технический placeholder chat_id до фактической привязки кода.
   *
   * @param string $side
   * @return string
   */
  function channel_bridge_make_pending_chat_id(string $side): string
  {
    $side = strtolower(trim($side));
    if (!in_array($side, channel_bridge_bind_supported_sides(), true)) {
      $side = CHANNEL_BRIDGE_BIND_SIDE_SOURCE;
    }

    try {
      $suffix = bin2hex(random_bytes(6));
    } catch (Throwable $e) {
      $suffix = (string)mt_rand(100000, 999999);
    }

    return '__cb_pending_' . $side . '_' . $suffix . '__';
  }

  /**
   * channel_bridge_generate_raw_bind_code()
   * Генерирует короткий одноразовый код автопривязки (4 цифры).
   *
   * @return string
   */
  function channel_bridge_generate_raw_bind_code(): string
  {
    return (string)random_int(1000, 9999);
  }

  /**
   * channel_bridge_extract_bind_code_from_text()
   * Извлекает код привязки из текста:
   *  - "1234"
   *  - "/start 1234"
   *  - "/bind 1234"
   *
   * @param string $text
   * @return string
   */
  function channel_bridge_extract_bind_code_from_text(string $text): string
  {
    $text = trim($text);
    if ($text === '') {
      return '';
    }

    if (preg_match('~^\d{4}$~', $text)) {
      return $text;
    }

    if (preg_match('~^/(?:start|bind)(?:@\w+)?(?:\s+(\d{4}))?\s*$~u', $text, $m)) {
      return trim((string)($m[1] ?? ''));
    }

    return '';
  }

  /**
   * channel_bridge_extract_tg_text_meta()
   * Извлекает универсальные поля текста/чата из Telegram update:
   *  - message / edited_message
   *  - channel_post / edited_channel_post
   *
   * @param array<string,mixed> $update
   * @return array<string,string>
   */
  function channel_bridge_extract_tg_text_meta(array $update): array
  {
    $msg = [];
    if (isset($update['message']) && is_array($update['message'])) {
      $msg = (array)$update['message'];
    } elseif (isset($update['edited_message']) && is_array($update['edited_message'])) {
      $msg = (array)$update['edited_message'];
    } elseif (isset($update['channel_post']) && is_array($update['channel_post'])) {
      $msg = (array)$update['channel_post'];
    } elseif (isset($update['edited_channel_post']) && is_array($update['edited_channel_post'])) {
      $msg = (array)$update['edited_channel_post'];
    }

    if (!$msg) {
      return [];
    }

    $chat = (array)($msg['chat'] ?? []);
    $from = (array)($msg['from'] ?? []);
    $text = trim((string)($msg['text'] ?? ''));
    if ($text === '') {
      $text = trim((string)($msg['caption'] ?? ''));
    }

    return [
      'chat_id' => trim((string)($chat['id'] ?? '')),
      'chat_type' => trim((string)($chat['type'] ?? '')),
      'text' => $text,
      'username' => trim((string)($from['username'] ?? '')),
      'first_name' => trim((string)($from['first_name'] ?? '')),
      'last_name' => trim((string)($from['last_name'] ?? '')),
    ];
  }

  /**
   * channel_bridge_tg_datetime_from_unix()
   * Нормализует unix timestamp Telegram в DATETIME строку.
   *
   * @param int $ts
   * @return string|null
   */
  function channel_bridge_tg_datetime_from_unix(int $ts): ?string
  {
    if ($ts <= 0) {
      return null;
    }

    return date('Y-m-d H:i:s', $ts);
  }

  /**
   * channel_bridge_extract_tg_update_meta()
   * Возвращает компактные метаданные Telegram update без raw payload.
   *
   * @param array<string,mixed> $update
   * @return array<string,mixed>
   */
  function channel_bridge_extract_tg_update_meta(array $update): array
  {
    $updateId = (int)($update['update_id'] ?? 0);
    $type = '';
    $msg = [];

    foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $candidate) {
      if (!isset($update[$candidate]) || !is_array($update[$candidate])) {
        continue;
      }
      $type = $candidate;
      $msg = (array)$update[$candidate];
      break;
    }

    if ($updateId <= 0 && !$msg) {
      return [];
    }

    $chat = (array)($msg['chat'] ?? []);
    $messageDateTs = (int)($msg['date'] ?? 0);
    $editDateTs = (int)($msg['edit_date'] ?? 0);

    return [
      'update_id' => $updateId,
      'update_type' => $type,
      'chat_id' => trim((string)($chat['id'] ?? '')),
      'chat_type' => trim((string)($chat['type'] ?? '')),
      'message_id' => trim((string)($msg['message_id'] ?? '')),
      'media_group_id' => trim((string)($msg['media_group_id'] ?? '')),
      'message_date_ts' => $messageDateTs,
      'edit_date_ts' => $editDateTs,
      'message_date' => channel_bridge_tg_datetime_from_unix($messageDateTs),
      'edit_date' => channel_bridge_tg_datetime_from_unix($editDateTs),
    ];
  }

  /**
   * channel_bridge_tg_update_audit_payload()
   * Собирает компактный payload для аудита webhook-решений.
   *
   * @param array<string,mixed> $meta
   * @param string $decision
   * @param array<string,mixed> $extra
   * @return array<string,mixed>
   */
  function channel_bridge_tg_update_audit_payload(array $meta, string $decision, array $extra = []): array
  {
    $payload = [
      'decision' => trim($decision),
      'update_id' => (int)($meta['update_id'] ?? 0),
      'update_type' => trim((string)($meta['update_type'] ?? '')),
      'chat_id' => trim((string)($meta['chat_id'] ?? '')),
      'message_id' => trim((string)($meta['message_id'] ?? '')),
      'media_group_id' => trim((string)($meta['media_group_id'] ?? '')),
      'message_date' => trim((string)($meta['message_date'] ?? '')),
      'edit_date' => trim((string)($meta['edit_date'] ?? '')),
    ];

    foreach ($payload as $key => $value) {
      if ($value === '' || $value === 0) {
        unset($payload[$key]);
      }
    }

    foreach ($extra as $key => $value) {
      $payload[$key] = $value;
    }

    return $payload;
  }

  /**
   * channel_bridge_is_tg_update_stale()
   * Проверяет, что channel_post слишком старый для автокросспоста.
   *
   * @param array<string,mixed> $meta
   * @param int $maxAgeSeconds
   * @return bool
   */
  function channel_bridge_is_tg_update_stale(array $meta, int $maxAgeSeconds = CHANNEL_BRIDGE_TG_UPDATE_MAX_AGE_SECONDS): bool
  {
    $type = trim((string)($meta['update_type'] ?? ''));
    if ($type !== 'channel_post' && $type !== 'edited_channel_post') {
      return false;
    }

    $messageTs = (int)($meta['message_date_ts'] ?? 0);
    if ($messageTs <= 0 || $maxAgeSeconds <= 0) {
      return false;
    }

    return ((time() - $messageTs) > $maxAgeSeconds);
  }

  /**
   * channel_bridge_webhook_updates_table_available()
   * Проверяет наличие таблицы дедупликации webhook update_id.
   *
   * @param PDO $pdo
   * @return bool
   */
  function channel_bridge_webhook_updates_table_available(PDO $pdo): bool
  {
    return channel_bridge_schema_table_exists($pdo, CHANNEL_BRIDGE_TABLE_WEBHOOK_UPDATES);
  }

  /**
   * channel_bridge_webhook_update_register()
   * Пытается зарегистрировать входящий Telegram update_id для дедупликации.
   *
   * @param PDO $pdo
   * @param array<string,mixed> $meta
   * @return array<string,mixed>
   */
  function channel_bridge_webhook_update_register(PDO $pdo, array $meta): array
  {
    $updateId = (int)($meta['update_id'] ?? 0);
    if ($updateId <= 0) {
      return ['active' => false, 'duplicate' => false];
    }

    if (!channel_bridge_webhook_updates_table_available($pdo)) {
      return ['active' => false, 'duplicate' => false];
    }

    try {
      $pdo->prepare("
        INSERT INTO " . CHANNEL_BRIDGE_TABLE_WEBHOOK_UPDATES . "
        (
          update_id,
          update_type,
          source_chat_id,
          source_message_id,
          media_group_id,
          message_date,
          edit_date
        )
        VALUES
        (
          :update_id,
          :update_type,
          :source_chat_id,
          :source_message_id,
          :media_group_id,
          :message_date,
          :edit_date
        )
      ")->execute([
        ':update_id' => $updateId,
        ':update_type' => trim((string)($meta['update_type'] ?? '')),
        ':source_chat_id' => trim((string)($meta['chat_id'] ?? '')),
        ':source_message_id' => trim((string)($meta['message_id'] ?? '')),
        ':media_group_id' => trim((string)($meta['media_group_id'] ?? '')),
        ':message_date' => $meta['message_date'] ?? null,
        ':edit_date' => $meta['edit_date'] ?? null,
      ]);
    } catch (Throwable $e) {
      if ((string)$e->getCode() === '23000') {
        return ['active' => true, 'duplicate' => true];
      }
      throw $e;
    }

    return ['active' => true, 'duplicate' => false];
  }

  /**
   * channel_bridge_tg_text_link_urls()
   * Извлекает URL из Telegram entities типа text_link.
   *
   * @param mixed $entities
   * @return array<int,string>
   */
  function channel_bridge_tg_text_link_urls($entities): array
  {
    if (!is_array($entities)) return [];

    $out = [];
    foreach ($entities as $entity) {
      if (!is_array($entity)) continue;
      if (trim((string)($entity['type'] ?? '')) !== 'text_link') continue;
      $url = trim((string)($entity['url'] ?? ''));
      if ($url === '') continue;
      $out[] = $url;
    }

    if (!$out) return [];
    $uniq = [];
    foreach ($out as $url) {
      $uniq[$url] = true;
    }
    return array_keys($uniq);
  }

  /**
   * channel_bridge_tg_text_links_html()
   * Собирает HTML-текст с привязкой Telegram text_link к исходному тексту.
   * Нужен для MAX: ссылка остаётся на тексте, но не распаковывается в голый URL.
   *
   * @param string $text
   * @param mixed $entities
   * @return string
   */
  function channel_bridge_tg_text_links_html(string $text, $entities): string
  {
    $text = (string)$text;
    if ($text === '' || !is_array($entities) || !$entities) {
      return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    $links = [];
    foreach ($entities as $entity) {
      if (!is_array($entity)) continue;
      if (trim((string)($entity['type'] ?? '')) !== 'text_link') continue;

      $offset = (int)($entity['offset'] ?? -1);
      $length = (int)($entity['length'] ?? 0);
      $url = trim((string)($entity['url'] ?? ''));
      if ($offset < 0 || $length <= 0 || $url === '') continue;

      $links[] = [
        'offset' => $offset,
        'length' => $length,
        'url' => $url,
      ];
    }

    if (!$links) {
      return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    usort($links, static function (array $a, array $b): int {
      return ((int)$a['offset'] <=> (int)$b['offset']);
    });

    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars) || !$chars) {
      return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    $charUnits = [];
    foreach ($chars as $idx => $char) {
      $utf16 = mb_convert_encoding((string)$char, 'UTF-16BE', 'UTF-8');
      $units = (int)(strlen($utf16) / 2);
      $charUnits[$idx] = ($units > 0) ? $units : 1;
    }

    $out = '';
    $charIndex = 0;
    $utf16Pos = 0;
    $linkIndex = 0;
    $linksCount = count($links);

    while ($charIndex < count($chars)) {
      while ($linkIndex < $linksCount && (int)$links[$linkIndex]['offset'] < $utf16Pos) {
        $linkIndex++;
      }

      if ($linkIndex < $linksCount && (int)$links[$linkIndex]['offset'] === $utf16Pos) {
        $targetEnd = (int)$links[$linkIndex]['offset'] + (int)$links[$linkIndex]['length'];
        $label = '';

        while ($charIndex < count($chars) && $utf16Pos < $targetEnd) {
          $label .= (string)$chars[$charIndex];
          $utf16Pos += (int)($charUnits[$charIndex] ?? 1);
          $charIndex++;
        }

        if ($label !== '') {
          $out .= '<a href="' . htmlspecialchars((string)$links[$linkIndex]['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            . '</a>';
        }

        $linkIndex++;
        continue;
      }

      $out .= htmlspecialchars((string)$chars[$charIndex], ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $utf16Pos += (int)($charUnits[$charIndex] ?? 1);
      $charIndex++;
    }

    return $out;
  }

  /**
   * channel_bridge_tg_message_has_media()
   * Быстрая проверка, содержит ли TG-пост медиа.
   *
   * @param array<string,mixed> $msg
   * @return bool
   */
  function channel_bridge_tg_message_has_media(array $msg): bool
  {
    foreach (['photo', 'video', 'animation', 'document', 'audio', 'voice'] as $k) {
      if (!array_key_exists($k, $msg)) continue;
      $v = $msg[$k];
      if (is_array($v) && $v) return true;
      if (!is_array($v) && $v !== null && $v !== '') return true;
    }
    return false;
  }

  /**
   * channel_bridge_tg_message_public_url()
   * Формирует публичный URL поста в TG-канале, если доступен username.
   *
   * @param array<string,mixed> $msg
   * @return string
   */
  function channel_bridge_tg_message_public_url(array $msg): string
  {
    $chat = (array)($msg['chat'] ?? []);
    $username = trim((string)($chat['username'] ?? ''));
    $messageId = trim((string)($msg['message_id'] ?? ''));
    if ($username === '' || $messageId === '' || !preg_match('~^\d+$~', $messageId)) {
      return '';
    }
    return 'https://t.me/' . $username . '/' . $messageId;
  }

  /**
   * channel_bridge_tg_numeric_message_id_from_meta()
   * Возвращает числовой message_id из source_message_id или media_group списка.
   *
   * @param array<string,mixed> $meta
   * @return string
   */
  function channel_bridge_tg_numeric_message_id_from_meta(array $meta): string
  {
    $sourceMessageId = trim((string)($meta['source_message_id'] ?? ''));
    if (preg_match('~^\d+$~', $sourceMessageId)) {
      return $sourceMessageId;
    }

    $ids = channel_bridge_tg_norm_message_ids($meta['tg_media_group_message_ids'] ?? []);
    if ($ids) {
      return (string)$ids[0];
    }

    return '';
  }

  /**
   * channel_bridge_tg_post_url_from_meta()
   * Возвращает ссылку на исходный TG-пост:
   *  1) готовый tg_public_post_url;
   *  2) t.me/<username>/<message_id>;
   *  3) t.me/c/<internal_id>/<message_id> для -100... chat_id.
   *
   * @param array<string,mixed> $meta
   * @return string
   */
  function channel_bridge_tg_post_url_from_meta(array $meta): string
  {
    $readyUrl = trim((string)($meta['tg_public_post_url'] ?? ''));
    if ($readyUrl !== '' && preg_match('~^https?://~i', $readyUrl)) {
      return $readyUrl;
    }

    $messageId = channel_bridge_tg_numeric_message_id_from_meta($meta);
    if ($messageId === '') return '';

    $username = trim((string)($meta['tg_chat_username'] ?? ''));
    if ($username !== '') {
      return 'https://t.me/' . ltrim($username, '@') . '/' . $messageId;
    }

    $chatId = channel_bridge_norm_chat_id((string)($meta['source_chat_id'] ?? ''));
    if (preg_match('~^-100(\d+)$~', $chatId, $m)) {
      return 'https://t.me/c/' . $m[1] . '/' . $messageId;
    }

    return '';
  }

  /**
   * channel_bridge_tg_link_preview_url()
   * Возвращает URL из link_preview_options.url (если Telegram передал его в update).
   *
   * @param array<string,mixed> $msg
   * @return string
   */
  function channel_bridge_tg_link_preview_url(array $msg): string
  {
    $lpo = $msg['link_preview_options'] ?? null;
    if (!is_array($lpo)) return '';

    return trim((string)($lpo['url'] ?? ''));
  }

  /**
   * channel_bridge_tg_best_photo_file_id()
   * Возвращает file_id фото с наибольшим размером из сообщения Telegram.
   *
   * @param array<string,mixed> $msg
   * @return string
   */
  function channel_bridge_tg_best_photo_file_id(array $msg): string
  {
    $photos = $msg['photo'] ?? null;
    if (!is_array($photos) || !$photos) return '';

    $bestFileId = '';
    $bestArea = -1;
    foreach ($photos as $photo) {
      if (!is_array($photo)) continue;
      $fileId = trim((string)($photo['file_id'] ?? ''));
      if ($fileId === '') continue;

      $w = (int)($photo['width'] ?? 0);
      $h = (int)($photo['height'] ?? 0);
      $area = $w * $h;
      if ($area >= $bestArea) {
        $bestArea = $area;
        $bestFileId = $fileId;
      }
    }

    return $bestFileId;
  }

  /**
   * channel_bridge_append_missing_urls()
   * Добавляет отсутствующие URL в конец текста отдельными строками.
   *
   * @param string $text
   * @param array<int,string> $urls
   * @return string
   */
  function channel_bridge_append_missing_urls(string $text, array $urls): string
  {
    $text = trim($text);
    $add = [];

    foreach ($urls as $url) {
      $url = trim($url);
      if ($url === '') continue;
      if ($text !== '' && stripos($text, $url) !== false) continue;
      $add[$url] = true;
    }

    if (!$add) return $text;

    $tail = implode("\n", array_keys($add));
    if ($text === '') return $tail;
    return $text . "\n\n" . $tail;
  }

  /**
   * channel_bridge_max_markdown_escape()
   * Экранирует markdown-символы в подписи ссылки для MAX.
   *
   * @param string $text
   * @return string
   */
  function channel_bridge_max_markdown_escape(string $text): string
  {
    $text = str_replace('\\', '\\\\', $text);
    $text = str_replace(['[', ']'], ['\\[', '\\]'], $text);
    return $text;
  }

  /**
   * channel_bridge_max_prepare_text()
   * Готовит текст для MAX:
   *  - HTML-ссылки переводит в markdown-гиперссылки;
   *  - не разворачивает скрытые ссылки в голые URL;
   *  - сообщает, нужен ли format=markdown.
   *
   * @param string $text
   * @return array{text:string,markdown:int}
   */
  function channel_bridge_max_prepare_text(string $text): array
  {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('~<br\s*/?>~iu', "\n", $text);
    if (!is_string($text)) $text = '';

    $markdown = 0;
    $text = preg_replace_callback('~<a\s+[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>~isu', static function (array $m) use (&$markdown): string {
      $url = trim((string)($m[2] ?? ''));
      $label = trim(strip_tags((string)($m[3] ?? '')));
      if ($url === '') return $label;
      if ($label === '') return $url;
      if (mb_strtolower($label, 'UTF-8') === mb_strtolower($url, 'UTF-8')) return $url;

      $markdown = 1;
      return '[' . channel_bridge_max_markdown_escape($label) . '](' . $url . ')';
    }, $text);
    if (!is_string($text)) $text = '';

    $text = strip_tags($text);

    return [
      'text' => channel_bridge_normalize_text($text),
      'markdown' => $markdown,
    ];
  }

  /**
   * channel_bridge_max_apply_repost_quote()
   * Преобразует строку "Репост: ..." в markdown-цитату для MAX.
   *
   * @param string $text
   * @return array{text:string,quoted:int}
   */
  function channel_bridge_max_apply_repost_quote(string $text): array
  {
    $text = channel_bridge_normalize_text($text);
    if ($text === '') return ['text' => '', 'quoted' => 0];

    $lines = preg_split('~\R~u', $text);
    if (!is_array($lines) || !$lines) {
      return ['text' => $text, 'quoted' => 0];
    }

    $quoted = 0;
    foreach ($lines as $idx => $line) {
      $line = (string)$line;
      if (!preg_match('~^\s*(?:>\s*)?Репост:\s+~u', $line)) {
        $lines[$idx] = $line;
        continue;
      }
      $line = preg_replace('~^\s*(?:>\s*)?~u', '', $line);
      if (!is_string($line)) $line = '';
      $line = trim($line);
      if ($line === '') continue;
      $lines[$idx] = '> ' . $line;
      $quoted = 1;
    }

    return [
      'text' => implode("\n", $lines),
      'quoted' => $quoted,
    ];
  }

  /**
   * channel_bridge_media_group_buffer_path()
   * Путь к временному буферу альбома TG (media_group_id).
   *
   * @param string $sourceChatId
   * @param string $mediaGroupId
   * @return string
   */
  function channel_bridge_media_group_buffer_path(string $sourceChatId, string $mediaGroupId): string
  {
    $key = hash('sha256', trim($sourceChatId) . '|' . trim($mediaGroupId));
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'cb_mg_' . $key . '.json';
  }

  /**
   * channel_bridge_media_group_lock_and_read()
   * Открывает файл буфера альбома и берёт EX lock.
   *
   * @param string $path
   * @return array<string,mixed>
   */
  function channel_bridge_media_group_lock_and_read(string $path): array
  {
    $fp = @fopen($path, 'c+');
    if ($fp === false) {
      return ['ok' => false, 'error' => 'BUFFER_OPEN_FAILED'];
    }
    if (!@flock($fp, LOCK_EX)) {
      @fclose($fp);
      return ['ok' => false, 'error' => 'BUFFER_LOCK_FAILED'];
    }

    $raw = stream_get_contents($fp);
    $state = [];
    if (is_string($raw) && trim($raw) !== '') {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) $state = $decoded;
    }

    return ['ok' => true, 'fp' => $fp, 'state' => $state];
  }

  /**
   * channel_bridge_media_group_write_and_unlock()
   * Сохраняет состояние буфера и освобождает lock.
   *
   * @param resource $fp
   * @param array<string,mixed> $state
   * @return void
   */
  function channel_bridge_media_group_write_and_unlock($fp, array $state): void
  {
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) $json = '{}';

    @ftruncate($fp, 0);
    @rewind($fp);
    @fwrite($fp, $json);
    @fflush($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);
  }

  /**
   * channel_bridge_media_group_unlock()
   * Освобождает lock на буфере без записи.
   *
   * @param resource $fp
   * @return void
   */
  function channel_bridge_media_group_unlock($fp): void
  {
    @flock($fp, LOCK_UN);
    @fclose($fp);
  }

  /**
   * channel_bridge_media_group_cleanup()
   * Removes expired media_group buffers during normal webhook traffic.
   *
   * @param int $ttlSeconds
   * @return int
   */
  function channel_bridge_media_group_cleanup(int $ttlSeconds = CHANNEL_BRIDGE_MEDIA_GROUP_TTL_SECONDS): int
  {
    $ttlSeconds = max(60, $ttlSeconds);
    $pattern = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'cb_mg_*.json';
    $files = glob($pattern);
    if (!is_array($files) || !$files) {
      return 0;
    }

    $removed = 0;
    $now = microtime(true);
    foreach ($files as $path) {
      $path = trim((string)$path);
      if ($path === '' || !is_file($path)) {
        continue;
      }

      $fp = @fopen($path, 'c+');
      if ($fp === false) {
        continue;
      }
      if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        @fclose($fp);
        continue;
      }

      $raw = stream_get_contents($fp);
      $state = [];
      if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
          $state = $decoded;
        }
      }

      $lastSeenAt = (float)($state['last_seen_at'] ?? 0);
      $sentAt = (float)($state['sent_at'] ?? 0);
      $mtime = @filemtime($path);
      $mtimeAt = ($mtime !== false) ? (float)$mtime : 0.0;
      $pivotAt = max($lastSeenAt, $sentAt, $mtimeAt);
      $expired = ($pivotAt > 0) && (($now - $pivotAt) >= $ttlSeconds);

      @flock($fp, LOCK_UN);
      @fclose($fp);

      if ($expired && @unlink($path)) {
        $removed++;
      }
    }

    return $removed;
  }

  /**
   * channel_bridge_media_group_state_add_item()
   * Adds one webhook element to media_group state.
   *
   * @param array<string,mixed> $state
   * @param array<string,mixed> $payload
   * @param float $now
   * @return array<string,mixed>
   */
  function channel_bridge_media_group_state_add_item(array $state, array $payload, float $now): array
  {
    $sourceMessageId = trim((string)($payload['source_message_id'] ?? ''));
    $updateId = (int)($payload['tg_update_id'] ?? 0);
    $sortMessageId = preg_match('~^\d+$~', $sourceMessageId) ? (int)$sourceMessageId : 0;

    if (!isset($state['items']) || !is_array($state['items'])) {
      $state['items'] = [];
    }

    $item = isset($state['items'][$sourceMessageId]) && is_array($state['items'][$sourceMessageId])
      ? (array)$state['items'][$sourceMessageId]
      : [
        'source_message_id' => $sourceMessageId,
        'sort_message_id' => $sortMessageId,
        'photo_file_ids' => [],
        'message_text' => '',
        'tg_text_html' => '',
        'tg_text_link_urls' => [],
        'tg_public_post_url' => '',
        'tg_chat_username' => '',
      ];

    $isNewItem = !isset($state['items'][$sourceMessageId]);

    $photoMap = [];
    foreach ((array)($item['photo_file_ids'] ?? []) as $photoId) {
      $photoId = trim((string)$photoId);
      if ($photoId !== '') {
        $photoMap[$photoId] = true;
      }
    }
    foreach ((array)($payload['tg_photo_file_ids'] ?? []) as $photoId) {
      $photoId = trim((string)$photoId);
      if ($photoId !== '') {
        $photoMap[$photoId] = true;
      }
    }
    $singlePhotoId = trim((string)($payload['tg_photo_file_id'] ?? ''));
    if ($singlePhotoId !== '') {
      $photoMap[$singlePhotoId] = true;
    }
    $item['photo_file_ids'] = array_values(array_keys($photoMap));
    $item['sort_message_id'] = $sortMessageId;

    $messageText = channel_bridge_normalize_text((string)($payload['message_text'] ?? ''));
    if ($messageText !== '' && trim((string)($item['message_text'] ?? '')) === '') {
      $item['message_text'] = $messageText;
    }

    $textHtml = trim((string)($payload['tg_text_html'] ?? ''));
    if ($textHtml !== '' && trim((string)($item['tg_text_html'] ?? '')) === '') {
      $item['tg_text_html'] = $textHtml;
    }

    $linkMap = [];
    foreach ((array)($item['tg_text_link_urls'] ?? []) as $url) {
      $url = trim((string)$url);
      if ($url !== '') {
        $linkMap[$url] = true;
      }
    }
    foreach ((array)($payload['tg_text_link_urls'] ?? []) as $url) {
      $url = trim((string)$url);
      if ($url !== '') {
        $linkMap[$url] = true;
      }
    }
    $item['tg_text_link_urls'] = array_values(array_keys($linkMap));

    $publicUrl = trim((string)($payload['tg_public_post_url'] ?? ''));
    if ($publicUrl !== '' && trim((string)($item['tg_public_post_url'] ?? '')) === '') {
      $item['tg_public_post_url'] = $publicUrl;
    }

    $chatUsername = trim((string)($payload['tg_chat_username'] ?? ''));
    if ($chatUsername !== '' && trim((string)($item['tg_chat_username'] ?? '')) === '') {
      $item['tg_chat_username'] = $chatUsername;
    }

    $state['items'][$sourceMessageId] = $item;
    $state['source_platform'] = CHANNEL_BRIDGE_SOURCE_TG;
    $state['source_chat_id'] = trim((string)($payload['source_chat_id'] ?? ''));
    $state['media_group_id'] = trim((string)($payload['tg_media_group_id'] ?? ''));
    $state['items_count'] = count((array)$state['items']);
    $state['sent'] = ((int)($state['sent'] ?? 0) === 1) ? 1 : 0;
    $state['revision'] = (int)($state['revision'] ?? 0);

    if (!isset($state['update_ids']) || !is_array($state['update_ids'])) {
      $state['update_ids'] = [];
    }
    if ($updateId > 0) {
      $state['update_ids'][(string)$updateId] = $updateId;
    }

    if (!isset($state['first_seen_at']) || (float)$state['first_seen_at'] <= 0) {
      $state['first_seen_at'] = $now;
    }
    if ($isNewItem || !isset($state['last_seen_at']) || (float)$state['last_seen_at'] <= 0) {
      $state['last_seen_at'] = $now;
    }

    if ($isNewItem) {
      $state['revision']++;
      $state['dispatching'] = 0;
      $state['dispatch_token'] = '';
      $state['dispatch_revision'] = 0;
      $state['dispatch_started_at'] = 0.0;
    }

    return [
      'state' => $state,
      'added' => $isNewItem,
    ];
  }

  /**
   * channel_bridge_media_group_item_count()
   * Returns current item count for media_group state.
   *
   * @param array<string,mixed> $state
   * @return int
   */
  function channel_bridge_media_group_item_count(array $state): int
  {
    return count((array)($state['items'] ?? []));
  }

  /**
   * channel_bridge_media_group_clear_stale_dispatch()
   * Clears abandoned dispatch claim so another webhook can retry the album.
   *
   * @param array<string,mixed> $state
   * @param float $now
   * @return array<string,mixed>
   */
  function channel_bridge_media_group_clear_stale_dispatch(array $state, float $now): array
  {
    if ((int)($state['dispatching'] ?? 0) !== 1) {
      return $state;
    }

    $startedAt = (float)($state['dispatch_started_at'] ?? 0);
    if ($startedAt > 0 && ($now - $startedAt) < CHANNEL_BRIDGE_MEDIA_GROUP_DISPATCH_STALE_SECONDS) {
      return $state;
    }

    $state['dispatching'] = 0;
    $state['dispatch_token'] = '';
    $state['dispatch_revision'] = 0;
    $state['dispatch_started_at'] = 0.0;

    return $state;
  }

  /**
   * channel_bridge_media_group_finish_dispatch()
   * Marks media_group as sent or releases the dispatch claim.
   *
   * @param array<string,mixed> $payload
   * @param string $dispatchToken
   * @param bool $markSent
   * @return array<string,mixed>
   */
  function channel_bridge_media_group_finish_dispatch(array $payload, string $dispatchToken, bool $markSent): array
  {
    $sourceChatId = channel_bridge_norm_chat_id((string)($payload['source_chat_id'] ?? ''));
    $mediaGroupId = trim((string)($payload['tg_media_group_id'] ?? ''));
    $dispatchToken = trim($dispatchToken);
    if ($sourceChatId === '' || $mediaGroupId === '' || $dispatchToken === '') {
      return ['ok' => false, 'reason' => 'bad_meta'];
    }

    $path = channel_bridge_media_group_buffer_path($sourceChatId, $mediaGroupId);
    $locked = channel_bridge_media_group_lock_and_read($path);
    if (($locked['ok'] ?? false) !== true) {
      return ['ok' => false, 'reason' => 'lock_failed'];
    }

    $fp = $locked['fp'];
    $state = is_array($locked['state'] ?? null) ? (array)$locked['state'] : [];
    $currentToken = trim((string)($state['dispatch_token'] ?? ''));
    if ($currentToken === '') {
      channel_bridge_media_group_unlock($fp);
      return ['ok' => true, 'reason' => 'claim_missing'];
    }
    if (!hash_equals($currentToken, $dispatchToken)) {
      channel_bridge_media_group_unlock($fp);
      return ['ok' => true, 'reason' => 'claim_mismatch'];
    }

    $state['dispatching'] = 0;
    $state['dispatch_token'] = '';
    $state['dispatch_revision'] = 0;
    $state['dispatch_started_at'] = 0.0;
    if ($markSent) {
      $state['sent'] = 1;
      $state['sent_at'] = microtime(true);
    }

    channel_bridge_media_group_write_and_unlock($fp, $state);

    return ['ok' => true, 'reason' => $markSent ? 'marked_sent' : 'released'];
  }

  /**
   * channel_bridge_media_group_payload_from_state()
   * Builds one aggregated payload from media_group state.
   *
   * @param array<string,mixed> $state
   * @return array<string,mixed>
   */
  function channel_bridge_media_group_payload_from_state(array $state): array
  {
    $items = [];
    foreach ((array)($state['items'] ?? []) as $item) {
      if (is_array($item)) {
        $items[] = $item;
      }
    }

    usort($items, static function (array $left, array $right): int {
      $leftSort = (int)($left['sort_message_id'] ?? 0);
      $rightSort = (int)($right['sort_message_id'] ?? 0);
      if ($leftSort !== $rightSort) {
        return ($leftSort < $rightSort) ? -1 : 1;
      }

      return strcmp(
        trim((string)($left['source_message_id'] ?? '')),
        trim((string)($right['source_message_id'] ?? ''))
      );
    });

    $messageIds = [];
    $photoIds = [];
    $linkUrls = [];
    $messageText = '';
    $textHtml = '';
    $publicPostUrl = '';
    $chatUsername = '';

    foreach ($items as $item) {
      $messageId = trim((string)($item['source_message_id'] ?? ''));
      if (preg_match('~^\d+$~', $messageId)) {
        $messageIds[(int)$messageId] = true;
      }

      foreach ((array)($item['photo_file_ids'] ?? []) as $photoId) {
        $photoId = trim((string)$photoId);
        if ($photoId !== '') {
          $photoIds[$photoId] = true;
        }
      }

      if ($messageText === '') {
        $messageText = channel_bridge_normalize_text((string)($item['message_text'] ?? ''));
      }
      if ($textHtml === '') {
        $textHtml = trim((string)($item['tg_text_html'] ?? ''));
      }
      if ($publicPostUrl === '') {
        $publicPostUrl = trim((string)($item['tg_public_post_url'] ?? ''));
      }
      if ($chatUsername === '') {
        $chatUsername = trim((string)($item['tg_chat_username'] ?? ''));
      }

      foreach ((array)($item['tg_text_link_urls'] ?? []) as $url) {
        $url = trim((string)$url);
        if ($url !== '') {
          $linkUrls[$url] = true;
        }
      }
    }

    $messageIdList = array_keys($messageIds);
    sort($messageIdList, SORT_NUMERIC);

    return [
      'source_platform' => CHANNEL_BRIDGE_SOURCE_TG,
      'source_chat_id' => trim((string)($state['source_chat_id'] ?? '')),
      'source_message_id' => 'mg:' . trim((string)($state['media_group_id'] ?? '')),
      'message_text' => $messageText,
      'tg_text_html' => $textHtml,
      'tg_media_group_id' => trim((string)($state['media_group_id'] ?? '')),
      'tg_media_group_message_ids' => array_values($messageIdList),
      'tg_photo_file_ids' => array_values(array_keys($photoIds)),
      'tg_photo_file_id' => (string)(array_key_first($photoIds) ?? ''),
      'tg_text_link_urls' => array_values(array_keys($linkUrls)),
      'tg_public_post_url' => $publicPostUrl,
      'tg_chat_username' => $chatUsername,
    ];
  }

  function channel_bridge_media_group_collect(
    array $payload,
    int $waitMs = 0,
    int $idleMs = 0
  ): array {
    $sourcePlatform = channel_bridge_norm_platform((string)($payload['source_platform'] ?? ''));
    $sourceChatId = channel_bridge_norm_chat_id((string)($payload['source_chat_id'] ?? ''));
    $mediaGroupId = trim((string)($payload['tg_media_group_id'] ?? ''));
    $sourceMessageId = trim((string)($payload['source_message_id'] ?? ''));
    if ($sourcePlatform !== CHANNEL_BRIDGE_SOURCE_TG || $sourceChatId === '' || $mediaGroupId === '' || $sourceMessageId === '') {
      return ['mode' => 'skip'];
    }

    channel_bridge_media_group_cleanup(CHANNEL_BRIDGE_MEDIA_GROUP_TTL_SECONDS);

    $runtimeWaitMs = 12000;
    $runtimeStepMs = 100;
    $runtimeIdleMs = 3500;
    $runtimeMinAgeMs = 7000;

    $waitMs = ($waitMs > 0) ? $waitMs : $runtimeWaitMs;
    $idleMs = ($idleMs > 0) ? $idleMs : $runtimeIdleMs;
    $waitMs = max($runtimeStepMs, min($runtimeWaitMs, $waitMs));
    $stepMs = $runtimeStepMs;
    $idleMs = max($stepMs, min($runtimeWaitMs, $idleMs));
    $path = channel_bridge_media_group_buffer_path($sourceChatId, $mediaGroupId);
    $startedAt = microtime(true);

    $locked = channel_bridge_media_group_lock_and_read($path);
    if (($locked['ok'] ?? false) !== true) {
      return ['mode' => 'error', 'error' => (string)($locked['error'] ?? 'BUFFER_LOCK_ERROR')];
    }

    $fp = $locked['fp'];
    $state = is_array($locked['state'] ?? null) ? (array)$locked['state'] : [];
    $state = channel_bridge_media_group_clear_stale_dispatch($state, $startedAt);
    if ((int)($state['sent'] ?? 0) === 1) {
      channel_bridge_media_group_unlock($fp);
      return ['mode' => 'sent', 'media_group_id' => $mediaGroupId, 'reason' => 'already_sent'];
    }

    $added = channel_bridge_media_group_state_add_item($state, $payload, $startedAt);
    $state = is_array($added['state'] ?? null) ? (array)$added['state'] : [];
    channel_bridge_media_group_write_and_unlock($fp, $state);

    $deadlineAt = $startedAt + ($waitMs / 1000.0);
    while (true) {
      $remainingMs = (int)floor(($deadlineAt - microtime(true)) * 1000);
      if ($remainingMs > 0) {
        usleep(min($stepMs, $remainingMs) * 1000);
      }

      $lockedCheck = channel_bridge_media_group_lock_and_read($path);
      if (($lockedCheck['ok'] ?? false) !== true) {
        return ['mode' => 'error', 'error' => (string)($lockedCheck['error'] ?? 'BUFFER_RELOCK_ERROR')];
      }

      $fpCheck = $lockedCheck['fp'];
      $stateCheck = is_array($lockedCheck['state'] ?? null) ? (array)$lockedCheck['state'] : [];
      $stateCheck = channel_bridge_media_group_clear_stale_dispatch($stateCheck, microtime(true));
      if ((int)($stateCheck['sent'] ?? 0) === 1) {
        channel_bridge_media_group_unlock($fpCheck);
        return ['mode' => 'sent', 'media_group_id' => $mediaGroupId, 'reason' => 'already_sent'];
      }

      $lastSeenAt = (float)($stateCheck['last_seen_at'] ?? $startedAt);
      if ($lastSeenAt <= 0) {
        $lastSeenAt = $startedAt;
      }
      $itemCount = channel_bridge_media_group_item_count($stateCheck);

      $now = microtime(true);
      $firstSeenAt = (float)($stateCheck['first_seen_at'] ?? $now);
      if ($firstSeenAt <= 0) {
        $firstSeenAt = $now;
      }
      $ageMs = (int)round(($now - $firstSeenAt) * 1000);
      $idleAgeMs = (int)round(($now - $lastSeenAt) * 1000);
      $deadlineReached = ($now >= $deadlineAt);
      $quietReached = ($idleAgeMs >= $idleMs);
      $windowMature = ($ageMs >= $runtimeMinAgeMs);
      if ((int)($stateCheck['dispatching'] ?? 0) === 1) {
        channel_bridge_media_group_write_and_unlock($fpCheck, $stateCheck);
        return [
          'mode' => 'pending',
          'media_group_id' => $mediaGroupId,
          'reason' => 'dispatch_in_progress',
          'message_count' => $itemCount,
        ];
      }

      $hasMinimumItems = ($itemCount >= CHANNEL_BRIDGE_MEDIA_GROUP_MIN_ITEMS);
      if (!$quietReached || !$hasMinimumItems || !$windowMature) {
        if ($deadlineReached) {
          channel_bridge_media_group_write_and_unlock($fpCheck, $stateCheck);
          return [
            'mode' => 'pending',
            'media_group_id' => $mediaGroupId,
            'reason' => !$hasMinimumItems
              ? 'await_more_items'
              : (!$windowMature ? 'await_window' : 'await_quiet'),
            'message_count' => $itemCount,
            'age_ms' => $ageMs,
            'idle_ms' => $idleAgeMs,
          ];
        }

        channel_bridge_media_group_unlock($fpCheck);
        continue;
      }

      $dispatchToken = hash('sha256', $sourceChatId . '|' . $mediaGroupId . '|' . microtime(true) . '|' . mt_rand(1, PHP_INT_MAX));
      $stateCheck['dispatching'] = 1;
      $stateCheck['dispatch_token'] = $dispatchToken;
      $stateCheck['dispatch_revision'] = (int)($stateCheck['revision'] ?? 0);
      $stateCheck['dispatch_started_at'] = $now;

      $aggPayload = channel_bridge_media_group_payload_from_state($stateCheck);
      $messageCount = count((array)($aggPayload['tg_media_group_message_ids'] ?? []));
      $photoCount = count((array)($aggPayload['tg_photo_file_ids'] ?? []));

      $stateCheck['items_count'] = count((array)($stateCheck['items'] ?? []));
      channel_bridge_media_group_write_and_unlock($fpCheck, $stateCheck);

      return [
        'mode' => 'ready',
        'payload' => $aggPayload,
        'media_group_id' => $mediaGroupId,
        'dispatch_token' => $dispatchToken,
        'message_count' => $messageCount,
        'photo_count' => $photoCount,
        'age_ms' => $ageMs,
        'idle_ms' => $idleAgeMs,
      ];
    }
  }

  /**
   * channel_bridge_public_root_url()
   * Возвращает корневой URL текущего проекта (scheme + host).
   * Нужен для формирования абсолютного webhook URL для Telegram.
   *
   * @return string
   */
  function channel_bridge_public_root_url(): string
  {
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
      return '';
    }

    /**
     * На хостингах за reverse proxy PHP может видеть HTTP,
     * хотя внешний трафик уже HTTPS. Поэтому сначала читаем
     * X-Forwarded-Proto/X-Forwarded-Ssl, затем HTTPS.
     */
    $xfProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $xfProto = strtolower((string)explode(',', $xfProto)[0]);
    $xfSsl = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));

    if ($xfProto === 'https' || $xfSsl === 'on') {
      $scheme = 'https';
    } elseif (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
      $scheme = 'https';
    } else {
      /**
       * Для внешних доменов по умолчанию считаем https,
       * чтобы Telegram не отклонял webhook как "invalid URL".
       */
      $isLocalHost = (stripos($host, 'localhost') !== false) || (stripos($host, '127.0.0.1') !== false);
      $scheme = $isLocalHost ? 'http' : 'https';
    }

    return $scheme . '://' . $host;
  }

  /**
   * channel_bridge_webhook_endpoint_url()
   * Возвращает URL endpoint модуля:
   *  - абсолютный (по умолчанию) для setWebhook/getWebhookInfo;
   *  - относительный, если $absolute = false.
   *
   * @param bool $absolute
   * @return string
   */
  function channel_bridge_webhook_endpoint_url(bool $absolute = true): string
  {
    $path = function_exists('url')
      ? (string)url('/core/telegram_webhook_channel_bridge.php')
      : '/core/telegram_webhook_channel_bridge.php';

    if (!$absolute) {
      return $path;
    }

    $root = channel_bridge_public_root_url();
    if ($root === '') {
      return $path;
    }

    return $root . $path;
  }

  /**
   * channel_bridge_apply_tg_webhook()
   * Применяет webhook в Telegram для бота модуля на текущий endpoint.
   *
   * @param array<string,mixed> $settings
   * @return array<string,mixed>
   */
  function channel_bridge_apply_tg_webhook(array $settings): array
  {
    $token = trim((string)($settings['tg_bot_token'] ?? ''));
    if ($token === '') {
      return ['ok' => false, 'error' => 'TG_TOKEN_EMPTY'];
    }

    $webhookUrl = trim(channel_bridge_webhook_endpoint_url(true));
    if ($webhookUrl === '' || stripos($webhookUrl, 'http') !== 0) {
      return ['ok' => false, 'error' => 'TG_WEBHOOK_URL_EMPTY'];
    }

    $options = [];
    $secret = trim((string)($settings['tg_webhook_secret'] ?? ''));
    if ($secret !== '') {
      $options['secret_token'] = $secret;
    }

    return tg_set_webhook($token, $webhookUrl, $options);
  }

  /**
   * channel_bridge_fetch_tg_webhook_state()
   * Запрашивает текущий webhook у Telegram и возвращает
   * диагностический срез для UI (установлен/совпадает/ошибка).
   *
   * @param array<string,mixed> $settings
   * @return array<string,mixed>
   */
  function channel_bridge_fetch_tg_webhook_state(array $settings): array
  {
    $expectedUrl = channel_bridge_webhook_endpoint_url(true);
    $token = trim((string)($settings['tg_bot_token'] ?? ''));
    if ($token === '') {
      return [
        'ok' => false,
        'reason' => 'no_token',
        'expected_url' => $expectedUrl,
      ];
    }

    if (!function_exists('tg_get_webhook_info')) {
      return [
        'ok' => false,
        'reason' => 'tg_unavailable',
        'expected_url' => $expectedUrl,
      ];
    }

    $info = tg_get_webhook_info($token);
    if (($info['ok'] ?? false) !== true) {
      return [
        'ok' => false,
        'reason' => 'telegram_error',
        'expected_url' => $expectedUrl,
        'api' => $info,
      ];
    }

    $result = (array)($info['result'] ?? []);
    $currentUrl = trim((string)($result['url'] ?? ''));
    $isSet = ($currentUrl !== '');
    $isMatch = $isSet
      && ($expectedUrl !== '')
      && (strcasecmp($currentUrl, $expectedUrl) === 0);

    return [
      'ok' => true,
      'reason' => 'ok',
      'expected_url' => $expectedUrl,
      'current_url' => $currentUrl,
      'is_set' => $isSet,
      'is_match' => $isMatch,
      'pending_update_count' => (int)($result['pending_update_count'] ?? 0),
      'last_error_date' => (int)($result['last_error_date'] ?? 0),
      'last_error_message' => trim((string)($result['last_error_message'] ?? '')),
      'raw' => $info,
    ];
  }

  /**
   * channel_bridge_strip_bom()
   * Удаляет UTF-8 BOM в начале строки.
   *
   * @param string $text
   * @return string
   */
  function channel_bridge_strip_bom(string $text): string
  {
    if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
      return (string)substr($text, 3);
    }
    return $text;
  }

  /**
   * channel_bridge_to_utf8()
   * Пытается привести строку к UTF-8:
   *  - если уже UTF-8, возвращает как есть;
   *  - если строка CP1251, конвертирует в UTF-8;
   *  - на выходе удаляет BOM.
   *
   * @param string $text
   * @return string
   */
  function channel_bridge_to_utf8(string $text): string
  {
    $text = channel_bridge_strip_bom($text);

    $isUtf8 = function_exists('mb_check_encoding')
      ? mb_check_encoding($text, 'UTF-8')
      : (preg_match('//u', $text) === 1);
    if ($isUtf8) {
      return $text;
    }

    $converted = '';
    if (function_exists('iconv')) {
      $tmp = @iconv('CP1251', 'UTF-8//IGNORE', $text);
      if (is_string($tmp)) $converted = $tmp;
    }

    if ($converted === '' && function_exists('mb_convert_encoding')) {
      $tmp = @mb_convert_encoding($text, 'UTF-8', 'CP1251');
      if (is_string($tmp)) $converted = $tmp;
    }

    if ($converted === '') {
      $converted = $text;
    }

    return channel_bridge_strip_bom($converted);
  }

  /**
   * channel_bridge_normalize_text()
   * Нормализует текст для хранения/отправки:
   *  - приводит к UTF-8;
   *  - убирает нулевые байты;
   *  - триммит пробелы по краям.
   *
   * @param string $text
   * @return string
   */
  function channel_bridge_normalize_text(string $text): string
  {
    $text = channel_bridge_to_utf8($text);
    $text = str_replace("\0", '', $text);
    return trim($text);
  }

  /**
   * channel_bridge_norm_platform()
   * Нормализует код платформы.
   *
   * @param string $platform
   * @return string
   */
  function channel_bridge_norm_platform(string $platform): string
  {
    return strtolower(trim($platform));
  }

  /**
   * channel_bridge_norm_chat_id()
   * Нормализует идентификатор чата/канала.
   *
   * @param string $chatId
   * @return string
   */
  function channel_bridge_norm_chat_id(string $chatId): string
  {
    return trim(channel_bridge_to_utf8($chatId));
  }

  /**
   * channel_bridge_link_rule_normalize_domain_root()
   * Нормализует root-домен правила (без протокола и www).
   *
   * @param string $value
   * @return string
   */
  function channel_bridge_link_rule_normalize_domain_root(string $value): string
  {
    $value = trim(channel_bridge_to_utf8($value));
    if ($value === '') return '';

    $value = preg_replace('~\s+~u', '', $value);
    if (!is_string($value) || $value === '') return '';

    $value = strtolower($value);
    $value = preg_replace('~^[a-z][a-z0-9+\-.]*://~i', '', $value);
    if (!is_string($value)) $value = '';
    if ($value === '') return '';

    if (strpos($value, 'www.') === 0) {
      $value = (string)substr($value, 4);
    }

    $value = trim($value, ". \t\r\n/");
    return $value;
  }

  /**
   * channel_bridge_text_contains()
   * UTF-8-safe проверка наличия подстроки.
   *
   * @param string $haystack
   * @param string $needle
   * @return bool
   */
  function channel_bridge_text_contains(string $haystack, string $needle): bool
  {
    if ($haystack === '' || $needle === '') return false;
    if (function_exists('mb_stripos')) {
      return mb_stripos($haystack, $needle, 0, 'UTF-8') !== false;
    }
    return stripos($haystack, $needle) !== false;
  }

  /**
   * channel_bridge_extract_urls_from_text()
   * Извлекает URL из текста и нормализует их для отправки.
   *
   * @param string $text
   * @return array<int,string>
   */
  function channel_bridge_extract_urls_from_text(string $text): array
  {
    $text = channel_bridge_normalize_text($text);
    if ($text === '') return [];

    $m = [];
    preg_match_all('~(?:https?://|www\.)[^\s<>"\'`]+~iu', $text, $m);
    $rawUrls = isset($m[0]) && is_array($m[0]) ? (array)$m[0] : [];
    if (!$rawUrls) return [];

    $out = [];
    foreach ($rawUrls as $url) {
      $url = trim((string)$url);
      $url = rtrim($url, ".,:;!?)]}>\"'");
      if ($url === '') continue;

      if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . ltrim($url, '/');
      }
      $out[$url] = true;
    }

    return array_keys($out);
  }

  /**
   * channel_bridge_route_blacklist_domains_parse()
   * Разбирает чёрный список маршрута в нормализованный массив подстрок.
   *
   * @param string $value
   * @return array<int,string>
   */
  function channel_bridge_route_blacklist_domains_parse(string $value): array
  {
    $value = trim(channel_bridge_to_utf8($value));
    if ($value === '') return [];

    $parts = preg_split('~[\s,;]+~u', $value);
    if (!is_array($parts) || !$parts) return [];

    $out = [];
    foreach ($parts as $part) {
      $rule = trim(channel_bridge_to_utf8((string)$part));
      if ($rule === '') continue;
      if (function_exists('mb_strtolower')) {
        $rule = mb_strtolower($rule, 'UTF-8');
      } else {
        $rule = strtolower($rule);
      }
      $out[$rule] = true;
    }

    return array_keys($out);
  }

  /**
   * channel_bridge_route_blacklist_domains_normalize()
   * Нормализует чёрный список маршрута для хранения в БД.
   *
   * @param string $value
   * @return string
   */
  function channel_bridge_route_blacklist_domains_normalize(string $value): string
  {
    $domains = channel_bridge_route_blacklist_domains_parse($value);
    return $domains ? implode("\n", $domains) : '';
  }

  /**
   * channel_bridge_url_domain_normalize()
   * Извлекает и нормализует домен из URL.
   *
   * @param string $url
   * @return string
   */
  function channel_bridge_url_domain_normalize(string $url): string
  {
    $url = trim(channel_bridge_to_utf8($url));
    if ($url === '') return '';

    if (!preg_match('~^https?://~i', $url)) {
      $url = 'https://' . ltrim($url, '/');
    }

    $host = trim((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    if ($host === '') return '';

    return channel_bridge_link_rule_normalize_domain_root($host);
  }

  /**
   * channel_bridge_domain_matches_rule()
   * Проверяет совпадение домена URL с правилом чёрного списка.
   *
   * @param string $domain
   * @param string $ruleDomain
   * @return bool
   */
  function channel_bridge_domain_matches_rule(string $domain, string $ruleDomain): bool
  {
    $domain = channel_bridge_link_rule_normalize_domain_root($domain);
    $ruleDomain = channel_bridge_link_rule_normalize_domain_root($ruleDomain);
    if ($domain === '' || $ruleDomain === '') return false;
    if ($domain === $ruleDomain) return true;

    $suffix = '.' . $ruleDomain;
    $domainLen = strlen($domain);
    $suffixLen = strlen($suffix);
    if ($domainLen <= $suffixLen) return false;

    return substr($domain, -$suffixLen) === $suffix;
  }

  /**
   * channel_bridge_route_blacklist_match()
   * Проверяет, должен ли маршрут быть пропущен по подстрокам в ссылках поста.
   *
   * @param string $blacklistDomains
   * @param string $text
   * @param array<int,string> $extraUrls
   * @return array{blocked:bool,matched_rules:array<int,string>,post_urls:array<int,string>}
   */
  function channel_bridge_route_blacklist_match(string $blacklistDomains, string $text, array $extraUrls = []): array
  {
    $blockedRules = channel_bridge_route_blacklist_domains_parse($blacklistDomains);
    if (!$blockedRules) {
      return ['blocked' => false, 'matched_rules' => [], 'post_urls' => []];
    }

    $rawUrls = channel_bridge_extract_urls_from_text($text);
    foreach ($extraUrls as $url) {
      $url = trim((string)$url);
      if ($url === '') continue;
      $rawUrls[] = $url;
    }
    if (!$rawUrls) {
      return ['blocked' => false, 'matched_rules' => [], 'post_urls' => []];
    }

    $postUrlsMap = [];
    foreach ($rawUrls as $url) {
      $url = trim(channel_bridge_to_utf8((string)$url));
      if ($url === '') continue;
      $postUrlsMap[$url] = true;
    }
    if (!$postUrlsMap) {
      return ['blocked' => false, 'matched_rules' => [], 'post_urls' => []];
    }

    $matchedMap = [];
    foreach (array_keys($postUrlsMap) as $postUrl) {
      $postUrlLower = function_exists('mb_strtolower')
        ? mb_strtolower($postUrl, 'UTF-8')
        : strtolower($postUrl);

      foreach ($blockedRules as $rule) {
        $matched = function_exists('mb_strpos')
          ? (mb_strpos($postUrlLower, $rule, 0, 'UTF-8') !== false)
          : (strpos($postUrlLower, $rule) !== false);
        if (!$matched) continue;
        $matchedMap[$rule] = true;
      }
    }

    return [
      'blocked' => !empty($matchedMap),
      'matched_rules' => array_keys($matchedMap),
      'post_urls' => array_keys($postUrlsMap),
    ];
  }

  /**
   * channel_bridge_link_suffix_rules_list()
   * Возвращает список правил приписок для доменов.
   *
   * @param PDO $pdo
   * @param bool $onlyEnabled
   * @return array<int,array<string,mixed>>
   */
  function channel_bridge_link_suffix_rules_list(PDO $pdo, bool $onlyEnabled = false): array
  {
    channel_bridge_require_schema($pdo);

    $where = $onlyEnabled ? 'WHERE enabled = 1' : '';
    $st = $pdo->query("
      SELECT
        id,
        domain_root,
        suffix_text,
        enabled,
        sort
      FROM " . CHANNEL_BRIDGE_TABLE_LINK_SUFFIX_RULES . "
      $where
      ORDER BY sort ASC, id ASC
    ");
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!is_array($rows)) return [];

    $out = [];
    foreach ($rows as $row) {
      $domainRoot = channel_bridge_link_rule_normalize_domain_root((string)($row['domain_root'] ?? ''));
      $suffixText = channel_bridge_normalize_text((string)($row['suffix_text'] ?? 'Репост'));
      if ($suffixText === '') $suffixText = 'Репост';
      if ($domainRoot === '') continue;

      $out[] = [
        'id' => (int)($row['id'] ?? 0),
        'domain_root' => $domainRoot,
        'suffix_text' => $suffixText,
        'enabled' => ((int)($row['enabled'] ?? 0) === 1) ? 1 : 0,
        'sort' => (int)($row['sort'] ?? 100),
      ];
    }

    return $out;
  }

  /**
   * channel_bridge_link_suffix_rules_replace()
   * Полностью перезаписывает правила доменных приписок.
   *
   * @param PDO $pdo
   * @param array<int,array<string,mixed>> $rows
   * @return void
   */
  function channel_bridge_link_suffix_rules_replace(PDO $pdo, array $rows): void
  {
    channel_bridge_require_schema($pdo);

    $prepared = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;

      $domainRoot = channel_bridge_link_rule_normalize_domain_root((string)($row['domain_root'] ?? ''));
      $suffixText = channel_bridge_normalize_text((string)($row['suffix_text'] ?? 'Репост'));
      if ($suffixText === '') $suffixText = 'Репост';
      $enabled = ((int)($row['enabled'] ?? 0) === 1) ? 1 : 0;
      $sort = (int)($row['sort'] ?? 100);
      if ($sort < -2147483648) $sort = -2147483648;
      if ($sort > 2147483647) $sort = 2147483647;

      if ($domainRoot === '') continue;

      $prepared[$domainRoot] = [
        'domain_root' => $domainRoot,
        'suffix_text' => $suffixText,
        'enabled' => $enabled,
        'sort' => $sort,
      ];
    }

    $pdo->beginTransaction();
    try {
      $pdo->exec("DELETE FROM " . CHANNEL_BRIDGE_TABLE_LINK_SUFFIX_RULES);

      if ($prepared) {
        $st = $pdo->prepare("
          INSERT INTO " . CHANNEL_BRIDGE_TABLE_LINK_SUFFIX_RULES . "
          (
            domain_root,
            suffix_text,
            enabled,
            sort
          )
          VALUES
          (
            :domain_root,
            :suffix_text,
            :enabled,
            :sort
          )
        ");

        foreach ($prepared as $row) {
          $st->execute([
            ':domain_root' => (string)$row['domain_root'],
            ':suffix_text' => (string)$row['suffix_text'],
            ':enabled' => (int)$row['enabled'],
            ':sort' => (int)$row['sort'],
          ]);
        }
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }

  /**
   * channel_bridge_apply_link_suffix_rules()
   * Добавляет приписку "Репост: <tg_post_url>" по найденным триггерам.
   *
   * @param string $text
   * @param array<int,array<string,mixed>> $rules
   * @param string $repostUrl
   * @return array{text:string,matched_domains:array<int,string>,appended_suffixes:array<int,string>}
   */
  function channel_bridge_apply_link_suffix_rules(string $text, array $rules, string $repostUrl = ''): array
  {
    $text = channel_bridge_normalize_text($text);
    $repostUrl = trim($repostUrl);
    if ($text === '' || !$rules || $repostUrl === '') {
      return ['text' => $text, 'matched_domains' => [], 'appended_suffixes' => []];
    }
    $searchHaystack = function_exists('mb_strtolower')
      ? mb_strtolower($text, 'UTF-8')
      : strtolower($text);

    $matchedDomains = [];
    $suffixes = [];

    foreach ($rules as $rule) {
      if (!is_array($rule)) continue;

      $enabled = ((int)($rule['enabled'] ?? 0) === 1);
      if (!$enabled) continue;

      $domainRootRaw = (string)($rule['domain_root'] ?? '');
      $domainRoot = channel_bridge_link_rule_normalize_domain_root($domainRootRaw);
      if ($domainRoot === '') continue;

      $searchNeedle = function_exists('mb_strtolower')
        ? mb_strtolower($domainRoot, 'UTF-8')
        : strtolower($domainRoot);
      if (!channel_bridge_text_contains($searchHaystack, $searchNeedle)) continue;

      $matchedDomains[$domainRoot] = true;
      $suffixes['Репост: ' . $repostUrl] = true;
    }

    if (!$suffixes) {
      return ['text' => $text, 'matched_domains' => [], 'appended_suffixes' => []];
    }

    $out = $text;
    $appended = [];
    foreach (array_keys($suffixes) as $suffix) {
      if ($out !== '') {
        $hasSuffix = function_exists('mb_stripos')
          ? (mb_stripos($out, $suffix, 0, 'UTF-8') !== false)
          : (stripos($out, $suffix) !== false);
        if ($hasSuffix) continue;
      }
      $out = ($out === '') ? $suffix : ($out . "\n\n" . $suffix);
      $appended[] = $suffix;
    }

    return [
      'text' => $out,
      'matched_domains' => array_keys($matchedDomains),
      'appended_suffixes' => $appended,
    ];
  }

  /**
   * channel_bridge_settings_defaults()
   * Возвращает дефолтные настройки модуля.
   *
   * @return array<string,mixed>
   */
  function channel_bridge_settings_defaults(): array
  {
    return [
      'enabled' => 0,

      'tg_enabled' => 1,
      'tg_bot_token' => '',
      'tg_webhook_secret' => '',
      'tg_parse_mode' => 'HTML',

      'vk_enabled' => 0,
      'vk_group_token' => '',
      'vk_owner_id' => '',
      'vk_api_version' => '5.199',

      'max_enabled' => 0,
      'max_api_key' => '',
      'max_base_url' => 'https://platform-api.max.ru',
      'max_send_path' => '/messages',
    ];
  }

  /**
   * channel_bridge_schema_db_name()
   * Возвращает имя текущей БД.
   *
   * @param PDO $pdo
   * @return string
   */
  function channel_bridge_schema_db_name(PDO $pdo): string
  {
    $cfg = function_exists('app_config') ? (array)app_config() : [];
    $dbCfg = (array)($cfg['db'] ?? []);
    $dbName = trim((string)($dbCfg['name'] ?? ''));
    if ($dbName !== '') return $dbName;

    $st = $pdo->query('SELECT DATABASE()');
    $name = $st ? trim((string)$st->fetchColumn()) : '';
    return $name;
  }

  /**
   * channel_bridge_schema_missing_tables()
   * Возвращает список отсутствующих таблиц модуля.
   *
   * @param PDO $pdo
   * @return array<int,string>
   */
  function channel_bridge_schema_missing_tables(PDO $pdo): array
  {
    $dbName = channel_bridge_schema_db_name($pdo);
    if ($dbName === '') {
      return CHANNEL_BRIDGE_REQUIRED_TABLES;
    }

    $tables = array_values(array_unique(array_filter(CHANNEL_BRIDGE_REQUIRED_TABLES, static function ($name) {
      return trim((string)$name) !== '';
    })));
    if (!$tables) {
      return [];
    }

    $in = implode(',', array_fill(0, count($tables), '?'));
    $params = array_merge([$dbName], $tables);

    $st = $pdo->prepare("
      SELECT TABLE_NAME
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = ?
        AND TABLE_NAME IN ($in)
    ");
    $st->execute($params);

    $exists = $st->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!is_array($exists)) $exists = [];
    $existsMap = array_fill_keys(array_map('strval', $exists), true);

    $missing = [];
    foreach ($tables as $table) {
      if (!isset($existsMap[$table])) {
        $missing[] = $table;
      }
    }

    return $missing;
  }

  /**
   * channel_bridge_schema_table_exists()
   * Проверяет наличие конкретной таблицы в текущей БД.
   *
   * @param PDO $pdo
   * @param string $tableName
   * @return bool
   */
  function channel_bridge_schema_table_exists(PDO $pdo, string $tableName): bool
  {
    static $cache = [];

    $tableName = trim($tableName);
    if ($tableName === '') {
      return false;
    }
    if (array_key_exists($tableName, $cache)) {
      return (bool)$cache[$tableName];
    }

    $dbName = channel_bridge_schema_db_name($pdo);
    if ($dbName === '') {
      $cache[$tableName] = false;
      return false;
    }

    $st = $pdo->prepare("
      SELECT 1
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = :db_name
        AND TABLE_NAME = :table_name
      LIMIT 1
    ");
    $st->execute([
      ':db_name' => $dbName,
      ':table_name' => $tableName,
    ]);

    $cache[$tableName] = ($st->fetchColumn() !== false);
    return (bool)$cache[$tableName];
  }

  /**
   * channel_bridge_schema_column_exists()
   * Проверяет наличие колонки в конкретной таблице текущей БД.
   *
   * @param PDO $pdo
   * @param string $tableName
   * @param string $columnName
   * @return bool
   */
  function channel_bridge_schema_column_exists(PDO $pdo, string $tableName, string $columnName): bool
  {
    static $cache = [];

    $tableName = trim($tableName);
    $columnName = trim($columnName);
    if ($tableName === '' || $columnName === '') {
      return false;
    }

    $cacheKey = $tableName . '::' . $columnName;
    if (array_key_exists($cacheKey, $cache)) {
      return (bool)$cache[$cacheKey];
    }

    $dbName = channel_bridge_schema_db_name($pdo);
    if ($dbName === '') {
      $cache[$cacheKey] = false;
      return false;
    }

    $st = $pdo->prepare("
      SELECT 1
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = :db_name
        AND TABLE_NAME = :table_name
        AND COLUMN_NAME = :column_name
      LIMIT 1
    ");
    $st->execute([
      ':db_name' => $dbName,
      ':table_name' => $tableName,
      ':column_name' => $columnName,
    ]);

    $cache[$cacheKey] = ($st->fetchColumn() !== false);
    return (bool)$cache[$cacheKey];
  }

  /**
   * channel_bridge_require_schema()
   * Проверяет наличие таблиц модуля.
   * Если таблиц нет, бросает исключение с деталями.
   *
   * @param PDO $pdo
   * @return void
   */
  function channel_bridge_require_schema(PDO $pdo): void
  {
    /**
     * $checked — флаг однократной проверки в рамках запроса.
     */
    static $checked = false;

    if ($checked) return;

    $missing = channel_bridge_schema_missing_tables($pdo);
    if ($missing) {
      throw new RuntimeException(
        'Не применён SQL модуля channel_bridge. Отсутствуют таблицы: ' . implode(', ', $missing)
      );
    }

    $checked = true;
  }

  /**
   * channel_bridge_settings_get()
   * Возвращает текущие настройки модуля.
   *
   * @param PDO $pdo
   * @return array<string,mixed>
   */
  function channel_bridge_settings_get(PDO $pdo): array
  {
    channel_bridge_require_schema($pdo);

    $defaults = channel_bridge_settings_defaults();

    $st = $pdo->query("
      SELECT
        enabled,
        tg_enabled,
        tg_bot_token,
        tg_webhook_secret,
        tg_parse_mode,
        vk_enabled,
        vk_group_token,
        vk_owner_id,
        vk_api_version,
        max_enabled,
        max_api_key,
        max_base_url,
        max_send_path
      FROM " . CHANNEL_BRIDGE_TABLE_SETTINGS . "
      WHERE id = 1
      LIMIT 1
    ");
    $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
    if (!is_array($row)) {
      return $defaults;
    }

    $settings = [
      'enabled' => ((int)($row['enabled'] ?? 0) === 1) ? 1 : 0,

      'tg_enabled' => ((int)($row['tg_enabled'] ?? 0) === 1) ? 1 : 0,
      'tg_bot_token' => trim((string)($row['tg_bot_token'] ?? '')),
      'tg_webhook_secret' => trim((string)($row['tg_webhook_secret'] ?? '')),
      'tg_parse_mode' => trim((string)($row['tg_parse_mode'] ?? 'HTML')),

      'vk_enabled' => ((int)($row['vk_enabled'] ?? 0) === 1) ? 1 : 0,
      'vk_group_token' => trim((string)($row['vk_group_token'] ?? '')),
      'vk_owner_id' => trim((string)($row['vk_owner_id'] ?? '')),
      'vk_api_version' => trim((string)($row['vk_api_version'] ?? '5.199')),

      'max_enabled' => ((int)($row['max_enabled'] ?? 0) === 1) ? 1 : 0,
      'max_api_key' => trim((string)($row['max_api_key'] ?? '')),
      'max_base_url' => rtrim(trim((string)($row['max_base_url'] ?? 'https://platform-api.max.ru')), '/'),
      'max_send_path' => trim((string)($row['max_send_path'] ?? '/messages')),
    ];

    if (!in_array($settings['tg_parse_mode'], ['HTML', 'Markdown', 'MarkdownV2', ''], true)) {
      $settings['tg_parse_mode'] = 'HTML';
    }
    if ($settings['vk_api_version'] === '') {
      $settings['vk_api_version'] = '5.199';
    }
    // Совместимость со старыми настройками: botapi.max.ru -> platform-api.max.ru.
    if ($settings['max_base_url'] === 'https://botapi.max.ru') {
      $settings['max_base_url'] = 'https://platform-api.max.ru';
    }
    if ($settings['max_base_url'] === '') {
      $settings['max_base_url'] = 'https://platform-api.max.ru';
    }
    if ($settings['max_send_path'] === '') {
      $settings['max_send_path'] = '/messages';
    }

    return $settings;
  }

  /**
   * channel_bridge_settings_save()
   * Сохраняет настройки модуля и возвращает сохранённое состояние.
   *
   * @param PDO $pdo
   * @param array<string,mixed> $input
   * @return array<string,mixed>
   */
  function channel_bridge_settings_save(PDO $pdo, array $input): array
  {
    channel_bridge_require_schema($pdo);

    $enabled = ((int)($input['enabled'] ?? 0) === 1) ? 1 : 0;

    $tgEnabled = ((int)($input['tg_enabled'] ?? 0) === 1) ? 1 : 0;
    $tgBotToken = trim((string)($input['tg_bot_token'] ?? ''));
    $tgWebhookSecret = trim((string)($input['tg_webhook_secret'] ?? ''));
    $tgParseMode = trim((string)($input['tg_parse_mode'] ?? 'HTML'));
    if (!in_array($tgParseMode, ['HTML', 'Markdown', 'MarkdownV2', ''], true)) {
      $tgParseMode = 'HTML';
    }

    $vkEnabled = ((int)($input['vk_enabled'] ?? 0) === 1) ? 1 : 0;
    $vkGroupToken = trim((string)($input['vk_group_token'] ?? ''));
    $vkOwnerId = trim((string)($input['vk_owner_id'] ?? ''));
    $vkApiVersion = trim((string)($input['vk_api_version'] ?? '5.199'));
    if ($vkApiVersion === '') $vkApiVersion = '5.199';

    $maxEnabled = ((int)($input['max_enabled'] ?? 0) === 1) ? 1 : 0;
    $maxApiKey = trim((string)($input['max_api_key'] ?? ''));
    $maxBaseUrl = rtrim(trim((string)($input['max_base_url'] ?? 'https://platform-api.max.ru')), '/');
    $maxSendPath = trim((string)($input['max_send_path'] ?? '/messages'));
    if ($maxBaseUrl === '') $maxBaseUrl = 'https://platform-api.max.ru';
    if ($maxBaseUrl === 'https://botapi.max.ru') $maxBaseUrl = 'https://platform-api.max.ru';
    if ($maxSendPath === '') $maxSendPath = '/messages';

    $pdo->prepare("
      INSERT INTO " . CHANNEL_BRIDGE_TABLE_SETTINGS . "
      (
        id,
        enabled,
        tg_enabled,
        tg_bot_token,
        tg_webhook_secret,
        tg_parse_mode,
        vk_enabled,
        vk_group_token,
        vk_owner_id,
        vk_api_version,
        max_enabled,
        max_api_key,
        max_base_url,
        max_send_path
      )
      VALUES
      (
        1,
        :enabled,
        :tg_enabled,
        :tg_bot_token,
        :tg_webhook_secret,
        :tg_parse_mode,
        :vk_enabled,
        :vk_group_token,
        :vk_owner_id,
        :vk_api_version,
        :max_enabled,
        :max_api_key,
        :max_base_url,
        :max_send_path
      )
      ON DUPLICATE KEY UPDATE
        enabled = VALUES(enabled),
        tg_enabled = VALUES(tg_enabled),
        tg_bot_token = VALUES(tg_bot_token),
        tg_webhook_secret = VALUES(tg_webhook_secret),
        tg_parse_mode = VALUES(tg_parse_mode),
        vk_enabled = VALUES(vk_enabled),
        vk_group_token = VALUES(vk_group_token),
        vk_owner_id = VALUES(vk_owner_id),
        vk_api_version = VALUES(vk_api_version),
        max_enabled = VALUES(max_enabled),
        max_api_key = VALUES(max_api_key),
        max_base_url = VALUES(max_base_url),
        max_send_path = VALUES(max_send_path)
    ")->execute([
      ':enabled' => $enabled,
      ':tg_enabled' => $tgEnabled,
      ':tg_bot_token' => $tgBotToken,
      ':tg_webhook_secret' => $tgWebhookSecret,
      ':tg_parse_mode' => $tgParseMode,
      ':vk_enabled' => $vkEnabled,
      ':vk_group_token' => $vkGroupToken,
      ':vk_owner_id' => $vkOwnerId,
      ':vk_api_version' => $vkApiVersion,
      ':max_enabled' => $maxEnabled,
      ':max_api_key' => $maxApiKey,
      ':max_base_url' => $maxBaseUrl,
      ':max_send_path' => $maxSendPath,
    ]);

    $linkSuffixRules = [];
    if (isset($input['link_suffix_rules']) && is_array($input['link_suffix_rules'])) {
      $linkSuffixRules = (array)$input['link_suffix_rules'];
    }
    channel_bridge_link_suffix_rules_replace($pdo, $linkSuffixRules);

    return channel_bridge_settings_get($pdo);
  }

  /**
   * channel_bridge_routes_list()
   * Возвращает список маршрутов с последним статусом отправки.
   *
   * @param PDO $pdo
   * @return array<int,array<string,mixed>>
   */
  function channel_bridge_routes_list(PDO $pdo): array
  {
    channel_bridge_require_schema($pdo);

    $routesHasBlacklistDomains = channel_bridge_schema_column_exists($pdo, CHANNEL_BRIDGE_TABLE_ROUTES, 'blacklist_domains');
    $blacklistSelect = $routesHasBlacklistDomains
      ? "r.blacklist_domains,"
      : "'' AS blacklist_domains,";

    $sql = "
      SELECT
        r.id,
        r.title,
        r.source_platform,
        r.source_chat_id,
        r.target_platform,
        r.target_chat_id,
        r.target_extra,
        $blacklistSelect
        r.enabled,
        r.created_at,
        r.updated_at,
        dl.send_status AS last_status,
        dl.created_at AS last_sent_at
      FROM " . CHANNEL_BRIDGE_TABLE_ROUTES . " r
      LEFT JOIN " . CHANNEL_BRIDGE_TABLE_DISPATCH_LOG . " dl
        ON dl.id = (
          SELECT d2.id
          FROM " . CHANNEL_BRIDGE_TABLE_DISPATCH_LOG . " d2
          WHERE d2.route_id = r.id
          ORDER BY d2.id DESC
          LIMIT 1
        )
      ORDER BY r.id DESC
    ";

    $st = $pdo->query($sql);
    $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!is_array($rows)) return [];

    return $rows;
  }

  /**
   * channel_bridge_seen_tg_chats()
   * Возвращает список TG chat_id, которые бот уже видел во входящем потоке.
   *
   * @param PDO $pdo
   * @param int $limit
   * @return array<int,array<string,mixed>>
   */
  function channel_bridge_seen_tg_chats(PDO $pdo, int $limit = 200): array
  {
    channel_bridge_require_schema($pdo);

    if ($limit < 1) $limit = 200;
    if ($limit > 1000) $limit = 1000;

    $sql = "
      SELECT
        i.source_chat_id AS chat_id,
        COUNT(*) AS events_count,
        MAX(i.created_at) AS last_seen_at
      FROM " . CHANNEL_BRIDGE_TABLE_INBOX . " i
      WHERE i.source_platform = :source_platform
        AND i.source_chat_id <> ''
      GROUP BY i.source_chat_id
      ORDER BY last_seen_at DESC
      LIMIT :lim
    ";

    $st = $pdo->prepare($sql);
    $st->bindValue(':source_platform', CHANNEL_BRIDGE_SOURCE_TG, PDO::PARAM_STR);
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows) || !$rows) return [];

    $map = [];
    foreach ($rows as $row) {
      $chatId = channel_bridge_norm_chat_id((string)($row['chat_id'] ?? ''));
      if ($chatId === '') continue;
      $map[$chatId] = [
        'chat_id' => $chatId,
        'events_count' => (int)($row['events_count'] ?? 0),
        'last_seen_at' => trim((string)($row['last_seen_at'] ?? '')),
        'used_as_source' => 0,
        'used_as_target' => 0,
      ];
    }
    if (!$map) return [];

    $routesSt = $pdo->query("
      SELECT source_platform, source_chat_id, target_platform, target_chat_id
      FROM " . CHANNEL_BRIDGE_TABLE_ROUTES . "
    ");
    $routeRows = $routesSt ? $routesSt->fetchAll(PDO::FETCH_ASSOC) : [];
    if (is_array($routeRows) && $routeRows) {
      foreach ($routeRows as $r) {
        $sp = channel_bridge_norm_platform((string)($r['source_platform'] ?? ''));
        $tp = channel_bridge_norm_platform((string)($r['target_platform'] ?? ''));
        $sc = channel_bridge_norm_chat_id((string)($r['source_chat_id'] ?? ''));
        $tc = channel_bridge_norm_chat_id((string)($r['target_chat_id'] ?? ''));

        if ($sp === CHANNEL_BRIDGE_SOURCE_TG && $sc !== '' && isset($map[$sc])) {
          $map[$sc]['used_as_source'] = 1;
        }
        if ($tp === CHANNEL_BRIDGE_TARGET_TG && $tc !== '' && isset($map[$tc])) {
          $map[$tc]['used_as_target'] = 1;
        }
      }
    }

    return array_values($map);
  }

  /**
   * channel_bridge_route_find()
   * Возвращает один маршрут по ID.
   *
   * @param PDO $pdo
   * @param int $id
   * @return array<string,mixed>|null
   */
  function channel_bridge_route_find(PDO $pdo, int $id): ?array
  {
    channel_bridge_require_schema($pdo);

    if ($id <= 0) return null;

    $routesHasBlacklistDomains = channel_bridge_schema_column_exists($pdo, CHANNEL_BRIDGE_TABLE_ROUTES, 'blacklist_domains');
    $blacklistSelect = $routesHasBlacklistDomains
      ? "blacklist_domains,"
      : "'' AS blacklist_domains,";

    $st = $pdo->prepare("
      SELECT
        id,
        title,
        source_platform,
        source_chat_id,
        target_platform,
        target_chat_id,
        target_extra,
        $blacklistSelect
        enabled,
        created_at,
        updated_at
      FROM " . CHANNEL_BRIDGE_TABLE_ROUTES . "
      WHERE id = :id
      LIMIT 1
    ");
    $st->execute([':id' => $id]);

    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
  }

  /**
   * channel_bridge_generate_bind_code()
   * Генерирует одноразовый код автопривязки для стороны маршрута.
   *
   * @param PDO $pdo
   * @param int $routeId
   * @param string $side
   * @param int $createdBy
   * @return string
   */
  function channel_bridge_generate_bind_code(PDO $pdo, int $routeId, string $side, int $createdBy = 0): string
  {
    channel_bridge_require_schema($pdo);

    if ($routeId <= 0) {
      throw new RuntimeException(channel_bridge_t('channel_bridge.error_bad_id'));
    }

    $side = strtolower(trim($side));
    if (!in_array($side, channel_bridge_bind_supported_sides(), true)) {
      throw new RuntimeException(channel_bridge_t('channel_bridge.error_bind_bad_side'));
    }

    $route = channel_bridge_route_find($pdo, $routeId);
    if (!$route) {
      throw new RuntimeException(channel_bridge_t('channel_bridge.error_route_not_found'));
    }

    if (
      $side === CHANNEL_BRIDGE_BIND_SIDE_TARGET
      && channel_bridge_norm_platform((string)($route['target_platform'] ?? '')) !== CHANNEL_BRIDGE_TARGET_TG
    ) {
      throw new RuntimeException(channel_bridge_t('channel_bridge.error_bind_target_not_tg'));
    }

    $ttlMinutes = (int)CHANNEL_BRIDGE_BIND_CODE_TTL_MINUTES;
    if ($ttlMinutes < 1) $ttlMinutes = 30;
    if ($ttlMinutes > 1440) $ttlMinutes = 1440;

    $now = channel_bridge_now();
    $expiresAt = date('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

    $rawCode = '';
    $hash = '';
    $attempt = 0;
    while ($attempt < 50) {
      $candidate = channel_bridge_generate_raw_bind_code();
      $candidateHash = hash('sha256', $candidate);

      $stCheck = $pdo->prepare("
        SELECT id
        FROM " . CHANNEL_BRIDGE_TABLE_BIND_TOKENS . "
        WHERE token_hash = :token_hash
          AND used_at IS NULL
          AND expires_at >= :now
        LIMIT 1
      ");
      $stCheck->execute([
        ':token_hash' => $candidateHash,
        ':now' => $now,
      ]);

      if (!(int)$stCheck->fetchColumn()) {
        $rawCode = $candidate;
        $hash = $candidateHash;
        break;
      }
      $attempt++;
    }

    if ($rawCode === '' || $hash === '') {
      throw new RuntimeException(channel_bridge_t('channel_bridge.error_bind_code_generate'));
    }

    $pdo->prepare("
      UPDATE " . CHANNEL_BRIDGE_TABLE_BIND_TOKENS . "
      SET used_at = :used_at
      WHERE route_id = :route_id
        AND bind_side = :bind_side
        AND used_at IS NULL
    ")->execute([
      ':used_at' => $now,
      ':route_id' => $routeId,
      ':bind_side' => $side,
    ]);

    $pdo->prepare("
      INSERT INTO " . CHANNEL_BRIDGE_TABLE_BIND_TOKENS . "
      (
        route_id,
        bind_side,
        token_hash,
        expires_at,
        created_by
      )
      VALUES
      (
        :route_id,
        :bind_side,
        :token_hash,
        :expires_at,
        :created_by
      )
    ")->execute([
      ':route_id' => $routeId,
      ':bind_side' => $side,
      ':token_hash' => $hash,
      ':expires_at' => $expiresAt,
      ':created_by' => max(0, (int)$createdBy),
    ]);

    return $rawCode;
  }

  /**
   * channel_bridge_bind_route_by_code()
   * Привязывает chat_id из Telegram update к стороне маршрута по одноразовому коду.
   *
   * @param PDO $pdo
   * @param string $rawCode
   * @param array<string,mixed> $chatMeta
   * @return array<string,mixed>
   */
  function channel_bridge_bind_route_by_code(PDO $pdo, string $rawCode, array $chatMeta): array
  {
    channel_bridge_require_schema($pdo);

    $rawCode = trim($rawCode);
    if ($rawCode === '') {
      return ['ok' => false, 'reason' => 'code_empty', 'message' => channel_bridge_t('channel_bridge.error_bind_code_invalid')];
    }

    $chatId = channel_bridge_norm_chat_id((string)($chatMeta['chat_id'] ?? ''));
    if ($chatId === '') {
      return ['ok' => false, 'reason' => 'chat_missing', 'message' => channel_bridge_t('channel_bridge.error_bind_chat_missing')];
    }

    $hash = hash('sha256', $rawCode);
    $now = channel_bridge_now();

    $st = $pdo->prepare("
      SELECT id, route_id, bind_side
      FROM " . CHANNEL_BRIDGE_TABLE_BIND_TOKENS . "
      WHERE token_hash = :token_hash
        AND used_at IS NULL
        AND expires_at >= :now
      ORDER BY id DESC
      LIMIT 1
    ");
    $st->execute([
      ':token_hash' => $hash,
      ':now' => $now,
    ]);
    $tokenRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($tokenRow)) {
      return ['ok' => false, 'reason' => 'code_invalid', 'message' => channel_bridge_t('channel_bridge.error_bind_code_invalid')];
    }

    $tokenId = (int)($tokenRow['id'] ?? 0);
    $routeId = (int)($tokenRow['route_id'] ?? 0);
    $side = strtolower(trim((string)($tokenRow['bind_side'] ?? '')));
    if ($tokenId <= 0 || $routeId <= 0 || !in_array($side, channel_bridge_bind_supported_sides(), true)) {
      return ['ok' => false, 'reason' => 'code_invalid', 'message' => channel_bridge_t('channel_bridge.error_bind_code_invalid')];
    }

    $route = channel_bridge_route_find($pdo, $routeId);
    if (!$route) {
      return ['ok' => false, 'reason' => 'route_not_found', 'message' => channel_bridge_t('channel_bridge.error_route_not_found')];
    }

    if (
      $side === CHANNEL_BRIDGE_BIND_SIDE_TARGET
      && channel_bridge_norm_platform((string)($route['target_platform'] ?? '')) !== CHANNEL_BRIDGE_TARGET_TG
    ) {
      return ['ok' => false, 'reason' => 'target_not_tg', 'message' => channel_bridge_t('channel_bridge.error_bind_target_not_tg')];
    }

    $chatType = trim((string)($chatMeta['chat_type'] ?? ''));
    $routeTitle = (string)($route['title'] ?? ('#' . $routeId));

    try {
      $pdo->beginTransaction();

      $pdo->prepare("
        UPDATE " . CHANNEL_BRIDGE_TABLE_BIND_TOKENS . "
        SET
          used_at = :used_at,
          used_chat_id = :used_chat_id,
          used_chat_type = :used_chat_type
        WHERE id = :id
          AND used_at IS NULL
        LIMIT 1
      ")->execute([
        ':used_at' => $now,
        ':used_chat_id' => $chatId,
        ':used_chat_type' => $chatType,
        ':id' => $tokenId,
      ]);

      if ($side === CHANNEL_BRIDGE_BIND_SIDE_SOURCE) {
        $pdo->prepare("
          UPDATE " . CHANNEL_BRIDGE_TABLE_ROUTES . "
          SET source_chat_id = :chat_id
          WHERE id = :id
          LIMIT 1
        ")->execute([
          ':chat_id' => $chatId,
          ':id' => $routeId,
        ]);
      } else {
        $pdo->prepare("
          UPDATE " . CHANNEL_BRIDGE_TABLE_ROUTES . "
          SET target_chat_id = :chat_id
          WHERE id = :id
          LIMIT 1
        ")->execute([
          ':chat_id' => $chatId,
          ':id' => $routeId,
        ]);
      }

      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();

      $code = trim((string)$e->getCode());
      if ($code === '23000') {
        return [
          'ok' => false,
          'reason' => 'route_conflict',
          'message' => channel_bridge_t('channel_bridge.error_bind_route_conflict'),
          'error' => $e->getMessage(),
        ];
      }

      return [
        'ok' => false,
        'reason' => 'db_error',
        'message' => channel_bridge_t('channel_bridge.error_bind_save', ['error' => $e->getMessage()]),
        'error' => $e->getMessage(),
      ];
    }

    return [
      'ok' => true,
      'reason' => 'bound',
      'route_id' => $routeId,
      'route_title' => $routeTitle,
      'side' => $side,
      'chat_id' => $chatId,
    ];
  }

  /**
   * channel_bridge_route_validate()
   * Валидирует входные данные маршрута.
   *
   * @param array<string,mixed> $input
   * @return array{ok:bool,error:string,data:array<string,mixed>}
   */
  function channel_bridge_route_validate(array $input): array
  {
    $title = trim((string)($input['title'] ?? ''));
    $sourcePlatform = channel_bridge_norm_platform((string)($input['source_platform'] ?? ''));
    $sourceChatId = channel_bridge_norm_chat_id((string)($input['source_chat_id'] ?? ''));
    $targetPlatform = channel_bridge_norm_platform((string)($input['target_platform'] ?? ''));
    $targetChatId = channel_bridge_norm_chat_id((string)($input['target_chat_id'] ?? ''));
    $targetExtra = trim(channel_bridge_to_utf8((string)($input['target_extra'] ?? '')));
    $blacklistDomains = channel_bridge_route_blacklist_domains_normalize((string)($input['blacklist_domains'] ?? ''));
    $enabled = ((int)($input['enabled'] ?? 0) === 1) ? 1 : 0;

    if ($sourcePlatform === '' || !in_array($sourcePlatform, channel_bridge_supported_sources(), true)) {
      return ['ok' => false, 'error' => channel_bridge_t('channel_bridge.error_bad_source_platform'), 'data' => []];
    }
    if ($targetPlatform === '' || !in_array($targetPlatform, channel_bridge_supported_targets(), true)) {
      return ['ok' => false, 'error' => channel_bridge_t('channel_bridge.error_bad_target_platform'), 'data' => []];
    }
    if ($sourceChatId === '') {
      return ['ok' => false, 'error' => channel_bridge_t('channel_bridge.error_source_chat_required'), 'data' => []];
    }
    if ($targetChatId === '') {
      return ['ok' => false, 'error' => channel_bridge_t('channel_bridge.error_target_chat_required'), 'data' => []];
    }

    if ($title === '') {
      $title = $sourcePlatform . ':' . $sourceChatId . ' -> ' . $targetPlatform . ':' . $targetChatId;
    }

    return [
      'ok' => true,
      'error' => '',
      'data' => [
        'title' => $title,
        'source_platform' => $sourcePlatform,
        'source_chat_id' => $sourceChatId,
        'target_platform' => $targetPlatform,
        'target_chat_id' => $targetChatId,
        'target_extra' => $targetExtra,
        'blacklist_domains' => $blacklistDomains,
        'enabled' => $enabled,
      ],
    ];
  }

  /**
   * channel_bridge_route_add()
   * Добавляет новый маршрут.
   *
   * @param PDO $pdo
   * @param array<string,mixed> $input
   * @return int
   */
  function channel_bridge_route_add(PDO $pdo, array $input): int
  {
    channel_bridge_require_schema($pdo);

    $autoBindSource = ((int)($input['auto_bind_source'] ?? 0) === 1);
    $autoBindTarget = ((int)($input['auto_bind_target'] ?? 0) === 1);

    if ($autoBindSource) {
      $input['source_chat_id'] = channel_bridge_make_pending_chat_id(CHANNEL_BRIDGE_BIND_SIDE_SOURCE);
    }
    if ($autoBindTarget) {
      $targetPlatform = channel_bridge_norm_platform((string)($input['target_platform'] ?? ''));
      if ($targetPlatform !== CHANNEL_BRIDGE_TARGET_TG) {
        throw new RuntimeException(channel_bridge_t('channel_bridge.error_bind_target_not_tg'));
      }
      $input['target_chat_id'] = channel_bridge_make_pending_chat_id(CHANNEL_BRIDGE_BIND_SIDE_TARGET);
    }

    $valid = channel_bridge_route_validate($input);
    if (($valid['ok'] ?? false) !== true) {
      throw new RuntimeException((string)($valid['error'] ?? channel_bridge_t('channel_bridge.error_validation')));
    }

    $data = (array)$valid['data'];
    $routesHasBlacklistDomains = channel_bridge_schema_column_exists($pdo, CHANNEL_BRIDGE_TABLE_ROUTES, 'blacklist_domains');
    if (!$routesHasBlacklistDomains && trim((string)($data['blacklist_domains'] ?? '')) !== '') {
      throw new RuntimeException(channel_bridge_t('channel_bridge.error_route_blacklist_schema_missing'));
    }

    $fields = [
      'title',
      'source_platform',
      'source_chat_id',
      'target_platform',
      'target_chat_id',
      'target_extra',
    ];
    $placeholders = [
      ':title',
      ':source_platform',
      ':source_chat_id',
      ':target_platform',
      ':target_chat_id',
      ':target_extra',
    ];
    if ($routesHasBlacklistDomains) {
      $fields[] = 'blacklist_domains';
      $placeholders[] = ':blacklist_domains';
    }
    $fields[] = 'enabled';
    $placeholders[] = ':enabled';

    $st = $pdo->prepare("
      INSERT INTO " . CHANNEL_BRIDGE_TABLE_ROUTES . "
      (
        " . implode(",\n        ", $fields) . "
      )
      VALUES
      (
        " . implode(",\n        ", $placeholders) . "
      )
    ");
    $params = [
      ':title' => (string)$data['title'],
      ':source_platform' => (string)$data['source_platform'],
      ':source_chat_id' => (string)$data['source_chat_id'],
      ':target_platform' => (string)$data['target_platform'],
      ':target_chat_id' => (string)$data['target_chat_id'],
      ':target_extra' => (string)$data['target_extra'],
      ':enabled' => (int)$data['enabled'],
    ];
    if ($routesHasBlacklistDomains) {
      $params[':blacklist_domains'] = (string)($data['blacklist_domains'] ?? '');
    }
    $st->execute($params);

    return (int)$pdo->lastInsertId();
  }

  /**
   * channel_bridge_route_update()
   * Обновляет существующий маршрут.
   *
   * @param PDO $pdo
   * @param int $id
   * @param array<string,mixed> $input
   * @return bool
   */
  function channel_bridge_route_update(PDO $pdo, int $id, array $input): bool
  {
    channel_bridge_require_schema($pdo);

    if ($id <= 0) {
      throw new RuntimeException(channel_bridge_t('channel_bridge.error_bad_id'));
    }

    $valid = channel_bridge_route_validate($input);
    if (($valid['ok'] ?? false) !== true) {
      throw new RuntimeException((string)($valid['error'] ?? channel_bridge_t('channel_bridge.error_validation')));
    }
    $data = (array)$valid['data'];
    $routesHasBlacklistDomains = channel_bridge_schema_column_exists($pdo, CHANNEL_BRIDGE_TABLE_ROUTES, 'blacklist_domains');
    if (!$routesHasBlacklistDomains && trim((string)($data['blacklist_domains'] ?? '')) !== '') {
      throw new RuntimeException(channel_bridge_t('channel_bridge.error_route_blacklist_schema_missing'));
    }

    $set = [
      "title = :title",
      "source_platform = :source_platform",
      "source_chat_id = :source_chat_id",
      "target_platform = :target_platform",
      "target_chat_id = :target_chat_id",
      "target_extra = :target_extra",
    ];
    if ($routesHasBlacklistDomains) {
      $set[] = "blacklist_domains = :blacklist_domains";
    }
    $set[] = "enabled = :enabled";

    $st = $pdo->prepare("
      UPDATE " . CHANNEL_BRIDGE_TABLE_ROUTES . "
      SET
        " . implode(",\n        ", $set) . "
      WHERE id = :id
      LIMIT 1
    ");

    $params = [
      ':id' => $id,
      ':title' => (string)$data['title'],
      ':source_platform' => (string)$data['source_platform'],
      ':source_chat_id' => (string)$data['source_chat_id'],
      ':target_platform' => (string)$data['target_platform'],
      ':target_chat_id' => (string)$data['target_chat_id'],
      ':target_extra' => (string)$data['target_extra'],
      ':enabled' => (int)$data['enabled'],
    ];
    if ($routesHasBlacklistDomains) {
      $params[':blacklist_domains'] = (string)($data['blacklist_domains'] ?? '');
    }

    return $st->execute($params);
  }

  /**
   * channel_bridge_route_toggle()
   * Включает/выключает маршрут.
   *
   * @param PDO $pdo
   * @param int $id
   * @param int $enabled
   * @return bool
   */
  function channel_bridge_route_toggle(PDO $pdo, int $id, int $enabled): bool
  {
    channel_bridge_require_schema($pdo);
    if ($id <= 0) return false;

    $enabled = ($enabled === 1) ? 1 : 0;
    $st = $pdo->prepare("
      UPDATE " . CHANNEL_BRIDGE_TABLE_ROUTES . "
      SET enabled = :enabled
      WHERE id = :id
      LIMIT 1
    ");
    return $st->execute([':enabled' => $enabled, ':id' => $id]);
  }

  /**
   * channel_bridge_route_delete()
   * Удаляет маршрут.
   *
   * @param PDO $pdo
   * @param int $id
   * @return bool
   */
  function channel_bridge_route_delete(PDO $pdo, int $id): bool
  {
    channel_bridge_require_schema($pdo);
    if ($id <= 0) return false;

    $st = $pdo->prepare("
      DELETE FROM " . CHANNEL_BRIDGE_TABLE_ROUTES . "
      WHERE id = :id
      LIMIT 1
    ");
    return $st->execute([':id' => $id]);
  }

  /**
   * channel_bridge_http_post_form()
   * Выполняет HTTP POST application/x-www-form-urlencoded.
   *
   * @param string $url
   * @param array<string,mixed> $params
   * @param array<int,string> $headers
   * @param int $timeout
   * @return array<string,mixed>
   */
  function channel_bridge_http_post_form(string $url, array $params, array $headers = [], int $timeout = 20): array
  {
    $url = trim($url);
    if ($url === '') return ['ok' => false, 'error' => 'URL_EMPTY'];
    if ($timeout < 1) $timeout = 20;

    $payload = http_build_query($params);
    $httpCode = 0;
    $raw = '';

    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers),
      ]);
      $result = curl_exec($ch);
      $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = trim((string)curl_error($ch));
      curl_close($ch);

      if ($result === false) {
        return ['ok' => false, 'error' => 'CURL_ERROR', 'http_code' => $httpCode, 'description' => $curlError];
      }
      $raw = (string)$result;
    } else {
      $context = stream_context_create([
        'http' => [
          'method' => 'POST',
          'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
          'content' => $payload,
          'timeout' => $timeout,
        ],
      ]);
      $result = @file_get_contents($url, false, $context);
      $meta = $http_response_header ?? [];
      if (is_array($meta) && isset($meta[0]) && preg_match('~\s(\d{3})\s~', (string)$meta[0], $m)) {
        $httpCode = (int)$m[1];
      }
      if ($result === false) {
        return ['ok' => false, 'error' => 'HTTP_ERROR', 'http_code' => $httpCode];
      }
      $raw = (string)$result;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) $json = [];

    return ['ok' => true, 'http_code' => $httpCode, 'raw' => $raw, 'json' => $json];
  }

  /**
   * channel_bridge_http_post_json()
   * Выполняет HTTP POST с JSON-телом.
   *
   * @param string $url
   * @param array<string,mixed> $payload
   * @param array<int,string> $headers
   * @param int $timeout
   * @return array<string,mixed>
   */
  function channel_bridge_http_post_json(string $url, array $payload, array $headers = [], int $timeout = 20): array
  {
    $url = trim($url);
    if ($url === '') return ['ok' => false, 'error' => 'URL_EMPTY'];
    if ($timeout < 1) $timeout = 20;

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) return ['ok' => false, 'error' => 'JSON_ENCODE_FAIL'];

    $httpCode = 0;
    $raw = '';

    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
      ]);
      $result = curl_exec($ch);
      $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curlError = trim((string)curl_error($ch));
      curl_close($ch);

      if ($result === false) {
        return ['ok' => false, 'error' => 'CURL_ERROR', 'http_code' => $httpCode, 'description' => $curlError];
      }
      $raw = (string)$result;
    } else {
      $headersTxt = "Content-Type: application/json\r\n";
      foreach ($headers as $h) {
        $headersTxt .= trim((string)$h) . "\r\n";
      }

      $context = stream_context_create([
        'http' => [
          'method' => 'POST',
          'header' => $headersTxt,
          'content' => $body,
          'timeout' => $timeout,
        ],
      ]);
      $result = @file_get_contents($url, false, $context);
      $meta = $http_response_header ?? [];
      if (is_array($meta) && isset($meta[0]) && preg_match('~\s(\d{3})\s~', (string)$meta[0], $m)) {
        $httpCode = (int)$m[1];
      }
      if ($result === false) {
        return ['ok' => false, 'error' => 'HTTP_ERROR', 'http_code' => $httpCode];
      }
      $raw = (string)$result;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) $json = [];

    return ['ok' => true, 'http_code' => $httpCode, 'raw' => $raw, 'json' => $json];
  }

  /**
   * channel_bridge_tg_build_file_download_url()
   * Запрашивает у Telegram getFile и строит прямой URL файла.
   *
   * @param string $tgToken
   * @param string $fileId
   * @return array<string,mixed>
   */
  function channel_bridge_tg_build_file_download_url(string $tgToken, string $fileId): array
  {
    $tgToken = trim($tgToken);
    $fileId = trim($fileId);
    if ($tgToken === '' || $fileId === '') {
      return ['ok' => false, 'error' => 'TG_FILE_PARAMS_EMPTY'];
    }

    if (!function_exists('tg_request')) {
      return ['ok' => false, 'error' => 'TG_REQUEST_UNAVAILABLE'];
    }

    $res = tg_request($tgToken, 'getFile', ['file_id' => $fileId]);
    if (($res['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($res['error'] ?? 'TG_GETFILE_ERROR'), 'raw' => $res];
    }

    $result = (array)($res['result'] ?? []);
    $filePath = trim((string)($result['file_path'] ?? ''));
    if ($filePath === '') {
      return ['ok' => false, 'error' => 'TG_FILE_PATH_EMPTY', 'raw' => $res];
    }

    $url = 'https://api.telegram.org/file/bot' . $tgToken . '/' . ltrim($filePath, '/');
    return ['ok' => true, 'url' => $url, 'file_path' => $filePath];
  }

  /**
   * channel_bridge_http_download_to_tmp()
   * Скачивает файл по URL во временный файл.
   *
   * @param string $url
   * @param int $timeout
   * @return array<string,mixed>
   */
  function channel_bridge_http_download_to_tmp(string $url, int $timeout = 30): array
  {
    $url = trim($url);
    if ($url === '') return ['ok' => false, 'error' => 'URL_EMPTY'];
    if ($timeout < 1) $timeout = 30;

    $tmpPath = tempnam(sys_get_temp_dir(), 'cb_max_img_');
    if (!is_string($tmpPath) || $tmpPath === '') {
      return ['ok' => false, 'error' => 'TMP_CREATE_FAILED'];
    }

    $httpCode = 0;
    $contentType = '';
    $ok = false;
    $error = '';

    if (function_exists('curl_init')) {
      $fp = @fopen($tmpPath, 'wb');
      if ($fp === false) {
        @unlink($tmpPath);
        return ['ok' => false, 'error' => 'TMP_OPEN_FAILED'];
      }

      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
      ]);
      $result = curl_exec($ch);
      $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $contentType = trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
      $curlError = trim((string)curl_error($ch));
      curl_close($ch);
      fclose($fp);

      if ($result === false) {
        $error = ($curlError !== '') ? $curlError : 'CURL_ERROR';
      } else {
        $ok = ($httpCode >= 200 && $httpCode < 300);
        if (!$ok) $error = 'HTTP_' . $httpCode;
      }
    } else {
      $context = stream_context_create([
        'http' => [
          'method' => 'GET',
          'timeout' => $timeout,
        ],
      ]);
      $result = @file_get_contents($url, false, $context);
      $meta = $http_response_header ?? [];
      if (is_array($meta)) {
        foreach ($meta as $line) {
          if ($httpCode === 0 && preg_match('~\s(\d{3})\s~', (string)$line, $m)) {
            $httpCode = (int)$m[1];
          }
          if ($contentType === '' && stripos((string)$line, 'Content-Type:') === 0) {
            $contentType = trim((string)substr((string)$line, 13));
          }
        }
      }

      if (!is_string($result)) {
        $error = 'HTTP_ERROR';
      } else {
        $bytes = @file_put_contents($tmpPath, $result);
        if (!is_int($bytes) || $bytes <= 0) {
          $error = 'TMP_WRITE_FAILED';
        } else {
          $ok = ($httpCode >= 200 && $httpCode < 300);
          if (!$ok) $error = 'HTTP_' . $httpCode;
        }
      }
    }

    if (!$ok) {
      @unlink($tmpPath);
      return ['ok' => false, 'error' => $error !== '' ? $error : 'DOWNLOAD_FAILED', 'http_code' => $httpCode];
    }

    $size = (int)@filesize($tmpPath);
    if ($size <= 0) {
      @unlink($tmpPath);
      return ['ok' => false, 'error' => 'EMPTY_FILE'];
    }

    return [
      'ok' => true,
      'path' => $tmpPath,
      'size' => $size,
      'http_code' => $httpCode,
      'content_type' => $contentType,
    ];
  }

  /**
   * channel_bridge_http_post_multipart_file()
   * Выполняет multipart/form-data POST (поле data=@file).
   *
   * @param string $url
   * @param string $filePath
   * @param array<int,string> $headers
   * @param int $timeout
   * @return array<string,mixed>
   */
  function channel_bridge_http_post_multipart_file(string $url, string $filePath, array $headers = [], int $timeout = 30): array
  {
    $url = trim($url);
    $filePath = trim($filePath);
    if ($url === '' || $filePath === '') return ['ok' => false, 'error' => 'PARAMS_EMPTY'];
    if (!is_file($filePath)) return ['ok' => false, 'error' => 'FILE_NOT_FOUND'];
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'CURL_REQUIRED'];
    if ($timeout < 1) $timeout = 30;

    $mimeType = function_exists('mime_content_type') ? (string)@mime_content_type($filePath) : '';
    if ($mimeType === '') $mimeType = 'application/octet-stream';
    $filename = basename($filePath);
    $fileObj = new CURLFile($filePath, $mimeType, $filename);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => ['data' => $fileObj],
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT => $timeout,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_HTTPHEADER => array_merge(['Expect:'], $headers),
    ]);

    $result = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = trim((string)curl_error($ch));
    curl_close($ch);

    if ($result === false) {
      return ['ok' => false, 'error' => 'CURL_ERROR', 'description' => $curlError, 'http_code' => $httpCode];
    }

    $raw = (string)$result;
    $json = json_decode($raw, true);
    if (!is_array($json)) $json = [];

    return ['ok' => true, 'http_code' => $httpCode, 'raw' => $raw, 'json' => $json];
  }

  /**
   * channel_bridge_tg_source_photo_file_ids()
   * Возвращает список file_id картинок из sourceMeta.
   *
   * @param array<string,mixed> $sourceMeta
   * @return array<int,string>
   */
  function channel_bridge_tg_source_photo_file_ids(array $sourceMeta): array
  {
    $sourcePlatform = channel_bridge_norm_platform((string)($sourceMeta['source_platform'] ?? ''));
    if ($sourcePlatform !== CHANNEL_BRIDGE_SOURCE_TG) return [];

    $out = [];
    if (isset($sourceMeta['tg_photo_file_ids']) && is_array($sourceMeta['tg_photo_file_ids'])) {
      foreach ($sourceMeta['tg_photo_file_ids'] as $pid) {
        $pid = trim((string)$pid);
        if ($pid !== '') $out[$pid] = true;
      }
    }

    $single = trim((string)($sourceMeta['tg_photo_file_id'] ?? ''));
    if ($single !== '') $out[$single] = true;

    return array_keys($out);
  }

  /**
   * channel_bridge_max_make_image_attachment()
   * Собирает один image attachment для MAX по file_id Telegram.
   *
   * @param array<string,mixed> $settings
   * @param string $tgFileId
   * @return array<string,mixed>
   */
  function channel_bridge_max_make_image_attachment(array $settings, string $tgFileId): array
  {
    $tgFileId = trim($tgFileId);
    if ($tgFileId === '') {
      return ['ok' => false, 'error' => 'TG_PHOTO_NOT_FOUND'];
    }

    $tgToken = trim((string)($settings['tg_bot_token'] ?? ''));
    if ($tgToken === '') {
      return ['ok' => false, 'error' => 'TG_TOKEN_EMPTY'];
    }

    $apiKey = trim((string)($settings['max_api_key'] ?? ''));
    if (stripos($apiKey, 'Bearer ') === 0) $apiKey = trim((string)substr($apiKey, 7));
    if (stripos($apiKey, 'OAuth ') === 0) $apiKey = trim((string)substr($apiKey, 6));
    if ($apiKey === '') {
      return ['ok' => false, 'error' => 'MAX_API_KEY_EMPTY'];
    }

    $baseUrl = rtrim(trim((string)($settings['max_base_url'] ?? '')), '/');
    if ($baseUrl === '') {
      return ['ok' => false, 'error' => 'MAX_BASE_URL_EMPTY'];
    }

    $fileUrlRes = channel_bridge_tg_build_file_download_url($tgToken, $tgFileId);
    if (($fileUrlRes['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($fileUrlRes['error'] ?? 'TG_FILE_URL_ERROR'), 'raw' => $fileUrlRes];
    }

    $download = channel_bridge_http_download_to_tmp((string)($fileUrlRes['url'] ?? ''), 30);
    if (($download['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($download['error'] ?? 'TG_FILE_DOWNLOAD_ERROR'), 'raw' => $download];
    }

    $tmpPath = (string)($download['path'] ?? '');
    try {
      $uploadInfo = channel_bridge_http_post_json($baseUrl . '/uploads?type=image', [], [
        'Authorization: ' . $apiKey,
      ], 20);
      if (($uploadInfo['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => (string)($uploadInfo['error'] ?? 'MAX_UPLOAD_URL_HTTP_ERROR'), 'raw' => $uploadInfo];
      }

      $uploadInfoCode = (int)($uploadInfo['http_code'] ?? 0);
      if ($uploadInfoCode < 200 || $uploadInfoCode >= 300) {
        return ['ok' => false, 'error' => 'MAX_UPLOAD_URL_HTTP_' . $uploadInfoCode, 'raw' => $uploadInfo];
      }

      $uploadJson = (array)($uploadInfo['json'] ?? []);
      $uploadUrl = trim((string)($uploadJson['url'] ?? ''));
      if ($uploadUrl === '' && isset($uploadJson['result']) && is_array($uploadJson['result'])) {
        $uploadUrl = trim((string)($uploadJson['result']['url'] ?? ''));
      }
      if ($uploadUrl === '') {
        return ['ok' => false, 'error' => 'MAX_UPLOAD_URL_EMPTY', 'raw' => $uploadInfo];
      }

      $uploadHeadersRaw = null;
      if (isset($uploadJson['headers'])) {
        $uploadHeadersRaw = $uploadJson['headers'];
      } elseif (isset($uploadJson['result']) && is_array($uploadJson['result']) && isset($uploadJson['result']['headers'])) {
        $uploadHeadersRaw = $uploadJson['result']['headers'];
      }
      $uploadHeaders = [];
      if (is_array($uploadHeadersRaw)) {
        foreach ($uploadHeadersRaw as $hk => $hv) {
          if (is_int($hk)) {
            $h = trim((string)$hv);
            if ($h !== '') $uploadHeaders[] = $h;
            continue;
          }
          $hName = trim((string)$hk);
          $hVal = trim((string)$hv);
          if ($hName === '' || $hVal === '') continue;
          $uploadHeaders[] = $hName . ': ' . $hVal;
        }
      }

      $uploadFile = channel_bridge_http_post_multipart_file($uploadUrl, $tmpPath, $uploadHeaders, 40);
      if (($uploadFile['ok'] ?? false) !== true) {
        return ['ok' => false, 'error' => (string)($uploadFile['error'] ?? 'MAX_UPLOAD_FILE_HTTP_ERROR'), 'raw' => $uploadFile];
      }

      $uploadFileCode = (int)($uploadFile['http_code'] ?? 0);
      if ($uploadFileCode < 200 || $uploadFileCode >= 300) {
        return ['ok' => false, 'error' => 'MAX_UPLOAD_FILE_HTTP_' . $uploadFileCode, 'raw' => $uploadFile];
      }

      $uploadPayload = (array)($uploadFile['json'] ?? []);
      if (!$uploadPayload) {
        return ['ok' => false, 'error' => 'MAX_UPLOAD_PAYLOAD_EMPTY', 'raw' => $uploadFile];
      }

      return [
        'ok' => true,
        'attachment' => [
          'type' => 'image',
          'payload' => $uploadPayload,
        ],
      ];
    } finally {
      if ($tmpPath !== '' && is_file($tmpPath)) {
        @unlink($tmpPath);
      }
    }
  }

  /**
   * channel_bridge_send_tg_forward_message()
   * Пересылает исходный TG-пост в TG-цель как forwardMessage,
   * чтобы сохранить исходный формат/медиа/плашку "Переслано от".
   *
   * @param string $token
   * @param string $targetChatId
   * @param array<string,mixed> $sourceMeta
   * @return array<string,mixed>
   */
  function channel_bridge_send_tg_forward_message(string $token, string $targetChatId, array $sourceMeta): array
  {
    if (!function_exists('tg_request')) {
      return ['ok' => false, 'error' => 'TG_REQUEST_UNAVAILABLE'];
    }

    $sourceChatId = trim((string)($sourceMeta['source_chat_id'] ?? ''));
    $sourceMessageId = trim((string)($sourceMeta['source_message_id'] ?? ''));
    if ($sourceChatId === '' || !preg_match('~^\d+$~', $sourceMessageId)) {
      return ['ok' => false, 'error' => 'TG_SOURCE_META_INVALID'];
    }

    $res = tg_request($token, 'forwardMessage', [
      'chat_id' => $targetChatId,
      'from_chat_id' => $sourceChatId,
      'message_id' => (int)$sourceMessageId,
    ]);

    return [
      'ok' => (($res['ok'] ?? false) === true),
      'raw' => $res,
      'error' => (string)($res['description'] ?? $res['error'] ?? ''),
      'transport' => 'forwardMessage',
    ];
  }

  /**
   * channel_bridge_tg_norm_message_ids()
   * Нормализует список message_id для forwardMessages.
   *
   * @param mixed $raw
   * @return array<int,int>
   */
  function channel_bridge_tg_norm_message_ids($raw): array
  {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $v) {
      $id = (int)$v;
      if ($id > 0) $out[$id] = true;
    }
    if (!$out) return [];
    $ids = array_keys($out);
    sort($ids, SORT_NUMERIC);
    return array_values($ids);
  }

  /**
   * channel_bridge_send_tg_forward_messages()
   * Пересылает группу сообщений TG (альбом) в TG-цель.
   *
   * @param string $token
   * @param string $targetChatId
   * @param array<string,mixed> $sourceMeta
   * @return array<string,mixed>
   */
  function channel_bridge_send_tg_forward_messages(string $token, string $targetChatId, array $sourceMeta): array
  {
    if (!function_exists('tg_request')) {
      return ['ok' => false, 'error' => 'TG_REQUEST_UNAVAILABLE'];
    }

    $sourceChatId = trim((string)($sourceMeta['source_chat_id'] ?? ''));
    $messageIds = channel_bridge_tg_norm_message_ids($sourceMeta['tg_media_group_message_ids'] ?? []);
    if ($sourceChatId === '' || count($messageIds) < 2) {
      return ['ok' => false, 'error' => 'TG_MEDIA_GROUP_META_INVALID'];
    }

    $res = tg_request($token, 'forwardMessages', [
      'chat_id' => $targetChatId,
      'from_chat_id' => $sourceChatId,
      'message_ids' => $messageIds,
    ]);
    if (($res['ok'] ?? false) === true) {
      return [
        'ok' => true,
        'raw' => $res,
        'error' => '',
        'transport' => 'forwardMessages',
      ];
    }

    // Fallback для старых версий Bot API или ограничений платформы.
    $lastRaw = $res;
    foreach ($messageIds as $mid) {
      $one = tg_request($token, 'forwardMessage', [
        'chat_id' => $targetChatId,
        'from_chat_id' => $sourceChatId,
        'message_id' => $mid,
      ]);
      $lastRaw = $one;
      if (($one['ok'] ?? false) !== true) {
        return [
          'ok' => false,
          'raw' => $one,
          'error' => (string)($one['description'] ?? $one['error'] ?? 'TG_FORWARD_MEDIA_GROUP_FAILED'),
          'transport' => 'forwardMessage_loop',
        ];
      }
    }

    return [
      'ok' => true,
      'raw' => $lastRaw,
      'error' => '',
      'transport' => 'forwardMessage_loop',
    ];
  }

  /**
   * channel_bridge_send_tg()
   * Отправляет сообщение в Telegram-цель.
   *
   * @param array<string,mixed> $settings
   * @param array<string,mixed> $route
   * @param string $text
   * @param array<string,mixed> $sourceMeta
   * @return array<string,mixed>
   */
  function channel_bridge_send_tg(array $settings, array $route, string $text, array $sourceMeta = []): array
  {
    if ((int)($settings['tg_enabled'] ?? 0) !== 1) {
      return ['ok' => false, 'error' => 'TG_DISABLED'];
    }

    $token = trim((string)($settings['tg_bot_token'] ?? ''));
    if ($token === '') {
      return ['ok' => false, 'error' => 'TG_TOKEN_EMPTY'];
    }

    $chatId = trim((string)($route['target_chat_id'] ?? ''));
    if ($chatId === '') {
      return ['ok' => false, 'error' => 'TG_CHAT_ID_EMPTY'];
    }

    $sourcePlatform = channel_bridge_norm_platform((string)($sourceMeta['source_platform'] ?? ''));
    if ($sourcePlatform === CHANNEL_BRIDGE_SOURCE_TG) {
      $groupIds = channel_bridge_tg_norm_message_ids($sourceMeta['tg_media_group_message_ids'] ?? []);
      if (count($groupIds) > 1) {
        return channel_bridge_send_tg_forward_messages($token, $chatId, $sourceMeta);
      }
      $sourceMessageIdRaw = trim((string)($sourceMeta['source_message_id'] ?? ''));
      if (count($groupIds) === 1 && !preg_match('~^\d+$~', $sourceMessageIdRaw)) {
        $singleMeta = $sourceMeta;
        $singleMeta['source_message_id'] = (string)$groupIds[0];
        return channel_bridge_send_tg_forward_message($token, $chatId, $singleMeta);
      }
      return channel_bridge_send_tg_forward_message($token, $chatId, $sourceMeta);
    }

    if (trim($text) === '') {
      return ['ok' => false, 'error' => 'TG_TEXT_EMPTY'];
    }

    $options = [];
    $options['disable_web_page_preview'] = true;
    $parseMode = trim((string)($settings['tg_parse_mode'] ?? 'HTML'));
    if ($parseMode !== '') {
      $options['parse_mode'] = $parseMode;
    }

    $res = tg_send_message($token, $chatId, $text, $options);
    return [
      'ok' => (($res['ok'] ?? false) === true),
      'raw' => $res,
      'error' => (string)($res['description'] ?? $res['error'] ?? ''),
      'transport' => 'sendMessage',
    ];
  }

  /**
   * channel_bridge_send_vk()
   * Отправляет сообщение в VK (метод wall.post).
   *
   * @param array<string,mixed> $settings
   * @param array<string,mixed> $route
   * @param string $text
   * @return array<string,mixed>
   */
  function channel_bridge_send_vk(array $settings, array $route, string $text): array
  {
    if ((int)($settings['vk_enabled'] ?? 0) !== 1) {
      return ['ok' => false, 'error' => 'VK_DISABLED'];
    }

    $token = trim((string)($settings['vk_group_token'] ?? ''));
    if ($token === '') {
      return ['ok' => false, 'error' => 'VK_TOKEN_EMPTY'];
    }

    $ownerId = trim((string)($route['target_chat_id'] ?? ''));
    if ($ownerId === '') {
      $ownerId = trim((string)($settings['vk_owner_id'] ?? ''));
    }
    if ($ownerId === '') {
      return ['ok' => false, 'error' => 'VK_OWNER_ID_EMPTY'];
    }

    $version = trim((string)($settings['vk_api_version'] ?? '5.199'));
    if ($version === '') $version = '5.199';

    $http = channel_bridge_http_post_form('https://api.vk.com/method/wall.post', [
      'owner_id' => $ownerId,
      'from_group' => 1,
      'message' => $text,
      'access_token' => $token,
      'v' => $version,
    ]);
    if (($http['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($http['error'] ?? 'VK_HTTP_ERROR'), 'raw' => $http];
    }

    $json = (array)($http['json'] ?? []);
    if (isset($json['error'])) {
      $err = is_array($json['error']) ? (string)($json['error']['error_msg'] ?? 'VK_API_ERROR') : 'VK_API_ERROR';
      return ['ok' => false, 'error' => $err, 'raw' => $json];
    }

    $ok = isset($json['response']);
    return ['ok' => $ok, 'raw' => $json, 'error' => $ok ? '' : 'VK_BAD_RESPONSE'];
  }

  /**
   * channel_bridge_send_max()
   * Отправляет сообщение в MAX через настраиваемый endpoint.
   *
   * @param array<string,mixed> $settings
   * @param array<string,mixed> $route
   * @param string $text
   * @param array<string,mixed> $sourceMeta
   * @return array<string,mixed>
   */
  function channel_bridge_send_max(array $settings, array $route, string $text, array $sourceMeta = []): array
  {
    if ((int)($settings['max_enabled'] ?? 0) !== 1) {
      return ['ok' => false, 'error' => 'MAX_DISABLED'];
    }

    $apiKey = trim((string)($settings['max_api_key'] ?? ''));
    if (stripos($apiKey, 'Bearer ') === 0) {
      $apiKey = trim((string)substr($apiKey, 7));
    }
    if (stripos($apiKey, 'OAuth ') === 0) {
      $apiKey = trim((string)substr($apiKey, 6));
    }
    if ($apiKey === '') {
      return ['ok' => false, 'error' => 'MAX_API_KEY_EMPTY'];
    }

    $baseUrl = rtrim(trim((string)($settings['max_base_url'] ?? '')), '/');
    $sendPath = trim((string)($settings['max_send_path'] ?? ''));
    if ($baseUrl === '' || $sendPath === '') {
      return ['ok' => false, 'error' => 'MAX_ENDPOINT_EMPTY'];
    }

    $chatId = trim((string)($route['target_chat_id'] ?? ''));
    if ($chatId === '') {
      return ['ok' => false, 'error' => 'MAX_CHAT_ID_EMPTY'];
    }

    $maxHtmlText = trim((string)($sourceMeta['max_text_html'] ?? ''));
    $preparedText = channel_bridge_max_prepare_text(($maxHtmlText !== '') ? $maxHtmlText : $text);
    $text = channel_bridge_normalize_text((string)($preparedText['text'] ?? ''));
    $markdownNeeded = ((int)($preparedText['markdown'] ?? 0) === 1) ? 1 : 0;

    $quoteApplied = 0;
    $quoted = channel_bridge_max_apply_repost_quote($text);
    if (is_array($quoted)) {
      $text = channel_bridge_normalize_text((string)($quoted['text'] ?? $text));
      $quoteApplied = ((int)($quoted['quoted'] ?? 0) === 1) ? 1 : 0;
    }

    $payload = ['text' => $text];
    $query = [
      'chat_id' => $chatId,
    ];

    $extra = trim((string)($route['target_extra'] ?? ''));
    if ($extra !== '') {
      $extraMap = json_decode($extra, true);
      if (is_array($extraMap)) {
        foreach (['chat_id', 'user_id', 'disable_link_preview'] as $qk) {
          if (!array_key_exists($qk, $extraMap)) continue;
          $qv = $extraMap[$qk];
          if (is_scalar($qv) || $qv === null) {
            $qvStr = trim((string)$qv);
            if ($qvStr !== '') {
              $query[$qk] = $qvStr;
            }
          }
          unset($extraMap[$qk]);
        }
        if (isset($query['user_id']) && trim((string)$query['user_id']) !== '') {
          unset($query['chat_id']);
        }
        $payload = array_merge($payload, $extraMap);
      }
    }

    if (($quoteApplied === 1 || $markdownNeeded === 1) && !isset($payload['format'])) {
      $payload['format'] = 'markdown';
    }

    if (!isset($query['chat_id']) && !isset($query['user_id'])) {
      return ['ok' => false, 'error' => 'MAX_RECIPIENT_EMPTY'];
    }

    $photoIds = channel_bridge_tg_source_photo_file_ids($sourceMeta);
    $attachmentErrors = [];
    foreach ($photoIds as $pid) {
      $imgAttachment = channel_bridge_max_make_image_attachment($settings, $pid);
      if (($imgAttachment['ok'] ?? false) === true && is_array($imgAttachment['attachment'] ?? null)) {
        if (!isset($payload['attachments']) || !is_array($payload['attachments'])) {
          $payload['attachments'] = [];
        }
        $payload['attachments'][] = $imgAttachment['attachment'];
        continue;
      }
      $attachmentErrors[] = trim((string)($imgAttachment['error'] ?? 'MAX_ATTACHMENT_ERROR'));
    }
    if ($attachmentErrors && function_exists('audit_log')) {
      audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'max_attachment_skip', 'warn', [
        'errors' => implode(';', $attachmentErrors),
        'source_chat_id' => (string)($sourceMeta['source_chat_id'] ?? ''),
        'source_message_id' => (string)($sourceMeta['source_message_id'] ?? ''),
        'photos_total' => count($photoIds),
        'photos_failed' => count($attachmentErrors),
      ]);
    }
    if ($photoIds && $attachmentErrors) {
      return [
        'ok' => false,
        'error' => 'MAX_ATTACHMENT_PARTIAL',
        'raw' => [
          'photos_total' => count($photoIds),
          'photos_failed' => count($attachmentErrors),
          'errors' => $attachmentErrors,
        ],
      ];
    }

    $url = $baseUrl . '/' . ltrim($sendPath, '/');
    $url .= '?' . http_build_query($query);
    $http = channel_bridge_http_post_json($url, $payload, [
      'Authorization: ' . $apiKey,
    ]);

    if (($http['ok'] ?? false) !== true) {
      return ['ok' => false, 'error' => (string)($http['error'] ?? 'MAX_HTTP_ERROR'), 'raw' => $http];
    }

    $httpCode = (int)($http['http_code'] ?? 0);
    $ok = ($httpCode >= 200 && $httpCode < 300);
    return ['ok' => $ok, 'raw' => $http['json'] ?: (string)($http['raw'] ?? ''), 'error' => $ok ? '' : ('HTTP_' . $httpCode)];
  }

  /**
   * channel_bridge_send_by_route()
   * Отправляет сообщение в цель конкретного маршрута.
   *
   * @param array<string,mixed> $settings
   * @param array<string,mixed> $route
   * @param string $text
   * @param array<string,mixed> $sourceMeta
   * @return array<string,mixed>
   */
  function channel_bridge_send_by_route(array $settings, array $route, string $text, array $sourceMeta = []): array
  {
    $target = channel_bridge_norm_platform((string)($route['target_platform'] ?? ''));

    if ($target === CHANNEL_BRIDGE_TARGET_TG) {
      return channel_bridge_send_tg($settings, $route, $text, $sourceMeta);
    }
    if ($target === CHANNEL_BRIDGE_TARGET_VK) {
      return channel_bridge_send_vk($settings, $route, $text);
    }
    if ($target === CHANNEL_BRIDGE_TARGET_MAX) {
      return channel_bridge_send_max($settings, $route, $text, $sourceMeta);
    }

    return ['ok' => false, 'error' => 'TARGET_NOT_SUPPORTED'];
  }

  /**
   * channel_bridge_log_dispatch()
   * Сохраняет результат отправки в журнал.
   *
   * @param PDO $pdo
   * @param array<string,mixed> $row
   * @return void
   */
  function channel_bridge_log_dispatch(PDO $pdo, array $row): void
  {
    $rawResponse = $row['response_raw'] ?? '';
    if (!is_string($rawResponse)) {
      $rawResponse = json_encode($rawResponse, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      if (!is_string($rawResponse)) $rawResponse = '';
    }

    if (function_exists('mb_substr')) {
      $rawResponse = (string)mb_substr($rawResponse, 0, 2000);
    } else {
      $rawResponse = (string)substr($rawResponse, 0, 2000);
    }

    $pdo->prepare("
      INSERT INTO " . CHANNEL_BRIDGE_TABLE_DISPATCH_LOG . "
      (
        route_id,
        source_platform,
        source_chat_id,
        source_message_id,
        target_platform,
        target_chat_id,
        message_text,
        send_status,
        error_text,
        response_raw
      )
      VALUES
      (
        :route_id,
        :source_platform,
        :source_chat_id,
        :source_message_id,
        :target_platform,
        :target_chat_id,
        :message_text,
        :send_status,
        :error_text,
        :response_raw
      )
    ")->execute([
      ':route_id' => (int)($row['route_id'] ?? 0),
      ':source_platform' => trim((string)($row['source_platform'] ?? '')),
      ':source_chat_id' => trim((string)($row['source_chat_id'] ?? '')),
      ':source_message_id' => trim((string)($row['source_message_id'] ?? '')),
      ':target_platform' => trim((string)($row['target_platform'] ?? '')),
      ':target_chat_id' => trim((string)($row['target_chat_id'] ?? '')),
      ':message_text' => channel_bridge_normalize_text((string)($row['message_text'] ?? '')),
      ':send_status' => trim((string)($row['send_status'] ?? 'failed')),
      ':error_text' => trim((string)($row['error_text'] ?? '')),
      ':response_raw' => $rawResponse,
    ]);
  }

  /**
   * channel_bridge_collect_routes_for_source()
   * Возвращает активные маршруты для заданного источника.
   *
   * @param PDO $pdo
   * @param string $sourcePlatform
   * @param string $sourceChatId
   * @return array<int,array<string,mixed>>
   */
  function channel_bridge_collect_routes_for_source(PDO $pdo, string $sourcePlatform, string $sourceChatId): array
  {
    channel_bridge_require_schema($pdo);

    $sourcePlatform = channel_bridge_norm_platform($sourcePlatform);
    $sourceChatId = channel_bridge_norm_chat_id($sourceChatId);
    if ($sourcePlatform === '' || $sourceChatId === '') {
      return [];
    }

    $routesHasBlacklistDomains = channel_bridge_schema_column_exists($pdo, CHANNEL_BRIDGE_TABLE_ROUTES, 'blacklist_domains');
    $blacklistSelect = $routesHasBlacklistDomains
      ? "blacklist_domains,"
      : "'' AS blacklist_domains,";

    $st = $pdo->prepare("
      SELECT
        id,
        title,
        source_platform,
        source_chat_id,
        target_platform,
        target_chat_id,
        target_extra,
        $blacklistSelect
        enabled
      FROM " . CHANNEL_BRIDGE_TABLE_ROUTES . "
      WHERE enabled = 1
        AND source_platform = :source_platform
        AND source_chat_id = :source_chat_id
      ORDER BY id ASC
    ");
    $st->execute([
      ':source_platform' => $sourcePlatform,
      ':source_chat_id' => $sourceChatId,
    ]);

    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }

  /**
   * channel_bridge_ingest()
   * Единая точка приёма входящего сообщения:
   *  1) нормализует payload;
   *  2) пишет запись в inbox (с дедупликацией по source_message_id);
   *  3) находит маршруты и отправляет сообщение в цели;
   *  4) пишет dispatch_log;
   *  5) возвращает статистику по отправкам.
   *
   * @param PDO $pdo
   * @param array<string,mixed> $payload
   * @return array<string,mixed>
   */
  function channel_bridge_ingest(PDO $pdo, array $payload): array
  {
    channel_bridge_require_schema($pdo);

    $settings = channel_bridge_settings_get($pdo);
    if ((int)($settings['enabled'] ?? 0) !== 1) {
      return [
        'ok' => false,
        'reason' => 'module_disabled',
        'message' => channel_bridge_t('channel_bridge.error_module_disabled'),
      ];
    }

    $sourcePlatform = channel_bridge_norm_platform((string)($payload['source_platform'] ?? ''));
    $mediaGroupId = trim((string)($payload['tg_media_group_id'] ?? ''));
    $mediaGroupDispatchToken = '';
    if ($sourcePlatform === CHANNEL_BRIDGE_SOURCE_TG && $mediaGroupId !== '' && stripos((string)($payload['source_message_id'] ?? ''), 'mg:') !== 0) {
      $mg = channel_bridge_media_group_collect($payload);
      $mgMode = trim((string)($mg['mode'] ?? ''));
      if (function_exists('audit_log')) {
        $mgPayload = is_array($mg['payload'] ?? null) ? (array)$mg['payload'] : [];
        $mgPhotoIds = (array)($mgPayload['tg_photo_file_ids'] ?? []);
        $mgMsgIds = (array)($mgPayload['tg_media_group_message_ids'] ?? []);
        audit_log(CHANNEL_BRIDGE_MODULE_CODE, 'media_group', 'info', [
          'mode' => $mgMode,
          'media_group_id' => $mediaGroupId,
          'source_chat_id' => (string)($payload['source_chat_id'] ?? ''),
          'source_message_id' => (string)($payload['source_message_id'] ?? ''),
          'photos' => count($mgPhotoIds),
          'messages' => count($mgMsgIds),
          'reason' => (string)($mg['reason'] ?? ''),
          'error' => (string)($mg['error'] ?? ''),
        ]);
      }

      if ($mgMode === 'sent') {
        return [
          'ok' => true,
          'reason' => 'media_group_already_sent',
          'targets' => 0,
          'sent' => 0,
          'failed' => 0,
          'media_group_id' => $mediaGroupId,
        ];
      }
      if ($mgMode === 'pending') {
        return [
          'ok' => true,
          'reason' => 'media_group_pending',
          'targets' => 0,
          'sent' => 0,
          'failed' => 0,
          'media_group_id' => $mediaGroupId,
        ];
      }
      if ($mgMode === 'ready' && is_array($mg['payload'] ?? null)) {
        $payload = (array)$mg['payload'];
        $mediaGroupDispatchToken = trim((string)($mg['dispatch_token'] ?? ''));
      } elseif ($mgMode === 'error') {
        return [
          'ok' => false,
          'reason' => 'media_group_collect_failed',
          'message' => (string)($mg['error'] ?? 'Media group collect failed'),
        ];
      }
    }

    $sourcePlatform = channel_bridge_norm_platform((string)($payload['source_platform'] ?? ''));
    $sourceChatId = channel_bridge_norm_chat_id((string)($payload['source_chat_id'] ?? ''));
    $sourceMessageId = trim((string)($payload['source_message_id'] ?? ''));
    if ($mediaGroupDispatchToken === '' && $sourcePlatform === CHANNEL_BRIDGE_SOURCE_TG && $mediaGroupId !== '' && stripos($sourceMessageId, 'mg:') === 0) {
      $mediaGroupDispatchToken = trim((string)($payload['cb_media_group_dispatch_token'] ?? ''));
    }
    $sourceMessageIdDb = ($sourceMessageId === '') ? null : $sourceMessageId;
    $messageText = channel_bridge_normalize_text((string)($payload['message_text'] ?? ''));

    if ($sourcePlatform === '' || !in_array($sourcePlatform, channel_bridge_supported_sources(), true)) {
      return ['ok' => false, 'reason' => 'bad_source_platform', 'message' => channel_bridge_t('channel_bridge.error_bad_source_platform')];
    }
    if ($sourceChatId === '') {
      return ['ok' => false, 'reason' => 'source_chat_required', 'message' => channel_bridge_t('channel_bridge.error_source_chat_required')];
    }
    $canDispatchWithoutText = (
      $sourcePlatform === CHANNEL_BRIDGE_SOURCE_TG
      && $sourceMessageId !== ''
    );
    if ($messageText === '' && !$canDispatchWithoutText) {
      return ['ok' => false, 'reason' => 'message_required', 'message' => channel_bridge_t('channel_bridge.error_message_required')];
    }

    $rawPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($rawPayload)) $rawPayload = '{}';

    $inboxId = 0;
    try {
      $pdo->prepare("
        INSERT INTO " . CHANNEL_BRIDGE_TABLE_INBOX . "
        (
          source_platform,
          source_chat_id,
          source_message_id,
          message_text,
          payload_raw
        )
        VALUES
        (
          :source_platform,
          :source_chat_id,
          :source_message_id,
          :message_text,
          :payload_raw
        )
      ")->execute([
        ':source_platform' => $sourcePlatform,
        ':source_chat_id' => $sourceChatId,
        ':source_message_id' => $sourceMessageIdDb,
        ':message_text' => $messageText,
        ':payload_raw' => $rawPayload,
      ]);
      $inboxId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
      if ((string)$e->getCode() === '23000') {
        $isMediaGroupRetry = (
          $sourcePlatform === CHANNEL_BRIDGE_SOURCE_TG
          && $mediaGroupId !== ''
          && stripos($sourceMessageId, 'mg:') === 0
        );
        if ($isMediaGroupRetry) {
          $inboxId = 0;
        } else {
          return [
            'ok' => true,
            'reason' => 'duplicate',
            'message' => channel_bridge_t('channel_bridge.ingest_duplicate'),
            'targets' => 0,
            'sent' => 0,
            'failed' => 0,
          ];
        }
      } else {
        throw $e;
      }
    }

    $isAggregatedMediaGroup = (
      $sourcePlatform === CHANNEL_BRIDGE_SOURCE_TG
      && $mediaGroupId !== ''
      && stripos($sourceMessageId, 'mg:') === 0
    );

    try {
      $routes = channel_bridge_collect_routes_for_source($pdo, $sourcePlatform, $sourceChatId);
      $targets = count($routes);
      $sent = 0;
      $failed = 0;
      $skipped = 0;
      $dispatchText = $messageText;
      $dispatchMaxHtml = trim((string)($payload['tg_text_html'] ?? ''));
      $repostSourceUrl = '';
      if ($sourcePlatform === CHANNEL_BRIDGE_SOURCE_TG) {
        $repostSourceUrl = channel_bridge_tg_post_url_from_meta([
          'source_chat_id' => $sourceChatId,
          'source_message_id' => $sourceMessageId,
          'tg_media_group_message_ids' => (array)($payload['tg_media_group_message_ids'] ?? []),
          'tg_public_post_url' => (string)($payload['tg_public_post_url'] ?? ''),
          'tg_chat_username' => (string)($payload['tg_chat_username'] ?? ''),
        ]);
      }
      $linkRules = channel_bridge_link_suffix_rules_list($pdo, true);
      if ($linkRules) {
        $suffixApply = channel_bridge_apply_link_suffix_rules($dispatchText, $linkRules, $repostSourceUrl);
        $dispatchText = channel_bridge_normalize_text((string)($suffixApply['text'] ?? $dispatchText));
        if ($dispatchMaxHtml !== '') {
          $suffixApplyHtml = channel_bridge_apply_link_suffix_rules($dispatchMaxHtml, $linkRules, $repostSourceUrl);
          $dispatchMaxHtml = trim((string)($suffixApplyHtml['text'] ?? $dispatchMaxHtml));
        }
      }

      foreach ($routes as $route) {
        $routeId = (int)($route['id'] ?? 0);
        $targetPlatform = channel_bridge_norm_platform((string)($route['target_platform'] ?? ''));
        $targetChatId = channel_bridge_norm_chat_id((string)($route['target_chat_id'] ?? ''));
        $blacklistMatch = channel_bridge_route_blacklist_match(
          (string)($route['blacklist_domains'] ?? ''),
          $messageText,
          (array)($payload['tg_text_link_urls'] ?? [])
        );
        if (($blacklistMatch['blocked'] ?? false) === true) {
          $skipped++;

          channel_bridge_log_dispatch($pdo, [
            'route_id' => $routeId,
            'source_platform' => $sourcePlatform,
            'source_chat_id' => $sourceChatId,
            'source_message_id' => $sourceMessageId,
            'target_platform' => $targetPlatform,
            'target_chat_id' => $targetChatId,
            'message_text' => $dispatchText,
            'send_status' => 'skipped',
            'error_text' => 'blocked_substring: ' . implode(', ', (array)($blacklistMatch['matched_rules'] ?? [])),
            'response_raw' => json_encode([
              'reason' => 'blocked_substring',
              'matched_rules' => (array)($blacklistMatch['matched_rules'] ?? []),
              'post_urls' => (array)($blacklistMatch['post_urls'] ?? []),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
          ]);

          continue;
        }

        $send = channel_bridge_send_by_route($settings, $route, $dispatchText, [
          'source_platform' => $sourcePlatform,
          'source_chat_id' => $sourceChatId,
          'source_message_id' => $sourceMessageId,
          'tg_media_group_id' => trim((string)($payload['tg_media_group_id'] ?? '')),
          'tg_media_group_message_ids' => (array)($payload['tg_media_group_message_ids'] ?? []),
          'tg_photo_file_ids' => (array)($payload['tg_photo_file_ids'] ?? []),
          'tg_photo_file_id' => trim((string)($payload['tg_photo_file_id'] ?? '')),
          'tg_text_link_urls' => (array)($payload['tg_text_link_urls'] ?? []),
          'max_text_html' => $dispatchMaxHtml,
          'tg_public_post_url' => (string)($payload['tg_public_post_url'] ?? ''),
          'tg_chat_username' => (string)($payload['tg_chat_username'] ?? ''),
        ]);
        $ok = (($send['ok'] ?? false) === true);
        if ($ok) $sent++; else $failed++;

        channel_bridge_log_dispatch($pdo, [
          'route_id' => $routeId,
          'source_platform' => $sourcePlatform,
          'source_chat_id' => $sourceChatId,
          'source_message_id' => $sourceMessageId,
          'target_platform' => $targetPlatform,
          'target_chat_id' => $targetChatId,
          'message_text' => $dispatchText,
          'send_status' => $ok ? 'sent' : 'failed',
          'error_text' => trim((string)($send['error'] ?? '')),
          'response_raw' => $send['raw'] ?? '',
        ]);
      }

      if ($isAggregatedMediaGroup && $mediaGroupDispatchToken !== '') {
        $markSent = ($failed === 0);
        channel_bridge_media_group_finish_dispatch($payload, $mediaGroupDispatchToken, $markSent);
      }

      return [
        'ok' => true,
        'reason' => 'dispatched',
        'targets' => $targets,
        'sent' => $sent,
        'failed' => $failed,
        'skipped' => $skipped,
        'inbox_id' => $inboxId,
      ];
    } catch (Throwable $e) {
      if ($isAggregatedMediaGroup && $mediaGroupDispatchToken !== '') {
        channel_bridge_media_group_finish_dispatch($payload, $mediaGroupDispatchToken, false);
      }

      throw $e;
    }
  }

  /**
   * channel_bridge_extract_tg_channel_post()
   * Извлекает данные channel_post/edited_channel_post из webhook update Telegram.
   *
   * @param array<string,mixed> $update
   * @return array<string,mixed>
   */
  function channel_bridge_extract_tg_channel_post(array $update): array
  {
    $msg = [];
    if (isset($update['channel_post']) && is_array($update['channel_post'])) {
      $msg = (array)$update['channel_post'];
    } elseif (isset($update['edited_channel_post']) && is_array($update['edited_channel_post'])) {
      $msg = (array)$update['edited_channel_post'];
    }

    if (!$msg) return [];

    $chat = (array)($msg['chat'] ?? []);
    $textRaw = trim((string)($msg['text'] ?? ''));
    $textEntities = $msg['entities'] ?? [];
    if ($textRaw === '') {
      $textRaw = trim((string)($msg['caption'] ?? ''));
      $textEntities = $msg['caption_entities'] ?? [];
    }
    $text = channel_bridge_normalize_text($textRaw);
    $textHtml = channel_bridge_tg_text_links_html($textRaw, $textEntities);

    $extraUrls = array_merge(
      channel_bridge_tg_text_link_urls($msg['entities'] ?? []),
      channel_bridge_tg_text_link_urls($msg['caption_entities'] ?? [])
    );
    $previewUrl = channel_bridge_tg_link_preview_url($msg);
    if ($previewUrl !== '') {
      $extraUrls[] = $previewUrl;
    }
    $extraUrls = array_values(array_unique(array_filter(array_map('trim', $extraUrls), static function ($v) {
      return $v !== '';
    })));

    $publicUrl = channel_bridge_tg_message_public_url($msg);

    $bestPhotoFileId = channel_bridge_tg_best_photo_file_id($msg);
    $mediaGroupId = trim((string)($msg['media_group_id'] ?? ''));
    $messageId = trim((string)($msg['message_id'] ?? ''));

    return [
      'source_platform' => CHANNEL_BRIDGE_SOURCE_TG,
      'source_chat_id' => trim((string)($chat['id'] ?? '')),
      'source_message_id' => $messageId,
      'message_text' => $text,
      'tg_text_html' => $textHtml,
      'tg_media_group_id' => $mediaGroupId,
      'tg_media_group_message_ids' => ($messageId !== '' ? [$messageId] : []),
      'tg_photo_file_ids' => ($bestPhotoFileId !== '' ? [$bestPhotoFileId] : []),
      'tg_photo_file_id' => $bestPhotoFileId,
      'tg_text_link_urls' => $extraUrls,
      'tg_public_post_url' => $publicUrl,
      'tg_chat_username' => trim((string)($chat['username'] ?? '')),
    ];
  }
}


