<?php
/**
 * FILE: /adm/view/index.php
 * ROLE: VIEW (layout only) — DEMO MODE (без модулей и без dispatcher)
 * CONNECTIONS:
 *  - /core/bootstrap.php (BASE_URL, url(), h())
 *  - /adm/view/assets/css/main.css
 *  - /adm/view/assets/js/main.js
 *
 * NOTES:
 *  - ВРЕМЕННО: никакого m/do, никакого подключения модулей.
 *  - Цель: чтобы демо-контент и инфраструктурный JS (модалка/бургер/тема) точно работали.
 */

declare(strict_types=1);

/**
 * Страховка на случай прямого открытия view
 */
if (!defined('ROOT_PATH')) {
  define('ROOT_PATH', dirname(__DIR__, 2)); // /adm/view -> /adm -> /
}

/**
 * Нужны url(), h(), BASE_URL (и всё)
 */
require_once ROOT_PATH . '/core/bootstrap.php';

?><!doctype html>
<html lang="ru" data-theme="color">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>CRM2026</title>

  <!-- main.css = сборщик (внутри темы + компоненты) -->
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

      <!-- DEMO MENU (заглушка) -->
      <nav class="menu" aria-label="Menu">
        <a class="menu__item is-active" href="<?= h(url('/adm/index.php')) ?>">
          <span class="menu__icon" aria-hidden="true">⌂</span>
          <span class="menu__label">Главная</span>
        </a>

        <a class="menu__item" href="javascript:void(0)">
          <span class="menu__icon" aria-hidden="true">👥</span>
          <span class="menu__label">Пользователи</span>
        </a>

        <a class="menu__item" href="javascript:void(0)">
          <span class="menu__icon" aria-hidden="true">▦</span>
          <span class="menu__label">Модули</span>
        </a>

        <a class="menu__item" href="javascript:void(0)">
          <span class="menu__icon" aria-hidden="true">🔑</span>
          <span class="menu__label">OAuth токены</span>
        </a>
      </nav>

      <div class="sidebar__footer">
        <label class="field field--stack">
          <span class="field__label">Тема</span>
          <select class="select" id="themeSelect" aria-label="Theme select">
            <option value="color">color</option>
            <option value="black">black</option>
            <option value="light">light</option>
          </select>
        </label>

        <!-- DEMO logout button (заглушка) -->
        <button class="btn btn--danger btn--wide" type="button" disabled>
          Выход (demo)
        </button>
      </div>
    </aside>

    <div class="shell">
      <header class="topbar">
        <button class="iconbtn" id="btnBurger" type="button" aria-label="Toggle menu">
          <span class="iconbtn__bars" aria-hidden="true"></span>
        </button>

        <div class="topbar__title">CRM2026</div>

        <div class="topbar__right">
          <button class="btn btn--accent" id="btnTestModal" type="button">
            Тест модалки
          </button>
        </div>
      </header>

      <main class="main" aria-label="Main content">
        <div class="pagehead">
          <h1 class="h1">Главная</h1>
          <div class="muted">Добро пожаловать в CRM2026 (demo mode).</div>
        </div>

        <!-- ===== DASHBOARD (как было) ===== -->
        <section class="grid">
          <div class="card">
            <div class="card__label">Пользователь</div>
            <div class="card__value">i@fastycrm.ru</div>
          </div>

          <div class="card">
            <div class="card__label">Телефон</div>
            <div class="card__value">79298256116</div>
          </div>

          <div class="card">
            <div class="card__label">Тема</div>
            <div class="card__value" id="themeEcho">color</div>
          </div>
        </section>

        <section class="stack">
          <div class="card">
            <div class="card__head">
              <div class="card__title">Пустая зона (фон отсутствует)</div>
              <div class="card__hint muted">Карточки висят в воздухе, фон — только у страницы.</div>
            </div>
            <div class="card__body">
              <div class="skeleton">
                <div class="skeleton__bar"></div>
                <div class="skeleton__bar"></div>
                <div class="skeleton__bar"></div>
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card__head">
              <div class="card__title">Таблица (пример)</div>
            </div>
            <div class="card__body">
              <div class="table-wrap">
                <table class="table">
                  <thead>
                    <tr>
                      <th>Событие</th>
                      <th>Клиент</th>
                      <th class="ta-r">Сумма</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr><td>Звонок</td><td>Anna Ivanova</td><td class="ta-r">$100.00</td></tr>
                    <tr><td>Встреча</td><td>Alex Petrov</td><td class="ta-r">$200.00</td></tr>
                    <tr><td>Счёт</td><td>Ivan S.</td><td class="ta-r">$500.00</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </section>
        <!-- ===== /DASHBOARD ===== -->
      </main>
    </div>
  </div>

  <!-- GLOBAL MODAL -->
  <div class="modal" id="modal" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="modal__backdrop" data-modal-close="1"></div>
    <div class="modal__panel" role="document" aria-label="Modal">
      <div class="modal__head">
        <div class="modal__title" id="modalTitle">Модалка</div>
        <button class="iconbtn" type="button" data-modal-close="1" aria-label="Close modal">✕</button>
      </div>
      <div class="modal__body" id="modalBody"></div>
      <div class="modal__foot">
        <button class="btn" type="button" data-modal-close="1">Закрыть</button>
        <button class="btn btn--accent" type="button" id="modalOk">Ок</button>
      </div>
    </div>
  </div>

  <script src="<?= h(url('/adm/view/assets/js/main.js')) ?>"></script>
</body>
</html>
