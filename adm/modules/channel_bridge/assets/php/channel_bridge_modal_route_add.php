<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_modal_route_add.php
 * ROLE: do=modal_route_add — модалка добавления маршрута копирования.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/channel_bridge_lib.php';

acl_guard(module_allowed_roles(CHANNEL_BRIDGE_MODULE_CODE));

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!channel_bridge_can_manage($roles)) {
  json_err('Forbidden', 403);
}

$csrf = csrf_token();

ob_start();
?>
<div class="card" style="box-shadow:none; border-color:var(--border-soft);">
  <div class="card__body" style="display:grid; gap:12px;">
    <form method="post" action="<?= h(url('/adm/index.php?m=channel_bridge&do=route_add')) ?>" style="display:grid; gap:12px;">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

      <label class="field field--stack">
        <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_route_title')) ?></span>
        <input class="select" type="text" name="title" value="">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_source_platform')) ?></span>
        <select class="select" name="source_platform" data-ui-select="1">
          <option value="tg">Telegram</option>
        </select>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_source_chat_id')) ?></span>
        <input class="select" type="text" name="source_chat_id" value="" placeholder="@source_channel или -100...">
      </label>

      <label class="field" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" name="auto_bind_source" value="1">
        <span><?= h(channel_bridge_t('channel_bridge.field_auto_bind_source')) ?></span>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_target_platform')) ?></span>
        <select class="select" name="target_platform" data-ui-select="1">
          <option value="tg">Telegram</option>
          <option value="vk">VK</option>
          <option value="max">MAX</option>
        </select>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_target_chat_id')) ?></span>
        <input class="select" type="text" name="target_chat_id" value="" placeholder="@target_channel / -123456 / room-id">
      </label>

      <label class="field" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" name="auto_bind_target" value="1">
        <span><?= h(channel_bridge_t('channel_bridge.field_auto_bind_target')) ?></span>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_target_extra')) ?></span>
        <textarea class="select" name="target_extra" rows="3" placeholder='{"thread_id":"42"}'></textarea>
      </label>

      <label class="field" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" name="enabled" value="1" checked>
        <span><?= h(channel_bridge_t('channel_bridge.field_route_enabled')) ?></span>
      </label>

      <button class="btn btn--accent" type="submit"><?= h(channel_bridge_t('channel_bridge.btn_route_add')) ?></button>
    </form>
  </div>
</div>
<?php
$html = (string)ob_get_clean();

json_ok([
  'title' => channel_bridge_t('channel_bridge.modal_route_add_title'),
  'html' => $html,
]);
