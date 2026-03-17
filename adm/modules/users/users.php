<?php
/**
 * FILE: /adm/modules/users/users.php
 * ROLE: VIEW - список пользователей (UI only)
 * CONNECTIONS:
 *  - db()
 *  - csrf_token()
 *  - auth_user_role()
 *  - module_allowed_roles(), acl_guard()
 *  - url(), h()
 *  - users_t(), users_status_label()
 *
 * CANON:
 *  - Никакой логики изменения данных
 *  - Действия - только через /adm/index.php?m=users&do=...
 *  - Управление - иконками (как в modules)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/assets/php/users_i18n.php';

/**
 * ACL: доступ к модулю users (по таблице modules.roles)
 */
acl_guard(module_allowed_roles('users'));

/**
 * $pdo - соединение с БД
 */
$pdo = db();

/**
 * $csrf - CSRF токен для форм
 */
$csrf = csrf_token();

/**
 * $role - код основной роли текущего пользователя
 */
$role = (string)auth_user_role();

/**
 * $isAdmin - флаг админа
 */
$isAdmin = ($role === 'admin');

/**
 * $isManager - флаг менеджера
 */
$isManager = ($role === 'manager');

/**
 * $stmt - запрос пользователей + агрегат ролей
 */
$stmt = $pdo->query("
  SELECT
    u.id,
    u.name,
    u.phone,
    u.email,
    u.status,
    u.ui_theme,
    u.created_at,
    GROUP_CONCAT(r.code ORDER BY r.sort ASC, r.id ASC SEPARATOR ', ') AS roles
  FROM users u
  LEFT JOIN user_roles ur ON ur.user_id = u.id
  LEFT JOIN roles r ON r.id = ur.role_id
  GROUP BY u.id
  ORDER BY u.id DESC
");

/**
 * $users - строки пользователей
 */
$users = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
?>

<h1><?= h(users_t('users.page_title')) ?></h1>

<div class="tablewrap">
  <table class="table table--modules">
    <thead>
      <tr>
        <th style="width:44px"></th>
        <th><?= h(users_t('users.col_user')) ?></th>
        <th style="width:160px"><?= h(users_t('users.col_phone')) ?></th>
        <th>Email</th>
        <th style="width:160px"><?= h(users_t('users.col_roles')) ?></th>
        <th style="width:90px"><?= h(users_t('users.col_status')) ?></th>
        <th class="t-right" style="width:170px">
          <?php if ($isAdmin || $isManager): ?>
            <button class="iconbtn iconbtn--sm"
                    type="button"
                    data-users-open-modal="1"
                    data-users-modal="<?= h(url('/adm/index.php?m=users&do=modal_add')) ?>"
                    aria-label="<?= h(users_t('users.action_add_user')) ?>"
                    title="<?= h(users_t('users.action_add_user')) ?>">
              <i class="bi bi-person-plus"></i>
            </button>
          <?php endif; ?>
        </th>
      </tr>
    </thead>

    <tbody>
      <?php foreach ($users as $u): ?>
        <?php
          /**
           * $id - id пользователя
           */
          $id = (int)($u['id'] ?? 0);

          /**
           * $name - имя
           */
          $name = (string)($u['name'] ?? '');

          /**
           * $phone - телефон
           */
          $phone = (string)($u['phone'] ?? '');

          /**
           * $email - email
           */
          $email = (string)($u['email'] ?? '');

          /**
           * $roles - роли строкой
           */
          $roles = (string)($u['roles'] ?? '');

          /**
           * $status - статус
           */
          $status = (string)($u['status'] ?? 'active');

          /**
           * $icon - иконка в первой колонке
           */
          $icon = 'bi bi-person-circle';
          if ($status === 'blocked') $icon = 'bi bi-person-slash';
        ?>

        <tr>
          <td><i class="<?= h($icon) ?>"></i></td>

          <td>
            <span hidden data-user-id="<?= (int)$id ?>"><?= (int)$id ?></span>
            <strong><?= h($name !== '' ? $name : users_t('users.dash')) ?></strong>
          </td>

          <td class="mono"><?= h($phone) ?></td>
          <td class="mono"><?= h($email !== '' ? $email : users_t('users.dash')) ?></td>

          <td class="mono">
            <?= h($roles !== '' ? $roles : users_t('users.dash')) ?>
          </td>

          <td>
            <?php if ($status === 'blocked'): ?>
              <span class="tag"><i class="bi bi-lock"></i> <?= h(users_status_label('blocked')) ?></span>
            <?php else: ?>
              <span class="tag"><i class="bi bi-check2-circle"></i> <?= h(users_status_label('active')) ?></span>
            <?php endif; ?>
          </td>

          <td class="t-right">
            <div class="table__actions">

              <!-- EDIT -->
              <button class="iconbtn iconbtn--sm"
                      type="button"
                      data-users-open-modal="1"
                      data-users-modal="<?= h(url('/adm/index.php?m=users&do=modal_update&id=' . $id)) ?>"
                      aria-label="<?= h(users_t('users.action_edit')) ?>"
                      title="<?= h(users_t('users.action_edit')) ?>">
                <i class="bi bi-pencil"></i>
              </button>

              <!-- TOGGLE -->
              <form method="post"
                    action="<?= h(url('/adm/index.php?m=users&do=toggle')) ?>"
                    class="table__actionform">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <button class="iconbtn iconbtn--sm"
                        type="submit"
                        aria-label="<?= h($status === 'blocked' ? users_t('users.action_unblock') : users_t('users.action_block')) ?>"
                        title="<?= h($status === 'blocked' ? users_t('users.action_unblock') : users_t('users.action_block')) ?>">
                  <i class="bi <?= $status === 'blocked' ? 'bi-toggle-off' : 'bi-toggle-on' ?>"></i>
                </button>
              </form>

              <!-- RESET PASSWORD -->
              <form method="post"
                    action="<?= h(url('/adm/index.php?m=users&do=reset_password')) ?>"
                    class="table__actionform">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$id ?>">
                <button class="iconbtn iconbtn--sm"
                        type="submit"
                        aria-label="<?= h(users_t('users.action_reset_password')) ?>"
                        title="<?= h(users_t('users.action_reset_password')) ?>">
                  <i class="bi bi-envelope-arrow-up"></i>
                </button>
              </form>

            </div>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$users): ?>
        <tr>
          <td colspan="7" class="muted" style="padding:16px;"><?= h(users_t('users.empty_list')) ?></td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script src="<?= h(url('/adm/modules/users/assets/js/main.js')) ?>"></script>
