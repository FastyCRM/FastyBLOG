<?php
/**
 * FILE: /adm/modules/stopbot/stopbot.php
 * ROLE: VIEW модуля stopbot (UI only)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/assets/php/stopbot_lib.php';

acl_guard(module_allowed_roles(STOPBOT_MODULE_CODE));

$pdo = db();
$csrf = csrf_token();

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$isManage = stopbot_is_manage_role($roles);

$missing = stopbot_tables_missing($pdo, STOPBOT_REQUIRED_TABLES);
if ($missing) {
  ?>
  <h1><?= h(stopbot_t('stopbot.page_title')) ?></h1>
  <div class="card">
    <div class="card__head">
      <div class="card__title"><?= h(stopbot_t('stopbot.install_title')) ?></div>
      <div class="card__hint muted">
        <?= h(stopbot_t('stopbot.install_hint')) ?>
        <code>adm/modules/stopbot/install.sql</code>
      </div>
    </div>
    <div class="card__body">
      <?php if ($isManage): ?>
        <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=install_db')) ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <button class="btn btn--accent" type="submit"><?= h(stopbot_t('stopbot.action_install_db')) ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php
  return;
}

$settings = stopbot_settings_get($pdo);
$logEnabled = ((int)($settings['log_enabled'] ?? 1) === 1);

$bots = stopbot_bot_list($pdo, $uid, $roles);
$selectedBotId = (int)($_GET['bot_id'] ?? 0);

if ($selectedBotId <= 0 && $bots) {
  $selectedBotId = (int)($bots[0]['id'] ?? 0);
}

$selectedBot = $selectedBotId > 0 ? stopbot_bot_get($pdo, $selectedBotId) : [];
$selectedPlatform = strtolower(trim((string)($selectedBot['platform'] ?? STOPBOT_PLATFORM_TG)));
if ($selectedPlatform !== STOPBOT_PLATFORM_MAX) {
  $selectedPlatform = STOPBOT_PLATFORM_TG;
}

$selectedPromoOwner = $selectedBotId > 0 ? stopbot_bot_promo_owner($pdo, $selectedBotId) : [];
$selectedPromoOwnerId = (int)($selectedPromoOwner['id'] ?? 0);
if ($selectedPromoOwnerId <= 0) $selectedPromoOwnerId = $selectedBotId;
$isSharedPromoList = ($selectedBotId > 0 && $selectedPromoOwnerId > 0 && $selectedPromoOwnerId !== $selectedBotId);

$promos = $selectedBotId > 0 ? stopbot_promos_list($pdo, $selectedBotId) : [];
$rulesSplit = $selectedBotId > 0 ? stopbot_rules_split($pdo, $selectedBotId) : ['words' => [], 'roots' => [], 'domains' => []];
$channels = $selectedBotId > 0 ? stopbot_channels_list($pdo, $selectedBotId) : [];
$moderationLogs = $selectedBotId > 0 ? stopbot_logs_moderation_list($pdo, $selectedBotId, 200) : [];
$usersAssigned = ($selectedBotId > 0 && $isManage) ? stopbot_user_access_list($pdo, $selectedBotId) : [];
$usersCandidates = ($selectedBotId > 0 && $isManage) ? stopbot_users_attach_candidates($pdo, $selectedBotId) : [];

?>

<h1><?= h(stopbot_t('stopbot.page_title')) ?></h1>
<p class="muted"><?= h(stopbot_t('stopbot.page_hint')) ?></p>
<style>
  .stopbot-cell-wrap {
    white-space: normal;
    word-break: break-word;
    overflow-wrap: anywhere;
    line-height: 1.4;
  }
</style>

<section class="l-row u-mb-12">
  <article class="card l-col l-col--12">
      <div class="card__head card__head--row">
        <div class="card__head-main">
          <div class="card__title"><?= h(stopbot_t('stopbot.section_switch')) ?></div>
        </div>
        <?php if ($isManage): ?>
          <div class="card__head-actions">
            <button class="btn btn--accent" type="button"
                    data-stopbot-open-modal="1"
                    data-stopbot-modal="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=modal_bot_add')) ?>">
              <?= h(stopbot_t('stopbot.action_add_bot')) ?>
            </button>
            <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=settings_toggle_log')) ?>">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <button class="btn" type="submit">
                <?= h($logEnabled ? stopbot_t('stopbot.action_log_disable') : stopbot_t('stopbot.action_log_enable')) ?>
              </button>
            </form>
          </div>
        <?php endif; ?>
      </div>
      <div class="card__body">
        <form method="get" class="form">
          <input type="hidden" name="m" value="<?= h(STOPBOT_MODULE_CODE) ?>">

          <div class="l-row" style="align-items:flex-end;">
            <div class="l-col l-col--4 l-col--sm-12">
              <label class="field field--stack">
                <span class="field__label"><?= h(stopbot_t('stopbot.field_current_bot')) ?></span>
                <select class="select" name="bot_id" required>
                  <?php if (!$bots): ?>
                    <option value="0"><?= h(stopbot_t('stopbot.no_bots')) ?></option>
                  <?php else: ?>
                    <?php foreach ($bots as $b): ?>
                      <?php $bid = (int)($b['id'] ?? 0); ?>
                      <option value="<?= (int)$bid ?>" <?= $bid === $selectedBotId ? 'selected' : '' ?>>
                        <?= h((string)($b['name'] ?? '')) ?>
                      </option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </label>
            </div>

            <div class="l-col l-col--2 l-col--sm-12" style="display:flex; align-items:flex-end;">
              <button class="btn" type="submit"><?= h(stopbot_t('stopbot.action_select')) ?></button>
            </div>
          </div>
        </form>
      </div>
  </article>
</section>

<?php if ($isManage): ?>
  <section class="l-row u-mb-12">
    <article class="card l-col l-col--12">
        <div class="card__head">
          <div class="card__title"><?= h(stopbot_t('stopbot.section_bots')) ?></div>
        </div>
        <div class="card__body">
          <div class="tablewrap">
            <table class="table table--modules">
              <thead>
                <tr>
                  <th><?= h(stopbot_t('stopbot.col_name')) ?></th>
                  <th><?= h(stopbot_t('stopbot.col_platform')) ?></th>
                  <th><?= h(stopbot_t('stopbot.col_status')) ?></th>
                  <th><?= h(stopbot_t('stopbot.col_promo_source')) ?></th>
                  <th><?= h(stopbot_t('stopbot.col_webhook')) ?></th>
                  <th class="t-right" style="width:200px"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($bots as $b): ?>
                  <?php
                    $bid = (int)($b['id'] ?? 0);
                    $platform = (string)($b['platform'] ?? '');
                    $isTg = ($platform === STOPBOT_PLATFORM_TG);
                    $enabled = ((int)($b['enabled'] ?? 0) === 1);
                    $wh = stopbot_bot_webhook_url($bid, $platform, false);
                    $promoSourceBotId = (int)($b['promo_source_bot_id'] ?? 0);
                    $promoSourceLabel = stopbot_t('stopbot.promo_source_self');
                    if ($promoSourceBotId > 0) {
                      $promoSourceBot = stopbot_bot_get($pdo, $promoSourceBotId);
                      if ($promoSourceBot) {
                        $promoSourceName = trim((string)($promoSourceBot['name'] ?? ''));
                        if ($promoSourceName === '') $promoSourceName = '#' . $promoSourceBotId;
                        $promoSourceLabel = $promoSourceName;
                      } else {
                        $promoSourceLabel = '#' . $promoSourceBotId;
                      }
                    }
                  ?>
                  <tr>
                    <td>
                      <strong><?= h((string)($b['name'] ?? '')) ?></strong>
                    </td>
                    <td><?= h($isTg ? stopbot_t('stopbot.platform_tg') : stopbot_t('stopbot.platform_max')) ?></td>
                    <td><?= h($enabled ? stopbot_t('stopbot.status_on') : stopbot_t('stopbot.status_off')) ?></td>
                    <td><?= h($promoSourceLabel) ?></td>
                    <td class="mono"><code><?= h($wh) ?></code></td>
                    <td class="t-right">
                      <div class="table__actions">
                        <button class="iconbtn iconbtn--sm" type="button"
                                data-stopbot-open-modal="1"
                                data-stopbot-modal="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=modal_bot_update&id=' . $bid)) ?>"
                                aria-label="<?= h(stopbot_t('stopbot.action_edit')) ?>"
                                title="<?= h(stopbot_t('stopbot.action_edit')) ?>">
                          <i class="bi bi-pencil"></i>
                        </button>

                        <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=bot_toggle')) ?>" class="table__actionform">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="id" value="<?= (int)$bid ?>">
                          <button class="iconbtn iconbtn--sm" type="submit"
                                  aria-label="<?= h($enabled ? stopbot_t('stopbot.action_disable') : stopbot_t('stopbot.action_enable')) ?>"
                                  title="<?= h($enabled ? stopbot_t('stopbot.action_disable') : stopbot_t('stopbot.action_enable')) ?>">
                            <i class="bi <?= $enabled ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                          </button>
                        </form>

                        <?php if ($isTg): ?>
                          <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=bot_webhook_set')) ?>" class="table__actionform">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$bid ?>">
                            <button class="iconbtn iconbtn--sm" type="submit"
                                    aria-label="<?= h(stopbot_t('stopbot.action_webhook_set')) ?>"
                                    title="<?= h(stopbot_t('stopbot.action_webhook_set')) ?>">
                              <i class="bi bi-plug"></i>
                            </button>
                          </form>
                        <?php else: ?>
                          <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=bot_max_webhook_info')) ?>" class="table__actionform">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$bid ?>">
                            <button class="iconbtn iconbtn--sm" type="submit"
                                    aria-label="<?= h(stopbot_t('stopbot.action_max_webhook_info')) ?>"
                                    title="<?= h(stopbot_t('stopbot.action_max_webhook_info')) ?>">
                              <i class="bi bi-activity"></i>
                            </button>
                          </form>

                          <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=bot_max_webhook_set')) ?>" class="table__actionform">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$bid ?>">
                            <button class="iconbtn iconbtn--sm" type="submit"
                                    aria-label="<?= h(stopbot_t('stopbot.action_max_webhook_set')) ?>"
                                    title="<?= h(stopbot_t('stopbot.action_max_webhook_set')) ?>">
                              <i class="bi bi-plug"></i>
                            </button>
                          </form>
                        <?php endif; ?>

                        <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=bot_delete')) ?>" class="table__actionform" onsubmit="return confirm('<?= h(stopbot_t('stopbot.confirm_bot_delete')) ?>');">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="id" value="<?= (int)$bid ?>">
                          <button class="iconbtn iconbtn--sm" type="submit"
                                  aria-label="<?= h(stopbot_t('stopbot.action_delete')) ?>"
                                  title="<?= h(stopbot_t('stopbot.action_delete')) ?>">
                            <i class="bi bi-trash"></i>
                          </button>
                        </form>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
    </article>
  </section>
<?php endif; ?>

<?php if ($selectedBot): ?>
  <section class="l-row u-mb-12">
    <article class="card l-col l-col--12">
        <div class="card__head card__head--row">
          <div class="card__head-main">
            <div class="card__title"><?= h(stopbot_t('stopbot.section_channels')) ?></div>
            <div class="card__hint muted">
              <?= h(stopbot_t('stopbot.channels_hint')) ?>
            </div>
          </div>
          <?php if ($isManage): ?>
            <div class="card__head-actions">
              <button class="btn"
                      type="button"
                      data-stopbot-channel-probe="1"
                      data-stopbot-probe-url="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=channel_probe')) ?>"
                      data-stopbot-attach-url="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=channel_attach')) ?>"
                      data-csrf="<?= h($csrf) ?>"
                      data-bot-id="<?= (int)$selectedBotId ?>"
                      data-platform="<?= h($selectedPlatform) ?>"
                      data-label-add="<?= h(stopbot_t('stopbot.action_channel_add')) ?>"
                      data-label-added="<?= h(stopbot_t('stopbot.channels_probe_added')) ?>"
                      data-label-loading="<?= h(stopbot_t('stopbot.channels_probe_loading')) ?>">
                <?= h(stopbot_t('stopbot.action_channel_probe')) ?>
              </button>
              <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=bind_code_generate')) ?>">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">
                <button class="btn" type="submit"><?= h(stopbot_t('stopbot.action_bind_code')) ?></button>
              </form>
            </div>
          <?php endif; ?>
        </div>

        <div class="card__body">
          <?php if ($isManage): ?>
            <div style="display:grid; gap:8px; margin-bottom:12px;">
              <div class="muted">
                <?= h($selectedPlatform === STOPBOT_PLATFORM_MAX ? stopbot_t('stopbot.channels_probe_hint_max') : stopbot_t('stopbot.channels_probe_hint_tg')) ?>
              </div>
              <div class="tablewrap" data-stopbot-channel-probe-result="1">
                <div class="muted"><?= h(stopbot_t('stopbot.channels_probe_idle')) ?></div>
              </div>
            </div>
          <?php endif; ?>
          <div class="tablewrap">
            <table class="table table--modules">
              <thead>
                <tr>
                  <th><?= h(stopbot_t('stopbot.col_chat')) ?></th>
                  <th><?= h(stopbot_t('stopbot.col_chat_type')) ?></th>
                  <th><?= h(stopbot_t('stopbot.col_status')) ?></th>
                  <th class="t-right" style="width:160px"></th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$channels): ?>
                  <tr>
                    <td colspan="4" class="muted"><?= h(stopbot_t('stopbot.no_channels')) ?></td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($channels as $ch): ?>
                    <?php
                      $cid = (int)($ch['id'] ?? 0);
                      $chatTitle = trim((string)($ch['chat_title'] ?? ''));
                      $chatId = (string)($ch['chat_id'] ?? '');
                      $chatType = (string)($ch['chat_type'] ?? '');
                      $enabled = ((int)($ch['is_active'] ?? 0) === 1);
                    ?>
                    <tr>
                      <td>
                        <strong><?= h($chatTitle !== '' ? $chatTitle : $chatId) ?></strong>
                        <?php if ($chatTitle !== '' && $chatId !== ''): ?>
                          <div class="muted mono"><?= h($chatId) ?></div>
                        <?php endif; ?>
                      </td>
                      <td><?= h($chatType !== '' ? $chatType : stopbot_t('stopbot.dash')) ?></td>
                      <td><?= h($enabled ? stopbot_t('stopbot.status_on') : stopbot_t('stopbot.status_off')) ?></td>
                      <td class="t-right">
                        <?php if ($isManage): ?>
                          <div class="table__actions">
                            <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=channel_toggle')) ?>" class="table__actionform">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="id" value="<?= (int)$cid ?>">
                              <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">
                              <button class="iconbtn iconbtn--sm" type="submit"
                                      aria-label="<?= h($enabled ? stopbot_t('stopbot.action_disable') : stopbot_t('stopbot.action_enable')) ?>"
                                      title="<?= h($enabled ? stopbot_t('stopbot.action_disable') : stopbot_t('stopbot.action_enable')) ?>">
                                <i class="bi <?= $enabled ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                              </button>
                            </form>

                            <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=channel_unbind')) ?>" class="table__actionform" onsubmit="return confirm('<?= h(stopbot_t('stopbot.confirm_channel_unbind')) ?>');">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="id" value="<?= (int)$cid ?>">
                              <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">
                              <button class="iconbtn iconbtn--sm" type="submit"
                                      aria-label="<?= h(stopbot_t('stopbot.action_unbind')) ?>"
                                      title="<?= h(stopbot_t('stopbot.action_unbind')) ?>">
                                <i class="bi bi-x-circle"></i>
                              </button>
                            </form>
                          </div>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
    </article>
  </section>

  <section class="l-row u-mb-12">
    <article class="card l-col l-col--12">
        <div class="card__head card__head--row">
          <div class="card__head-main">
            <div class="card__title"><?= h(stopbot_t('stopbot.section_promos')) ?></div>
            <div class="card__hint muted">
              <?= h(stopbot_t('stopbot.promos_hint')) ?>
              <?php if ($isSharedPromoList): ?>
                <?php
                  $ownerName = trim((string)($selectedPromoOwner['name'] ?? ''));
                  if ($ownerName === '') $ownerName = '#' . $selectedPromoOwnerId;
                ?>
                <br>
                <?= h(stopbot_t('stopbot.promos_shared_hint', ['bot' => $ownerName])) ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="card__head-actions">
            <button class="btn btn--accent" type="button"
                    data-stopbot-open-modal="1"
                    data-stopbot-modal="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=modal_promo_add&bot_id=' . $selectedBotId)) ?>">
              <?= h(stopbot_t('stopbot.action_add_promo')) ?>
            </button>
          </div>
        </div>

        <div class="card__body">
          <div class="l-row u-mb-12">
            <div class="l-col l-col--5 l-col--sm-12">
              <label class="field field--stack">
                <span class="field__label"><?= h(stopbot_t('stopbot.search_promos_label')) ?></span>
                <input
                  class="input"
                  type="text"
                  value=""
                  placeholder="<?= h(stopbot_t('stopbot.search_promos_placeholder')) ?>"
                  autocomplete="off"
                  data-stopbot-promo-search="1"
                  data-stopbot-promo-target="stopbot-promos-<?= (int)$selectedBotId ?>">
              </label>
            </div>
          </div>
          <div class="tablewrap u-mb-12" style="max-height:320px; overflow:auto;">
            <table
              class="table table--modules"
              data-stopbot-promo-table="stopbot-promos-<?= (int)$selectedBotId ?>"
              data-stopbot-promo-empty-text="<?= h(stopbot_t('stopbot.search_promos_empty')) ?>">
              <thead>
                <tr>
                  <th><?= h(stopbot_t('stopbot.col_keywords')) ?></th>
                  <th><?= h(stopbot_t('stopbot.col_response')) ?></th>
                  <th><?= h(stopbot_t('stopbot.col_status')) ?></th>
                  <th class="t-right" style="width:140px"></th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$promos): ?>
                  <tr>
                    <td colspan="4" class="muted"><?= h(stopbot_t('stopbot.no_promos')) ?></td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($promos as $p): ?>
                    <?php
                      $pid = (int)($p['id'] ?? 0);
                      $enabled = ((int)($p['is_active'] ?? 0) === 1);
                      $keywords = (string)($p['keywords'] ?? '');
                      $keywordsSearch = stopbot_text_lower($keywords);
                      $resp = trim((string)($p['response_text'] ?? ''));
                      $respShort = $resp;
                      if (function_exists('mb_substr') && mb_strlen($respShort) > 140) {
                        $respShort = mb_substr($respShort, 0, 140) . '…';
                      } elseif (strlen($respShort) > 140) {
                        $respShort = substr($respShort, 0, 140) . '...';
                      }
                    ?>
                    <tr data-stopbot-promo-row="1" data-stopbot-promo-search-text="<?= h($keywordsSearch) ?>">
                      <td class="stopbot-cell-wrap"><?= h($keywords) ?></td>
                      <td class="stopbot-cell-wrap"><?= h($respShort !== '' ? $respShort : stopbot_t('stopbot.dash')) ?></td>
                      <td><?= h($enabled ? stopbot_t('stopbot.status_on') : stopbot_t('stopbot.status_off')) ?></td>
                      <td class="t-right">
                        <div class="table__actions">
                          <button class="iconbtn iconbtn--sm" type="button"
                                  data-stopbot-open-modal="1"
                                  data-stopbot-modal="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=modal_promo_update&id=' . $pid . '&bot_id=' . $selectedBotId)) ?>"
                                  aria-label="<?= h(stopbot_t('stopbot.action_edit')) ?>"
                                  title="<?= h(stopbot_t('stopbot.action_edit')) ?>">
                            <i class="bi bi-pencil"></i>
                          </button>

                          <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=promo_toggle')) ?>" class="table__actionform">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$pid ?>">
                            <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">
                            <button class="iconbtn iconbtn--sm" type="submit"
                                    aria-label="<?= h($enabled ? stopbot_t('stopbot.action_disable') : stopbot_t('stopbot.action_enable')) ?>"
                                    title="<?= h($enabled ? stopbot_t('stopbot.action_disable') : stopbot_t('stopbot.action_enable')) ?>">
                              <i class="bi <?= $enabled ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                            </button>
                          </form>

                          <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=promo_delete')) ?>" class="table__actionform" onsubmit="return confirm('<?= h(stopbot_t('stopbot.confirm_promo_delete')) ?>');">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$pid ?>">
                            <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">
                            <button class="iconbtn iconbtn--sm" type="submit"
                                    aria-label="<?= h(stopbot_t('stopbot.action_delete')) ?>"
                                    title="<?= h(stopbot_t('stopbot.action_delete')) ?>">
                              <i class="bi bi-trash"></i>
                            </button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <tr data-stopbot-promo-empty="1" style="display:none;">
                    <td colspan="4" class="muted"><?= h(stopbot_t('stopbot.search_promos_empty')) ?></td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="l-row">
            <div class="l-col l-col--4 l-col--sm-12">
              <div class="muted u-mb-8"><?= h(stopbot_t('stopbot.rules_words_count', ['count' => (string)count((array)($rulesSplit['words'] ?? []))])) ?></div>
              <div class="tablewrap" style="max-height:260px; overflow:auto;">
                <table class="table table--modules">
                  <thead><tr><th><?= h(stopbot_t('stopbot.split_words')) ?></th><th class="t-right" style="width:60px;"></th></tr></thead>
                  <tbody>
                  <?php if (!((array)($rulesSplit['words'] ?? []))): ?>
                    <tr><td class="muted" colspan="2"><?= h(stopbot_t('stopbot.no_rules_words')) ?></td></tr>
                  <?php else: ?>
                    <?php foreach ((array)($rulesSplit['words'] ?? []) as $value): ?>
                      <tr>
                        <td class="stopbot-cell-wrap"><?= h((string)$value) ?></td>
                        <td class="t-right">
                          <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=rule_delete')) ?>" class="table__actionform" onsubmit="return confirm('<?= h(stopbot_t('stopbot.confirm_rule_delete')) ?>');">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">
                            <input type="hidden" name="kind" value="word">
                            <input type="hidden" name="value" value="<?= h((string)$value) ?>">
                            <button class="iconbtn iconbtn--sm" type="submit" aria-label="<?= h(stopbot_t('stopbot.action_delete')) ?>" title="<?= h(stopbot_t('stopbot.action_delete')) ?>">
                              <i class="bi bi-trash"></i>
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="l-col l-col--4 l-col--sm-12">
              <div class="muted u-mb-8"><?= h(stopbot_t('stopbot.rules_roots_count', ['count' => (string)count((array)($rulesSplit['roots'] ?? []))])) ?></div>
              <div class="tablewrap" style="max-height:260px; overflow:auto;">
                <table class="table table--modules">
                  <thead><tr><th><?= h(stopbot_t('stopbot.split_roots')) ?></th><th class="t-right" style="width:60px;"></th></tr></thead>
                  <tbody>
                  <?php if (!((array)($rulesSplit['roots'] ?? []))): ?>
                    <tr><td class="muted" colspan="2"><?= h(stopbot_t('stopbot.no_rules_roots')) ?></td></tr>
                  <?php else: ?>
                    <?php foreach ((array)($rulesSplit['roots'] ?? []) as $value): ?>
                      <tr>
                        <td class="stopbot-cell-wrap"><?= h((string)$value) ?></td>
                        <td class="t-right">
                          <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=rule_delete')) ?>" class="table__actionform" onsubmit="return confirm('<?= h(stopbot_t('stopbot.confirm_rule_delete')) ?>');">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">
                            <input type="hidden" name="kind" value="root">
                            <input type="hidden" name="value" value="<?= h((string)$value) ?>">
                            <button class="iconbtn iconbtn--sm" type="submit" aria-label="<?= h(stopbot_t('stopbot.action_delete')) ?>" title="<?= h(stopbot_t('stopbot.action_delete')) ?>">
                              <i class="bi bi-trash"></i>
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="l-col l-col--4 l-col--sm-12">
              <div class="muted u-mb-8"><?= h(stopbot_t('stopbot.rules_domains_count', ['count' => (string)count((array)($rulesSplit['domains'] ?? []))])) ?></div>
              <div class="tablewrap" style="max-height:260px; overflow:auto;">
                <table class="table table--modules">
                  <thead><tr><th><?= h(stopbot_t('stopbot.split_domains')) ?></th><th class="t-right" style="width:60px;"></th></tr></thead>
                  <tbody>
                  <?php if (!((array)($rulesSplit['domains'] ?? []))): ?>
                    <tr><td class="muted" colspan="2"><?= h(stopbot_t('stopbot.no_rules_domains')) ?></td></tr>
                  <?php else: ?>
                    <?php foreach ((array)($rulesSplit['domains'] ?? []) as $value): ?>
                      <tr>
                        <td class="stopbot-cell-wrap"><?= h((string)$value) ?></td>
                        <td class="t-right">
                          <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=rule_delete')) ?>" class="table__actionform" onsubmit="return confirm('<?= h(stopbot_t('stopbot.confirm_rule_delete')) ?>');">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">
                            <input type="hidden" name="kind" value="domain">
                            <input type="hidden" name="value" value="<?= h((string)$value) ?>">
                            <button class="iconbtn iconbtn--sm" type="submit" aria-label="<?= h(stopbot_t('stopbot.action_delete')) ?>" title="<?= h(stopbot_t('stopbot.action_delete')) ?>">
                              <i class="bi bi-trash"></i>
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
    </article>
  </section>

  <section class="l-row u-mb-12">
    <article class="card l-col l-col--12">
      <div class="card__head">
        <div class="card__title"><?= h(stopbot_t('stopbot.section_logs')) ?></div>
        <div class="card__hint muted"><?= h(stopbot_t('stopbot.logs_hint')) ?></div>
      </div>
      <div class="card__body">
        <div class="l-row u-mb-12">
          <div class="l-col l-col--5 l-col--sm-12">
            <label class="field field--stack">
              <span class="field__label"><?= h(stopbot_t('stopbot.search_logs_label')) ?></span>
              <input
                class="input"
                type="text"
                value=""
                placeholder="<?= h(stopbot_t('stopbot.search_logs_placeholder')) ?>"
                autocomplete="off"
                data-stopbot-log-search="1"
                data-stopbot-log-target="stopbot-logs-<?= (int)$selectedBotId ?>">
            </label>
          </div>
        </div>
        <div class="tablewrap" style="max-height:300px; overflow:auto;">
          <table
            class="table table--modules"
            data-stopbot-log-table="stopbot-logs-<?= (int)$selectedBotId ?>"
            data-stopbot-log-empty-text="<?= h(stopbot_t('stopbot.search_logs_empty')) ?>">
            <thead>
              <tr>
                <th style="width:170px;"><?= h(stopbot_t('stopbot.col_time')) ?></th>
                <th><?= h(stopbot_t('stopbot.col_channel')) ?></th>
                <th style="width:150px;"><?= h(stopbot_t('stopbot.col_action')) ?></th>
                <th><?= h(stopbot_t('stopbot.col_text')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$moderationLogs): ?>
                <tr>
                  <td colspan="4" class="muted"><?= h(stopbot_t('stopbot.no_logs')) ?></td>
                </tr>
              <?php else: ?>
                <?php foreach ($moderationLogs as $log): ?>
                  <?php
                    $status = trim((string)($log['send_status'] ?? ''));
                    $statusLabel = $status;
                    if ($status === 'deleted') $statusLabel = stopbot_t('stopbot.log_status_deleted');
                    elseif ($status === 'delete_failed') $statusLabel = stopbot_t('stopbot.log_status_delete_failed');
                    elseif ($status === 'edited') $statusLabel = stopbot_t('stopbot.log_status_edited');
                    elseif ($status === 'edit_failed') $statusLabel = stopbot_t('stopbot.log_status_edit_failed');

                    $chatTitle = trim((string)($log['chat_title'] ?? ''));
                    $chatId = trim((string)($log['chat_id'] ?? ''));
                    $textRaw = trim((string)($log['message_text'] ?? ''));
                    $text = $textRaw;
                    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                      if (mb_strlen($text) > 220) $text = mb_substr($text, 0, 220) . '…';
                    } elseif (strlen($text) > 220) {
                      $text = substr($text, 0, 220) . '...';
                    }
                    $errorText = trim((string)($log['error_text'] ?? ''));
                    $logSearch = stopbot_text_lower(trim($chatTitle . ' ' . $chatId . ' ' . $statusLabel . ' ' . $textRaw . ' ' . $errorText));
                  ?>
                  <tr data-stopbot-log-row="1" data-stopbot-log-search-text="<?= h($logSearch) ?>">
                    <td class="mono"><?= h((string)($log['created_at'] ?? '')) ?></td>
                    <td>
                      <strong><?= h($chatTitle !== '' ? $chatTitle : $chatId) ?></strong>
                      <?php if ($chatId !== ''): ?>
                        <div class="muted mono"><?= h($chatId) ?></div>
                      <?php endif; ?>
                    </td>
                    <td><?= h($statusLabel) ?></td>
                    <td>
                      <div><?= h($text !== '' ? $text : stopbot_t('stopbot.dash')) ?></div>
                      <?php if ($errorText !== ''): ?>
                        <div class="muted"><?= h($errorText) ?></div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <tr data-stopbot-log-empty="1" style="display:none;">
                  <td colspan="4" class="muted"><?= h(stopbot_t('stopbot.search_logs_empty')) ?></td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </article>
  </section>

  <?php if ($isManage): ?>
    <section class="l-row u-mb-12">
      <article class="card l-col l-col--12">
          <div class="card__head">
            <div class="card__title"><?= h(stopbot_t('stopbot.section_users')) ?></div>
          </div>
          <div class="card__body">
            <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=user_attach')) ?>" class="form">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">

              <div class="l-row" style="align-items:flex-end;">
                <div class="l-col l-col--6 l-col--sm-12">
                  <label class="field field--stack">
                    <span class="field__label"><?= h(stopbot_t('stopbot.field_user')) ?></span>
                    <select class="select" name="user_id">
                      <?php foreach ($usersCandidates as $u): ?>
                        <option value="<?= (int)($u['id'] ?? 0) ?>">
                          <?= h((string)($u['name'] ?? '')) ?>
                          <?= ($u['role_codes'] ?? '') !== '' ? '(' . h((string)($u['role_codes'] ?? '')) . ')' : '' ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                </div>

                <div class="l-col l-col--3 l-col--sm-12" style="display:flex; align-items:flex-end;">
                  <button class="btn" type="submit" <?= $usersCandidates ? '' : 'disabled' ?>>
                    <?= h(stopbot_t('stopbot.action_attach')) ?>
                  </button>
                </div>
              </div>
            </form>

            <div class="tablewrap" style="margin-top:12px;">
              <table class="table table--modules">
                <thead>
                  <tr>
                    <th><?= h(stopbot_t('stopbot.col_user')) ?></th>
                    <th><?= h(stopbot_t('stopbot.col_roles')) ?></th>
                    <th class="t-right" style="width:120px"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$usersAssigned): ?>
                    <tr>
                      <td colspan="3" class="muted"><?= h(stopbot_t('stopbot.no_users')) ?></td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($usersAssigned as $u): ?>
                      <?php $uidRow = (int)($u['user_id'] ?? 0); ?>
                      <tr>
                        <td><?= h((string)($u['name'] ?? '')) ?></td>
                        <td><?= h((string)($u['role_codes'] ?? '')) ?></td>
                        <td class="t-right">
                          <form method="post" action="<?= h(url('/adm/index.php?m=' . STOPBOT_MODULE_CODE . '&do=user_detach')) ?>" class="table__actionform" onsubmit="return confirm('<?= h(stopbot_t('stopbot.confirm_user_detach')) ?>');">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">
                            <input type="hidden" name="user_id" value="<?= (int)$uidRow ?>">
                            <button class="iconbtn iconbtn--sm" type="submit"
                                    aria-label="<?= h(stopbot_t('stopbot.action_detach')) ?>"
                                    title="<?= h(stopbot_t('stopbot.action_detach')) ?>">
                              <i class="bi bi-person-dash"></i>
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
      </article>
    </section>
  <?php endif; ?>
<?php endif; ?>

<script src="<?= h(url('/adm/modules/stopbot/assets/js/main.js')) ?>"></script>
