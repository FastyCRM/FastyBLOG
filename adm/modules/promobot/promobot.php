<?php
/**
 * FILE: /adm/modules/promobot/promobot.php
 * ROLE: VIEW модуля promobot (UI only)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/assets/php/promobot_lib.php';

acl_guard(module_allowed_roles(PROMOBOT_MODULE_CODE));

$pdo = db();
$csrf = csrf_token();

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$isManage = promobot_is_manage_role($roles);

$missing = promobot_tables_missing($pdo, PROMOBOT_REQUIRED_TABLES);
if ($missing) {
  ?>
  <h1><?= h(promobot_t('promobot.page_title')) ?></h1>
  <div class="card">
    <div class="card__head">
      <div class="card__title"><?= h(promobot_t('promobot.install_title')) ?></div>
      <div class="card__hint muted">
        <?= h(promobot_t('promobot.install_hint')) ?>
        <code>adm/modules/promobot/install.sql</code>
      </div>
    </div>
    <div class="card__body">
      <?php if ($isManage): ?>
        <form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=install_db')) ?>">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <button class="btn btn--accent" type="submit"><?= h(promobot_t('promobot.action_install_db')) ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php
  return;
}

$settings = promobot_settings_get($pdo);
$logEnabled = ((int)($settings['log_enabled'] ?? 1) === 1);

$bots = promobot_bot_list($pdo, $uid, $roles);
$selectedBotId = (int)($_GET['bot_id'] ?? 0);

if ($selectedBotId <= 0 && $bots) {
  $selectedBotId = (int)($bots[0]['id'] ?? 0);
}

$selectedBot = $selectedBotId > 0 ? promobot_bot_get($pdo, $selectedBotId) : [];

$promos = $selectedBotId > 0 ? promobot_promos_list($pdo, $selectedBotId) : [];
$channels = $selectedBotId > 0 ? promobot_channels_list($pdo, $selectedBotId) : [];
$usersAssigned = ($selectedBotId > 0 && $isManage) ? promobot_user_access_list($pdo, $selectedBotId) : [];
$usersCandidates = ($selectedBotId > 0 && $isManage) ? promobot_users_attach_candidates($pdo, $selectedBotId) : [];

?>

<h1><?= h(promobot_t('promobot.page_title')) ?></h1>
<p class="muted"><?= h(promobot_t('promobot.page_hint')) ?></p>

<section class="l-row u-mb-12">
  <article class="card l-col l-col--12">
      <div class="card__head card__head--row">
        <div class="card__head-main">
          <div class="card__title"><?= h(promobot_t('promobot.section_switch')) ?></div>
        </div>
        <?php if ($isManage): ?>
          <div class="card__head-actions">
            <button class="btn btn--accent" type="button"
                    data-promobot-open-modal="1"
                    data-promobot-modal="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=modal_bot_add')) ?>">
              <?= h(promobot_t('promobot.action_add_bot')) ?>
            </button>
            <form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=settings_toggle_log')) ?>">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <button class="btn" type="submit">
                <?= h($logEnabled ? promobot_t('promobot.action_log_disable') : promobot_t('promobot.action_log_enable')) ?>
              </button>
            </form>
          </div>
        <?php endif; ?>
      </div>
      <div class="card__body">
        <form method="get" class="form">
          <input type="hidden" name="m" value="<?= h(PROMOBOT_MODULE_CODE) ?>">

          <div class="l-row" style="align-items:flex-end;">
            <div class="l-col l-col--4 l-col--sm-12">
              <label class="field field--stack">
                <span class="field__label"><?= h(promobot_t('promobot.field_current_bot')) ?></span>
                <select class="select" name="bot_id" required>
                  <?php if (!$bots): ?>
                    <option value="0"><?= h(promobot_t('promobot.no_bots')) ?></option>
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
              <button class="btn" type="submit"><?= h(promobot_t('promobot.action_select')) ?></button>
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
          <div class="card__title"><?= h(promobot_t('promobot.section_bots')) ?></div>
        </div>
        <div class="card__body">
          <div class="tablewrap">
            <table class="table table--modules">
              <thead>
                <tr>
                  <th><?= h(promobot_t('promobot.col_name')) ?></th>
                  <th><?= h(promobot_t('promobot.col_platform')) ?></th>
                  <th><?= h(promobot_t('promobot.col_status')) ?></th>
                  <th><?= h(promobot_t('promobot.col_webhook')) ?></th>
                  <th class="t-right" style="width:200px"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($bots as $b): ?>
                  <?php
                    $bid = (int)($b['id'] ?? 0);
                    $platform = (string)($b['platform'] ?? '');
                    $isTg = ($platform === PROMOBOT_PLATFORM_TG);
                    $enabled = ((int)($b['enabled'] ?? 0) === 1);
                    $wh = promobot_bot_webhook_url($bid, $platform, false);
                  ?>
                  <tr>
                    <td>
                      <strong><?= h((string)($b['name'] ?? '')) ?></strong>
                    </td>
                    <td><?= h($isTg ? promobot_t('promobot.platform_tg') : promobot_t('promobot.platform_max')) ?></td>
                    <td><?= h($enabled ? promobot_t('promobot.status_on') : promobot_t('promobot.status_off')) ?></td>
                    <td class="mono"><code><?= h($wh) ?></code></td>
                    <td class="t-right">
                      <div class="table__actions">
                        <button class="iconbtn iconbtn--sm" type="button"
                                data-promobot-open-modal="1"
                                data-promobot-modal="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=modal_bot_update&id=' . $bid)) ?>"
                                aria-label="<?= h(promobot_t('promobot.action_edit')) ?>"
                                title="<?= h(promobot_t('promobot.action_edit')) ?>">
                          <i class="bi bi-pencil"></i>
                        </button>

                        <form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=bot_toggle')) ?>" class="table__actionform">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="id" value="<?= (int)$bid ?>">
                          <button class="iconbtn iconbtn--sm" type="submit"
                                  aria-label="<?= h($enabled ? promobot_t('promobot.action_disable') : promobot_t('promobot.action_enable')) ?>"
                                  title="<?= h($enabled ? promobot_t('promobot.action_disable') : promobot_t('promobot.action_enable')) ?>">
                            <i class="bi <?= $enabled ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                          </button>
                        </form>

                        <?php if ($isTg): ?>
                          <form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=bot_webhook_set')) ?>" class="table__actionform">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$bid ?>">
                            <button class="iconbtn iconbtn--sm" type="submit"
                                    aria-label="<?= h(promobot_t('promobot.action_webhook_set')) ?>"
                                    title="<?= h(promobot_t('promobot.action_webhook_set')) ?>">
                              <i class="bi bi-plug"></i>
                            </button>
                          </form>
                        <?php endif; ?>

                        <form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=bot_delete')) ?>" class="table__actionform" onsubmit="return confirm('<?= h(promobot_t('promobot.confirm_bot_delete')) ?>');">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="id" value="<?= (int)$bid ?>">
                          <button class="iconbtn iconbtn--sm" type="submit"
                                  aria-label="<?= h(promobot_t('promobot.action_delete')) ?>"
                                  title="<?= h(promobot_t('promobot.action_delete')) ?>">
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
            <div class="card__title"><?= h(promobot_t('promobot.section_channels')) ?></div>
            <div class="card__hint muted">
              <?= h(promobot_t('promobot.channels_hint')) ?>
            </div>
          </div>
          <?php if ($isManage): ?>
            <div class="card__head-actions">
              <form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=bind_code_generate')) ?>">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">
                <button class="btn" type="submit"><?= h(promobot_t('promobot.action_bind_code')) ?></button>
              </form>
            </div>
          <?php endif; ?>
        </div>

        <div class="card__body">
          <div class="tablewrap">
            <table class="table table--modules">
              <thead>
                <tr>
                  <th><?= h(promobot_t('promobot.col_chat')) ?></th>
                  <th><?= h(promobot_t('promobot.col_chat_type')) ?></th>
                  <th><?= h(promobot_t('promobot.col_status')) ?></th>
                  <th class="t-right" style="width:160px"></th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$channels): ?>
                  <tr>
                    <td colspan="4" class="muted"><?= h(promobot_t('promobot.no_channels')) ?></td>
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
                      <td><?= h($chatType !== '' ? $chatType : promobot_t('promobot.dash')) ?></td>
                      <td><?= h($enabled ? promobot_t('promobot.status_on') : promobot_t('promobot.status_off')) ?></td>
                      <td class="t-right">
                        <?php if ($isManage): ?>
                          <div class="table__actions">
                            <form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=channel_toggle')) ?>" class="table__actionform">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="id" value="<?= (int)$cid ?>">
                              <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">
                              <button class="iconbtn iconbtn--sm" type="submit"
                                      aria-label="<?= h($enabled ? promobot_t('promobot.action_disable') : promobot_t('promobot.action_enable')) ?>"
                                      title="<?= h($enabled ? promobot_t('promobot.action_disable') : promobot_t('promobot.action_enable')) ?>">
                                <i class="bi <?= $enabled ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                              </button>
                            </form>

                            <form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=channel_unbind')) ?>" class="table__actionform" onsubmit="return confirm('<?= h(promobot_t('promobot.confirm_channel_unbind')) ?>');">
                              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                              <input type="hidden" name="id" value="<?= (int)$cid ?>">
                              <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">
                              <button class="iconbtn iconbtn--sm" type="submit"
                                      aria-label="<?= h(promobot_t('promobot.action_unbind')) ?>"
                                      title="<?= h(promobot_t('promobot.action_unbind')) ?>">
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
            <div class="card__title"><?= h(promobot_t('promobot.section_promos')) ?></div>
            <div class="card__hint muted"><?= h(promobot_t('promobot.promos_hint')) ?></div>
          </div>
          <div class="card__head-actions">
            <button class="btn btn--accent" type="button"
                    data-promobot-open-modal="1"
                    data-promobot-modal="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=modal_promo_add&bot_id=' . $selectedBotId)) ?>">
              <?= h(promobot_t('promobot.action_add_promo')) ?>
            </button>
          </div>
        </div>

        <div class="card__body">
          <div class="l-row u-mb-12">
            <div class="l-col l-col--5 l-col--sm-12">
              <label class="field field--stack">
                <span class="field__label"><?= h(promobot_t('promobot.search_promos_label')) ?></span>
                <input
                  class="input"
                  type="text"
                  value=""
                  placeholder="<?= h(promobot_t('promobot.search_promos_placeholder')) ?>"
                  autocomplete="off"
                  data-promobot-promo-search="1"
                  data-promobot-promo-target="promobot-promos-<?= (int)$selectedBotId ?>">
              </label>
            </div>
          </div>
          <div class="tablewrap">
            <table
              class="table table--modules"
              data-promobot-promo-table="promobot-promos-<?= (int)$selectedBotId ?>"
              data-promobot-promo-empty-text="<?= h(promobot_t('promobot.search_promos_empty')) ?>">
              <thead>
                <tr>
                  <th><?= h(promobot_t('promobot.col_keywords')) ?></th>
                  <th><?= h(promobot_t('promobot.col_response')) ?></th>
                  <th><?= h(promobot_t('promobot.col_status')) ?></th>
                  <th class="t-right" style="width:140px"></th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$promos): ?>
                  <tr>
                    <td colspan="4" class="muted"><?= h(promobot_t('promobot.no_promos')) ?></td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($promos as $p): ?>
                    <?php
                      $pid = (int)($p['id'] ?? 0);
                      $enabled = ((int)($p['is_active'] ?? 0) === 1);
                      $keywords = (string)($p['keywords'] ?? '');
                      $keywordsSearch = promobot_text_lower($keywords);
                      $resp = trim((string)($p['response_text'] ?? ''));
                      $respShort = $resp;
                      if (function_exists('mb_substr') && mb_strlen($respShort) > 140) {
                        $respShort = mb_substr($respShort, 0, 140) . '…';
                      } elseif (strlen($respShort) > 140) {
                        $respShort = substr($respShort, 0, 140) . '...';
                      }
                    ?>
                    <tr data-promobot-promo-row="1" data-promobot-promo-search-text="<?= h($keywordsSearch) ?>">
                      <td><?= h($keywords) ?></td>
                      <td><?= h($respShort !== '' ? $respShort : promobot_t('promobot.dash')) ?></td>
                      <td><?= h($enabled ? promobot_t('promobot.status_on') : promobot_t('promobot.status_off')) ?></td>
                      <td class="t-right">
                        <div class="table__actions">
                          <button class="iconbtn iconbtn--sm" type="button"
                                  data-promobot-open-modal="1"
                                  data-promobot-modal="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=modal_promo_update&id=' . $pid)) ?>"
                                  aria-label="<?= h(promobot_t('promobot.action_edit')) ?>"
                                  title="<?= h(promobot_t('promobot.action_edit')) ?>">
                            <i class="bi bi-pencil"></i>
                          </button>

                          <form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=promo_toggle')) ?>" class="table__actionform">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$pid ?>">
                            <button class="iconbtn iconbtn--sm" type="submit"
                                    aria-label="<?= h($enabled ? promobot_t('promobot.action_disable') : promobot_t('promobot.action_enable')) ?>"
                                    title="<?= h($enabled ? promobot_t('promobot.action_disable') : promobot_t('promobot.action_enable')) ?>">
                              <i class="bi <?= $enabled ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                            </button>
                          </form>

                          <form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=promo_delete')) ?>" class="table__actionform" onsubmit="return confirm('<?= h(promobot_t('promobot.confirm_promo_delete')) ?>');">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$pid ?>">
                            <button class="iconbtn iconbtn--sm" type="submit"
                                    aria-label="<?= h(promobot_t('promobot.action_delete')) ?>"
                                    title="<?= h(promobot_t('promobot.action_delete')) ?>">
                              <i class="bi bi-trash"></i>
                            </button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <tr data-promobot-promo-empty="1" style="display:none;">
                    <td colspan="4" class="muted"><?= h(promobot_t('promobot.search_promos_empty')) ?></td>
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
            <div class="card__title"><?= h(promobot_t('promobot.section_users')) ?></div>
          </div>
          <div class="card__body">
            <form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=user_attach')) ?>" class="form">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">

              <div class="l-row" style="align-items:flex-end;">
                <div class="l-col l-col--6 l-col--sm-12">
                  <label class="field field--stack">
                    <span class="field__label"><?= h(promobot_t('promobot.field_user')) ?></span>
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
                    <?= h(promobot_t('promobot.action_attach')) ?>
                  </button>
                </div>
              </div>
            </form>

            <div class="tablewrap" style="margin-top:12px;">
              <table class="table table--modules">
                <thead>
                  <tr>
                    <th><?= h(promobot_t('promobot.col_user')) ?></th>
                    <th><?= h(promobot_t('promobot.col_roles')) ?></th>
                    <th class="t-right" style="width:120px"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$usersAssigned): ?>
                    <tr>
                      <td colspan="3" class="muted"><?= h(promobot_t('promobot.no_users')) ?></td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($usersAssigned as $u): ?>
                      <?php $uidRow = (int)($u['user_id'] ?? 0); ?>
                      <tr>
                        <td><?= h((string)($u['name'] ?? '')) ?></td>
                        <td><?= h((string)($u['role_codes'] ?? '')) ?></td>
                        <td class="t-right">
                          <form method="post" action="<?= h(url('/adm/index.php?m=' . PROMOBOT_MODULE_CODE . '&do=user_detach')) ?>" class="table__actionform" onsubmit="return confirm('<?= h(promobot_t('promobot.confirm_user_detach')) ?>');">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="bot_id" value="<?= (int)$selectedBotId ?>">
                            <input type="hidden" name="user_id" value="<?= (int)$uidRow ?>">
                            <button class="iconbtn iconbtn--sm" type="submit"
                                    aria-label="<?= h(promobot_t('promobot.action_detach')) ?>"
                                    title="<?= h(promobot_t('promobot.action_detach')) ?>">
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

<script src="<?= h(url('/adm/modules/promobot/assets/js/main.js')) ?>"></script>
