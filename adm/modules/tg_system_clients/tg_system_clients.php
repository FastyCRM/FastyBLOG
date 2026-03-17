<?php
/**
 * FILE: /adm/modules/tg_system_clients/tg_system_clients.php
 * ROLE: VIEW модуля Telegram-уведомлений для клиентов
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/tg_system_clients_lib.php';

acl_guard(module_allowed_roles('tg_system_clients'));

$pdo = db();
$csrf = csrf_token();
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$canManage = tg_system_clients_is_manage_role($roles);

$schemaError = '';
$settings = tg_system_clients_settings_defaults();
$events = [];
$clients = [];

$searchQ = trim((string)($_GET['q'] ?? ''));
$selectedClientId = (int)($_GET['client_id'] ?? 0);
$searchDigits = preg_replace('/\D+/', '', $searchQ) ?? '';
$searchLen = function_exists('mb_strlen') ? (int)mb_strlen($searchQ, 'UTF-8') : strlen($searchQ);
$canRunSearch = ($searchQ !== '' && ($searchLen >= 2 || strlen($searchDigits) >= 4));
$searchError = '';
$clientsSearchApi = url('/adm/index.php?m=clients&do=api_search');

try {
  tg_system_clients_require_schema($pdo);
  $settings = tg_system_clients_settings_get($pdo);
  $events = tg_system_clients_events_get($pdo);

  if ($selectedClientId > 0) {
    $selected = tg_system_clients_user_with_link_by_id($pdo, $selectedClientId);
    if ($selected) {
      $clients = [$selected];
      if ($searchQ === '') {
        $searchQ = trim((string)($selected['name'] ?? ''));
      }
    }
  } elseif ($canRunSearch) {
    $clients = tg_system_clients_users_with_links($pdo, 200, $searchQ);
  } elseif ($searchQ !== '') {
    $searchError = 'Введите минимум 2 буквы или 4 цифры для поиска.';
  }
} catch (Throwable $e) {
  $schemaError = trim($e->getMessage());
}
?>

<link rel="stylesheet" href="<?= h(url('/adm/modules/tg_system_clients/assets/css/main.css')) ?>">

<section class="tg-su-head card">
  <div class="card__body tg-su-head__body">
    <div class="tg-su-head__left">
      <h1>Telegram: уведомления клиентам</h1>
      <div class="muted">Отдельный бот для клиентов CRM. Привязка по 4-значному коду из модуля.</div>
    </div>

    <div class="tg-su-head__right">
      <?php if ($canManage): ?>
        <button class="iconbtn iconbtn--sm"
                type="button"
                data-tg-su-open-modal="1"
                data-tg-su-modal="<?= h(url('/adm/index.php?m=tg_system_clients&do=modal_settings')) ?>"
                aria-label="Настройки"
                title="Настройки">
          <i class="bi bi-gear"></i>
        </button>
      <?php endif; ?>
    </div>
  </div>
</section>

<section class="tg-su-grid">
  <article class="card">
    <div class="card__head">
      <div class="card__title">Состояние бота</div>
    </div>
    <div class="card__body tg-su-status">
      <div><span class="muted">Включён:</span> <strong><?= ((int)$settings['enabled'] === 1) ? 'да' : 'нет' ?></strong></div>
      <div><span class="muted">Токен:</span> <strong><?= trim((string)$settings['bot_token']) !== '' ? 'заполнен' : 'пусто' ?></strong></div>
      <div><span class="muted">Webhook URL:</span> <span class="mono"><?= h((string)($settings['webhook_url'] ?: '—')) ?></span></div>
      <div><span class="muted">Секрет webhook:</span> <strong><?= trim((string)$settings['webhook_secret']) !== '' ? 'задан' : 'не задан' ?></strong></div>
      <div><span class="muted">TTL кода:</span> <strong><?= (int)($settings['token_ttl_minutes'] ?? 15) ?> мин</strong></div>
      <div><span class="muted">Хранение лога:</span> <strong><?= (int)($settings['retention_days'] ?? 7) ?> дн</strong></div>
    </div>
  </article>

  <article class="card">
    <div class="card__head">
      <div class="card__title">Быстрый вызов из модулей</div>
    </div>
    <div class="card__body">
      <pre class="tg-su-code">sendClientTG('Сообщение для клиента');</pre>
      <pre class="tg-su-code">sendClientTG('Напоминание о визите', 'requests_client_reminder_60m');</pre>
      <div class="muted">Рассылка идет только по клиентам с активной привязкой Telegram и включенным событием.</div>
    </div>
  </article>
</section>

<?php if ($schemaError !== ''): ?>
  <section class="card">
    <div class="card__body">
      <div class="muted">
        <?= h($schemaError) ?>
      </div>
      <div class="muted" style="margin-top:8px;">
        Выполните SQL вручную из файла <span class="mono">adm/modules/tg_system_clients/install.sql</span>, затем обновите страницу.
      </div>
    </div>
  </section>
<?php endif; ?>

<section class="card">
  <div class="card__head">
    <div class="card__title">Системные события (глобально)</div>
    <div class="card__hint muted">Глобальное отключение режет рассылку для всех клиентов.</div>
  </div>

  <div class="card__body">
    <div class="tablewrap">
      <table class="table table--modules">
        <thead>
          <tr>
            <th>Код</th>
            <th>Название</th>
            <th>Описание</th>
            <th style="width:110px">Глобально</th>
            <th class="t-right" style="width:90px"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($events as $event): ?>
            <?php
              $eventCode = (string)($event['event_code'] ?? '');
              $eventTitle = (string)($event['title'] ?? $eventCode);
              $eventDesc = (string)($event['description'] ?? '');
              $isEnabled = ((int)($event['global_enabled'] ?? 0) === 1);
            ?>
            <tr>
              <td class="mono"><?= h($eventCode) ?></td>
              <td><?= h($eventTitle) ?></td>
              <td><?= h($eventDesc !== '' ? $eventDesc : '—') ?></td>
              <td><?= $isEnabled ? 'ON' : 'OFF' ?></td>
              <td class="t-right">
                <?php if ($canManage): ?>
                  <form method="post" action="<?= h(url('/adm/index.php?m=tg_system_clients&do=toggle_event')) ?>" class="table__actionform">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="event_code" value="<?= h($eventCode) ?>">
                    <input type="hidden" name="enabled" value="<?= $isEnabled ? '0' : '1' ?>">
                    <button class="iconbtn iconbtn--sm"
                            type="submit"
                            aria-label="<?= $isEnabled ? 'Отключить' : 'Включить' ?>"
                            title="<?= $isEnabled ? 'Отключить' : 'Включить' ?>">
                      <i class="bi <?= $isEnabled ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                    </button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$events): ?>
            <tr>
              <td colspan="5" class="muted">События не найдены.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="card">
  <div class="card__head">
    <div class="card__title">Привязка клиентов к Telegram (код 4 цифры)</div>
    <div class="card__hint muted">Поиск обязателен: полный список клиентов не выводится.</div>
  </div>

  <div class="card__body tg-su-search-body">
    <form method="get"
      action="<?= h(url('/adm/index.php')) ?>"
      class="form tg-su-search-form"
      data-tg-su-client-search="1"
      data-tg-su-search-api="<?= h($clientsSearchApi) ?>"
      data-tg-su-search-active-id="<?= (int)$selectedClientId ?>">
  <input type="hidden" name="m" value="tg_system_clients">
  <input type="hidden" name="client_id" value="<?= (int)$selectedClientId ?>" data-tg-su-client-id="1">
  <div class="tg-su-search-grid">
    <label class="field field--stack tg-su-search-field">
      <span class="field__label">Поиск клиента (ИНН / телефон / ФИО)</span>
      <input class="input"
             name="q"
             value="<?= h($searchQ) ?>"
             placeholder="Введите запрос"
             autocomplete="off"
             data-tg-su-search-input="1">
      <div class="tg-su-search-results scroll-thin is-hidden" data-tg-su-search-results="1"></div>
      <div class="tg-su-search-empty is-hidden" data-tg-su-search-empty="1">Ничего не найдено.</div>
    </label>
  </div>
</form>

    <?php if ($searchError !== ''): ?>
      <div class="muted" style="margin-bottom:12px;"><?= h($searchError) ?></div>
    <?php endif; ?>

    
    <div class="tablewrap">
      <table class="table table--modules">
        <thead>
          <tr>
            <th>ID</th>
            <th>Клиент</th>
            <th>Статус</th>
            <th>Telegram</th>
            <th style="width:150px">Последняя активность</th>
            <th class="t-right" style="width:190px"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($clients as $client): ?>
            <?php
              $clientId = (int)($client['id'] ?? 0);
              $clientName = (string)($client['name'] ?? '');
              $clientPhone = (string)($client['phone'] ?? '');
              $clientInn = (string)($client['inn'] ?? '');
              $clientStatus = (string)($client['status'] ?? 'active');
              $chatId = trim((string)($client['chat_id'] ?? ''));
              $username = trim((string)($client['username'] ?? ''));
              $linkedAt = (string)($client['linked_at'] ?? '');
              $lastSeen = (string)($client['last_seen_at'] ?? '');
              $isLinkActive = ((int)($client['is_active'] ?? 0) === 1);
              $isLinked = ($chatId !== '' && $isLinkActive);
            ?>
            <tr>
              <td class="mono"><?= (int)$clientId ?></td>
              <td>
                <strong><?= h($clientName !== '' ? $clientName : ('Клиент #' . $clientId)) ?></strong>
                <div class="muted mono">
                  <?= h($clientPhone !== '' ? $clientPhone : '—') ?>
                  <?php if ($clientInn !== ''): ?> | ИНН <?= h($clientInn) ?><?php endif; ?>
                </div>
              </td>
              <td><?= h($clientStatus) ?></td>
              <td>
                <?php if ($isLinked): ?>
                  <div class="mono"><?= h($chatId) ?></div>
                  <div class="muted"><?= h($username !== '' ? ('@' . $username) : 'привязано') ?></div>
                  <?php if ($linkedAt !== ''): ?>
                    <div class="muted">с <?= h($linkedAt) ?></div>
                  <?php endif; ?>
                <?php elseif ($chatId !== ''): ?>
                  <span class="muted">отвязано</span>
                <?php else: ?>
                  <span class="muted">не привязано</span>
                <?php endif; ?>
              </td>
              <td class="mono"><?= h($lastSeen !== '' ? $lastSeen : '—') ?></td>
              <td class="t-right">
                <?php if ($canManage): ?>
                  <div class="tg-su-actions">
                    <form method="post" action="<?= h(url('/adm/index.php?m=tg_system_clients&do=generate_link')) ?>" class="table__actionform">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
                      <button class="iconbtn iconbtn--sm tg-su-btn-plain"
                              type="submit"
                              aria-label="Сгенерировать код"
                              title="Сгенерировать код">
                        <i class="bi bi-link-45deg"></i>
                      </button>
                    </form>

                    <form method="post" action="<?= h(url('/adm/index.php?m=tg_system_clients&do=send_test')) ?>" class="table__actionform">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
                      <button class="iconbtn iconbtn--sm tg-su-btn-plain"
                              type="submit"
                              aria-label="Отправить тест"
                              title="<?= $isLinked ? 'Отправить тест' : 'Тест недоступен: нет привязки Telegram' ?>"
                              <?= $isLinked ? '' : 'disabled' ?>>
                        <i class="bi bi-send"></i>
                      </button>
                    </form>

                    <form method="post" action="<?= h(url('/adm/index.php?m=tg_system_clients&do=unlink')) ?>" class="table__actionform">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
                      <button class="iconbtn iconbtn--sm tg-su-btn-plain"
                              type="submit"
                              aria-label="Отвязать Telegram"
                              title="<?= $isLinked ? 'Отвязать Telegram' : 'Отвязка недоступна: нет активной привязки' ?>"
                              <?= $isLinked ? '' : 'disabled' ?>>
                        <i class="bi bi-x-circle"></i>
                      </button>
                    </form>
                  </div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (($canRunSearch || $selectedClientId > 0) && !$clients): ?>
            <tr>
              <td colspan="6" class="muted">Клиенты не найдены.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<script src="<?= h(url('/adm/modules/tg_system_clients/assets/js/main.js')) ?>"></script>
