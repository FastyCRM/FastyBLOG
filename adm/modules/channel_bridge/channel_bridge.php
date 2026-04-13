<?php
/**
 * FILE: /adm/modules/channel_bridge/channel_bridge.php
 * ROLE: VIEW модуля channel_bridge.
 * RESPONSIBILITY:
 *  - показать статусы интеграций;
 *  - показать список маршрутов;
 *  - открыть настройки/формы только через модальные окна.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/channel_bridge_lib.php';

acl_guard(module_allowed_roles(CHANNEL_BRIDGE_MODULE_CODE));

$pdo = db();
$csrf = csrf_token();
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$canManage = channel_bridge_can_manage($roles);

$schemaError = '';
$settings = channel_bridge_settings_defaults();
$routes = [];

try {
  channel_bridge_require_schema($pdo);
  $settings = channel_bridge_settings_get($pdo);
  $routes = channel_bridge_routes_list($pdo);
} catch (Throwable $e) {
  $schemaError = trim($e->getMessage());
}

$webhookEndpointPath = channel_bridge_webhook_endpoint_url(false);
$webhookEndpointUrl = channel_bridge_webhook_endpoint_url(true);
$webhookCurrentUrl = '';
$webhookStatusText = channel_bridge_t('channel_bridge.webhook_status_error');
$webhookHintText = '';

if ($schemaError !== '') {
  $webhookStatusText = channel_bridge_t('channel_bridge.webhook_status_unknown');
} elseif ((int)($settings['tg_enabled'] ?? 0) !== 1) {
  $webhookStatusText = channel_bridge_t('channel_bridge.webhook_status_disabled');
} else {
  $webhookState = channel_bridge_fetch_tg_webhook_state($settings);
  if (($webhookState['ok'] ?? false) === true) {
    $webhookCurrentUrl = trim((string)($webhookState['current_url'] ?? ''));

    if (($webhookState['is_set'] ?? false) !== true) {
      $webhookStatusText = channel_bridge_t('channel_bridge.webhook_status_not_set');
    } elseif (($webhookState['is_match'] ?? false) === true) {
      $webhookStatusText = channel_bridge_t('channel_bridge.webhook_status_active');
    } else {
      $webhookStatusText = channel_bridge_t('channel_bridge.webhook_status_mismatch');
    }

    $pendingCount = (int)($webhookState['pending_update_count'] ?? 0);
    if ($pendingCount > 0) {
      $webhookHintText = channel_bridge_t('channel_bridge.webhook_pending', ['count' => (string)$pendingCount]);
    }

    $lastError = trim((string)($webhookState['last_error_message'] ?? ''));
    if ($lastError !== '') {
      $errLine = channel_bridge_t('channel_bridge.webhook_last_error', ['error' => $lastError]);
      $webhookHintText = ($webhookHintText !== '') ? ($webhookHintText . ' | ' . $errLine) : $errLine;
    }
  } else {
    $reason = trim((string)($webhookState['reason'] ?? ''));
    if ($reason === 'no_token') {
      $webhookStatusText = channel_bridge_t('channel_bridge.webhook_status_no_token');
    } else {
      $webhookStatusText = channel_bridge_t('channel_bridge.webhook_status_error');
      $apiErr = trim((string)(($webhookState['api']['description'] ?? '') ?: ($webhookState['api']['error'] ?? '')));
      if ($apiErr !== '') {
        $webhookHintText = channel_bridge_t('channel_bridge.webhook_last_error', ['error' => $apiErr]);
      }
    }
  }
}
?>

<link rel="stylesheet" href="<?= h(url('/adm/modules/channel_bridge/assets/css/main.css')) ?>">

<section class="card cb-head">
  <div class="card__body cb-head__body">
    <div>
      <h1><?= h(channel_bridge_t('channel_bridge.page_title')) ?></h1>
      <div class="muted"><?= h(channel_bridge_t('channel_bridge.page_hint')) ?></div>
    </div>

    <div class="cb-head__actions">
      <?php if ($canManage): ?>
        <button class="iconbtn iconbtn--sm"
                type="button"
                data-cb-open-modal="1"
                data-cb-modal="<?= h(url('/adm/index.php?m=channel_bridge&do=modal_route_add')) ?>"
                aria-label="<?= h(channel_bridge_t('channel_bridge.btn_route_add')) ?>"
                title="<?= h(channel_bridge_t('channel_bridge.btn_route_add')) ?>">
          <i class="bi bi-plus-lg"></i>
        </button>

        <button class="iconbtn iconbtn--sm"
                type="button"
                data-cb-open-modal="1"
                data-cb-modal="<?= h(url('/adm/index.php?m=channel_bridge&do=modal_settings')) ?>"
                aria-label="<?= h(channel_bridge_t('channel_bridge.btn_settings')) ?>"
                title="<?= h(channel_bridge_t('channel_bridge.btn_settings')) ?>">
          <i class="bi bi-gear"></i>
        </button>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php if ($schemaError !== ''): ?>
  <section class="card">
    <div class="card__body">
      <div class="muted"><?= h($schemaError) ?></div>
      <div class="muted" style="margin-top:8px;">
        <?= h(channel_bridge_t('channel_bridge.schema_hint')) ?>
      </div>
    </div>
  </section>
<?php endif; ?>

<section class="cb-grid">
  <article class="card">
    <div class="card__head">
      <div class="card__title"><?= h(channel_bridge_t('channel_bridge.card_status_title')) ?></div>
    </div>
    <div class="card__body cb-status">
      <div><span class="muted"><?= h(channel_bridge_t('channel_bridge.status_module')) ?>:</span> <strong><?= ((int)($settings['enabled'] ?? 0) === 1) ? 'ON' : 'OFF' ?></strong></div>
      <div><span class="muted">TG:</span> <strong><?= ((int)($settings['tg_enabled'] ?? 0) === 1) ? 'ON' : 'OFF' ?></strong></div>
      <div><span class="muted">VK:</span> <strong><?= ((int)($settings['vk_enabled'] ?? 0) === 1) ? 'ON' : 'OFF' ?></strong></div>
      <div><span class="muted">MAX:</span> <strong><?= ((int)($settings['max_enabled'] ?? 0) === 1) ? 'ON' : 'OFF' ?></strong></div>
      <div><span class="muted"><?= h(channel_bridge_t('channel_bridge.status_routes')) ?>:</span> <strong><?= count($routes) ?></strong></div>
    </div>
  </article>

  <article class="card">
    <div class="card__head">
      <div class="card__title"><?= h(channel_bridge_t('channel_bridge.card_api_title')) ?></div>
    </div>
    <div class="card__body">
      <div class="muted"><?= h(channel_bridge_t('channel_bridge.api_hint')) ?></div>
      <div style="margin-top:8px;"><span class="muted"><?= h(channel_bridge_t('channel_bridge.webhook_status_label')) ?>:</span> <strong><?= h($webhookStatusText) ?></strong></div>
      <div class="muted" style="margin-top:8px;"><?= h(channel_bridge_t('channel_bridge.webhook_expected_url')) ?></div>
      <pre class="cb-code"><?= h($webhookEndpointUrl) ?></pre>
      <?php if ($webhookCurrentUrl !== '' && strcasecmp($webhookCurrentUrl, $webhookEndpointUrl) !== 0): ?>
        <div class="muted"><?= h(channel_bridge_t('channel_bridge.webhook_current_url')) ?></div>
        <pre class="cb-code"><?= h($webhookCurrentUrl) ?></pre>
      <?php endif; ?>
      <?php if ($webhookHintText !== ''): ?>
        <div class="muted"><?= h($webhookHintText) ?></div>
      <?php endif; ?>
      <?php if ($canManage): ?>
        <form method="post" action="<?= h(url('/adm/index.php?m=channel_bridge&do=webhook_refresh')) ?>" style="margin-top:12px; display:grid; gap:8px;">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="return_url" value="<?= h(url('/adm/index.php?m=channel_bridge')) ?>">
          <button class="btn" type="submit"><?= h(channel_bridge_t('channel_bridge.btn_webhook_refresh')) ?></button>
          <div class="muted"><?= h(channel_bridge_t('channel_bridge.webhook_refresh_hint')) ?></div>
        </form>
      <?php endif; ?>
      <div class="muted"><?= h(channel_bridge_t('channel_bridge.api_hint_secret')) ?></div>
      <div class="muted"><?= h($webhookEndpointPath) ?></div>
    </div>
  </article>
</section>

<section class="card">
  <div class="card__head">
    <div class="card__title"><?= h(channel_bridge_t('channel_bridge.routes_title')) ?></div>
    <div class="card__hint muted"><?= h(channel_bridge_t('channel_bridge.routes_hint')) ?></div>
    <div class="card__hint muted"><?= h(channel_bridge_t('channel_bridge.routes_bind_hint')) ?></div>
  </div>
  <div class="card__body">
    <div class="tablewrap">
      <table class="table">
        <thead>
          <tr>
            <th>ID</th>
            <th><?= h(channel_bridge_t('channel_bridge.col_route')) ?></th>
            <th><?= h(channel_bridge_t('channel_bridge.col_source')) ?></th>
            <th><?= h(channel_bridge_t('channel_bridge.col_target')) ?></th>
            <th><?= h(channel_bridge_t('channel_bridge.col_status')) ?></th>
            <th><?= h(channel_bridge_t('channel_bridge.col_last_send')) ?></th>
            <th class="t-right" style="width:200px;"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($routes as $row): ?>
            <?php
              $id = (int)($row['id'] ?? 0);
              $enabled = ((int)($row['enabled'] ?? 0) === 1);
              $targetPlatform = channel_bridge_norm_platform((string)($row['target_platform'] ?? ''));
              $sourceLabel = (string)($row['source_platform'] ?? '') . ':' . (string)($row['source_chat_id'] ?? '');
              $targetLabel = (string)($row['target_platform'] ?? '') . ':' . (string)($row['target_chat_id'] ?? '');
              $lastStatus = trim((string)($row['last_status'] ?? ''));
              $lastAt = trim((string)($row['last_sent_at'] ?? ''));
            ?>
            <tr>
              <td class="mono"><?= $id ?></td>
              <td><?= h((string)($row['title'] ?: '#'.$id)) ?></td>
              <td class="mono"><?= h($sourceLabel) ?></td>
              <td class="mono"><?= h($targetLabel) ?></td>
              <td><?= $enabled ? 'ON' : 'OFF' ?></td>
              <td><?= h($lastAt !== '' ? ($lastStatus !== '' ? ($lastAt . ' / ' . $lastStatus) : $lastAt) : '—') ?></td>
              <td class="t-right">
                <?php if ($canManage): ?>
                  <div class="table__actions">
                    <button class="iconbtn iconbtn--sm"
                            type="button"
                            data-cb-open-modal="1"
                            data-cb-modal="<?= h(url('/adm/index.php?m=channel_bridge&do=modal_route_update&id=' . $id)) ?>"
                            aria-label="<?= h(channel_bridge_t('channel_bridge.btn_edit')) ?>"
                            title="<?= h(channel_bridge_t('channel_bridge.btn_edit')) ?>">
                      <i class="bi bi-pencil"></i>
                    </button>

                    <form method="post" action="<?= h(url('/adm/index.php?m=channel_bridge&do=route_toggle')) ?>" class="table__actionform">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                      <button class="iconbtn iconbtn--sm"
                              type="submit"
                              aria-label="<?= $enabled ? h(channel_bridge_t('channel_bridge.btn_disable')) : h(channel_bridge_t('channel_bridge.btn_enable')) ?>"
                              title="<?= $enabled ? h(channel_bridge_t('channel_bridge.btn_disable')) : h(channel_bridge_t('channel_bridge.btn_enable')) ?>">
                        <i class="bi <?= $enabled ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                      </button>
                    </form>

                    <form method="post" action="<?= h(url('/adm/index.php?m=channel_bridge&do=route_bind_code')) ?>" class="table__actionform">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <input type="hidden" name="side" value="<?= h(CHANNEL_BRIDGE_BIND_SIDE_SOURCE) ?>">
                      <button class="iconbtn iconbtn--sm"
                              type="submit"
                              aria-label="<?= h(channel_bridge_t('channel_bridge.btn_bind_source_code')) ?>"
                              title="<?= h(channel_bridge_t('channel_bridge.btn_bind_source_code')) ?>">
                        <i class="bi bi-key"></i>
                      </button>
                    </form>

                    <?php if ($targetPlatform === CHANNEL_BRIDGE_TARGET_TG): ?>
                      <form method="post" action="<?= h(url('/adm/index.php?m=channel_bridge&do=route_bind_code')) ?>" class="table__actionform">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="side" value="<?= h(CHANNEL_BRIDGE_BIND_SIDE_TARGET) ?>">
                        <button class="iconbtn iconbtn--sm"
                                type="submit"
                                aria-label="<?= h(channel_bridge_t('channel_bridge.btn_bind_target_code')) ?>"
                                title="<?= h(channel_bridge_t('channel_bridge.btn_bind_target_code')) ?>">
                          <i class="bi bi-key-fill"></i>
                        </button>
                      </form>
                    <?php endif; ?>

                    <form method="post" action="<?= h(url('/adm/index.php?m=channel_bridge&do=route_test')) ?>" class="table__actionform">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <button class="iconbtn iconbtn--sm"
                              type="submit"
                              aria-label="<?= h(channel_bridge_t('channel_bridge.btn_test')) ?>"
                              title="<?= h(channel_bridge_t('channel_bridge.btn_test')) ?>">
                        <i class="bi bi-send"></i>
                      </button>
                    </form>

                    <form method="post"
                          action="<?= h(url('/adm/index.php?m=channel_bridge&do=route_delete')) ?>"
                          class="table__actionform"
                          onsubmit="return confirm('<?= h(channel_bridge_t('channel_bridge.confirm_delete_route')) ?>');">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <button class="iconbtn iconbtn--sm"
                              type="submit"
                              aria-label="<?= h(channel_bridge_t('channel_bridge.btn_delete')) ?>"
                              title="<?= h(channel_bridge_t('channel_bridge.btn_delete')) ?>">
                        <i class="bi bi-trash"></i>
                      </button>
                    </form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (!$routes): ?>
            <tr>
              <td colspan="7" class="muted"><?= h(channel_bridge_t('channel_bridge.routes_empty')) ?></td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<script src="<?= h(url('/adm/modules/channel_bridge/assets/js/main.js')) ?>"></script>
