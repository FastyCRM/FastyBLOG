<?php
/**
 * FILE: /adm/modules/bot_adv_calendar/bot_adv_calendar.php
 * ROLE: VIEW модуля bot_adv_calendar
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/bot_adv_calendar_lib.php';

acl_guard(module_allowed_roles('bot_adv_calendar'));

$pdo = db();
$csrf = csrf_token();
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$canManage = bot_adv_calendar_is_manage_role($roles);
$isAdmin = in_array('admin', $roles, true);

$schemaError = '';
$settings = bot_adv_calendar_settings_defaults();
$userRows = [];
$clientLinks = [];
$userAttached = false;
$userOptions = bot_adv_calendar_user_options_defaults();
$userWindows = [];

try {
  bot_adv_calendar_require_schema($pdo);
  $settings = bot_adv_calendar_settings_get($pdo);
  if ($canManage) {
    $userRows = bot_adv_calendar_users_manage_list($pdo, 500);
    $clientLinks = bot_adv_calendar_links_list($pdo, 'client', 200);
  } else {
    $userAttached = bot_adv_calendar_user_is_attached($pdo, $uid);
    if ($userAttached) {
      $userOptions = bot_adv_calendar_user_options_get($pdo, $uid);
      $userWindows = bot_adv_calendar_user_windows_list($pdo, $uid);
    }
  }
} catch (Throwable $e) {
  $schemaError = trim($e->getMessage());
}

$defaultWebhookUrl = (string)url('/adm/modules/bot_adv_calendar/webhook.php');
if (trim((string)($settings['webhook_url'] ?? '')) === '') {
  $settings['webhook_url'] = $defaultWebhookUrl;
}

$botEnabled = ((int)($settings['enabled'] ?? 0) === 1);
$tokenFilled = (trim((string)($settings['bot_token'] ?? '')) !== '');
$statusText = $botEnabled ? 'Включен' : 'Выключен';
$tokenText = $tokenFilled ? 'заполнен' : 'пусто';
$webhookUrl = trim((string)($settings['webhook_url'] ?? ''));
$tokenTtl = (int)($settings['token_ttl_minutes'] ?? 15);
$retentionDays = (int)($settings['retention_days'] ?? 7);
$roleText = $canManage ? 'admin, manager' : 'user';
$weekdayMap = bot_adv_calendar_weekday_map();
$dayoffMask = bot_adv_calendar_dayoff_mask_normalize((string)($userOptions['dayoff_mask'] ?? '0000011'));
$dayoffSet = [];
for ($i = 1; $i <= 7; $i++) {
  if (substr($dayoffMask, $i - 1, 1) === '1') {
    $dayoffSet[$i] = true;
  }
}
?>

<link rel="stylesheet" href="<?= h(url('/adm/modules/bot_adv_calendar/assets/css/main.css')) ?>">

<section class="bac-top">
  <article class="card bac-profile-card">
    <div class="card__head bac-profile-card__head">
      <div class="bac-profile-card__identity">
        <div class="bac-profile-card__label">Bot Adv Calendar</div>
        <div class="bac-profile-card__name">Управление рекламным Telegram-ботом</div>
        <div class="bac-profile-card__meta">
          <span>Статус: <strong><?= h($statusText) ?></strong></span>
          <span>Token: <strong><?= h($tokenText) ?></strong></span>
          <span>Роль интерфейса: <strong><?= h($roleText) ?></strong></span>
        </div>
      </div>

      <?php if ($canManage): ?>
        <div class="bac-profile-card__tools">
          <button class="iconbtn iconbtn--sm"
                  type="button"
                  data-bac-open-modal="1"
                  data-bac-modal="<?= h(url('/adm/index.php?m=bot_adv_calendar&do=modal_settings')) ?>"
                  title="Настройки бота">
            <i class="bi bi-gear"></i>
          </button>

          <form method="post" action="<?= h(url('/adm/index.php?m=bot_adv_calendar&do=webhook_set')) ?>" class="table__actionform">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button class="iconbtn iconbtn--sm" type="submit" name="mode" value="info" title="Проверить webhook">
              <i class="bi bi-info-circle"></i>
            </button>
          </form>

          <form method="post" action="<?= h(url('/adm/index.php?m=bot_adv_calendar&do=webhook_set')) ?>" class="table__actionform">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button class="iconbtn iconbtn--sm" type="submit" name="mode" value="set" title="Установить webhook">
              <i class="bi bi-plug"></i>
            </button>
          </form>

          <button class="iconbtn iconbtn--sm"
                  type="button"
                  data-bac-open-modal="1"
                  data-bac-modal="<?= h(url('/adm/index.php?m=bot_adv_calendar&do=modal_generate&actor_type=user')) ?>"
                  title="Подключить пользователя CRM">
            <i class="bi bi-person-plus"></i>
          </button>
          <?php if ($isAdmin): ?>
            <form method="post"
                  action="<?= h(url('/adm/index.php?m=bot_adv_calendar&do=requests_clear')) ?>"
                  class="table__actionform"
                  onsubmit="return confirm('ТЕСТОВЫЙ РЕЖИМ: удалить все заявки и освободить все окна?');">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="confirm_clear" value="yes">
              <button class="iconbtn iconbtn--sm" type="submit" title="Тест: очистить заявки">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card__body">
      <div class="bac-overview-grid">
        <div class="bac-overview-item">
          <div class="bac-overview-item__label">Webhook URL</div>
          <div class="bac-overview-item__value mono"><?= h($webhookUrl !== '' ? $webhookUrl : $defaultWebhookUrl) ?></div>
        </div>

        <div class="bac-overview-item">
          <div class="bac-overview-item__label">Рекомендуемый endpoint</div>
          <div class="bac-overview-item__value mono"><?= h($defaultWebhookUrl) ?></div>
        </div>

        <div class="bac-overview-item">
          <div class="bac-overview-item__label">TTL кода</div>
          <div class="bac-overview-item__value"><?= (int)$tokenTtl ?> мин</div>
        </div>

        <div class="bac-overview-item">
          <div class="bac-overview-item__label">Срок хранения логов</div>
          <div class="bac-overview-item__value"><?= (int)$retentionDays ?> дн.</div>
        </div>

        <div class="bac-overview-item bac-overview-item--wide">
          <div class="bac-overview-item__label">Привязка в боте</div>
          <div class="bac-overview-item__value">Пользователь CRM: сначала подключение в таблице ниже, затем опциональная TG-привязка через <span class="mono">/start 123456</span>. Клиент: имя + телефон, авторегистрация в `clients`.</div>
        </div>
      </div>

      <?php if ($schemaError !== ''): ?>
        <div class="bac-alert muted">
          <div><strong>Проблема схемы БД:</strong> <?= h($schemaError) ?></div>
          <div>Выполните вручную: <span class="mono">adm/modules/bot_adv_calendar/install.sql</span> (новая установка) или <span class="mono">adm/modules/bot_adv_calendar/update.sql</span> (обновление).</div>
        </div>
      <?php endif; ?>
    </div>
  </article>
</section>

<?php if ($canManage): ?>
  <section class="l-row bac-grid">
    <article class="card bac-table-card l-col l-col--12">
      <div class="card__head bac-table-card__head">
        <div>
          <div class="card__title">Подключенные пользователи CRM</div>
          <div class="card__hint muted">Сначала подключение к модулю в CRM. После этого можно выдать TG-код для управления ботом через Telegram.</div>
        </div>
      </div>
      <div class="card__body">
        <div class="table-wrap table-wrap--compact-grid">
          <table class="table table--compact-grid">
            <thead>
              <tr>
                <th class="table-col-id">ID</th>
                <th>Пользователь</th>
                <th>Статус</th>
                <th>Telegram</th>
                <th>Последняя активность</th>
                <th class="t-right">Действия</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($userRows as $row): ?>
                <?php
                  $actorId = (int)($row['actor_id'] ?? 0);
                  $actorName = (string)($row['actor_name'] ?? '');
                  $actorStatus = (string)($row['actor_status'] ?? '');
                  $chatId = (string)($row['chat_id'] ?? '');
                  $username = trim((string)($row['username'] ?? ''));
                  $lastSeenAt = (string)($row['last_seen_at'] ?? '');
                  $isActive = ((int)($row['is_active'] ?? 0) === 1);
                  $roleCodes = trim((string)($row['role_codes'] ?? ''));
                ?>
                <tr>
                  <td class="table-col-id mono" data-label="ID">#<?= $actorId ?></td>
                  <td data-label="Пользователь">
                    <?= h($actorName !== '' ? $actorName : ('User #' . $actorId)) ?>
                    <?php if ($roleCodes !== ''): ?>
                      <div class="muted"><?= h($roleCodes) ?></div>
                    <?php endif; ?>
                  </td>
                  <td data-label="Статус"><?= h($actorStatus !== '' ? $actorStatus : '-') ?></td>
                  <td data-label="Telegram">
                    <span class="mono"><?= h($chatId !== '' ? $chatId : '-') ?></span>
                    <?php if ($username !== ''): ?>
                      <div class="muted">@<?= h($username) ?></div>
                    <?php endif; ?>
                    <?php if (!$isActive): ?>
                      <div class="muted">не привязан</div>
                    <?php endif; ?>
                  </td>
                  <td class="mono" data-label="Активность"><?= h($lastSeenAt !== '' ? $lastSeenAt : '-') ?></td>
                  <td class="t-right" data-label="Действия">
                    <div class="table__actions">
                      <form method="post" action="<?= h(url('/adm/index.php?m=bot_adv_calendar&do=generate_link')) ?>" class="table__actionform">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="actor_type" value="user">
                        <input type="hidden" name="actor_id" value="<?= $actorId ?>">
                        <button class="iconbtn iconbtn--sm" type="submit" title="Сгенерировать новый код">
                          <i class="bi bi-link-45deg"></i>
                        </button>
                      </form>

                      <?php if ($isActive): ?>
                        <form method="post" action="<?= h(url('/adm/index.php?m=bot_adv_calendar&do=unlink')) ?>" class="table__actionform">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="actor_type" value="user">
                          <input type="hidden" name="actor_id" value="<?= $actorId ?>">
                          <button class="iconbtn iconbtn--sm" type="submit" title="Отвязать Telegram">
                            <i class="bi bi-x-circle"></i>
                          </button>
                        </form>
                      <?php endif; ?>

                      <form method="post" action="<?= h(url('/adm/index.php?m=bot_adv_calendar&do=user_detach')) ?>" class="table__actionform">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="user_id" value="<?= $actorId ?>">
                        <button class="iconbtn iconbtn--sm" type="submit" title="Отключить пользователя от модуля">
                          <i class="bi bi-person-x"></i>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$userRows): ?>
                <tr class="table-row--empty">
                  <td colspan="6" class="muted table-cell--empty">Подключенных пользователей CRM пока нет. Добавьте пользователя кнопкой в шапке модуля.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </article>

    <article class="card bac-table-card l-col l-col--12">
      <div class="card__head bac-table-card__head">
        <div>
          <div class="card__title">Привязанные клиенты</div>
          <div class="card__hint muted">Клиенты CRM (`clients.id`), привязанные к Telegram.</div>
        </div>
      </div>
      <div class="card__body">
        <div class="table-wrap table-wrap--compact-grid">
          <table class="table table--compact-grid">
            <thead>
              <tr>
                <th class="table-col-id">ID</th>
                <th>Клиент</th>
                <th>Статус</th>
                <th>Telegram</th>
                <th>Последняя активность</th>
                <th class="t-right">Действия</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($clientLinks as $row): ?>
                <?php
                  $actorId = (int)($row['actor_id'] ?? 0);
                  $actorName = (string)($row['actor_name'] ?? '');
                  $actorStatus = (string)($row['actor_status'] ?? '');
                  $chatId = (string)($row['chat_id'] ?? '');
                  $username = trim((string)($row['username'] ?? ''));
                  $lastSeenAt = (string)($row['last_seen_at'] ?? '');
                  $isActive = ((int)($row['is_active'] ?? 0) === 1);
                ?>
                <tr>
                  <td class="table-col-id mono" data-label="ID">#<?= $actorId ?></td>
                  <td data-label="Клиент"><?= h($actorName !== '' ? $actorName : ('Клиент #' . $actorId)) ?></td>
                  <td data-label="Статус"><?= h($actorStatus !== '' ? $actorStatus : '-') ?></td>
                  <td data-label="Telegram">
                    <span class="mono"><?= h($chatId !== '' ? $chatId : '-') ?></span>
                    <?php if ($username !== ''): ?>
                      <div class="muted">@<?= h($username) ?></div>
                    <?php endif; ?>
                    <?php if (!$isActive): ?>
                      <div class="muted">не привязан</div>
                    <?php endif; ?>
                  </td>
                  <td class="mono" data-label="Активность"><?= h($lastSeenAt !== '' ? $lastSeenAt : '-') ?></td>
                  <td class="t-right" data-label="Действия">
                    <div class="table__actions">

                      <?php if ($isActive): ?>
                        <form method="post" action="<?= h(url('/adm/index.php?m=bot_adv_calendar&do=unlink')) ?>" class="table__actionform">
                          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                          <input type="hidden" name="actor_type" value="client">
                          <input type="hidden" name="actor_id" value="<?= $actorId ?>">
                          <button class="iconbtn iconbtn--sm" type="submit" title="Отвязать">
                            <i class="bi bi-x-circle"></i>
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>

              <?php if (!$clientLinks): ?>
                <tr class="table-row--empty">
                  <td colspan="6" class="muted table-cell--empty">Привязки клиентов пока отсутствуют.</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </article>
  </section>
<?php else: ?>
  <?php if (!$userAttached): ?>
    <section class="card">
      <div class="card__body">
        <div class="card__title">Доступ ожидает подключения</div>
        <div class="muted" style="margin-top:8px;">
          Ваш CRM-пользователь пока не подключен к `bot_adv_calendar`.
          Попросите администратора подключить вас в модуле.
        </div>
      </div>
    </section>
  <?php else: ?>
    <section class="l-row bac-grid">
      <article class="card bac-table-card l-col l-col--12">
        <div class="card__head bac-table-card__head">
          <div>
            <div class="card__title">Настройки рекламного календаря</div>
            <div class="card__hint muted">CRM-режим: настройка графика и параметров публикации без обязательного Telegram.</div>
          </div>
        </div>
        <div class="card__body">
          <form method="post" action="<?= h(url('/adm/index.php?m=bot_adv_calendar&do=user_settings_save')) ?>" class="form">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <div class="bac-overview-grid">
              <label class="field field--stack">
                <span class="field__label">Режим бронирования</span>
                <select class="select" name="booking_mode">
                  <option value="fixed" <?= ((string)($userOptions['booking_mode'] ?? '') === 'fixed') ? 'selected' : '' ?>>Фиксированные точки времени</option>
                  <option value="range" <?= ((string)($userOptions['booking_mode'] ?? '') === 'range') ? 'selected' : '' ?>>Окна времени (диапазоны)</option>
                </select>
              </label>

              <label class="field field--stack">
                <span class="field__label">Интервал (мин)</span>
                <input class="input" type="number" min="5" max="720" step="5" name="slot_interval_minutes" value="<?= (int)($userOptions['slot_interval_minutes'] ?? 60) ?>">
              </label>

              <label class="field field--stack">
                <span class="field__label">Рабочий день: начало</span>
                <input class="input" type="time" name="work_start" value="<?= h((string)($userOptions['work_start'] ?? '09:00')) ?>">
              </label>

              <label class="field field--stack">
                <span class="field__label">Рабочий день: окончание</span>
                <input class="input" type="time" name="work_end" value="<?= h((string)($userOptions['work_end'] ?? '18:00')) ?>">
              </label>
            </div>

            <div style="margin-top:12px;">
              <div class="field__label" style="margin-bottom:6px;">Выходные дни (циклично)</div>
              <div style="display:flex; flex-wrap:wrap; gap:10px 14px;">
                <?php foreach ($weekdayMap as $wd => $wdLabel): ?>
                  <label style="display:inline-flex; align-items:center; gap:6px;">
                    <input type="checkbox" name="day_off_weekdays[]" value="<?= (int)$wd ?>" <?= !empty($dayoffSet[$wd]) ? 'checked' : '' ?>>
                    <span><?= h($wdLabel) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div style="margin-top:14px;">
              <button class="btn btn--accent" type="submit">Сохранить настройки</button>
            </div>
          </form>
        </div>
      </article>

      <article class="card bac-table-card l-col l-col--12">
        <div class="card__head bac-table-card__head">
          <div>
            <div class="card__title">Тарифные окна</div>
            <div class="card__hint muted">Создайте цены для фиксированного времени или диапазонов по дням недели.</div>
          </div>
        </div>
        <div class="card__body">
          <form method="post" action="<?= h(url('/adm/index.php?m=bot_adv_calendar&do=user_window_add')) ?>" class="form">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <div class="bac-overview-grid">
              <label class="field field--stack">
                <span class="field__label">День недели</span>
                <select class="select" name="weekday">
                  <option value="0">Каждый день</option>
                  <?php foreach ($weekdayMap as $wd => $wdLabel): ?>
                    <option value="<?= (int)$wd ?>"><?= h($wdLabel) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>

              <label class="field field--stack">
                <span class="field__label">Тип окна</span>
                <select class="select" name="window_type">
                  <option value="fixed">Фиксированное время</option>
                  <option value="range">Диапазон времени</option>
                </select>
              </label>

              <label class="field field--stack">
                <span class="field__label">С</span>
                <input class="input" type="time" name="time_from" required>
              </label>

              <label class="field field--stack">
                <span class="field__label">До (для диапазона)</span>
                <input class="input" type="time" name="time_to">
              </label>

              <label class="field field--stack">
                <span class="field__label">Цена (₽)</span>
                <input class="input" type="number" min="0" step="1" name="price" required>
              </label>
            </div>

            <div style="margin-top:14px;">
              <button class="btn btn--accent" type="submit">Добавить окно</button>
            </div>
          </form>

          <div class="table-wrap table-wrap--compact-grid" style="margin-top:14px;">
            <table class="table table--compact-grid">
              <thead>
                <tr>
                  <th>День</th>
                  <th>Тип</th>
                  <th>Время</th>
                  <th>Цена</th>
                  <th class="t-right">Действия</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($userWindows as $w): ?>
                  <?php
                    $windowId = (int)($w['id'] ?? 0);
                    $weekday = (int)($w['weekday'] ?? 0);
                    $windowType = trim((string)($w['window_type'] ?? 'fixed'));
                    $timeFrom = substr((string)($w['time_from'] ?? ''), 0, 5);
                    $timeTo = substr((string)($w['time_to'] ?? ''), 0, 5);
                    $price = (int)($w['price'] ?? 0);
                    $timeLabel = $timeFrom;
                    if ($windowType === 'range') {
                      $timeLabel = $timeFrom . ' - ' . ($timeTo !== '' ? $timeTo : '--:--');
                    }
                  ?>
                  <tr>
                    <td><?= h(bot_adv_calendar_weekday_label($weekday)) ?></td>
                    <td><?= h($windowType === 'range' ? 'Диапазон' : 'Фикс') ?></td>
                    <td class="mono"><?= h($timeLabel) ?></td>
                    <td class="mono"><?= number_format($price, 0, '.', ' ') ?> ₽</td>
                    <td class="t-right">
                      <form method="post" action="<?= h(url('/adm/index.php?m=bot_adv_calendar&do=user_window_delete')) ?>" class="table__actionform">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="window_id" value="<?= $windowId ?>">
                        <button class="iconbtn iconbtn--sm" type="submit" title="Удалить окно">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>

                <?php if (!$userWindows): ?>
                  <tr class="table-row--empty">
                    <td colspan="5" class="muted table-cell--empty">Тарифные окна пока не добавлены.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </article>
    </section>
  <?php endif; ?>
<?php endif; ?>

<script src="<?= h(url('/adm/modules/bot_adv_calendar/assets/js/main.js')) ?>"></script>
