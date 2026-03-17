<?php
/**
 * FILE: /adm/modules/oauth_tokens/oauth_tokens.php
 * ROLE: VIEW модуля oauth_tokens (UI + список + глобальная модалка)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/settings.php';

acl_guard(module_allowed_roles('oauth_tokens'));

/**
 * $pdo — соединение с БД.
 */
$pdo = db();

/**
 * $csrf — CSRF-токен для форм и JS-запросов.
 */
$csrf = csrf_token();

/**
 * $role — роль текущего пользователя.
 */
$role = (string)auth_user_role();

/**
 * $uid — id текущего пользователя.
 */
$uid = (int)auth_user_id();

/**
 * $isAdmin — флаг админа.
 */
$isAdmin = ($role === 'admin');

/**
 * $rows — список токенов для таблицы.
 */
$rows = [];

/**
 * $users — список пользователей для селекта назначения (только admin).
 */
$users = [];

if ($isAdmin) {
  /**
   * $stTokens — выборка всех токенов с назначениями.
   */
  $stTokens = $pdo->query(
    'SELECT '
    . 't.id, t.name, t.client_id, t.client_secret, t.token_received_at, '
    . 'otu.user_id AS assigned_user_id, '
    . 'COALESCE(u.name, u.phone, u.email) AS assigned_user_title '
    . 'FROM ' . OAUTH_TOKENS_TABLE . ' t '
    . 'LEFT JOIN ' . OAUTH_TOKENS_USERS_TABLE . ' otu ON otu.oauth_token_id = t.id '
    . 'LEFT JOIN users u ON u.id = otu.user_id '
    . 'ORDER BY t.id DESC'
  );

  $rows = $stTokens ? (array)$stTokens->fetchAll(PDO::FETCH_ASSOC) : [];

  /**
   * $stUsers — список пользователей для назначения токенов.
   */
  $stUsers = $pdo->query('SELECT id, COALESCE(name, phone, email) AS title FROM users ORDER BY id ASC');
  $users = $stUsers ? (array)$stUsers->fetchAll(PDO::FETCH_ASSOC) : [];
} else {
  /**
   * $stTokens — выборка только назначенного токена для user.
   */
  $stTokens = $pdo->prepare(
    'SELECT t.id, t.name, t.client_id, t.token_received_at '
    . 'FROM ' . OAUTH_TOKENS_TABLE . ' t '
    . 'JOIN ' . OAUTH_TOKENS_USERS_TABLE . ' otu ON otu.oauth_token_id = t.id '
    . 'WHERE otu.user_id = :uid '
    . 'ORDER BY t.id DESC '
    . 'LIMIT 1'
  );

  $stTokens->execute([':uid' => $uid]);
  $rows = (array)$stTokens->fetchAll(PDO::FETCH_ASSOC);
}
?>

<section class="l-row u-mb-12">
  <article class="card l-col l-col--12">
    <div class="card__head card__head--row">
      <div class="card__head-main">
        <div class="card__title">OAuth токены</div>
        <div class="card__hint muted">Admin: CRUD и назначение. User: доступ только к назначенному токену и обновлению.</div>
      </div>
      <div class="card__head-actions">
        <?php if ($isAdmin): ?>
          <button class="iconbtn iconbtn--sm" type="button" onclick="OauthTokensUI.openCreate()" title="Добавить">
            <i class="bi bi-plus-lg"></i>
          </button>
        <?php endif; ?>
      </div>
    </div>
  </article>
</section>

<section class="l-row u-mb-12">
  <article class="card l-col l-col--12">
    <div class="card__body">
      <div class="table-wrap table-wrap--compact-grid table-wrap--no-x">
        <table class="table table--compact-grid table--fit">
          <thead>
            <tr>
              <th class="table-col-id">ID</th>
              <th>Имя</th>
              <th>client_id</th>
              <th>Токен получен</th>
              <?php if ($isAdmin): ?>
                <th>Назначен</th>
              <?php endif; ?>
              <th class="t-right">Действия</th>
            </tr>
          </thead>

          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php
                /**
                 * $id — id токена.
                 */
                $id = (int)($row['id'] ?? 0);

                /**
                 * $assignedUserId — id назначенного пользователя.
                 */
                $assignedUserId = (int)($row['assigned_user_id'] ?? 0);

                /**
                 * $assignedUserTitle — отображаемое имя назначенного пользователя.
                 */
                $assignedUserTitle = trim((string)($row['assigned_user_title'] ?? ''));

                /**
                 * $receivedAt — дата последнего получения access_token.
                 */
                $receivedAt = trim((string)($row['token_received_at'] ?? ''));

                /**
                 * $editPayload — JSON полезная нагрузка для редактирования.
                 */
                $editPayload = json_encode([
                  'id' => $id,
                  'name' => (string)($row['name'] ?? ''),
                  'client_id' => (string)($row['client_id'] ?? ''),
                  'client_secret' => (string)($row['client_secret'] ?? ''),
                  'assign_user_id' => $assignedUserId,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($editPayload === false) {
                  $editPayload = '{}';
                }
              ?>
              <tr>
                <td class="table-col-id mono" data-label="ID">#<?= (int)$id ?></td>
                <td data-label="Имя"><?= h((string)($row['name'] ?? '')) ?></td>
                <td class="mono" data-label="client_id"><code><?= h((string)($row['client_id'] ?? '')) ?></code></td>
                <td class="mono" data-label="Токен получен"><?= h($receivedAt !== '' ? $receivedAt : '—') ?></td>

                <?php if ($isAdmin): ?>
                  <td class="mono" data-label="Назначен">
                    <?php if ($assignedUserId > 0): ?>
                      #<?= (int)$assignedUserId ?> — <?= h($assignedUserTitle !== '' ? $assignedUserTitle : 'user') ?>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </td>
                <?php endif; ?>

                <td class="t-right" data-label="Действия">
                  <div class="table__actions">
                    <button class="iconbtn iconbtn--sm" type="button" onclick="OauthTokensUI.start(<?= (int)$id ?>)" title="Обновить токен">
                      <i class="bi bi-arrow-repeat"></i>
                    </button>

                    <?php if ($isAdmin): ?>
                      <button
                        class="iconbtn iconbtn--sm"
                        type="button"
                        onclick="OauthTokensUI.openEdit(JSON.parse(this.dataset.payload || '{}'))"
                        data-payload="<?= h($editPayload) ?>"
                        title="Редактировать">
                        <i class="bi bi-pencil"></i>
                      </button>

                      <button class="iconbtn iconbtn--sm" type="button" onclick="OauthTokensUI.remove(<?= (int)$id ?>)" title="Удалить">
                        <i class="bi bi-trash"></i>
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php if (!$rows): ?>
              <tr class="table-row--empty">
                <td colspan="<?= $isAdmin ? 6 : 5 ?>" class="muted table-cell--empty">
                  <?= $isAdmin ? 'Пока нет токенов.' : 'Токен не назначен.' ?>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </article>
</section>

<input type="hidden" id="oauthTokensCsrf" value="<?= h($csrf) ?>">

<?php if ($isAdmin): ?>
  <template id="oauthTokensFormTpl">
    <form id="oauth-form" data-ui-select-scope="1">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" value="">

      <div class="l-row">
        <div class="l-col l-col--6 l-col--sm-12">
          <label class="field field--stack">
            <span class="field__label">Имя</span>
            <input class="input" name="name" required>
          </label>
        </div>

        <div class="l-col l-col--6 l-col--sm-12">
          <label class="field field--stack">
            <span class="field__label">Назначить пользователя</span>
            <select class="select" name="assign_user_id">
              <option value="0">— не назначать —</option>
              <?php foreach ($users as $u): ?>
                <option value="<?= (int)($u['id'] ?? 0) ?>">
                  #<?= (int)($u['id'] ?? 0) ?> — <?= h((string)($u['title'] ?? '')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        </div>

        <div class="l-col l-col--6 l-col--sm-12">
          <label class="field field--stack">
            <span class="field__label">client_id</span>
            <input class="input" name="client_id" required>
          </label>
        </div>

        <div class="l-col l-col--6 l-col--sm-12">
          <label class="field field--stack">
            <span class="field__label">client_secret</span>
            <input class="input" name="client_secret" required>
          </label>
        </div>
      </div>

      <div class="form-actions">
        <button class="btn btn--accent" type="submit">Сохранить</button>
        <button class="btn" type="button" data-oauth-cancel="1">Отмена</button>
      </div>
    </form>
  </template>
<?php endif; ?>

<script src="<?= h(url('/adm/modules/oauth_tokens/assets/js/main.js?v=20260208c')) ?>"></script>
