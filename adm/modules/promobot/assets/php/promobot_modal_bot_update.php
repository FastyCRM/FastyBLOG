<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_modal_bot_update.php
 * ROLE: do=modal_bot_update — модалка редактирования бота.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/promobot_lib.php';

acl_guard(module_allowed_roles(PROMOBOT_MODULE_CODE));

$roles = function_exists('auth_user_roles') ? (array)auth_user_roles((int)auth_user_id()) : [];
if (!promobot_is_manage_role($roles)) {
  json_err(promobot_t('promobot.flash_access_denied'), 403);
}

$botId = (int)($_GET['id'] ?? 0);
$bot = promobot_bot_get(db(), $botId);
if (!$bot) {
  json_err(promobot_t('promobot.flash_bot_not_found'), 404);
}

$csrf = csrf_token();
$platform = (string)($bot['platform'] ?? PROMOBOT_PLATFORM_TG);

$webhookUrl = promobot_bot_webhook_url($botId, $platform, true);

ob_start();
?>
<form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=bot_update')) ?>" class="form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="id" value="<?= (int)$botId ?>">
  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__head">
      <div class="card__title"><?= h(promobot_t('promobot.modal_bot_update_title')) ?></div>
    </div>
    <div class="card__body" style="display:grid; gap:12px;">
      <label class="field field--stack">
        <span class="field__label"><?= h(promobot_t('promobot.field_bot_name')) ?></span>
        <input class="select" style="height:40px;" type="text" name="name" maxlength="120" required value="<?= h((string)($bot['name'] ?? '')) ?>">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(promobot_t('promobot.field_platform')) ?></span>
        <input class="select input--readonly" style="height:40px;" type="text" readonly value="<?= h($platform === PROMOBOT_PLATFORM_MAX ? promobot_t('promobot.platform_max') : promobot_t('promobot.platform_tg')) ?>">
      </label>

      <label class="field" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" name="enabled" value="1" <?= ((int)($bot['enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
        <span><?= h(promobot_t('promobot.field_enabled')) ?></span>
      </label>

      <?php if ($platform === PROMOBOT_PLATFORM_TG): ?>
        <label class="field field--stack">
          <span class="field__label"><?= h(promobot_t('promobot.field_bot_token')) ?></span>
          <input class="select" style="height:40px;" type="text" name="bot_token" value="<?= h((string)($bot['bot_token'] ?? '')) ?>" autocomplete="off">
        </label>

        <label class="field field--stack">
          <span class="field__label"><?= h(promobot_t('promobot.field_webhook_secret')) ?></span>
          <input class="select" style="height:40px;" type="text" name="webhook_secret" value="<?= h((string)($bot['webhook_secret'] ?? '')) ?>" autocomplete="off">
        </label>

        <label class="field field--stack">
          <span class="field__label"><?= h(promobot_t('promobot.field_webhook_url')) ?></span>
          <input class="select input--readonly" style="height:40px;" type="text" readonly value="<?= h($webhookUrl) ?>">
        </label>
      <?php else: ?>
        <label class="field field--stack">
          <span class="field__label"><?= h(promobot_t('promobot.field_max_api_key')) ?></span>
          <input class="select" style="height:40px;" type="text" name="max_api_key" value="<?= h((string)($bot['max_api_key'] ?? '')) ?>" autocomplete="off">
        </label>

        <label class="field field--stack">
          <span class="field__label"><?= h(promobot_t('promobot.field_max_base_url')) ?></span>
          <input class="select" style="height:40px;" type="text" name="max_base_url" value="<?= h((string)($bot['max_base_url'] ?? 'https://platform-api.max.ru')) ?>">
        </label>

        <label class="field field--stack">
          <span class="field__label"><?= h(promobot_t('promobot.field_max_send_path')) ?></span>
          <input class="select" style="height:40px;" type="text" name="max_send_path" value="<?= h((string)($bot['max_send_path'] ?? '/messages')) ?>">
        </label>

        <label class="field field--stack">
          <span class="field__label"><?= h(promobot_t('promobot.field_webhook_url')) ?></span>
          <input class="select input--readonly" style="height:40px;" type="text" readonly value="<?= h($webhookUrl) ?>">
        </label>
      <?php endif; ?>

      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <button class="btn btn--accent" type="submit"><?= h(promobot_t('promobot.action_save')) ?></button>
      </div>
    </div>
  </div>
</form>
<?php
$html = ob_get_clean();

json_ok([
  'title' => promobot_t('promobot.modal_bot_update_title'),
  'html' => $html,
]);
