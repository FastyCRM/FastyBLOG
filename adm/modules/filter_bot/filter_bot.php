<?php
/**
 * FILE: /adm/modules/filter_bot/filter_bot.php
 * ROLE: VIEW модуля filter_bot.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/filter_bot_lib.php';

acl_guard(module_allowed_roles(FILTER_BOT_MODULE_CODE));

$schemaError = '';
$settings = filter_bot_defaults();
$channels = [];
$logs = [];
$tgWebhookUrl = filter_bot_tg_webhook_url(true);
$maxWebhookUrl = filter_bot_max_webhook_url(true);

try {
  $pdo = db();
  filter_bot_require_schema($pdo);
  $settings = filter_bot_settings_get($pdo);
  $channels = filter_bot_channels_list($pdo);
  $logs = filter_bot_logs_recent($pdo, 50);
} catch (Throwable $e) {
  $schemaError = trim($e->getMessage());
}
?>

<h1>Фильтр TG / MAX</h1>
<p class="muted">Модуль модерирует сообщения в выбранных чатах Telegram и MAX: мат и ссылки вне белого списка доменов.</p>

<?php if ($schemaError !== ''): ?>
  <section class="card u-mb-12">
    <div class="card__title">Схема БД не установлена</div>
    <div class="card__body">
      <div class="muted"><?= h($schemaError) ?></div>
      <div class="muted">Нажмите кнопку ниже, чтобы создать отсутствующие таблицы модуля.</div>
    </div>
    <div class="card__foot">
      <form method="post" action="<?= h(url('/adm/index.php?m=' . FILTER_BOT_MODULE_CODE . '&do=install_db')) ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <button class="btn btn--accent" type="submit">Создать БД</button>
      </form>
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
              <td>TG webhook URL</td>
              <td><code><?= h($tgWebhookUrl) ?></code></td>
            </tr>
            <tr>
              <td>MAX webhook URL</td>
              <td><code><?= h($maxWebhookUrl) ?></code></td>
            </tr>
            <tr>
              <td>Модуль</td>
              <td><strong><?= ((int)($settings['enabled'] ?? 0) === 1) ? 'ON' : 'OFF' ?></strong></td>
            </tr>
            <tr>
              <td>Telegram</td>
              <td><strong><?= ((int)($settings['tg_enabled'] ?? 0) === 1) ? 'ON' : 'OFF' ?></strong></td>
            </tr>
            <tr>
              <td>MAX</td>
              <td><strong><?= ((int)($settings['max_enabled'] ?? 0) === 1) ? 'ON' : 'OFF' ?></strong></td>
            </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="l-col l-col--6 l-col--lg-12">
    <div class="card">
      <div class="card__title">Подключённые чаты</div>
      <div class="card__body">
        <div class="muted">Фильтрация работает только для чатов/каналов, добавленных в таблицу ниже.</div>
        <div style="margin-top:8px;"><strong><?= count($channels) ?></strong> шт.</div>
      </div>
    </div>
  </div>
</section>

<section class="card u-mb-12">
  <div class="card__title">Настройки</div>
  <form method="post" action="<?= h(url('/adm/index.php?m=' . FILTER_BOT_MODULE_CODE . '&do=save')) ?>">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

    <div class="card__body field--stack">
      <label class="field">
        <input type="checkbox" name="enabled" value="1" <?= ((int)($settings['enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
        <span>Включить модуль</span>
      </label>

      <label class="field">
        <input type="checkbox" name="log_enabled" value="1" <?= ((int)($settings['log_enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
        <span>Писать журнал модерации</span>
      </label>

      <div class="l-row">
        <div class="l-col l-col--6 l-col--lg-12">
          <div class="card">
            <div class="card__title">Telegram</div>
            <div class="card__body field--stack">
              <label class="field">
                <input type="checkbox" name="tg_enabled" value="1" <?= ((int)($settings['tg_enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
                <span>Включить Telegram</span>
              </label>

              <label class="field field--stack">
                <span class="field__label">TG bot token</span>
                <input class="select" type="text" name="tg_bot_token" maxlength="255" value="<?= h((string)($settings['tg_bot_token'] ?? '')) ?>">
              </label>

              <label class="field field--stack">
                <span class="field__label">TG webhook secret</span>
                <input class="select" type="text" name="tg_webhook_secret" maxlength="255" value="<?= h((string)($settings['tg_webhook_secret'] ?? '')) ?>">
              </label>

              <label class="field">
                <input type="checkbox" name="tg_allow_private" value="1" <?= ((int)($settings['tg_allow_private'] ?? 0) === 1) ? 'checked' : '' ?>>
                <span>Разрешать личные чаты</span>
              </label>

              <label class="field">
                <input type="checkbox" name="tg_skip_admins" value="1" <?= ((int)($settings['tg_skip_admins'] ?? 0) === 1) ? 'checked' : '' ?>>
                <span>Не трогать админов / анонимных админов / посты канала</span>
              </label>

              <label class="field">
                <input type="checkbox" name="apply_tg_webhook" value="1">
                <span>После сохранения применить webhook в Telegram</span>
              </label>
            </div>
          </div>
        </div>

        <div class="l-col l-col--6 l-col--lg-12">
          <div class="card">
            <div class="card__title">MAX</div>
            <div class="card__body field--stack">
              <label class="field">
                <input type="checkbox" name="max_enabled" value="1" <?= ((int)($settings['max_enabled'] ?? 0) === 1) ? 'checked' : '' ?>>
                <span>Включить MAX</span>
              </label>

              <label class="field field--stack">
                <span class="field__label">MAX API key</span>
                <input class="select" type="text" name="max_api_key" maxlength="255" value="<?= h((string)($settings['max_api_key'] ?? '')) ?>">
              </label>

              <label class="field field--stack">
                <span class="field__label">MAX base URL</span>
                <input class="select" type="text" name="max_base_url" maxlength="255" value="<?= h((string)($settings['max_base_url'] ?? 'https://platform-api.max.ru')) ?>">
              </label>

              <label class="field">
                <input type="checkbox" name="max_skip_admins" value="1" <?= ((int)($settings['max_skip_admins'] ?? 0) === 1) ? 'checked' : '' ?>>
                <span>По возможности не трогать админов</span>
              </label>
            </div>
          </div>
        </div>
      </div>

      <div class="l-row">
        <div class="l-col l-col--6 l-col--lg-12">
          <label class="field field--stack">
            <span class="field__label">Предупреждение TG: мат</span>
            <input class="select" type="text" name="warn_badword_text" maxlength="255" value="<?= h((string)($settings['warn_badword_text'] ?? '')) ?>">
          </label>
        </div>
        <div class="l-col l-col--6 l-col--lg-12">
          <label class="field field--stack">
            <span class="field__label">Предупреждение TG: ссылка</span>
            <input class="select" type="text" name="warn_link_text" maxlength="255" value="<?= h((string)($settings['warn_link_text'] ?? '')) ?>">
          </label>
        </div>
      </div>

      <div class="l-row">
        <div class="l-col l-col--6 l-col--lg-12">
          <label class="field field--stack">
            <span class="field__label">Текст замены MAX: мат</span>
            <input class="select" type="text" name="max_warn_badword_text" maxlength="255" value="<?= h((string)($settings['max_warn_badword_text'] ?? '')) ?>">
          </label>
        </div>
        <div class="l-col l-col--6 l-col--lg-12">
          <label class="field field--stack">
            <span class="field__label">Текст замены MAX: ссылка</span>
            <input class="select" type="text" name="max_warn_link_text" maxlength="255" value="<?= h((string)($settings['max_warn_link_text'] ?? '')) ?>">
          </label>
        </div>
      </div>

      <label class="field field--stack">
        <span class="field__label">Словарь badwords</span>
        <textarea class="select" name="badwords_list" rows="10"><?= h((string)($settings['badwords_list'] ?? '')) ?></textarea>
        <div class="muted">Поддерживаются строки вида <code>word:слово</code> и <code>flex:корень</code>. Одна запись на строку.</div>
      </label>

      <label class="field field--stack">
        <span class="field__label">Белый список доменов</span>
        <textarea class="select" name="allowed_domains_list" rows="8"><?= h((string)($settings['allowed_domains_list'] ?? '')) ?></textarea>
        <div class="muted">Если в сообщении найден URL вне этого списка, сообщение считается запрещённым. Один домен на строку.</div>
      </label>
    </div>

    <div class="card__foot">
      <button class="btn btn--accent" type="submit">Сохранить</button>
    </div>
  </form>
</section>

<section class="card u-mb-12">
  <div class="card__title">Добавить чат / канал</div>
  <form method="post" action="<?= h(url('/adm/index.php?m=' . FILTER_BOT_MODULE_CODE . '&do=channel_add')) ?>">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <div class="card__body">
      <div class="l-row">
        <div class="l-col l-col--3 l-col--lg-12">
          <label class="field field--stack">
            <span class="field__label">Платформа</span>
            <select class="select" name="platform">
              <option value="tg">Telegram</option>
              <option value="max">MAX</option>
            </select>
          </label>
        </div>
        <div class="l-col l-col--3 l-col--lg-12">
          <label class="field field--stack">
            <span class="field__label">chat_id</span>
            <input class="select" type="text" name="chat_id" maxlength="64" value="">
          </label>
        </div>
        <div class="l-col l-col--4 l-col--lg-12">
          <label class="field field--stack">
            <span class="field__label">Название</span>
            <input class="select" type="text" name="chat_title" maxlength="190" value="">
          </label>
        </div>
        <div class="l-col l-col--2 l-col--lg-12">
          <label class="field field--stack">
            <span class="field__label">Тип</span>
            <input class="select" type="text" name="chat_type" maxlength="32" value="">
          </label>
        </div>
      </div>
    </div>
    <div class="card__foot">
      <button class="btn" type="submit">Добавить</button>
    </div>
  </form>
</section>

<section class="card u-mb-12">
  <div class="card__title">Чаты / каналы</div>
  <div class="card__body">
    <?php if (!$channels): ?>
      <div class="muted">Пока ничего не добавлено.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--fit">
          <thead>
          <tr>
            <th>ID</th>
            <th>Платформа</th>
            <th>chat_id</th>
            <th>Название</th>
            <th>Тип</th>
            <th>Статус</th>
            <th>Last seen</th>
            <th></th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($channels as $row): ?>
            <?php $id = (int)($row['id'] ?? 0); $enabled = ((int)($row['enabled'] ?? 0) === 1); ?>
            <tr>
              <td><?= $id ?></td>
              <td><?= h((string)($row['platform'] ?? '')) ?></td>
              <td><code><?= h((string)($row['chat_id'] ?? '')) ?></code></td>
              <td><?= h((string)($row['chat_title'] ?? '')) ?></td>
              <td><?= h((string)($row['chat_type'] ?? '')) ?></td>
              <td><?= $enabled ? 'ON' : 'OFF' ?></td>
              <td><?= h((string)($row['last_seen_at'] ?? '')) ?></td>
              <td class="t-right">
                <div class="form-actions">
                  <form method="post" action="<?= h(url('/adm/index.php?m=' . FILTER_BOT_MODULE_CODE . '&do=channel_toggle')) ?>">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="enabled" value="<?= $enabled ? '0' : '1' ?>">
                    <button class="btn" type="submit"><?= $enabled ? 'Выключить' : 'Включить' ?></button>
                  </form>
                  <form method="post" action="<?= h(url('/adm/index.php?m=' . FILTER_BOT_MODULE_CODE . '&do=channel_delete')) ?>" onsubmit="return confirm('Удалить чат из фильтра?');">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <button class="btn" type="submit">Удалить</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="card">
  <div class="card__title">Последние события</div>
  <div class="card__body">
    <?php if (!$logs): ?>
      <div class="muted">Журнал пуст.</div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table table--fit">
          <thead>
          <tr>
            <th>Когда</th>
            <th>Платформа</th>
            <th>Чат</th>
            <th>Сообщение</th>
            <th>Правило</th>
            <th>Действие</th>
            <th>Статус</th>
            <th>Ошибка</th>
          </tr>
          </thead>
          <tbody>
          <?php foreach ($logs as $row): ?>
            <tr>
              <td><?= h((string)($row['created_at'] ?? '')) ?></td>
              <td><?= h((string)($row['platform'] ?? '')) ?></td>
              <td><code><?= h((string)($row['chat_id'] ?? '')) ?></code></td>
              <td><?= h((string)($row['message_text'] ?? '')) ?></td>
              <td><?= h((string)($row['rule_code'] ?? '')) ?></td>
              <td><?= h((string)($row['action_code'] ?? '')) ?></td>
              <td><?= h((string)($row['status'] ?? '')) ?></td>
              <td><?= h((string)($row['error_text'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
