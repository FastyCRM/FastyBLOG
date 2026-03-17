<?php
/**
 * FILE: /adm/modules/clients/clients.php
 * ROLE: Clients list + unified dropdown search.
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/assets/php/clients_search_lib.php';
require_once __DIR__ . '/assets/php/clients_i18n.php';

acl_guard(module_allowed_roles('clients'));

$pdo = db();
$csrf = csrf_token();
$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
$isAdmin = in_array('admin', $roles, true);

$q = trim((string)($_GET['q'] ?? ''));
$selectedClientId = (int)($_GET['client_id'] ?? 0);
$clientsSearchApi = url('/adm/index.php?m=clients&do=api_search');

$rows = [];

if ($selectedClientId > 0) {
  $st = $pdo->prepare("
    SELECT c.id, c.first_name, c.last_name, c.middle_name, c.phone, c.email, c.status, c.created_at
    FROM " . CLIENTS_TABLE . " c
    WHERE c.id = :id
    LIMIT 1
  ");
  $st->execute([':id' => $selectedClientId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if ($row) {
    $rows = [$row];
    if ($q === '') {
      $q = trim((string)($row['last_name'] ?? '') . ' ' . (string)($row['first_name'] ?? '') . ' ' . (string)($row['middle_name'] ?? ''));
    }
  }
} elseif ($q !== '') {
  $items = clients_search_items($pdo, $q, $uid, $roles, 200);
  $ids = [];
  foreach ($items as $item) {
    $id = (int)($item['id'] ?? 0);
    if ($id > 0) $ids[] = $id;
  }
  $ids = array_values(array_unique($ids));

  if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("
      SELECT c.id, c.first_name, c.last_name, c.middle_name, c.phone, c.email, c.status, c.created_at
      FROM " . CLIENTS_TABLE . " c
      WHERE c.id IN ($in)
    ");
    $st->execute($ids);
    $fetched = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byId = [];
    foreach ($fetched as $r) {
      $id = (int)($r['id'] ?? 0);
      if ($id > 0) $byId[$id] = $r;
    }

    foreach ($ids as $id) {
      if (isset($byId[$id])) $rows[] = $byId[$id];
    }
  }
} else {
  $st = $pdo->query("
    SELECT c.id, c.first_name, c.last_name, c.middle_name, c.phone, c.email, c.status, c.created_at
    FROM " . CLIENTS_TABLE . " c
    ORDER BY c.id DESC
    LIMIT 500
  ");
  $rows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

$clientsJsI18n = [
  'modal_title' => clients_t('clients.js_modal_title'),
  'client_fallback' => clients_t('clients.client_fallback'),
  'inn_prefix' => clients_t('clients.inn_prefix'),
  'dash' => clients_t('clients.dash'),
];
?>

<link rel="stylesheet" href="<?= h(url('/adm/modules/clients/assets/css/main.css')) ?>">

<h1><?= h(clients_t('clients.page_title')) ?></h1>

<div class="card clients-search-card">
  <div class="card__body">
    <div class="clients-search-top">
      <form method="get"
            action="<?= h(url('/adm/index.php')) ?>"
            class="clients-search-form"
            data-clients-search-root="1"
            data-clients-search-api="<?= h($clientsSearchApi) ?>"
            data-clients-search-active-id="<?= (int)$selectedClientId ?>">
        <input type="hidden" name="m" value="clients">
        <input type="hidden" name="client_id" value="<?= (int)$selectedClientId ?>" data-clients-search-client-id="1">

        <label class="field field--stack clients-search-field">
          <span class="field__label"><?= h(clients_t('clients.search_label')) ?></span>
          <input class="select"
                 style="height:40px;"
                 name="q"
                 value="<?= h($q) ?>"
                 placeholder="<?= h(clients_t('clients.search_placeholder')) ?>"
                 autocomplete="off"
                 data-clients-search-input="1">
          <div class="clients-search-results scroll-thin is-hidden" data-clients-search-results="1"></div>
          <div class="clients-search-empty is-hidden" data-clients-search-empty="1"><?= h(clients_t('clients.search_empty')) ?></div>
        </label>

        <div class="clients-search-buttons">
          <?php if ($q !== '' || $selectedClientId > 0): ?>
            <a class="btn btn--ghost" href="<?= h(url('/adm/index.php?m=clients')) ?>"><?= h(clients_t('clients.search_reset')) ?></a>
          <?php endif; ?>
        </div>
      </form>

      <div class="clients-search-actions">
        <?php if ($isAdmin): ?>
          <button class="iconbtn iconbtn--sm"
                  type="button"
                  data-clients-open-modal="1"
                  data-clients-modal="<?= h(url('/adm/index.php?m=clients&do=modal_settings')) ?>"
                  aria-label="<?= h(clients_t('clients.action_settings')) ?>"
                  title="<?= h(clients_t('clients.action_settings')) ?>">
            <i class="bi bi-gear"></i>
          </button>
        <?php endif; ?>

        <button class="iconbtn iconbtn--sm"
                type="button"
                data-clients-open-modal="1"
                data-clients-modal="<?= h(url('/adm/index.php?m=clients&do=modal_add')) ?>"
                aria-label="<?= h(clients_t('clients.action_add_client')) ?>"
                title="<?= h(clients_t('clients.action_add_client')) ?>">
          <i class="bi bi-person-plus"></i>
        </button>
      </div>
    </div>
  </div>
</div>

<div class="tablewrap">
  <table class="table table--modules">
    <thead>
      <tr>
        <th style="width:44px"></th>
        <th><?= h(clients_t('clients.col_client')) ?></th>
        <th style="width:160px"><?= h(clients_t('clients.col_phone_login')) ?></th>
        <th><?= h(clients_t('clients.col_email')) ?></th>
        <th style="width:120px"><?= h(clients_t('clients.col_status')) ?></th>
        <th style="width:170px"><?= h(clients_t('clients.col_created')) ?></th>
        <th class="t-right" style="width:140px"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
          $id = (int)($r['id'] ?? 0);
          $firstName = (string)($r['first_name'] ?? '');
          $lastName = (string)($r['last_name'] ?? '');
          $middleName = (string)($r['middle_name'] ?? '');
          $fio = trim($lastName . ' ' . $firstName . ' ' . $middleName);
          $phone = (string)($r['phone'] ?? '');
          $email = (string)($r['email'] ?? '');
          $status = (string)($r['status'] ?? 'active');
          $createdAt = (string)($r['created_at'] ?? '');
          $icon = $status === 'blocked' ? 'bi bi-person-slash' : 'bi bi-person-circle';
        ?>
        <tr>
          <td><i class="<?= h($icon) ?>"></i></td>
          <td><strong><?= h($fio !== '' ? $fio : clients_t('clients.dash')) ?></strong></td>
          <td class="mono"><?= h($phone) ?></td>
          <td class="mono"><?= h($email !== '' ? $email : clients_t('clients.dash')) ?></td>
          <td>
            <?php if ($status === 'blocked'): ?>
              <span class="tag"><i class="bi bi-lock"></i> <?= h(clients_status_label('blocked')) ?></span>
            <?php else: ?>
              <span class="tag"><i class="bi bi-check2-circle"></i> <?= h(clients_status_label('active')) ?></span>
            <?php endif; ?>
          </td>
          <td class="mono"><?= h($createdAt !== '' ? $createdAt : clients_t('clients.dash')) ?></td>
          <td class="t-right">
            <div class="table__actions">
              <a class="iconbtn iconbtn--sm"
                 href="<?= h(url('/adm/index.php?m=personal_file&client_id=' . $id)) ?>"
                 aria-label="<?= h(clients_t('clients.action_personal_file')) ?>"
                 title="<?= h(clients_t('clients.action_personal_file')) ?>">
                <i class="bi bi-folder2-open"></i>
              </a>

              <button class="iconbtn iconbtn--sm"
                      type="button"
                      data-clients-open-modal="1"
                      data-clients-modal="<?= h(url('/adm/index.php?m=clients&do=modal_update&id=' . $id)) ?>"
                      aria-label="<?= h(clients_t('clients.action_edit')) ?>"
                      title="<?= h(clients_t('clients.action_edit')) ?>">
                <i class="bi bi-pencil"></i>
              </button>

              <form method="post"
                    action="<?= h(url('/adm/index.php?m=clients&do=toggle')) ?>"
                    class="table__actionform">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <button class="iconbtn iconbtn--sm"
                        type="submit"
                        aria-label="<?= h($status === 'blocked' ? clients_t('clients.action_unblock') : clients_t('clients.action_block')) ?>"
                        title="<?= h($status === 'blocked' ? clients_t('clients.action_unblock') : clients_t('clients.action_block')) ?>">
                  <i class="bi <?= $status === 'blocked' ? 'bi-toggle-off' : 'bi-toggle-on' ?>"></i>
                </button>
              </form>

              <form method="post"
                    action="<?= h(url('/adm/index.php?m=clients&do=reset_password')) ?>"
                    class="table__actionform">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <button class="iconbtn iconbtn--sm"
                        type="submit"
                        aria-label="<?= h(clients_t('clients.action_reset_password')) ?>"
                        title="<?= h(clients_t('clients.action_reset_password')) ?>">
                  <i class="bi bi-envelope-arrow-up"></i>
                </button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$rows): ?>
        <tr>
          <td colspan="7" class="muted" style="padding:16px;"><?= h(clients_t('clients.empty_list')) ?></td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
window.CRM_CLIENTS_I18N = <?= json_encode($clientsJsI18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= h(url('/adm/modules/clients/assets/js/main.js')) ?>"></script>
