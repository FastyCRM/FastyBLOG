<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_modal_bot_update.php
 * ROLE: do=modal_bot_update — модалка редактирования бота.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/stopbot_lib.php';

acl_guard(module_allowed_roles(STOPBOT_MODULE_CODE));

$roles = function_exists('auth_user_roles') ? (array)auth_user_roles((int)auth_user_id()) : [];
if (!stopbot_is_manage_role($roles)) {
  json_err(stopbot_t('stopbot.flash_access_denied'), 403);
}

$botId = (int)($_GET['id'] ?? 0);
$pdo = db();
$bot = stopbot_bot_get($pdo, $botId);
if (!$bot) {
  json_err(stopbot_t('stopbot.flash_bot_not_found'), 404);
}

$csrf = csrf_token();
$platform = (string)($bot['platform'] ?? STOPBOT_PLATFORM_TG);
$promoSourceBotId = (int)($bot['promo_source_bot_id'] ?? 0);
$promoSourceBots = stopbot_bot_promo_source_options($pdo, $botId);

if ($promoSourceBotId === $botId) {
  $promoSourceBotId = 0;
}

if ($promoSourceBotId > 0) {
  $knownSource = false;
  foreach ($promoSourceBots as $sourceBotRow) {
    if ((int)($sourceBotRow['id'] ?? 0) === $promoSourceBotId) {
      $knownSource = true;
      break;
    }
  }
  if (!$knownSource) {
    $promoSourceBot = stopbot_bot_get($pdo, $promoSourceBotId);
    if ($promoSourceBot) {
      $promoSourceBots[] = $promoSourceBot;
    } else {
      $promoSourceBotId = 0;
    }
  }
}

$webhookUrl = stopbot_bot_webhook_url($botId, $platform, true);

ob_start();
?>
<form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=bot_update')) ?>" class="form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="id" value="<?= (int)$botId ?>">
  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__head">
      <div class="card__title"><?= h(stopbot_t('stopbot.modal_bot_update_title')) ?></div>
    </div>
    <div class="card__body" style="display:grid; gap:12px;">
      <label class="field field--stack">
        <span class="field__label"><?= h(stopbot_t('stopbot.field_bot_name')) ?></span>
        <input class="select" style="height:40px;" type="text" name="name" maxlength="120" required value="<?= h((string)($bot['name'] ?? '')) ?>">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(stopbot_t('stopbot.field_platform')) ?></span>
        <input class="select input--readonly" style="height:40px;" type="text" readonly value="<?= h($platform === STOPBOT_PLATFORM_MAX ? stopbot_t('stopbot.platform_max') : stopbot_t('stopbot.platform_tg')) ?>">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(stopbot_t('stopbot.field_promo_source_bot')) ?></span>
        <select class="select" name="promo_source_bot_id">
          <option value="0" <?= $promoSourceBotId === 0 ? 'selected' : '' ?>><?= h(stopbot_t('stopbot.promo_source_self')) ?></option>
          <?php foreach ($promoSourceBots as $sourceBot): ?>
            <?php
              $sourceBotId = (int)($sourceBot['id'] ?? 0);
              $sourceBotName = trim((string)($sourceBot['name'] ?? ''));
              $sourcePlatform = (string)($sourceBot['platform'] ?? '');
              $sourcePlatformLabel = ($sourcePlatform === STOPBOT_PLATFORM_MAX)
                ? stopbot_t('stopbot.platform_max')
                : stopbot_t('stopbot.platform_tg');
            ?>
            <option value="<?= (int)$sourceBotId ?>" <?= $sourceBotId === $promoSourceBotId ? 'selected' : '' ?>>
              <?= h($sourceBotName !== '' ? $sourceBotName : ('#' . $sourceBotId)) ?> (<?= h($sourcePlatformLabel) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="field" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" name="enabled" value="1" <?= ((int)($bot['enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
        <span><?= h(stopbot_t('stopbot.field_enabled')) ?></span>
      </label>

      <?php if ($platform === STOPBOT_PLATFORM_TG): ?>
        <label class="field field--stack">
          <span class="field__label"><?= h(stopbot_t('stopbot.field_bot_token')) ?></span>
          <input class="select" style="height:40px;" type="text" name="bot_token" value="<?= h((string)($bot['bot_token'] ?? '')) ?>" autocomplete="off">
        </label>

        <label class="field field--stack">
          <span class="field__label"><?= h(stopbot_t('stopbot.field_webhook_secret')) ?></span>
          <input class="select" style="height:40px;" type="text" name="webhook_secret" value="<?= h((string)($bot['webhook_secret'] ?? '')) ?>" autocomplete="off">
        </label>

        <label class="field field--stack">
          <span class="field__label"><?= h(stopbot_t('stopbot.field_webhook_url')) ?></span>
          <input class="select input--readonly" style="height:40px;" type="text" readonly value="<?= h($webhookUrl) ?>">
        </label>
      <?php else: ?>
        <label class="field field--stack">
          <span class="field__label"><?= h(stopbot_t('stopbot.field_max_api_key')) ?></span>
          <input class="select" style="height:40px;" type="text" name="max_api_key" value="<?= h((string)($bot['max_api_key'] ?? '')) ?>" autocomplete="off">
        </label>

        <label class="field field--stack">
          <span class="field__label"><?= h(stopbot_t('stopbot.field_max_base_url')) ?></span>
          <input class="select" style="height:40px;" type="text" name="max_base_url" value="<?= h((string)($bot['max_base_url'] ?? 'https://platform-api.max.ru')) ?>">
        </label>

        <label class="field field--stack">
          <span class="field__label"><?= h(stopbot_t('stopbot.field_max_send_path')) ?></span>
          <input class="select" style="height:40px;" type="text" name="max_send_path" value="<?= h((string)($bot['max_send_path'] ?? '/messages')) ?>">
        </label>

        <label class="field field--stack">
          <span class="field__label"><?= h(stopbot_t('stopbot.field_webhook_url')) ?></span>
          <input class="select input--readonly" style="height:40px;" type="text" readonly value="<?= h($webhookUrl) ?>">
        </label>
      <?php endif; ?>

      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <button class="btn btn--accent" type="submit"><?= h(stopbot_t('stopbot.action_save')) ?></button>
      </div>
    </div>
  </div>
</form>
<?php
$html = ob_get_clean();

json_ok([
  'title' => stopbot_t('stopbot.modal_bot_update_title'),
  'html' => $html,
]);
