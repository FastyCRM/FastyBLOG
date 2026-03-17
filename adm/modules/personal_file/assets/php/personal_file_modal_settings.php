<?php
/**
 * FILE: /adm/modules/personal_file/assets/php/personal_file_modal_settings.php
 * ROLE: HTML модалки настроек (типы доступов / сроки жизни)
 * CONNECTIONS:
 *  - /adm/modules/personal_file/settings.php
 *  - /adm/modules/personal_file/assets/php/personal_file_lib.php
 *  - /core/response.php (json_ok/json_err)
 *
 * NOTES:
 *  - Открывается через иконку шестерни в модуле.
 *
 * СПИСОК ФУНКЦИЙ: (нет)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/../../settings.php';
require_once __DIR__ . '/personal_file_lib.php';

/**
 * ACL
 */
acl_guard(module_allowed_roles('personal_file'));

$uid = (int)(function_exists('auth_user_id') ? auth_user_id() : 0);
$roles = function_exists('auth_user_roles') ? (array)auth_user_roles($uid) : [];
if (!personal_file_can_manage($roles)) {
  json_err('Forbidden', 403);
}

$pdo = db();
$csrf = csrf_token();
$returnUrl = (string)($_SERVER['HTTP_REFERER'] ?? '/adm/index.php?m=personal_file');

/**
 * Типы доступов (все)
 */
$types = [];
try {
  $st = $pdo->query("\n    SELECT id, name, status\n    FROM " . PERSONAL_FILE_ACCESS_TYPES_TABLE . "\n    ORDER BY name ASC, id ASC\n  ");
  $types = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
  $types = [];
}

/**
 * Сроки жизни (все)
 */
$ttls = [];
try {
  $st2 = $pdo->query("\n    SELECT id, name, months, is_permanent, status, sort\n    FROM " . PERSONAL_FILE_ACCESS_TTLS_TABLE . "\n    ORDER BY sort ASC, id ASC\n  ");
  $ttls = $st2 ? $st2->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
  $ttls = [];
}

ob_start();
?>
<div class="card" style="box-shadow:none; border-color: var(--border-soft); margin-bottom:12px;">
  <div class="card__head">
    <div class="card__title">Типы доступов</div>
    <div class="card__hint muted">Управление справочником</div>
  </div>
  <div class="card__body">
    <form method="post" action="<?= h(url('/adm/index.php?m=personal_file&do=settings_type_add')) ?>" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:12px;">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
      <label class="field field--stack" style="min-width:240px;">
        <span class="field__label">Новый тип</span>
        <input class="select" name="name" placeholder="Например: WiFi">
      </label>
      <button class="btn btn--accent" type="submit">Добавить</button>
    </form>

    <div class="tablewrap">
      <table class="table">
        <thead>
          <tr>
            <th>Тип</th>
            <th style="width:140px">Статус</th>
            <th class="t-right" style="width:160px"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($types as $t): ?>
            <?php
              $id = (int)($t['id'] ?? 0);
              $name = (string)($t['name'] ?? '');
              $status = (string)($t['status'] ?? 'active');
            ?>
            <tr>
              <td>
                <form method="post" action="<?= h(url('/adm/index.php?m=personal_file&do=settings_type_update')) ?>" style="display:flex; gap:8px; align-items:center;">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
                  <input type="hidden" name="id" value="<?= (int)$id ?>">
                  <input class="select" name="name" value="<?= h($name) ?>">
                  <select class="select" name="status" data-ui-select="1" style="min-width:120px;">
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>active</option>
                    <option value="disabled" <?= $status === 'disabled' ? 'selected' : '' ?>>disabled</option>
                  </select>
                  <button class="iconbtn iconbtn--sm" type="submit" title="Сохранить">
                    <i class="bi bi-check2"></i>
                  </button>
                </form>
              </td>
              <td></td>
              <td class="t-right">
                <form method="post"
                      action="<?= h(url('/adm/index.php?m=personal_file&do=settings_type_delete')) ?>"
                      class="table__actionform"
                      onsubmit="return confirm('Удалить тип доступа?');">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
                  <input type="hidden" name="id" value="<?= (int)$id ?>">
                  <button class="iconbtn iconbtn--sm" type="submit" title="Удалить">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$types): ?>
            <tr><td colspan="3" class="muted" style="padding:12px;">Типов нет.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card" style="box-shadow:none; border-color: var(--border-soft);">
  <div class="card__head">
    <div class="card__title">Сроки жизни</div>
    <div class="card__hint muted">Справочник (месяцы/бессрочно)</div>
  </div>
  <div class="card__body">
    <form method="post" action="<?= h(url('/adm/index.php?m=personal_file&do=settings_ttl_add')) ?>" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-bottom:12px;">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
      <label class="field field--stack" style="min-width:220px;">
        <span class="field__label">Название</span>
        <input class="select" name="name" placeholder="Например: 6 месяцев">
      </label>
      <label class="field field--stack" style="width:120px;">
        <span class="field__label">Месяцев</span>
        <input class="select" name="months" placeholder="6">
      </label>
      <label class="field field--stack" style="width:140px;">
        <span class="field__label">Бессрочно</span>
        <select class="select" name="is_permanent" data-ui-select="1">
          <option value="0">нет</option>
          <option value="1">да</option>
        </select>
      </label>
      <button class="btn btn--accent" type="submit">Добавить</button>
    </form>

    <div class="tablewrap">
      <table class="table">
        <thead>
          <tr>
            <th>Срок</th>
            <th style="width:120px">Мес</th>
            <th style="width:120px">Бесср</th>
            <th style="width:120px">Статус</th>
            <th class="t-right" style="width:160px"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ttls as $t): ?>
            <?php
              $id = (int)($t['id'] ?? 0);
              $name = (string)($t['name'] ?? '');
              $months = (int)($t['months'] ?? 0);
              $perm = (int)($t['is_permanent'] ?? 0);
              $status = (string)($t['status'] ?? 'active');
            ?>
            <tr>
              <td>
                <form method="post" action="<?= h(url('/adm/index.php?m=personal_file&do=settings_ttl_update')) ?>" style="display:flex; gap:8px; align-items:center;">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
                  <input type="hidden" name="id" value="<?= (int)$id ?>">
                  <input class="select" name="name" value="<?= h($name) ?>" style="min-width:200px;">
                  <input class="select" name="months" value="<?= (int)$months ?>" style="width:80px;">
                  <select class="select" name="is_permanent" data-ui-select="1" style="width:120px;">
                    <option value="0" <?= $perm === 0 ? 'selected' : '' ?>>нет</option>
                    <option value="1" <?= $perm === 1 ? 'selected' : '' ?>>да</option>
                  </select>
                  <select class="select" name="status" data-ui-select="1" style="width:120px;">
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>active</option>
                    <option value="disabled" <?= $status === 'disabled' ? 'selected' : '' ?>>disabled</option>
                  </select>
                  <button class="iconbtn iconbtn--sm" type="submit" title="Сохранить">
                    <i class="bi bi-check2"></i>
                  </button>
                </form>
              </td>
              <td></td>
              <td></td>
              <td></td>
              <td class="t-right">
                <form method="post"
                      action="<?= h(url('/adm/index.php?m=personal_file&do=settings_ttl_delete')) ?>"
                      class="table__actionform"
                      onsubmit="return confirm('Удалить срок жизни?');">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="return_url" value="<?= h($returnUrl) ?>">
                  <input type="hidden" name="id" value="<?= (int)$id ?>">
                  <button class="iconbtn iconbtn--sm" type="submit" title="Удалить">
                    <i class="bi bi-trash"></i>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$ttls): ?>
            <tr><td colspan="5" class="muted" style="padding:12px;">Сроков нет.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$html = ob_get_clean();

json_ok([
  'title' => 'Настройки модуля',
  'html' => $html,
]);