<?php
/**
 * FILE: /adm/modules/ym_link_bot/ym_link_bot.php
 * ROLE: View for copyable YM Link Bot module.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

$moduleDir = basename(__DIR__);
$moduleRoot = ROOT_PATH . '/adm/modules/' . $moduleDir;
require_once $moduleRoot . '/settings.php';
require_once $moduleRoot . '/assets/php/ym_link_bot_lib.php';

acl_guard(module_allowed_roles(ymlb_module_code()));

$pdo = db();
ymlb_ensure_schema($pdo);
ymlb_sync_module_roles($pdo);
$settings = ymlb_settings_get($pdo);

$csrf = csrf_token();
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$canManage = ymlb_is_manage_role($roles);
$canManageData = true;

$moduleCode = ymlb_module_code();
$cssPath = $moduleRoot . '/assets/css/main.css';
$jsPath = $moduleRoot . '/assets/js/main.js';
$cssVer = is_file($cssPath) ? (string)filemtime($cssPath) : '1';
$jsVer = is_file($jsPath) ? (string)filemtime($jsPath) : '1';

$dataCardClass = 'l-col l-col--12';
?>

<link rel="stylesheet" href="<?= h(url('/adm/modules/' . $moduleCode . '/assets/css/main.css?v=' . rawurlencode($cssVer))) ?>">

<section class="card ymlb-card">
  <div class="card__head ymlb-card-head-actions">
    <div>
      <div class="card__title">YM Link Bot</div>
      <div class="card__hint muted">Copy module with dedicated webhook endpoints.</div>
    </div>
    <div class="ymlb-top-actions">
      <?php if ($canManageData): ?>
        <button type="button" class="btn" data-ymlb-open-modal="channel">Channel</button>
        <button type="button" class="btn" data-ymlb-open-modal="chat">Chat</button>
      <?php endif; ?>
      <?php if ($canManage): ?>
        <button type="button" class="iconbtn" data-ymlb-open-modal="settings" title="Settings">
          <i class="bi bi-gear"></i>
        </button>
      <?php endif; ?>
    </div>
  </div>
  <div class="card__body">
    <div class="ymlb-topline">
      <div><span class="muted">Модуль:</span> <code><?= h($moduleCode) ?></code></div>
      <?php if ($canManage): ?>
        <div><span class="muted">Webhook listener:</span> <code><?= h(ymlb_listener_url($settings)) ?></code></div>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="l-row ymlb-layout">
  <?php if ($canManage): ?>
    <article id="ymlbSettingsPanel" class="card ymlb-card l-col l-col--3 l-col--xl-4 l-col--lg-6 l-col--sm-12">
      <div class="card__head ymlb-card-head-actions">
        <div class="card__title">Настройки Бота</div>
      </div>
      <div class="card__body">
        <form class="ymlb-form ymlb-form-grid" id="ymlbSettingsForm" data-ymlb-form="settings">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

          <label class="field field--stack">
            <span class="field__label">Включен</span>
            <input type="checkbox" name="enabled" value="1" <?= ((int)$settings['enabled'] === 1) ? 'checked' : '' ?>>
          </label>

          <label class="field field--stack">
            <span class="field__label">ChatLink (обработка из чата/канала)</span>
            <input type="checkbox" name="chat_mode_enabled" value="1" <?= ((int)($settings['chat_mode_enabled'] ?? 1) === 1) ? 'checked' : '' ?>>
          </label>

          <label class="field field--stack">
            <span class="field__label">Bot token</span>
            <input class="input" type="text" name="bot_token" value="<?= h((string)$settings['bot_token']) ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label">Bot username (без @)</span>
            <input class="input" type="text" name="bot_username" value="<?= h((string)$settings['bot_username']) ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label">Webhook secret</span>
            <input class="input" type="text" name="webhook_secret" value="<?= h((string)$settings['webhook_secret']) ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label">Chat bot separate</span>
            <input type="checkbox" name="chat_bot_separate" value="1" <?= ((int)($settings['chat_bot_separate'] ?? 0) === 1) ? 'checked' : '' ?>>
          </label>
          <div class="ymlb-mini-hint muted">If disabled, chat processing uses the main bot webhook.</div>

          <label class="field field--stack">
            <span class="field__label">Chat bot token (optional)</span>
            <input class="input" type="text" name="chat_bot_token" value="<?= h((string)($settings['chat_bot_token'] ?? '')) ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label">Chat bot username (без @)</span>
            <input class="input" type="text" name="chat_bot_username" value="<?= h((string)($settings['chat_bot_username'] ?? '')) ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label">Chat webhook secret</span>
            <input class="input" type="text" name="chat_webhook_secret" value="<?= h((string)($settings['chat_webhook_secret'] ?? '')) ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label">Affiliate API key</span>
            <input class="input" type="text" name="affiliate_api_key" value="<?= h((string)$settings['affiliate_api_key']) ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label">Use Yandex Market API link</span>
            <input type="checkbox" name="partner_mode_enabled" value="1" <?= ((int)($settings['partner_mode_enabled'] ?? 1) === 1) ? 'checked' : '' ?>>
          </label>

          <label class="field field--stack">
            <span class="field__label">Use manual fallback link</span>
            <input type="checkbox" name="manual_mode_enabled" value="1" <?= ((int)($settings['manual_mode_enabled'] ?? 1) === 1) ? 'checked' : '' ?>>
          </label>

          <label class="field field--stack">
            <span class="field__label">Geo ID</span>
            <input class="input" type="number" name="geo_id" min="1" value="<?= (int)$settings['geo_id'] ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label">Статические параметры</span>
            <input class="input" type="text" name="link_static_params" value="<?= h((string)$settings['link_static_params']) ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label">Listener path</span>
            <input class="input" type="text" name="listener_path" value="<?= h((string)$settings['listener_path']) ?>">
          </label>

          <label class="field field--stack">
            <span class="field__label">Chat listener path</span>
            <input class="input" type="text" name="chat_listener_path" value="<?= h((string)($settings['chat_listener_path'] ?? '')) ?>">
          </label>

          <button class="btn btn--accent" type="submit">Сохранить настройки</button>
        </form>

        <div class="ymlb-webhook-box">
          <div class="ymlb-webhook-status">
            <span id="ymlbWebhookDot" class="ymlb-dot is-off" aria-hidden="true"></span>
            <span id="ymlbWebhookText" class="muted">Webhook не проверен</span>
          </div>
          <div class="ymlb-webhook-actions">
            <button type="button" class="btn" data-ymlb-action="webhook-check">Проверить webhook</button>
            <button type="button" class="btn btn--accent" data-ymlb-action="webhook-set">Подключить webhook</button>
          </div>
        </div>

        <div class="ymlb-webhook-box">
          <div class="ymlb-webhook-status">
            <span id="ymlbChatWebhookDot" class="ymlb-dot is-off" aria-hidden="true"></span>
            <span id="ymlbChatWebhookText" class="muted">Chat webhook не проверен</span>
          </div>
          <div class="ymlb-webhook-actions">
            <button type="button" class="btn" data-ymlb-action="chat-webhook-check">Проверить chat webhook</button>
            <button type="button" class="btn btn--accent" data-ymlb-action="chat-webhook-set">Подключить chat webhook</button>
          </div>
        </div>
      </div>
    </article>

    <article class="card ymlb-card <?= h($dataCardClass) ?>">
      <div class="card__head ymlb-card-head-actions">
        <div class="card__title">Привязанные Пользователи (OAuth открыто)</div>
        <button type="button" class="iconbtn" data-ymlb-open-modal="binding" title="Add binding">
          <i class="bi bi-plus-lg"></i>
        </button>
      </div>
      <div class="card__body">
        <form class="ymlb-form ymlb-form-grid" id="ymlbBindingForm" data-ymlb-form="binding">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="id" value="">

          <label class="field field--stack">
            <span class="field__label">Имя/метка</span>
            <input class="input" type="text" name="title" required>
          </label>
          <label class="field field--stack">
            <span class="field__label">CRM user id</span>
            <select class="select" name="crm_user_id" id="ymlbCrmUserSelect" required>
              <option value="">Загрузка пользователей...</option>
            </select>
          </label>
          <label class="field field--stack">
            <span class="field__label">Telegram user id</span>
            <input class="input" type="text" name="telegram_user_id">
          </label>
          <label class="field field--stack">
            <span class="field__label">Telegram username</span>
            <input class="input" type="text" name="telegram_username">
          </label>
          <label class="field field--stack">
            <span class="field__label">OAuth access token (manual, optional)</span>
            <input class="input" type="text" name="oauth_access_token" autocomplete="off" placeholder="Empty = auto OAuth by CRM user">
          </label>
          <label class="field field--stack">
            <span class="field__label">Clear manual OAuth</span>
            <input type="checkbox" name="oauth_access_token_clear" value="1">
          </label>
          <label class="field field--stack">
            <span class="field__label">Активен</span>
            <input type="checkbox" name="is_active" value="1" checked>
          </label>
          <button class="btn btn--accent" type="submit">Сохранить пользователя</button>
        </form>

        <div class="tablewrap">
          <table class="table table--modules">
            <thead>
            <tr>
              <th>ID</th>
              <th>Имя</th>
              <th>CRM</th>
              <th>TG user</th>
              <th>OAuth</th>
              <th>Статус</th>
              <th class="t-right"></th>
            </tr>
            </thead>
            <tbody id="ymlbBindingsBody">
            <tr><td colspan="7" class="muted">Загрузка...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </article>
  <?php endif; ?>

  <article class="card ymlb-card <?= h($dataCardClass) ?>">
    <div class="card__head ymlb-card-head-actions">
      <div>
        <div class="card__title">Каналы</div>
        <div class="card__hint muted">Сгенерируйте код и опубликуйте в канале: <code>/bind CODE</code></div>
      </div>
      <button type="button" class="iconbtn" data-ymlb-open-modal="channel" title="Link channel">
        <i class="bi bi-link-45deg"></i>
      </button>
    </div>
    <div class="card__body">
      <form class="ymlb-form ymlb-form-grid" id="ymlbChannelCodeForm" data-ymlb-form="channel-code">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label class="field field--stack">
          <span class="field__label">Пользователь (binding)</span>
          <input type="hidden" name="binding_id" value="">
          <div class="input input--readonly">Auto (all active bindings in this bot)</div>
        </label>
        <label class="field field--stack">
          <span class="field__label">Ожидаемый @channel (опц.)</span>
          <input class="input" type="text" name="channel_username">
        </label>
        <?php if ($canManageData): ?>
          <button class="btn btn--accent" type="submit">Сгенерировать код</button>
        <?php endif; ?>
      </form>
      <pre id="ymlbChannelCodeOut" class="ymlb-pre">Код подтверждения еще не создан.</pre>

      <div class="tablewrap">
        <table class="table table--modules">
          <thead>
          <tr>
            <th>ID</th>
            <th>Пользователь</th>
            <th>Канал</th>
            <th>Chat ID</th>
            <th>Код</th>
            <th>Статус</th>
            <th class="t-right"></th>
          </tr>
          </thead>
          <tbody id="ymlbChannelsBody">
          <tr><td colspan="7" class="muted">Загрузка...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </article>

  <article class="card ymlb-card <?= h($dataCardClass) ?>">
    <div class="card__head ymlb-card-head-actions">
      <div>
        <div class="card__title">Чаты</div>
        <div class="card__hint muted">Сгенерируйте код и опубликуйте в чате: <code>/bind CODE</code>. If separate chat bot is off, this works via main webhook.</div>
      </div>
      <button type="button" class="iconbtn" data-ymlb-open-modal="chat" title="Link chat">
        <i class="bi bi-chat-dots"></i>
      </button>
    </div>
    <div class="card__body">
      <form class="ymlb-form ymlb-form-grid" id="ymlbChatCodeForm" data-ymlb-form="chat-code">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <label class="field field--stack">
          <span class="field__label">Пользователь (binding)</span>
          <input type="hidden" name="binding_id" value="">
          <div class="input input--readonly">Auto (all active bindings in this bot)</div>
        </label>
        <label class="field field--stack">
          <span class="field__label">Ожидаемый @chat (опц.)</span>
          <input class="input" type="text" name="channel_username">
        </label>
        <?php if ($canManageData): ?>
          <button class="btn btn--accent" type="submit">Сгенерировать код</button>
        <?php endif; ?>
      </form>
      <pre id="ymlbChatCodeOut" class="ymlb-pre">Код подтверждения для чата еще не создан.</pre>

      <div class="tablewrap">
        <table class="table table--modules">
          <thead>
          <tr>
            <th>ID</th>
            <th>Пользователь</th>
            <th>Чат</th>
            <th>Chat ID</th>
            <th>Код</th>
            <th>Статус</th>
            <th class="t-right"></th>
          </tr>
          </thead>
          <tbody id="ymlbChatsBody">
          <tr><td colspan="7" class="muted">Загрузка...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </article>

  <article class="card ymlb-card <?= h($dataCardClass) ?>">
    <div class="card__head ymlb-card-head-actions">
      <div class="card__title">Площадки (CLID)</div>
      <button type="button" class="iconbtn" data-ymlb-open-modal="site" title="Add site">
        <i class="bi bi-plus-lg"></i>
      </button>
    </div>
    <div class="card__body">
      <form class="ymlb-form ymlb-form-grid" id="ymlbSiteForm" data-ymlb-form="site">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="">
        <label class="field field--stack">
          <span class="field__label">Пользователь (binding)</span>
          <select class="select" name="binding_id" id="ymlbBindingSelectForSite"></select>
        </label>
        <label class="field field--stack">
          <span class="field__label">Имя площадки</span>
          <input class="input" type="text" name="name" required>
        </label>
        <label class="field field--stack">
          <span class="field__label">CLID</span>
          <input class="input" type="text" name="clid" required>
        </label>
        <label class="field field--stack">
          <span class="field__label">Активна</span>
          <input type="checkbox" name="is_active" value="1" checked>
        </label>
        <?php if ($canManageData): ?>
          <button class="btn btn--accent" type="submit">Сохранить площадку</button>
        <?php endif; ?>
      </form>

      <div class="tablewrap">
        <table class="table table--modules">
          <thead>
          <tr>
            <th>ID</th>
            <th>Пользователь</th>
            <th>Площадка</th>
            <th>CLID</th>
            <th>Статус</th>
            <th class="t-right"></th>
          </tr>
          </thead>
          <tbody id="ymlbSitesBody">
          <tr><td colspan="6" class="muted">Загрузка...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </article>

  <?php if ($canManage): ?>
    <article class="card ymlb-card l-col l-col--3 l-col--xl-4 l-col--lg-6 l-col--sm-12">
      <div class="card__head">
        <div class="card__title">Очистка Фото</div>
        <div class="card__hint muted">Удаляет записи и файлы за выбранный период.</div>
      </div>
      <div class="card__body">
        <form class="ymlb-form ymlb-form-grid" id="ymlbCleanupForm" data-ymlb-form="cleanup">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <label class="field field--stack">
            <span class="field__label">С даты</span>
            <input class="input" type="date" name="date_from" required>
          </label>
          <label class="field field--stack">
            <span class="field__label">По дату</span>
            <input class="input" type="date" name="date_to" required>
          </label>
          <button class="btn btn--danger" type="submit">Удалить данные</button>
        </form>
        <pre id="ymlbCleanupOut" class="ymlb-pre">Очистка еще не запускалась.</pre>
      </div>
    </article>
  <?php endif; ?>
</section>

<div
  id="ymlbRoot"
  data-ymlb-root="1"
  data-module-code="<?= h($moduleCode) ?>"
  data-can-manage="<?= $canManage ? '1' : '0' ?>"
  data-can-manage-data="<?= $canManageData ? '1' : '0' ?>"
  data-csrf="<?= h($csrf) ?>">
</div>

<script src="<?= h(url('/adm/modules/' . $moduleCode . '/assets/js/main.js?v=' . rawurlencode($jsVer))) ?>"></script>
