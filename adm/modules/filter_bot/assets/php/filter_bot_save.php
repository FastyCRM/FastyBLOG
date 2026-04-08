<?php
/**
 * FILE: /adm/modules/filter_bot/assets/php/filter_bot_save.php
 * ROLE: Сохранение настроек filter_bot.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/filter_bot_lib.php';

acl_guard(module_allowed_roles(FILTER_BOT_MODULE_CODE));

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
  http_405('Method Not Allowed');
}

csrf_check((string)($_POST['csrf'] ?? ''));

$pdo = db();

try {
  filter_bot_require_schema($pdo);

  $saved = filter_bot_settings_save($pdo, [
    'enabled' => (int)($_POST['enabled'] ?? 0),
    'log_enabled' => (int)($_POST['log_enabled'] ?? 0),
    'tg_enabled' => (int)($_POST['tg_enabled'] ?? 0),
    'tg_bot_token' => (string)($_POST['tg_bot_token'] ?? ''),
    'tg_webhook_secret' => (string)($_POST['tg_webhook_secret'] ?? ''),
    'tg_allow_private' => (int)($_POST['tg_allow_private'] ?? 0),
    'tg_skip_admins' => (int)($_POST['tg_skip_admins'] ?? 0),
    'max_enabled' => (int)($_POST['max_enabled'] ?? 0),
    'max_api_key' => (string)($_POST['max_api_key'] ?? ''),
    'max_base_url' => (string)($_POST['max_base_url'] ?? ''),
    'max_skip_admins' => (int)($_POST['max_skip_admins'] ?? 0),
    'warn_badword_text' => (string)($_POST['warn_badword_text'] ?? ''),
    'warn_link_text' => (string)($_POST['warn_link_text'] ?? ''),
    'max_warn_badword_text' => (string)($_POST['max_warn_badword_text'] ?? ''),
    'max_warn_link_text' => (string)($_POST['max_warn_link_text'] ?? ''),
    'badwords_list' => (string)($_POST['badwords_list'] ?? ''),
    'allowed_domains_list' => (string)($_POST['allowed_domains_list'] ?? ''),
  ]);

  $flashParts = ['Настройки filter_bot сохранены.'];

  if ((int)($_POST['apply_tg_webhook'] ?? 0) === 1) {
    $apply = filter_bot_tg_apply_webhook($saved);
    if (($apply['ok'] ?? false) === true) {
      $flashParts[] = 'Webhook Telegram обновлён.';
    } else {
      $flashParts[] = 'Webhook Telegram не обновлён: ' . trim((string)($apply['error'] ?? 'UNKNOWN'));
    }
  }

  if ((int)($saved['max_enabled'] ?? 0) === 1 && trim((string)($saved['max_api_key'] ?? '')) !== '') {
    $sub = filter_bot_max_ensure_subscription($saved, filter_bot_max_webhook_url(true));
    if (($sub['ok'] ?? false) === true) {
      $flashParts[] = ((int)($sub['created'] ?? 0) === 1) ? 'Подписка MAX создана.' : 'Подписка MAX подтверждена.';
    } else {
      $flashParts[] = 'Подписка MAX не применена: ' . trim((string)($sub['error'] ?? 'UNKNOWN'));
    }

    $chatIds = filter_bot_channels_enabled_ids($pdo, FILTER_BOT_PLATFORM_MAX);
    if ($chatIds) {
      $access = filter_bot_max_check_chat_access($saved, $chatIds);
      $warn = [];
      if (!empty($access['not_admin'])) {
        $warn[] = 'бот не админ в чатах: ' . implode(', ', (array)$access['not_admin']);
      }
      if (!empty($access['no_edit_perm'])) {
        $warn[] = 'нет прав на редактирование: ' . implode(', ', (array)$access['no_edit_perm']);
      }
      if (!empty($access['errors'])) {
        $parts = [];
        foreach ((array)$access['errors'] as $item) {
          if (!is_array($item)) continue;
          $parts[] = (string)($item['chat_id'] ?? '?') . ' (' . (string)($item['error'] ?? 'error') . ')';
        }
        if ($parts) $warn[] = 'ошибки проверки MAX: ' . implode('; ', $parts);
      }
      if ($warn) {
        flash('MAX: ' . implode(' | ', $warn), 'danger', 1);
      }
    }
  }

  audit_log(FILTER_BOT_MODULE_CODE, 'settings_save', 'info', [
    'enabled' => (int)($saved['enabled'] ?? 0),
    'tg_enabled' => (int)($saved['tg_enabled'] ?? 0),
    'max_enabled' => (int)($saved['max_enabled'] ?? 0),
  ]);

  flash(implode(' ', $flashParts), 'ok');
} catch (Throwable $e) {
  audit_log(FILTER_BOT_MODULE_CODE, 'settings_save', 'error', [
    'error' => $e->getMessage(),
  ]);
  flash('Ошибка сохранения filter_bot: ' . $e->getMessage(), 'danger', 1);
}

redirect('/adm/index.php?m=' . FILTER_BOT_MODULE_CODE);
