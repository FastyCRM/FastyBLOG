<?php
/**
 * FILE: /adm/modules/modules/modules.php
 * ROLE: VIEW — управление модулями (UI only)
 * CONNECTIONS:
 *  - db()
 *  - csrf_token()
 *  - modules_is_protected() — если есть
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/assets/php/modules_i18n.php';

$pdo  = db();
$csrf = csrf_token();

$stmt = $pdo->query("
  SELECT id, code, name, icon, enabled, menu, roles
  FROM modules
  ORDER BY sort ASC, id ASC
");

$modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h1><?= h(modules_t('modules.page_title')) ?></h1>

<div class="tablewrap">
  <table class="table table--modules">
  <thead>
    <tr>
      <th style="width:44px"></th>
      <th><?= h(modules_t('modules.col_module')) ?></th>
      <th style="width:70px"><?= h(modules_t('modules.col_status')) ?></th>
      <th style="width:70px"><?= h(modules_t('modules.col_menu')) ?></th>
      <th><?= h(modules_t('modules.col_roles')) ?></th>
      <th style="width:140px"><?= h(modules_t('modules.col_files')) ?></th>
      <th style="width:140px"><?= h(modules_t('modules.col_protection')) ?></th>
      <th class="t-right" style="width:90px"><?= h(modules_t('modules.col_action')) ?></th>
    </tr>
  </thead>

  <tbody>
  <?php foreach ($modules as $m): ?>
    <?php
      $code    = (string)$m['code'];
      $name    = (string)$m['name'];
      $enabled = ((int)$m['enabled'] === 1);
      $menu    = ((int)$m['menu'] === 1);
      $roles   = (string)($m['roles'] ?? '-');

      $icon = trim((string)($m['icon'] ?? ''));
      if ($icon === '') $icon = 'bi bi-grid';

      $viewFile = ROOT_PATH . "/adm/modules/{$code}/{$code}.php";
      $hasFiles = is_file($viewFile);

      $protected = function_exists('modules_is_protected')
        ? modules_is_protected($code)
        : in_array($code, ['auth', 'modules'], true);
    ?>

    <tr>
      <td><i class="<?= h($icon) ?>"></i></td>

      <td>
        <div class="muted"><?= h($code) ?></div>
        <strong><?= h($name) ?></strong>
      </td>

      <td><?= $enabled ? h(modules_t('modules.status_on')) : h(modules_t('modules.status_off')) ?></td>
      <td><?= $menu ? h(modules_t('modules.menu_on')) : h(modules_t('modules.menu_off')) ?></td>
      <td><?= h($roles) ?></td>

      <td>
        <?php if (!$hasFiles): ?>
          <span class="tag"><i class="bi bi-folder-x"></i> <?= h(modules_t('modules.files_missing')) ?></span>
        <?php else: ?>
          <span class="tag"><i class="bi bi-folder-check"></i> <?= h(modules_t('modules.files_ok')) ?></span>
        <?php endif; ?>
      </td>

      <td>
        <?php if ($protected): ?>
          <span class="tag"><i class="bi bi-lock"></i> <?= h(modules_t('modules.protection_protected')) ?></span>
        <?php else: ?>
          <span class="tag"><i class="bi bi-unlock"></i> <?= h(modules_t('modules.protection_none')) ?></span>
        <?php endif; ?>
      </td>

      <td class="t-right">
        <?php if (!$protected): ?>
          <form method="post"
                action="<?= h(url('/adm/index.php?m=modules&do=toggle')) ?>"
                class="table-actions__form">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <button class="iconbtn iconbtn--sm" type="submit"
                    aria-label="<?= $enabled ? h(modules_t('modules.action_disable')) : h(modules_t('modules.action_enable')) ?>">
              <i class="bi <?= $enabled ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
            </button>
          </form>
        <?php endif; ?>
      </td>

    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
