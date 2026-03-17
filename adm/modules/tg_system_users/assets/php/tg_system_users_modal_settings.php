<?php
/**
 * FILE: /adm/modules/tg_system_users/assets/php/tg_system_users_modal_settings.php
 * ROLE: modal_settings — модалка настроек Telegram-бота
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/tg_system_users_lib.php';

acl_guard(module_allowed_roles('tg_system_users'));

/**
 * $uid — текущий пользователь.
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
/**
 * $roles — роли текущего пользователя.
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

if (!tg_system_users_is_manage_role($roles)) {
  json_err('Forbidden', 403);
}

/**
 * $pdo — соединение с БД.
 */
$pdo = db();

/**
 * $settings — текущие настройки модуля.
 */
$settings = tg_system_users_settings_get($pdo);

/**
 * $csrf — CSRF токен.
 */
$csrf = csrf_token();

/**
 * $scheme — http/https для подсказки webhook URL.
 */
$scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
/**
 * $host — текущий host.
 */
$host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
/**
 * $defaultWebhook — рекомендуемый webhook endpoint.
 */
$defaultWebhook = ($host !== '')
  ? ($scheme . '://' . $host . url('/core/telegram_webhook_system_users.php'))
  : url('/core/telegram_webhook_system_users.php');

ob_start();
?>
<form method="post" action="<?= h(url('/adm/index.php?m=tg_system_users&do=settings_update')) ?>" class="form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

  <div class="card" style="box-shadow:none; border-color:var(--border-soft);">
    <div class="card__head">
      <div class="card__title">Настройки Telegram-бота</div>
      <div class="card__hint muted">Webhook endpoint: <span class="mono"><?= h($defaultWebhook) ?></span></div>
    </div>

    <div class="card__body" style="display:grid; gap:12px;">
      <label class="field" style="display:flex; align-items:center; gap:8px;">
        <input type="checkbox" name="enabled" value="1" <?= ((int)$settings['enabled'] === 1) ? 'checked' : '' ?>>
        <span>Включить системные Telegram-уведомления</span>
      </label>

      <label class="field field--stack">
        <span class="field__label">Bot Token</span>
        <input class="input" name="bot_token" value="<?= h((string)$settings['bot_token']) ?>" autocomplete="off">
      </label>

      <label class="field field--stack">
        <span class="field__label">Webhook URL</span>
        <input class="input" name="webhook_url" value="<?= h((string)$settings['webhook_url']) ?>" placeholder="<?= h($defaultWebhook) ?>">
      </label>

      <label class="field field--stack">
        <span class="field__label">Webhook secret</span>
        <input class="input" name="webhook_secret" value="<?= h((string)$settings['webhook_secret']) ?>" autocomplete="off">
      </label>

      <label class="field field--stack">
        <span class="field__label">Parse mode</span>
        <select class="select" name="default_parse_mode">
          <?php $mode = (string)($settings['default_parse_mode'] ?? 'HTML'); ?>
          <option value="HTML" <?= $mode === 'HTML' ? 'selected' : '' ?>>HTML</option>
          <option value="Markdown" <?= $mode === 'Markdown' ? 'selected' : '' ?>>Markdown</option>
          <option value="MarkdownV2" <?= $mode === 'MarkdownV2' ? 'selected' : '' ?>>MarkdownV2</option>
        </select>
      </label>

      <div style="display:grid; gap:12px; grid-template-columns: repeat(2, minmax(120px, 1fr));">
        <label class="field field--stack">
          <span class="field__label">TTL кода, мин</span>
          <input class="input" type="number" name="token_ttl_minutes" min="1" max="1440" value="<?= (int)($settings['token_ttl_minutes'] ?? 15) ?>">
        </label>

        <label class="field field--stack">
          <span class="field__label">Лог отправок, дней</span>
          <input class="input" type="number" name="retention_days" min="1" max="30" value="<?= (int)($settings['retention_days'] ?? 7) ?>">
        </label>
      </div>

      <label class="field" style="display:flex; align-items:center; gap:8px;">
        <input type="checkbox" name="apply_webhook" value="1" checked>
        <span>После сохранения сразу применить webhook в Telegram</span>
      </label>
    </div>

    <div class="card__foot" style="display:flex; gap:10px; justify-content:flex-end;">
      <button class="btn btn--accent" type="submit">Сохранить</button>
      <button class="btn" type="button" data-modal-close="1">Закрыть</button>
    </div>
  </div>
</form>
<?php
$html = (string)ob_get_clean();

json_ok([
  'title' => 'Настройки TG-бота',
  'html' => $html,
]);
