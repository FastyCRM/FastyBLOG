<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_modal_bot_add.php
 * ROLE: do=modal_bot_add — модалка добавления бота.
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

$csrf = csrf_token();

$platforms = [
  PROMOBOT_PLATFORM_TG => promobot_t('promobot.platform_tg'),
  PROMOBOT_PLATFORM_MAX => promobot_t('promobot.platform_max'),
];

ob_start();
?>
<form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=bot_add')) ?>" class="form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__head">
      <div class="card__title"><?= h(promobot_t('promobot.modal_bot_add_title')) ?></div>
      <div class="card__hint muted"><?= h(promobot_t('promobot.bot_add_hint')) ?></div>
    </div>
    <div class="card__body" style="display:grid; gap:12px;">
      <label class="field field--stack">
        <span class="field__label"><?= h(promobot_t('promobot.field_bot_name')) ?></span>
        <input class="select" style="height:40px;" type="text" name="name" maxlength="120" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(promobot_t('promobot.field_platform')) ?></span>
        <select class="select" name="platform" required>
          <?php foreach ($platforms as $code => $label): ?>
            <option value="<?= h($code) ?>"><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="field" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" name="enabled" value="1" checked>
        <span><?= h(promobot_t('promobot.field_enabled')) ?></span>
      </label>

      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <button class="btn btn--accent" type="submit"><?= h(promobot_t('promobot.action_save')) ?></button>
      </div>
    </div>
  </div>
</form>
<?php
$html = ob_get_clean();

json_ok([
  'title' => promobot_t('promobot.modal_bot_add_title'),
  'html' => $html,
]);
