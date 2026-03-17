<?php
/**
 * FILE: /adm/modules/dashboard/dashboard.php
 * ROLE: VIEW модуля dashboard (реальные данные пользователя и заявок)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/dashboard_lib.php';

/**
 * ACL: доступ к dashboard
 */
acl_guard(module_allowed_roles('dashboard'));

/**
 * $pdo — соединение с БД
 */
$pdo = db();

/**
 * $uid — текущий пользователь
 */
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);

/**
 * $roles — роли пользователя
 */
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];

/**
 * $isAdmin — админ
 */
$isAdmin = in_array('admin', $roles, true);

/**
 * $isManager — менеджер
 */
$isManager = in_array('manager', $roles, true);

/**
 * $isBasicUser — обычный user (без admin/manager)
 */
$isBasicUser = in_array('user', $roles, true) && !$isAdmin && !$isManager;

/**
 * $showSystemLine — показывать 2-ю линию дашборда
 */
$showSystemLine = !$isBasicUser;

/**
 * $profile — данные пользователя
 */
$profile = dashboard_user_profile($pdo, $uid);

/**
 * $notifications — непросмотренные оповещения (временная заглушка)
 */
$notifications = dashboard_unread_notifications_stub();

/**
 * $systemCards — блоки системной информации (временные заглушки)
 */
$systemCards = $showSystemLine ? dashboard_system_cards_stub() : [];

/**
 * $recentDone — последние выполненные заявки пользователя
 */
$recentDone = dashboard_recent_done_requests($pdo, $uid, 4);

/**
 * $salary — расчёт ЗП по выполненным заявкам за 30 дней
 */
$salary = dashboard_salary_summary($pdo, $uid);

/**
 * $notifyCount — количество непросмотренных уведомлений
 */
$notifyCount = count($notifications);

/**
 * $salaryPercentRaw — процент сдельной оплаты
 */
$salaryPercentRaw = (float)($salary['percent'] ?? 40);

/**
 * $salaryPercentText — форматированный процент для UI
 */
$salaryPercentText = ((abs($salaryPercentRaw - round($salaryPercentRaw)) < 0.0001)
  ? (string)((int)round($salaryPercentRaw))
  : str_replace('.', ',', (string)$salaryPercentRaw));
?>

<link rel="stylesheet" href="<?= h(url('/adm/modules/dashboard/assets/css/main.css')) ?>">

<section class="dash-top">
  <article class="card dash-profile-card">
    <div class="card__head dash-profile-card__head">
      <div class="dash-profile-card__identity">
        <div class="dash-profile-card__label"><?= h(dashboard_t('dashboard.profile_label_name')) ?></div>
        <div class="dash-profile-card__name"><?= h((string)($profile['full_name'] ?? '—')) ?></div>

        <div class="dash-profile-card__meta">
          <span><?= h(dashboard_t('dashboard.profile_label_phone')) ?>: <?= h((string)($profile['phone'] ?: '—')) ?></span>
          <span><?= h(dashboard_t('dashboard.profile_label_email')) ?>: <?= h((string)($profile['email'] ?: dashboard_t('dashboard.profile_email_empty'))) ?></span>
          <span><?= h(dashboard_t('dashboard.profile_label_theme')) ?>: <?= h((string)($profile['ui_theme'] ?: 'color')) ?></span>
        </div>
      </div>

      <div class="dash-profile-card__tools">
        <details class="dash-notify" data-dashboard-notify="1">
          <summary class="iconbtn iconbtn--sm" aria-label="<?= h(dashboard_t('dashboard.notifications_aria')) ?>" title="<?= h(dashboard_t('dashboard.notifications_aria')) ?>">
            <i class="bi bi-bell"></i>
            <?php if ($notifyCount > 0): ?>
              <span class="dash-notify__badge"><?= (int)$notifyCount ?></span>
            <?php endif; ?>
          </summary>

          <div class="dash-notify__panel">
            <div class="dash-notify__head">
              <div class="dash-notify__head-title"><?= h(dashboard_t('dashboard.notifications_title')) ?></div>
              <div class="dash-notify__head-hint muted"><?= h(dashboard_t('dashboard.notifications_hint_stub')) ?></div>
            </div>

            <?php if (!$notifications): ?>
              <div class="dash-notify__empty muted"><?= h(dashboard_t('dashboard.notifications_empty')) ?></div>
            <?php else: ?>
              <ul class="dash-notify__list">
                <?php foreach ($notifications as $item): ?>
                  <li class="dash-notify__item">
                    <div class="dash-notify__title"><?= h((string)($item['title'] ?? dashboard_t('dashboard.notification_fallback_title'))) ?></div>
                    <div class="dash-notify__text"><?= h((string)($item['text'] ?? '')) ?></div>
                    <div class="dash-notify__time muted"><?= h((string)($item['time'] ?? '')) ?></div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </details>

        <button class="iconbtn iconbtn--sm"
                type="button"
                aria-label="<?= h(dashboard_t('dashboard.profile_settings_aria')) ?>"
                title="<?= h(dashboard_t('dashboard.profile_settings_aria')) ?>"
                data-dashboard-open-modal="1"
                data-dashboard-modal="<?= h(url('/adm/index.php?m=dashboard&do=modal_settings')) ?>">
          <i class="bi bi-gear"></i>
        </button>
      </div>
    </div>
  </article>
</section>

<?php if ($showSystemLine): ?>
  <section class="dash-system-grid l-grid-auto">
    <?php foreach ($systemCards as $card): ?>
      <article class="card">
        <div class="card__head">
          <div class="card__title"><?= h((string)($card['title'] ?? dashboard_t('dashboard.system_card_fallback_title'))) ?></div>
        </div>
        <div class="card__body">
          <div class="muted"><?= h((string)($card['text'] ?? '')) ?></div>
          <a class="btn" href="<?= h((string)($card['action_url'] ?? url('/adm/index.php?m=dashboard'))) ?>">
            <?= h((string)($card['action_label'] ?? dashboard_t('dashboard.system_card_fallback_action'))) ?>
          </a>
        </div>
      </article>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<section class="dash-bottom-grid l-row">
  <article class="card dash-recent-card l-col l-col--8 l-col--xl-8 l-col--lg-12">
    <div class="card__head">
      <div class="card__title"><?= h(dashboard_t('dashboard.recent_done_title')) ?></div>
      <a class="btn" href="<?= h(url('/adm/index.php?m=requests')) ?>"><?= h(dashboard_t('dashboard.requests_all')) ?></a>
    </div>

    <div class="card__body">
      <div class="table-wrap table-wrap--compact-grid">
        <table class="table table--compact-grid">
          <thead>
            <tr>
              <th class="table-col-id">ID</th>
              <th><?= h(dashboard_t('dashboard.table_client')) ?></th>
              <th><?= h(dashboard_t('dashboard.table_service')) ?></th>
              <th><?= h(dashboard_t('dashboard.table_date')) ?></th>
              <th class="t-right"><?= h(dashboard_t('dashboard.table_amount')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$recentDone): ?>
              <tr class="table-row--empty">
                <td colspan="5" class="muted table-cell--empty"><?= h(dashboard_t('dashboard.recent_done_empty')) ?></td>
              </tr>
            <?php else: ?>
              <?php foreach ($recentDone as $row): ?>
                <?php
                  /**
                   * $doneRaw — исходная дата завершения
                   */
                  $doneRaw = (string)($row['done_at'] ?? '');

                  /**
                   * $doneTs — timestamp даты завершения
                   */
                  $doneTs = ($doneRaw !== '') ? strtotime($doneRaw) : false;

                  /**
                   * $doneText — человекочитаемая дата
                   */
                  $doneText = $doneTs ? date('d.m.Y', $doneTs) : '—';

                  /**
                   * $sumText — форматированная сумма
                   */
                  $sumText = dashboard_money((float)($row['total'] ?? 0)) . ' ₽';
                ?>
                <tr>
                  <td class="table-col-id" data-label="ID">#<?= (int)($row['id'] ?? 0) ?></td>
                  <td data-label="<?= h(dashboard_t('dashboard.table_client')) ?>"><?= h((string)($row['client_name'] ?? '—')) ?></td>
                  <td data-label="<?= h(dashboard_t('dashboard.table_service')) ?>"><?= h((string)($row['service_name'] ?? '—')) ?></td>
                  <td data-label="<?= h(dashboard_t('dashboard.table_date')) ?>"><?= h($doneText) ?></td>
                  <td class="t-right" data-label="<?= h(dashboard_t('dashboard.table_amount')) ?>"><?= h($sumText) ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </article>

  <article class="card dash-salary l-col l-col--4 l-col--xl-4 l-col--lg-12">
    <div class="card__head">
      <div class="card__title"><?= h(dashboard_t('dashboard.salary_title')) ?></div>
      <div class="card__hint muted">
        <?= h(dashboard_t('dashboard.salary_hint_period', [
          'percent' => $salaryPercentText,
          'days' => (int)($salary['period_days'] ?? 30),
        ])) ?>
      </div>
    </div>

    <div class="card__body">
      <div class="dash-salary__meta">
        <span class="muted"><?= h(dashboard_t('dashboard.salary_done_count')) ?></span>
        <strong><?= (int)($salary['done_count'] ?? 0) ?></strong>
      </div>

      <div class="dash-salary__meta">
        <span class="muted"><?= h(dashboard_t('dashboard.salary_done_sum')) ?></span>
        <strong><?= h(dashboard_money((float)($salary['done_sum'] ?? 0)) . ' ₽') ?></strong>
      </div>

      <div class="dash-salary__meta">
        <span class="muted"><?= h(dashboard_t('dashboard.salary_piecework')) ?></span>
        <strong><?= !empty($salary['enabled']) ? h(dashboard_t('dashboard.salary_piecework_on')) : h(dashboard_t('dashboard.salary_piecework_off')) ?></strong>
      </div>

      <div class="dash-salary__value">
        <?= h(dashboard_money((float)($salary['salary'] ?? 0)) . ' ₽') ?>
      </div>
    </div>
  </article>
</section>

<script src="<?= h(url('/adm/modules/dashboard/assets/js/main.js')) ?>"></script>
