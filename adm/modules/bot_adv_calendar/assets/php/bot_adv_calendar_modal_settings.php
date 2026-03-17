<?php
/**
 * FILE: /adm/modules/bot_adv_calendar/assets/php/bot_adv_calendar_modal_settings.php
 * ROLE: modal_settings модуля bot_adv_calendar
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/bot_adv_calendar_lib.php';

acl_guard(module_allowed_roles('bot_adv_calendar'));

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!bot_adv_calendar_is_manage_role($roles)) {
  json_err('Forbidden', 403);
}

$pdo = db();
$settings = bot_adv_calendar_settings_get($pdo);
$csrf = csrf_token();

$defaultWebhookUrl = (string)url('/adm/modules/bot_adv_calendar/webhook.php');
if (trim((string)($settings['webhook_url'] ?? '')) === '') {
  $settings['webhook_url'] = $defaultWebhookUrl;
}

ob_start();
?>
<form method="post" action="<?= h(url('/adm/index.php?m=bot_adv_calendar&do=settings_update')) ?>" class="form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <div class="card" style="box-shadow:none; border-color:var(--border-soft);">
    <div class="card__head">
      <div class="card__title">Настройки бота</div>
      <div class="card__hint muted">Webhook endpoint: <span class="mono"><?= h($defaultWebhookUrl) ?></span></div>
    </div>
    <div class="card__body" style="display:grid; gap:12px;">
      <label class="field" style="display:flex; align-items:center; gap:8px;">
        <input type="checkbox" name="enabled" value="1" <?= ((int)($settings['enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
        <span>Включить бота</span>
      </label>

      <label class="field field--stack">
        <span class="field__label">Bot token</span>
        <input class="input" name="bot_token" value="<?= h((string)($settings['bot_token'] ?? '')) ?>" autocomplete="off">
      </label>

      <label class="field field--stack">
        <span class="field__label">Webhook URL</span>
        <input class="input" name="webhook_url" value="<?= h((string)($settings['webhook_url'] ?? '')) ?>" placeholder="<?= h($defaultWebhookUrl) ?>" autocomplete="off">
      </label>

      <label class="field field--stack">
        <span class="field__label">Webhook secret</span>
        <input class="input" name="webhook_secret" value="<?= h((string)($settings['webhook_secret'] ?? '')) ?>" autocomplete="off">
      </label>

      <div style="display:grid; gap:12px; grid-template-columns:repeat(2,minmax(120px,1fr));">
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
        <input type="checkbox" name="apply_webhook" value="1">
        <span>После сохранения сразу применить webhook</span>
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
  'title' => 'Настройки Bot Adv Calendar',
  'html' => $html,
]);

