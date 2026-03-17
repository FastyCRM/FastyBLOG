<?php
/**
 * FILE: /adm/modules/tg_system_users/tg_system_users.php
 * ROLE: VIEW модуля системных Telegram-уведомлений для сотрудников
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/tg_system_users_lib.php';

acl_guard(module_allowed_roles('tg_system_users'));

/**
 * $pdo — соединение с БД.
 */
$pdo = db();

/**
 * $csrf — CSRF токен.
 */
$csrf = csrf_token();

/**
 * $uid — текущий пользователь.
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);

/**
 * $roles — роли текущего пользователя.
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

/**
 * $canManage — может управлять модулем.
 */
$canManage = tg_system_users_is_manage_role($roles);

/**
 * $schemaError — ошибка, если SQL модуля не применён.
 */
$schemaError = '';
/**
 * $settings — настройки бота.
 */
$settings = tg_system_users_settings_defaults();

/**
 * $events — список системных событий.
 */
$events = [];

/**
 * $users — пользователи и статус привязки Telegram.
 */
$users = [];

try {
  tg_system_users_require_schema($pdo);
  $settings = tg_system_users_settings_get($pdo);
  $events = tg_system_users_events_get($pdo);
  $users = tg_system_users_users_with_links($pdo, 300);
} catch (Throwable $e) {
  $schemaError = trim($e->getMessage());
}
?>

<link rel="stylesheet" href="<?= h(url('/adm/modules/tg_system_users/assets/css/main.css')) ?>">

<section class="tg-su-head card">
  <div class="card__body tg-su-head__body">
    <div class="tg-su-head__left">
      <h1>Telegram: системные уведомления (сотрудники)</h1>
      <div class="muted">Модуль для пользователей CRM. Клиентский бот делается отдельным модулем.</div>
    </div>

    <div class="tg-su-head__right">
      <?php if ($canManage): ?>
        <button class="iconbtn iconbtn--sm"
                type="button"
                data-tg-su-open-modal="1"
                data-tg-su-modal="<?= h(url('/adm/index.php?m=tg_system_users&do=modal_settings')) ?>"
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
      <pre class="tg-su-code">sendSystemTG('Сообщение для сотрудников');</pre>
      <pre class="tg-su-code">sendSystemTG('Плановые работы в 23:00', 'system_updates');</pre>
      <div class="muted">Доставка идёт только тем пользователям, у кого есть привязка Telegram и включено событие.</div>
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
        Выполните SQL вручную из файла <span class="mono">adm/modules/tg_system_users/install.sql</span>, затем обновите страницу.
      </div>
    </div>
  </section>
<?php endif; ?>

<section class="card">
  <div class="card__head">
    <div class="card__title">Системные события (глобально)</div>
    <div class="card__hint muted">Глобальное отключение режет рассылку для всех пользователей.</div>
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
                  <form method="post" action="<?= h(url('/adm/index.php?m=tg_system_users&do=toggle_event')) ?>" class="table__actionform">
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
    <div class="card__title">Привязка сотрудников к Telegram (код 4 цифры)</div>
    <div class="card__hint muted">Менеджер/админ генерирует 4-значный код и передаёт сотруднику.</div>
  </div>

  <div class="card__body">
    <div class="tablewrap">
      <table class="table table--modules">
        <thead>
          <tr>
            <th>ID</th>
            <th>Пользователь</th>
            <th>Роли</th>
            <th>Статус</th>
            <th>Telegram</th>
            <th style="width:150px">Последняя активность</th>
            <th class="t-right" style="width:190px"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $user): ?>
            <?php
              $userId = (int)($user['id'] ?? 0);
              $userName = (string)($user['name'] ?? '');
              $userRoles = (string)($user['roles'] ?? '');
              $userStatus = (string)($user['status'] ?? 'active');
              $chatId = trim((string)($user['chat_id'] ?? ''));
              $username = trim((string)($user['username'] ?? ''));
              $linkedAt = (string)($user['linked_at'] ?? '');
              $lastSeen = (string)($user['last_seen_at'] ?? '');
              $isLinkActive = ((int)($user['is_active'] ?? 0) === 1);
              $isLinked = ($chatId !== '' && $isLinkActive);
            ?>
            <tr>
              <td class="mono"><?= (int)$userId ?></td>
              <td>
                <strong><?= h($userName !== '' ? $userName : ('Пользователь #' . $userId)) ?></strong>
                <div class="muted mono"><?= h((string)($user['phone'] ?? '')) ?></div>
              </td>
              <td class="mono"><?= h($userRoles !== '' ? $userRoles : '—') ?></td>
              <td><?= h($userStatus) ?></td>
              <td>
                <?php if ($isLinked): ?>
                  <div class="mono"><?= h($chatId) ?></div>
                  <div class="muted"><?= h($username !== '' ? ('@' . $username) : 'привязано') ?></div>
                  <?php if ($linkedAt !== ''): ?>
                    <div class="muted">с <?= h($linkedAt) ?></div>
                  <?php endif; ?>
                <?php elseif ($chatId !== ''): ?>
                  <span class="muted">отвязан</span>
                <?php else: ?>
                  <span class="muted">не привязан</span>
                <?php endif; ?>
              </td>
              <td class="mono"><?= h($lastSeen !== '' ? $lastSeen : '—') ?></td>
              <td class="t-right">
                <?php if ($canManage): ?>
                  <div class="tg-su-actions">
                    <form method="post" action="<?= h(url('/adm/index.php?m=tg_system_users&do=generate_link')) ?>" class="table__actionform">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
                      <button class="iconbtn iconbtn--sm tg-su-btn-plain"
                              type="submit"
                              aria-label="Сгенерировать код"
                              title="Сгенерировать код">
                        <i class="bi bi-link-45deg"></i>
                      </button>
                    </form>

                    <form method="post" action="<?= h(url('/adm/index.php?m=tg_system_users&do=send_test')) ?>" class="table__actionform">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
                      <button class="iconbtn iconbtn--sm tg-su-btn-plain"
                              type="submit"
                              aria-label="Отправить тест"
                              title="<?= $isLinked ? 'Отправить тест' : 'Тест недоступен: нет привязки Telegram' ?>"
                              <?= $isLinked ? '' : 'disabled' ?>>
                        <i class="bi bi-send"></i>
                      </button>
                    </form>

                    <form method="post" action="<?= h(url('/adm/index.php?m=tg_system_users&do=unlink')) ?>" class="table__actionform">
                      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                      <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
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
          <?php if (!$users): ?>
            <tr>
              <td colspan="7" class="muted">Пользователи не найдены.</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<script src="<?= h(url('/adm/modules/tg_system_users/assets/js/main.js')) ?>"></script>
