<?php
/**
 * FILE: /adm/modules/stopbot/assets/php/stopbot_modal_promo_update.php
 * ROLE: do=modal_promo_update — модалка редактирования промокода.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/stopbot_lib.php';

acl_guard(module_allowed_roles(STOPBOT_MODULE_CODE));

$promoId = (int)($_GET['id'] ?? 0);
$contextBotId = (int)($_GET['bot_id'] ?? 0);
$pdo = db();
$promo = stopbot_promo_get($pdo, $promoId);
if (!$promo) {
  json_err(stopbot_t('stopbot.flash_promo_not_found'), 404);
}

$botId = (int)($promo['bot_id'] ?? 0);
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

if ($contextBotId > 0) {
  if (!stopbot_promo_belongs_to_context($pdo, $promo, $contextBotId)) {
    json_err(stopbot_t('stopbot.flash_promo_not_found'), 404);
  }
  if (!stopbot_user_has_bot_access($pdo, $uid, $contextBotId, $roles)) {
    json_err(stopbot_t('stopbot.flash_access_denied'), 403);
  }
} elseif (!stopbot_user_has_bot_access($pdo, $uid, $botId, $roles)) {
  json_err(stopbot_t('stopbot.flash_access_denied'), 403);
}

$csrf = csrf_token();

ob_start();
?>
<form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=promo_update')) ?>" class="form">
  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
  <input type="hidden" name="id" value="<?= (int)$promoId ?>">
  <input type="hidden" name="bot_id" value="<?= (int)($contextBotId > 0 ? $contextBotId : $botId) ?>">

  <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
    <div class="card__head">
      <div class="card__title"><?= h(stopbot_t('stopbot.modal_promo_update_title')) ?></div>
    </div>
    <div class="card__body" style="display:grid; gap:12px;">
      <label class="field field--stack">
        <span class="field__label"><?= h(stopbot_t('stopbot.field_keywords')) ?></span>
        <input class="select" style="height:40px;" type="text" name="keywords" maxlength="500" required value="<?= h((string)($promo['keywords'] ?? '')) ?>">
      </label>

      <label class="field" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" name="is_active" value="1" <?= ((int)($promo['is_active'] ?? 0) === 1) ? 'checked' : '' ?>>
        <span><?= h(stopbot_t('stopbot.field_active')) ?></span>
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
  'title' => stopbot_t('stopbot.modal_promo_update_title'),
  'html' => $html,
]);
