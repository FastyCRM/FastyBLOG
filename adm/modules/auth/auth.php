<?php
/**
 * FILE: /adm/modules/auth/auth.php
 * ROLE: VIEW (экран входа)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  http_response_code(500);
  exit('Entrypoint required: open /adm/index.php');
}

$csrf = function_exists('csrf_token') ? csrf_token() : '';
?>
<div class="stack">
  <div class="card" style="max-width:520px;margin:0 auto;">
    <div class="card__head">
      <div class="card__title"><?= h(t('auth.login_card_title')) ?></div>
      <div class="card__hint muted"><?= h(t('auth.login_card_hint')) ?></div>
    </div>

    <div class="card__body">
      <form method="post" action="<?= h(url('/adm/index.php?m=auth&do=login')) ?>" class="field--stack">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <label class="field field--stack">
          <span class="field__label"><?= h(t('auth.login_label')) ?></span>
          <input class="input" type="text" name="login" autocomplete="username" required>
        </label>

        <label class="field field--stack" style="margin-top:10px;">
          <span class="field__label"><?= h(t('auth.password_label')) ?></span>
          <input class="input" type="password" name="password" autocomplete="current-password" required>
        </label>

        <div style="margin-top:14px;">
          <button class="btn btn--accent btn--wide" type="submit"><?= h(t('auth.login_submit')) ?></button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="<?= h(url('/adm/modules/auth/assets/js/main.js')) ?>"></script>
