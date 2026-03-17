<?php
/**
 * FILE: /adm/modules/channel_bridge/assets/php/channel_bridge_modal_settings.php
 * ROLE: do=modal_settings — модальное окно настроек модуля + кнопка "Создать БД".
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

$pdo = db();
$settings = channel_bridge_settings_defaults();
$linkSuffixRules = [];

try {
  $settings = channel_bridge_settings_get($pdo);
  $linkSuffixRules = channel_bridge_link_suffix_rules_list($pdo, false);
} catch (Throwable $e) {
  /**
   * При отсутствии таблиц модалка всё равно должна открываться,
   * чтобы пользователь мог нажать "Создать БД".
   */
}

$csrf = csrf_token();
$publicWebhookUrl = channel_bridge_webhook_endpoint_url(true);
$autoApplyWebhookDefault = (trim((string)($settings['tg_bot_token'] ?? '')) !== '');
$tgProbeUrl = url('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE . '&do=tg_probe');
$maxProbeUrl = url('/adm/index.php?m=' . CHANNEL_BRIDGE_MODULE_CODE . '&do=max_probe');

ob_start();
?>
<div class="card" style="box-shadow:none; border-color:var(--border-soft);">
  <div class="card__head">
    <div class="card__title"><?= h(channel_bridge_t('channel_bridge.modal_settings_title')) ?></div>
    <div class="card__hint muted"><?= h(channel_bridge_t('channel_bridge.modal_settings_hint')) ?></div>
  </div>

  <div class="card__body" style="display:grid; gap:14px;">
    <form method="post" action="<?= h(url('/adm/index.php?m=channel_bridge&do=settings_update')) ?>" class="form" style="display:grid; gap:14px;">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

      <label class="field" style="display:flex; gap:8px; align-items:center;">
        <input type="checkbox" name="enabled" value="1" <?= ((int)($settings['enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
        <span><?= h(channel_bridge_t('channel_bridge.field_enabled')) ?></span>
      </label>

      <div class="card" style="box-shadow:none; border-color:var(--border-soft);">
        <div class="card__head">
          <div class="card__title">Telegram</div>
        </div>
        <div class="card__body" style="display:grid; gap:10px;">
          <label class="field" style="display:flex; gap:8px; align-items:center;">
            <input type="checkbox" name="tg_enabled" value="1" <?= ((int)($settings['tg_enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
            <span><?= h(channel_bridge_t('channel_bridge.field_tg_enabled')) ?></span>
          </label>

          <label class="field field--stack">
            <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_tg_bot_token')) ?></span>
            <input class="select" type="text" name="tg_bot_token" value="<?= h((string)($settings['tg_bot_token'] ?? '')) ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_tg_webhook_secret')) ?></span>
            <input class="select" type="text" name="tg_webhook_secret" value="<?= h((string)($settings['tg_webhook_secret'] ?? '')) ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_tg_parse_mode')) ?></span>
            <select class="select" name="tg_parse_mode" data-ui-select="1">
              <?php
                $pm = (string)($settings['tg_parse_mode'] ?? 'HTML');
                if (!in_array($pm, ['HTML', 'Markdown', 'MarkdownV2', ''], true)) $pm = 'HTML';
              ?>
              <option value="" <?= $pm === '' ? 'selected' : '' ?>>none</option>
              <option value="HTML" <?= $pm === 'HTML' ? 'selected' : '' ?>>HTML</option>
              <option value="Markdown" <?= $pm === 'Markdown' ? 'selected' : '' ?>>Markdown</option>
              <option value="MarkdownV2" <?= $pm === 'MarkdownV2' ? 'selected' : '' ?>>MarkdownV2</option>
            </select>
          </label>

          <label class="field field--stack">
            <span class="field__label"><?= h(channel_bridge_t('channel_bridge.webhook_expected_url')) ?></span>
            <pre class="cb-code" style="margin:0;"><?= h($publicWebhookUrl) ?></pre>
          </label>

          <label class="field" style="display:flex; gap:8px; align-items:center;">
            <input type="checkbox" name="apply_webhook" value="1" <?= $autoApplyWebhookDefault ? 'checked' : '' ?>>
            <span><?= h(channel_bridge_t('channel_bridge.field_apply_webhook')) ?></span>
          </label>
          <div class="muted" style="margin-top:-4px;"><?= h(channel_bridge_t('channel_bridge.field_apply_webhook_hint')) ?></div>

          <div style="display:grid; gap:8px; margin-top:4px;">
            <button class="btn"
                    type="button"
                    data-cb-tg-probe="1"
                    data-cb-tg-probe-url="<?= h($tgProbeUrl) ?>"
                    data-csrf="<?= h($csrf) ?>">
              <?= h(channel_bridge_t('channel_bridge.btn_tg_probe')) ?>
            </button>
            <div class="muted"><?= h(channel_bridge_t('channel_bridge.tg_probe_hint')) ?></div>
            <div class="tablewrap" data-cb-tg-probe-result="1">
              <div class="muted"><?= h(channel_bridge_t('channel_bridge.tg_probe_idle')) ?></div>
            </div>
          </div>
        </div>
      </div>

      <div class="card" style="box-shadow:none; border-color:var(--border-soft);">
        <div class="card__head">
          <div class="card__title">VK</div>
        </div>
        <div class="card__body" style="display:grid; gap:10px;">
          <label class="field" style="display:flex; gap:8px; align-items:center;">
            <input type="checkbox" name="vk_enabled" value="1" <?= ((int)($settings['vk_enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
            <span><?= h(channel_bridge_t('channel_bridge.field_vk_enabled')) ?></span>
          </label>

          <label class="field field--stack">
            <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_vk_group_token')) ?></span>
            <input class="select" type="text" name="vk_group_token" value="<?= h((string)($settings['vk_group_token'] ?? '')) ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_vk_owner_id')) ?></span>
            <input class="select" type="text" name="vk_owner_id" value="<?= h((string)($settings['vk_owner_id'] ?? '')) ?>" placeholder="-123456">
          </label>

          <label class="field field--stack">
            <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_vk_api_version')) ?></span>
            <input class="select" type="text" name="vk_api_version" value="<?= h((string)($settings['vk_api_version'] ?? '5.199')) ?>">
          </label>
        </div>
      </div>

      <div class="card" style="box-shadow:none; border-color:var(--border-soft);">
        <div class="card__head">
          <div class="card__title">MAX</div>
        </div>
        <div class="card__body" style="display:grid; gap:10px;">
          <label class="field" style="display:flex; gap:8px; align-items:center;">
            <input type="checkbox" name="max_enabled" value="1" <?= ((int)($settings['max_enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
            <span><?= h(channel_bridge_t('channel_bridge.field_max_enabled')) ?></span>
          </label>

          <label class="field field--stack">
            <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_max_api_key')) ?></span>
            <input class="select" type="text" name="max_api_key" value="<?= h((string)($settings['max_api_key'] ?? '')) ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_max_base_url')) ?></span>
            <input class="select" type="text" name="max_base_url" value="<?= h((string)($settings['max_base_url'] ?? 'https://platform-api.max.ru')) ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label"><?= h(channel_bridge_t('channel_bridge.field_max_send_path')) ?></span>
            <input class="select" type="text" name="max_send_path" value="<?= h((string)($settings['max_send_path'] ?? '/messages')) ?>">
          </label>

          <div style="display:grid; gap:8px; margin-top:4px;">
            <button class="btn"
                    type="button"
                    data-cb-max-probe="1"
                    data-cb-max-probe-url="<?= h($maxProbeUrl) ?>"
                    data-csrf="<?= h($csrf) ?>">
              <?= h(channel_bridge_t('channel_bridge.btn_max_probe')) ?>
            </button>
            <div class="muted"><?= h(channel_bridge_t('channel_bridge.max_probe_hint')) ?></div>
            <div class="tablewrap" data-cb-max-probe-result="1">
              <div class="muted"><?= h(channel_bridge_t('channel_bridge.max_probe_idle')) ?></div>
            </div>
          </div>
        </div>
      </div>

      <div class="card" style="box-shadow:none; border-color:var(--border-soft);">
        <div class="card__head">
          <div class="card__title"><?= h(channel_bridge_t('channel_bridge.link_suffix_title')) ?></div>
        </div>
        <div class="card__body" style="display:grid; gap:10px;">
          <div class="muted"><?= h(channel_bridge_t('channel_bridge.link_suffix_hint')) ?></div>

          <div class="tablewrap">
            <table class="table">
              <thead>
                <tr>
                  <th><?= h(channel_bridge_t('channel_bridge.link_suffix_col_domain')) ?></th>
                  <th><?= h(channel_bridge_t('channel_bridge.link_suffix_col_enabled')) ?></th>
                  <th><?= h(channel_bridge_t('channel_bridge.link_suffix_col_sort')) ?></th>
                  <th></th>
                </tr>
              </thead>
              <tbody data-cb-link-rules-body="1">
                <?php foreach ($linkSuffixRules as $rule): ?>
                  <tr data-cb-link-rule-row="1">
                    <td>
                      <input class="select" type="text" name="rule_domain_root[]" value="<?= h((string)($rule['domain_root'] ?? '')) ?>" placeholder="prfl.me">
                    </td>
                    <td style="width:120px;">
                      <select class="select" name="rule_enabled[]" data-ui-select="1">
                        <option value="1" <?= ((int)($rule['enabled'] ?? 0) === 1) ? 'selected' : '' ?>>ON</option>
                        <option value="0" <?= ((int)($rule['enabled'] ?? 0) === 1) ? '' : 'selected' ?>>OFF</option>
                      </select>
                    </td>
                    <td style="width:110px;">
                      <input class="select" type="number" name="rule_sort[]" value="<?= h((string)($rule['sort'] ?? 100)) ?>">
                    </td>
                    <td style="width:72px;" class="t-right">
                      <button class="btn" type="button" data-cb-rule-remove="1"><?= h(channel_bridge_t('channel_bridge.link_suffix_btn_remove')) ?></button>
                    </td>
                  </tr>
                <?php endforeach; ?>

                <tr data-cb-link-rule-row="1">
                  <td>
                    <input class="select" type="text" name="rule_domain_root[]" value="" placeholder="prfl.me">
                  </td>
                  <td style="width:120px;">
                    <select class="select" name="rule_enabled[]" data-ui-select="1">
                      <option value="1" selected>ON</option>
                      <option value="0">OFF</option>
                    </select>
                  </td>
                  <td style="width:110px;">
                    <input class="select" type="number" name="rule_sort[]" value="100">
                  </td>
                  <td style="width:72px;" class="t-right">
                    <button class="btn" type="button" data-cb-rule-remove="1"><?= h(channel_bridge_t('channel_bridge.link_suffix_btn_remove')) ?></button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <button class="btn" type="button" data-cb-rule-add="1"><?= h(channel_bridge_t('channel_bridge.link_suffix_btn_add')) ?></button>
          <div class="muted"><?= h(channel_bridge_t('channel_bridge.link_suffix_hint_extra')) ?></div>
        </div>
      </div>

      <button class="btn btn--accent" type="submit"><?= h(channel_bridge_t('channel_bridge.btn_save_settings')) ?></button>
    </form>

    <form method="post" action="<?= h(url('/adm/index.php?m=channel_bridge&do=install_db')) ?>" class="form">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <button class="btn" type="submit"><?= h(channel_bridge_t('channel_bridge.btn_install_db')) ?></button>
      <div class="muted" style="margin-top:6px;"><?= h(channel_bridge_t('channel_bridge.install_db_hint')) ?></div>
    </form>
  </div>
</div>
<?php
$html = (string)ob_get_clean();

json_ok([
  'title' => channel_bridge_t('channel_bridge.modal_settings_title'),
  'html' => $html,
]);
