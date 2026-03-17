<?php
/**
 * FILE: /adm/view/index.php
 * ROLE: VIEW (layout) + minimal dispatcher (m/do)
 * CONNECTIONS:
 *  - /adm/core/bootstrap.php (url(), h(), BASE_URL, ROOT_PATH + core includes)
 *  - /core/auth.php (auth_is_logged_in, auth_require_login, auth_user_id)
 *  - /core/response.php (http_404)
 *
 * MODULES LOCATION:
 *  - /adm/modules/<m>/<m>.php              (VIEW)
 *  - /adm/modules/<m>/assets/php/main.php  (DO router)
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
  http_response_code(500);
  exit('Entrypoint required: open /adm/index.php');
}

/**
 * Bootstrap уже подключён из /adm/index.php.
 * Здесь НЕ подключаем его повторно.
 */

/* =========================================================
 * DISPATCHER (m/do)
 * ========================================================= */

// $m — код модуля
$m  = (string)($_GET['m'] ?? 'dashboard');

// $do — действие (router внутри модуля)
$do = (string)($_GET['do'] ?? '');

// чистим параметры (безопасность)
$m  = preg_replace('~[^a-z0-9_]+~i', '', $m);
$do = preg_replace('~[^a-z0-9_]+~i', '', $do);

if ($m === '') $m = 'dashboard';

/**
 * Если НЕ залогинен и do пустой — показываем auth как первый экран.
 * ВАЖНО: do=login/logout должны проходить даже для гостя, поэтому do не трогаем.
 */
if ($do === '') {
  if (!function_exists('auth_is_logged_in') || !auth_is_logged_in()) {
    $m = 'auth';
  }
}

/**
 * Для всего, кроме auth: нужна авторизация.
 * (ACL/DB-меню подключим позже, когда скажешь — сейчас не ломаем)
 */
if ($m !== 'auth') {
  if (function_exists('auth_require_login')) {
    auth_require_login();
  }
}

/**
 * FALLBACK MODULE:
 * Если dashboard отключён/недоступен — открываем первый доступный модуль
 * по порядку сортировки из таблицы modules (enabled/menu/roles).
 */
if ($m === 'dashboard' && $do === '') {
  /**
   * $needDashboardFallback — нужен ли fallback с dashboard.
   */
  $needDashboardFallback = false;

  if (function_exists('module_is_enabled') && !module_is_enabled('dashboard')) {
    $needDashboardFallback = true;
  }

  /**
   * Проверяем роли dashboard, чтобы не ловить Forbidden в VIEW.
   */
  if (!$needDashboardFallback && function_exists('module_allowed_roles') && function_exists('auth_user_id') && function_exists('auth_user_roles') && function_exists('acl_roles_intersect')) {
    /**
     * $dashboardAllowedRoles — роли доступа к dashboard из БД.
     */
    $dashboardAllowedRoles = (array)module_allowed_roles('dashboard');

    /**
     * $uidFallback — текущий пользователь.
     */
    $uidFallback = (int)auth_user_id();

    /**
     * $userRolesFallback — роли текущего пользователя.
     */
    $userRolesFallback = $uidFallback > 0 ? (array)auth_user_roles($uidFallback) : [];

    if (!$dashboardAllowedRoles || !acl_roles_intersect($userRolesFallback, $dashboardAllowedRoles)) {
      $needDashboardFallback = true;
    }
  }

  if ($needDashboardFallback && function_exists('modules_get_menu') && function_exists('auth_user_role')) {
    /**
     * $fallbackRole — текущая роль для фильтра меню.
     */
    $fallbackRole = (string)auth_user_role();

    /**
     * $fallbackMenuItems — доступные модули в порядке sort ASC.
     */
    $fallbackMenuItems = (array)modules_get_menu($fallbackRole);

    /**
     * $fallbackCode — первый подходящий модуль.
     */
    $fallbackCode = '';
    foreach ($fallbackMenuItems as $fallbackItem) {
      $fallbackCode = trim((string)($fallbackItem['code'] ?? ''));
      if ($fallbackCode !== '') {
        break;
      }
    }

    if ($fallbackCode !== '') {
      $m = $fallbackCode;
    }
  }
}

/**
 * I18N-РєРѕРЅС‚РµРєСЃС‚ Р°РєС‚РёРІРЅРѕРіРѕ РјРѕРґСѓР»СЏ.
 * РќСѓР¶РµРЅ РїРѕСЃР»Рµ С„РёРЅР°Р»СЊРЅРѕРіРѕ РѕРїСЂРµРґРµР»РµРЅРёСЏ $m (РІРєР»СЋС‡Р°СЏ auth/dashboard fallback).
 */
$GLOBALS['I18N_MODULE_CODE'] = $m;
if (function_exists('load_language')) {
  load_language((string)($_SESSION['lang'] ?? 'ru'));
}

/**
 * $currentLang вЂ” С‚РµРєСѓС‰РёР№ СЏР·С‹Рє РёРЅС‚РµСЂС„РµР№СЃР°.
 */
$currentLang = function_exists('i18n_lang') ? i18n_lang() : ((string)($_SESSION['lang'] ?? 'ru'));
/**
 * SUBSCRIPTION GUARD
 * Важно: НЕ вызывать на auth и НЕ вызывать на do=... (login/logout),
 * иначе будет редирект-петля.
 */
if ($m !== 'auth' && $do === '') {
  require_once ROOT_PATH . '/core/subscription_guard.php';
  subscription_guard();
}

/**
 * Пути модулей (ТОЛЬКО в /adm/modules)
 */

// $moduleDir — папка модуля
$moduleDir  = ROOT_PATH . '/adm/modules/' . $m;

// $moduleView — view-файл модуля
$moduleView = $moduleDir . '/' . $m . '.php';

// $moduleMain — main.php модуля (router do)
$moduleMain = $moduleDir . '/assets/php/main.php';

/**
 * ACTIONS: do задан — выполняем router модуля без layout.
 */
if ($do !== '') {
  if (!is_file($moduleMain)) {
    http_404('Module main.php not found');
  }
  require $moduleMain;
  exit;
}

/**
 * AUTH PAGE: minimal layout
 * На экране авторизации не должно быть меню/сайдбара/топбара и заголовка "auth".
 * Только форма и flashbar.
 */
if ($m === 'auth' && $do === '') {

  // $flash — очередь сообщений (одноразовый показ)
  $flash = function_exists('flash_pull') ? (array)flash_pull() : [];

  // $flashJson — сериализация в JS
  $flashJson = json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($flashJson === false) $flashJson = '[]';

  // $moduleView уже рассчитан выше
  if (!is_file($moduleView)) {
    http_404('Module view not found');
  }

  ?><!doctype html>
  <html lang="<?= h($currentLang) ?>" data-theme="color">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title><?= h(t('common.login_title')) ?></title>
    <link rel="stylesheet" href="<?= h(url('/adm/view/assets/css/main.css')) ?>">
  </head>
  <body class="auth">
    <!-- FLASHBAR (system messages) -->
    <div class="flashbar" id="flashbar" aria-live="polite" aria-atomic="true"></div>

    <main class="main main--auth" aria-label="Auth">
      <?php require $moduleView; ?>
    </main>

    <script>
      // window.__FLASH__ — одноразовый список сообщений от PHP
      window.__FLASH__ = <?= $flashJson ?>;
    </script>

    <script src="<?= h(url('/adm/view/assets/js/main.js')) ?>"></script>
  </body>
  </html>
  <?php
  exit;
}

/**
 * UI variables (full layout)
 */

// $uid — текущий пользователь (или null)
$uid = function_exists('auth_user_id') ? auth_user_id() : null;

// $userRole — роль текущего пользователя
$userRole = (string)(function_exists('auth_user_role') ? auth_user_role() : '');

// $menuItems — пункты меню из БД (enabled=1, menu=1, roles-фильтр)
$menuItems = [];
if ($uid && function_exists('modules_get_menu')) {
  $menuItems = (array)modules_get_menu($userRole);
}

// $pageTitle — заголовок страницы
$pageTitle = ($m === 'dashboard') ? 'Главная' : $m;
if ($menuItems) {
  foreach ($menuItems as $menuItem) {
    // $menuCode — код пункта меню
    $menuCode = (string)($menuItem['code'] ?? '');
    if ($menuCode !== '' && $menuCode === $m) {
      // $menuTitle — заголовок пункта меню
      $menuTitle = trim((string)($menuItem['title'] ?? ''));
      if ($menuTitle !== '') {
        $pageTitle = $menuTitle;
      }
      break;
    }
  }
}

/**
 * FLASH -> JS (one-time)
 * ВАЖНО: flash_pull() должен быть ТОЛЬКО здесь (для full layout),
 * потому что для auth минимального layout он уже вызван выше.
 */

// $flash — очередь сообщений из /core/flash.php
$flash = function_exists('flash_pull') ? (array)flash_pull() : [];

// $flashJson — сериализация в JS
$flashJson = json_encode($flash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($flashJson === false) $flashJson = '[]';

/**
 * FULL LAYOUT
 * Сайдбар, топбар, основное содержимое.
 */

?><!doctype html>
<html lang="<?= h($currentLang) ?>" data-theme="color">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>CRM2026</title>
  <!-- Bootstrap Icons (system-wide) -->
  <link rel="stylesheet" href="/adm/view/assets/libs/vendor/bootstrap-icons/font/bootstrap-icons.css">

  <link rel="stylesheet" href="<?= h(url('/adm/view/assets/css/main.css')) ?>">
  
</head>

<body>
  <div class="app" id="app">

    <aside class="sidebar" id="sidebar" aria-label="Sidebar">
      <div class="sidebar__brand">
        <div class="brand">
          <span class="brand__dot" aria-hidden="true"></span>
          <span class="brand__name">CRM2026</span>
        </div>
      </div>

    <nav class="menu" aria-label="Menu">
      <?php if ($uid): ?>
        <?php foreach ($menuItems as $it): ?>
          <?php
            // $code — код модуля
            $code = (string)($it['code'] ?? '');

            // $isActive — активный пункт меню
            $isActive = ($code !== '' && $m === $code);

            // $href — ссылка на модуль
            $href = (string)($it['href'] ?? ('/adm/index.php?m=' . urlencode($code)));

            // $iconClass — класс bootstrap-icons вида "bi bi-..."
            $iconClass = (string)($it['icon'] ?? 'bi bi-dot');
          ?>
          <a class="menu__item <?= $isActive ? 'is-active' : '' ?>" href="<?= h(url($href)) ?>">
            <span class="menu__icon" aria-hidden="true">
              <i class="<?= h($iconClass) ?>"></i>
            </span>
            <span class="menu__label"><?= h((string)($it['title'] ?? $code)) ?></span>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>


      <div class="sidebar__footer">
        <form method="post" action="<?= h(url('/adm/index.php')) ?>" id="langForm">
          <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="i18n_switch" value="1">
          <input type="hidden" name="return_url" value="<?= h((string)($_SERVER['REQUEST_URI'] ?? '/adm/index.php')) ?>">
          <label class="field field--stack">
            <span class="field__label"><?= h(t('common.language_label')) ?></span>
            <select class="select" id="langSelect" name="lang" aria-label="Language select" data-ui-select="1">
              <option value="ru" <?= $currentLang === 'ru' ? 'selected' : '' ?>><?= h(t('common.language_ru')) ?></option>
              <option value="en" <?= $currentLang === 'en' ? 'selected' : '' ?>><?= h(t('common.language_en')) ?></option>
            </select>
          </label>
        </form>

        <label class="field field--stack">
          <span class="field__label"><?= h(t('common.theme_label')) ?></span>
          <select class="select" id="themeSelect" aria-label="Theme select" data-ui-select="1">
            <option value="color"><?= h(t('common.theme_color')) ?></option>
            <option value="black"><?= h(t('common.theme_black')) ?></option>
            <option value="light"><?= h(t('common.theme_light')) ?></option>
          </select>
        </label>

        <?php if ($uid): ?>
          <form method="post" action="<?= h(url('/adm/index.php?m=auth&do=logout')) ?>">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <button class="btn btn--danger btn--wide" type="submit"><?= h(t('common.logout')) ?></button>
          </form>
        <?php endif; ?>
      </div>
    </aside>

    <div class="shell">
      <header class="topbar">
        <div class="topbar__left">
          <button class="iconbtn" id="btnBurger" type="button" aria-label="Toggle menu">
            <span class="iconbtn__bars" aria-hidden="true"></span>
          </button>
          <div class="topbar__title-wrap">
            <div class="topbar__title">CRM2026</div>
            <div class="topbar__subtitle"><?= h($pageTitle) ?></div>
          </div>
        </div>

        <div class="topbar__right">
          <?php if ($uid): ?>
            <span class="topbar__role"><?= h($userRole !== '' ? $userRole : 'user') ?></span>
          <?php endif; ?>
        </div>
      </header>

      <main class="main" aria-label="Main content">
        <!-- <div class="pagehead"> -->
          <!-- <h1 class="h1"><?= h($pageTitle) ?></h1> -->
          <!-- <div class="muted">Добро пожаловать в CRM2026.</div> -->
        <!-- </div> -->

        <?php
          if (!is_file($moduleView)) {
            http_404('Module view not found');
          }
          require $moduleView;
        ?>
      </main>
    </div>
  </div>

  <!-- FLASHBAR (system messages) -->
  <div class="flashbar" id="flashbar" aria-live="polite" aria-atomic="true"></div>

  <div class="modal" id="modal" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal__backdrop" data-modal-close="1"></div>
    <div class="modal__panel" role="document" aria-label="Modal">
      <div class="modal__head">
        <div class="modal__title" id="modalTitle"><?= h(t('common.modal_title_default')) ?></div>
        <button class="iconbtn" type="button" data-modal-close="1" aria-label="Close modal">✕</button>
      </div>
      <div class="modal__body" id="modalBody"></div>
      <div class="modal__foot">
        <button class="btn" type="button" data-modal-close="1"><?= h(t('common.modal_close')) ?></button>
        <button class="btn btn--accent" type="button" id="modalOk"><?= h(t('common.modal_ok')) ?></button>
      </div>
    </div>
  </div>

  <script>
    // window.__FLASH__ — одноразовый список сообщений от PHP
    window.__FLASH__ = <?= $flashJson ?>;
  </script>

  <script src="<?= h(url('/adm/view/assets/js/main.js')) ?>"></script>
</body>
</html>
