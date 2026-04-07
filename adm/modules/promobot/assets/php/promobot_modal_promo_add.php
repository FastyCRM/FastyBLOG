<?php
/**
 * FILE: /adm/modules/promobot/assets/php/promobot_modal_promo_add.php
 * ROLE: do=modal_promo_add — модалка добавления промокода.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/promobot_lib.php';

acl_guard(module_allowed_roles(PROMOBOT_MODULE_CODE));

$botId = (int)($_GET['bot_id'] ?? 0);
$pdo = db();
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

if (!promobot_user_has_bot_access($pdo, $uid, $botId, $roles)) {
  json_err(promobot_t('promobot.flash_access_denied'), 403);
}

$csrf = csrf_token();

ob_start();
?>
<form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=promo_add')) ?>" class="form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="bot_id" value="<?= (int)$botId ?>">

  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__head">
      <div class="card__title"><?= h(promobot_t('promobot.modal_promo_add_title')) ?></div>
    </div>
    <div class="card__body" style="display:grid; gap:12px;">
      <label class="field field--stack">
        <span class="field__label"><?= h(promobot_t('promobot.field_keywords')) ?></span>
        <input class="select" style="height:40px;" type="text" name="keywords" maxlength="500" placeholder="<?= h(promobot_t('promobot.field_keywords_placeholder')) ?>" required>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(promobot_t('promobot.field_response_text')) ?></span>
        <textarea class="select" name="response_text" rows="6" style="min-height:120px; height:auto; padding:10px;" required></textarea>
      </label>

      <label class="field" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" name="is_active" value="1" checked>
        <span><?= h(promobot_t('promobot.field_active')) ?></span>
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
  'title' => promobot_t('promobot.modal_promo_add_title'),
  'html' => $html,
]);
