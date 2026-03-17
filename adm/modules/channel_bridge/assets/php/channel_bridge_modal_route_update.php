<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_modal_route_update.php
 * ROLE: do=modal_route_update — модалка редактирования маршрута.
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

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  json_err(channel_bridge_t('channel_bridge.error_bad_id'), 400);
}

$pdo = db();
$route = channel_bridge_route_find($pdo, $id);
if (!$route) {
  json_err(channel_bridge_t('channel_bridge.error_route_not_found'), 404);
}

$csrf = csrf_token();
$sourcePlatform = (string)($route['source_platform'] ?? 'tg');
$targetPlatform = (string)($route['target_platform'] ?? 'tg');

ob_start();
?>
<div class="card" style="box-shadow:none; border-color:var(--border-soft);">
  <div class="card__body" style="display:grid; gap:12px;">
    <form method="post" action="<?= h(url('/adm/index.php?m=channel_bridge&do=route_update')) ?>" style="display:grid; gap:12px;">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="<?= (int)$id ?>">

      <label class="field field--stack">
        <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_route_title')) ?></span>
        <input class="select" type="text" name="title" value="<?= h((string)($route['title'] ?? '')) ?>">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_source_platform')) ?></span>
        <select class="select" name="source_platform" data-ui-select="1">
          <option value="tg" <?= $sourcePlatform === 'tg' ? 'selected' : '' ?>>Telegram</option>
        </select>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_source_chat_id')) ?></span>
        <input class="select" type="text" name="source_chat_id" value="<?= h((string)($route['source_chat_id'] ?? '')) ?>">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_target_platform')) ?></span>
        <select class="select" name="target_platform" data-ui-select="1">
          <option value="tg" <?= $targetPlatform === 'tg' ? 'selected' : '' ?>>Telegram</option>
          <option value="vk" <?= $targetPlatform === 'vk' ? 'selected' : '' ?>>VK</option>
          <option value="max" <?= $targetPlatform === 'max' ? 'selected' : '' ?>>MAX</option>
        </select>
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_target_chat_id')) ?></span>
        <input class="select" type="text" name="target_chat_id" value="<?= h((string)($route['target_chat_id'] ?? '')) ?>">
      </label>

      <label class="field field--stack">
        <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_target_extra')) ?></span>
        <textarea class="select" name="target_extra" rows="3"><?= h((string)($route['target_extra'] ?? '')) ?></textarea>
      </label>

      <label class="field" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" name="enabled" value="1" <?= ((int)($route['enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
        <span><?= h(channel_bridge_t('channel_bridge.field_route_enabled')) ?></span>
      </label>

      <button class="btn btn--accent" type="submit"><?= h(channel_bridge_t('channel_bridge.btn_route_update')) ?></button>
    </form>
  </div>
</div>
<?php
$html = (string)ob_get_clean();

json_ok([
  'title' => channel_bridge_t('channel_bridge.modal_route_update_title'),
  'html' => $html,
]);

