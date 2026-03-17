<?php
/**
 * FILE: /adm/modules/max_comments/max_comments.php
 * ROLE: VIEW модуля max_comments.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/max_comments_lib.php';

acl_guard(module_allowed_roles(MAX_COMMENTS_MODULE_CODE));

$schemaError = '';
$bridgeError = '';
$settings = max_comments_defaults();
$selectedRows = [];
$apiChats = [];
$channelsView = [];
$bridge = [];
$botUsername = '';
$routeChatsCount = 0;
$hasOwnApiKeyColumn = true;

try {
  $pdo = db();
  max_comments_require_schema($pdo);
  $hasOwnApiKeyColumn = max_comments_table_has_column($pdo, MAX_COMMENTS_TABLE_SETTINGS, 'max_api_key');
  $settings = max_comments_settings_get($pdo);
  $selectedRows = max_comments_channels_get($pdo);
} catch (Throwable $e) {
  $schemaError = $e->getMessage();
}

if ($schemaError === '') {
  try {
    $chatsRes = max_comments_bridge_route_chats($pdo);
    if (($chatsRes['ok'] ?? false) === true) {
      $apiChats = (array)($chatsRes['items'] ?? []);
      $routeChatsCount = count($apiChats);
    } else {
      $bridgeError = trim((string)($chatsRes['error'] ?? 'Не удалось загрузить chat_id из channel_bridge.'));
    }

    $bridge = max_comments_bridge_settings($pdo);
    $readyErr = '';
    if (max_comments_bridge_ready($bridge, $readyErr)) {
      $botUsername = max_comments_bridge_bot_username($bridge);
    } else {
      if ($bridgeError === '') {
        $bridgeError = $readyErr;
      }
    }
  } catch (Throwable $e) {
    $bridgeError = $e->getMessage();
  }
}

$selectedMap = [];
foreach ($selectedRows as $row) {
  $chatId = trim((string)($row['chat_id'] ?? ''));
  if ($chatId === '') continue;
  $selectedMap[$chatId] = [
    'title' => trim((string)($row['title'] ?? '')),
    'enabled' => ((int)($row['enabled'] ?? 0) === 1) ? 1 : 0,
  ];
}

foreach ($apiChats as $item) {
  if (!is_array($item)) continue;
  $chatId = trim((string)($item['chat_id'] ?? ''));
  if ($chatId === '') continue;

  $title = trim((string)($item['title'] ?? ''));
  $type = trim((string)($item['type'] ?? ''));
  $enabled = isset($selectedMap[$chatId]) ? ((int)$selectedMap[$chatId]['enabled']) : 0;

  $channelsView[$chatId] = [
    'chat_id' => $chatId,
    'title' => $title,
    'type' => $type,
    'enabled' => $enabled,
    'missing' => 0,
  ];
}

foreach ($selectedMap as $chatId => $row) {
  if (isset($channelsView[$chatId])) continue;
  $channelsView[$chatId] = [
    'chat_id' => $chatId,
    'title' => (string)($row['title'] ?? ''),
    'type' => '',
    'enabled' => 1,
    'missing' => 1,
  ];
}

uasort($channelsView, static function (array $a, array $b): int {
  $ta = trim((string)($a['title'] ?? ''));
  $tb = trim((string)($b['title'] ?? ''));
  if ($ta === '' && $tb === '') {
    return strcmp((string)($a['chat_id'] ?? ''), (string)($b['chat_id'] ?? ''));
  }
  if ($ta === '') return 1;
  if ($tb === '') return -1;
  return strcasecmp($ta, $tb);
});

$miniAppUrlAbs = max_comments_miniapp_url(true);
$miniAppUrlRel = max_comments_miniapp_url(false);
$miniAppDeepLink = $botUsername !== '' ? ('https://max.ru/' . $botUsername . '?startapp=max_comments') : '';
$webhookUrlAbs = max_comments_webhook_url(true);
$webhookUrlRel = max_comments_webhook_url(false);
?>

<h1>MAX комментарии</h1>
<p class="muted">Кнопка <strong>open_app</strong> добавляется к новым постам в выбранных каналах MAX.</p>

<?php if ($schemaError !== ''): ?>
  <section class="card u-mb-12">
    <div class="card__title">Схема БД не установлена</div>
    <div class="card__body">
      <div class="muted"><?= h($schemaError) ?></div>
      <div class="muted">
        Примените SQL: <code>adm/modules/max_comments/install.sql</code>
      </div>
    </div>
  </section>
  <?php return; ?>
<?php endif; ?>

<section class="l-row u-mb-12">
  <div class="l-col l-col--6 l-col--lg-12">
    <div class="card">
    <div class="card__title">Endpoint</div>
    <div class="card__body">
      <div class="table-wrap">
        <table class="table table--fit">
          <tbody>
          <tr>
            <td>Mini App URL (вставить в MAX)</td>
            <td><code><?= h($miniAppUrlAbs) ?></code></td>
          </tr>
          <tr>
            <td>Deep link (open_app)</td>
            <td><code><?= h($miniAppDeepLink !== '' ? $miniAppDeepLink : 'н/д: не удалось получить username бота') ?></code></td>
          </tr>
          <tr>
            <td>Mini App path</td>
            <td><code><?= h($miniAppUrlRel) ?></code></td>
          </tr>
          <tr>
            <td>Webhook URL</td>
            <td><code><?= h($webhookUrlAbs) ?></code></td>
          </tr>
          <tr>
            <td>Webhook path</td>
            <td><code><?= h($webhookUrlRel) ?></code></td>
          </tr>
          </tbody>
        </table>
      </div>
    </div>
    </div>
  </div>

  <div class="l-col l-col--6 l-col--lg-12">
    <div class="card">
    <div class="card__title">MAX / Bridge</div>
    <div class="card__body">
      <div class="table-wrap">
        <table class="table table--fit">
          <tbody>
          <tr>
            <td>API key (из этого модуля)</td>
            <td><strong><?= trim((string)($bridge['max_api_key'] ?? '')) !== '' ? 'заполнен' : 'пустой' ?></strong></td>
          </tr>
          <tr>
            <td>Base URL</td>
            <td><code><?= h((string)($bridge['max_base_url'] ?? 'https://platform-api.max.ru')) ?></code></td>
          </tr>
          <tr>
            <td>chat_id из channel_bridge (MAX target)</td>
            <td><strong><?= (int)$routeChatsCount ?></strong></td>
          </tr>
          </tbody>
        </table>
      </div>
      <?php if ($bridgeError !== ''): ?>
        <div class="muted"><?= h($bridgeError) ?></div>
      <?php endif; ?>
    </div>
    </div>
  </div>
</section>

<section class="card">
  <div class="card__title">Настройки</div>
  <form method="post" action="<?= h(url('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE . '&do=save')) ?>">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

    <div class="card__body field--stack">
      <label class="field">
        <input type="checkbox" name="enabled" value="1" <?= ((int)($settings['enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
        <span>Включить модуль</span>
      </label>

      <label class="field field--stack">
        <span class="field__label">Текст кнопки</span>
        <input class="select" type="text" name="button_text" maxlength="64" value="<?= h((string)($settings['button_text'] ?? 'Комментарии')) ?>">
      </label>

      <label class="field field--stack">
        <span class="field__label">MAX API key (бот комментариев)</span>
        <input class="select" type="text" name="max_api_key" maxlength="255" value="<?= h((string)($settings['max_api_key'] ?? '')) ?>" <?= $hasOwnApiKeyColumn ? '' : 'disabled' ?>>
      </label>
      <?php if (!$hasOwnApiKeyColumn): ?>
        <div class="muted">Нужно применить обновленный SQL модуля: в таблице отсутствует колонка <code>max_api_key</code>.</div>
      <?php endif; ?>

    </div>

    <div class="card__title">Каналы MAX</div>
    <div class="card__body">
      <?php if (!$channelsView): ?>
        <div class="muted">Список пуст. Добавьте маршруты с целью MAX в модуле channel_bridge.</div>
      <?php else: ?>
        <div class="tablewrap">
          <table class="table table--fit">
            <thead>
            <tr>
              <th>ON</th>
              <th>Канал</th>
              <th>chat_id</th>
              <th>Тип</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($channelsView as $row): ?>
              <?php
              $chatId = (string)($row['chat_id'] ?? '');
              $title = trim((string)($row['title'] ?? ''));
              $type = trim((string)($row['type'] ?? ''));
              $enabled = ((int)($row['enabled'] ?? 0) === 1);
              $missing = ((int)($row['missing'] ?? 0) === 1);
              if ($title === '') $title = '(без названия)';
              if ($missing) $title .= ' (не найдено в channel_bridge)';
              ?>
              <tr>
                <td>
                  <label>
                    <input type="checkbox" name="channels[]" value="<?= h($chatId) ?>" <?= $enabled ? 'checked' : '' ?>>
                    <input type="hidden" name="channel_title[<?= h($chatId) ?>]" value="<?= h($title) ?>">
                  </label>
                </td>
                <td><?= h($title) ?></td>
                <td><code><?= h($chatId) ?></code></td>
                <td><?= h($type !== '' ? $type : '—') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card__foot">
      <div class="form-actions">
        <button class="btn"
                type="submit"
                formaction="<?= h(url('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE . '&do=probe')) ?>"
                formmethod="post">
          Проверить MAX
        </button>
        <button class="btn"
                type="submit"
                formaction="<?= h(url('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE . '&do=test_button')) ?>"
                formmethod="post">
          Тест кнопки
        </button>
        <button class="btn"
                type="submit"
                formaction="<?= h(url('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE . '&do=test_read')) ?>"
                formmethod="post">
          Тест чтения
        </button>
        <button class="btn"
                type="submit"
                formaction="<?= h(url('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE . '&do=test_updates')) ?>"
                formmethod="post">
          Тест updates
        </button>
        <button class="btn"
                type="submit"
                formaction="<?= h(url('/adm/index.php?m=' . MAX_COMMENTS_MODULE_CODE . '&do=poll_now')) ?>"
                formmethod="post">
          Poll постов
        </button>
        <button class="btn btn--accent" type="submit">Сохранить</button>
      </div>
    </div>
  </form>
</section>
