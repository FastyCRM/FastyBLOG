<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_modal_bot_add.php
 * ROLE: do=modal_bot_add — модалка добавления бота.
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

$csrf = csrf_token();
$pdo = db();
$promoSourceBots = stopbot_bot_promo_source_options($pdo, 0);

$platforms = [
  STOPBOT_PLATFORM_TG => stopbot_t('stopbot.platform_tg'),
  STOPBOT_PLATFORM_MAX => stopbot_t('stopbot.platform_max'),
];

ob_start();
?>
<form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=bot_add')) ?>" class="form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__head">
      <div class="card__title"><?= h(stopbot_t('stopbot.modal_bot_add_title')) ?></div>
      <div class="card__hint muted"><?= h(stopbot_t('stopbot.bot_add_hint')) ?></div>
    </div>
    <div class="card__body" style="display:grid; gap:12px;">
      <label class="field field--stack">
        <span class="field__label"><?= h(stopbot_t('stopbot.field_bot_name')) ?></span>
        <input class="select" style="height:40px;" type="text" name="name" maxlength="120" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(stopbot_t('stopbot.field_platform')) ?></span>
        <select class="select" name="platform" required>
          <?php foreach ($platforms as $code => $label): ?>
            <option value="<?= h($code) ?>"><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(stopbot_t('stopbot.field_promo_source_bot')) ?></span>
        <select class="select" name="promo_source_bot_id">
          <option value="0"><?= h(stopbot_t('stopbot.promo_source_self')) ?></option>
          <?php foreach ($promoSourceBots as $sourceBot): ?>
            <?php
              $sourceBotId = (int)($sourceBot['id'] ?? 0);
              $sourceBotName = trim((string)($sourceBot['name'] ?? ''));
              $sourcePlatform = (string)($sourceBot['platform'] ?? '');
              $sourcePlatformLabel = ($sourcePlatform === STOPBOT_PLATFORM_MAX)
                ? stopbot_t('stopbot.platform_max')
                : stopbot_t('stopbot.platform_tg');
            ?>
            <option value="<?= (int)$sourceBotId ?>">
              <?= h($sourceBotName !== '' ? $sourceBotName : ('#' . $sourceBotId)) ?> (<?= h($sourcePlatformLabel) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="field" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" name="enabled" value="1" checked>
        <span><?= h(stopbot_t('stopbot.field_enabled')) ?></span>
      </label>

      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <button class="btn btn--accent" type="submit"><?= h(stopbot_t('stopbot.action_save')) ?></button>
      </div>
    </div>
  </div>
</form>
<?php
$html = ob_get_clean();

json_ok([
  'title' => stopbot_t('stopbot.modal_bot_add_title'),
  'html' => $html,
]);
