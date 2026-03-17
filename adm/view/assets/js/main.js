/**
 * FILE: /adm/view/assets/js/main.js
 * ROLE: UI infrastructure (burger, theme, modal, flashbar)
 * CONNECTIONS:
 *  - DOM: #app, #sidebar, #btnBurger, #themeSelect
 *  - Optional: #themeEcho, #btnTestModal
 *  - Modal DOM: #modal, #modalTitle, #modalBody
 *  - Flash DOM: #flashbar, window.__FLASH__
 *
 * RULES:
 *  - No module logic here
 *  - One global modal for all modules
 */

(function () {
  'use strict';

  /** @type {HTMLElement|null} */
  const app = document.getElementById('app');
  /** @type {HTMLElement|null} */
  const sidebar = document.getElementById('sidebar');
  /** @type {HTMLElement|null} */
  const btnBurger = document.getElementById('btnBurger');
  /** @type {HTMLSelectElement|null} */
  const themeSelect = document.getElementById('themeSelect');
  /** @type {HTMLSelectElement|null} */
  const langSelect = document.getElementById('langSelect');
  /** @type {HTMLFormElement|null} */
  const langForm = document.getElementById('langForm');
  /** @type {HTMLElement|null} */
  const themeEcho = document.getElementById('themeEcho');
  /** @type {HTMLElement|null} */
  const btnTestModal = document.getElementById('btnTestModal');

  const STR = {
    closeLabel: '\u0417\u0430\u043a\u0440\u044b\u0442\u044c',
    closeGlyph: '\u00d7',
    modalTitleDefault: '\u041c\u043e\u0434\u0430\u043b\u043a\u0430',
    testModalTitle: '\u0422\u0435\u0441\u0442 \u043c\u043e\u0434\u0430\u043b\u043a\u0438',
    testModalIntro: '\u042d\u0442\u043e \u0442\u0435\u0441\u0442\u043e\u0432\u0430\u044f \u043c\u043e\u0434\u0430\u043b\u043a\u0430. \u041c\u043e\u0434\u0443\u043b\u0438 \u043f\u043e\u0434\u0441\u043e\u0432\u044b\u0432\u0430\u044e\u0442 HTML \u0447\u0435\u0440\u0435\u0437 do=modal_*.',
    cardTitle: '\u041f\u0440\u0438\u043c\u0435\u0440 \u043a\u043e\u043d\u0442\u0435\u043d\u0442\u0430',
    cardHint: '\u041a\u0430\u0440\u0442\u043e\u0447\u043a\u0430 \u0432\u043d\u0443\u0442\u0440\u0438 \u043c\u043e\u0434\u0430\u043b\u043a\u0438 \u0438\u0441\u043f\u043e\u043b\u044c\u0437\u0443\u0435\u0442 \u0442\u0435 \u0436\u0435 \u0442\u043e\u043a\u0435\u043d\u044b.',
    fieldLabel: '\u041f\u043e\u043b\u0435',
    fieldPlaceholder: '\u041f\u043e\u043a\u0430 \u0431\u0435\u0437 \u0444\u043e\u0440\u043c\u044b, \u0442\u043e\u043b\u044c\u043a\u043e \u0432\u0438\u0434',
  };

  /** @type {HTMLElement|null} */
  const flashbar = document.getElementById('flashbar');

  /** @type {HTMLElement|null} */
  const modal = document.getElementById('modal');
  /** @type {HTMLElement|null} */
  const modalTitle = document.getElementById('modalTitle');
  /** @type {HTMLElement|null} */
  const modalBody = document.getElementById('modalBody');

  // ---------------------------
  // FLASHBAR
  // ---------------------------

  function flash_bg(bg) {
    const val = (bg || 'info').toString().trim();

    if (val === 'danger') return 'rgba(255, 77, 79, 0.22)';
    if (val === 'warn')   return 'rgba(255, 199, 0, 0.20)';
    if (val === 'ok')     return 'rgba(82, 196, 26, 0.18)';
    if (val === 'accent') return 'rgba(0, 153, 255, 0.18)';
    if (val === 'info')   return 'rgba(255, 255, 255, 0.10)';

    if (/^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(val)) return val;

    return 'rgba(255, 255, 255, 0.10)';
  }

  function flash_beep(type) {
    try {
      const AudioCtx = window.AudioContext || window.webkitAudioContext;
      if (!AudioCtx) return;

      const ctx = new AudioCtx();

      function tone(freq, ms, wave, gainValue, slideTo) {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = wave;
        osc.frequency.setValueAtTime(freq, ctx.currentTime);

        if (slideTo && slideTo > 0) {
          osc.frequency.linearRampToValueAtTime(slideTo, ctx.currentTime + (ms / 1000));
        }

        const t0 = ctx.currentTime;
        gain.gain.setValueAtTime(0.0001, t0);
        gain.gain.linearRampToValueAtTime(gainValue, t0 + 0.01);
        gain.gain.linearRampToValueAtTime(0.0001, t0 + (ms / 1000));

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start(t0);
        osc.stop(t0 + (ms / 1000));
      }

      function seq(notes) {
        let total = 0;

        for (const n of notes) {
          total += n.delay;

          setTimeout(() => {
            tone(n.f, n.ms, n.w, n.g, n.s || 0);
          }, total);

          total += n.ms;
        }

        setTimeout(() => ctx.close().catch(() => {}), total + 120);
      }

      const t = (type || 'info').toString();

      if (t === 'danger') {
        seq([
          { f: 980, ms: 120, w: 'square',   g: 0.13, s: 760, delay: 0 },
          { f: 980, ms: 120, w: 'square',   g: 0.13, s: 620, delay: 60 },
        ]);
        return;
      }

      if (t === 'warn') {
        seq([
          { f: 660, ms: 90,  w: 'triangle', g: 0.10, delay: 0 },
          { f: 660, ms: 90,  w: 'triangle', g: 0.10, delay: 80 },
        ]);
        return;
      }

      if (t === 'ok') {
        seq([
          { f: 520, ms: 160, w: 'sine',     g: 0.09, s: 740, delay: 0 },
        ]);
        return;
      }

      if (t === 'accent') {
        seq([
          { f: 740, ms: 70,  w: 'square',   g: 0.09, delay: 0 },
          { f: 880, ms: 70,  w: 'square',   g: 0.09, delay: 70 },
          { f: 740, ms: 90,  w: 'square',   g: 0.09, delay: 70 },
        ]);
        return;
      }

      seq([
        { f: 440, ms: 90, w: 'sine', g: 0.06, delay: 0 },
      ]);
    } catch (e) {}
  }

  function flash_show(item) {
    if (!flashbar) return;

    const text = (item && item.text) ? String(item.text) : '';
    if (!text) return;

    const bg = flash_bg(item.bg || 'info');
    const needBeep = (item && Number(item.beep || 0) === 1);

    const el = document.createElement('div');
    el.className = 'flash';
    el.style.background = bg;

    const t = document.createElement('div');
    t.className = 'flash__text';
    t.textContent = text;

    const x = document.createElement('button');
    x.className = 'flash__close';
    x.type = 'button';
    x.setAttribute('aria-label', STR.closeLabel);
    x.textContent = STR.closeGlyph;

    x.addEventListener('click', () => el.remove());

    el.appendChild(t);
    el.appendChild(x);
    flashbar.appendChild(el);

    if (needBeep) flash_beep(item.bg || 'info');

    setTimeout(() => { if (el.isConnected) el.remove(); }, 6000);
  }

  const AppFlash = window.App || (window.App = {});
  AppFlash.flash = function (item) {
    flash_show(item || {});
  };
  AppFlash.notify = function (text, bg, beep) {
    flash_show({
      text: String(text || ''),
      bg: String(bg || 'info'),
      beep: beep ? 1 : 0,
    });
  };

  function flash_boot() {
    const list = window.__FLASH__;
    if (!Array.isArray(list)) return;

    for (const item of list) {
      flash_show(item);
    }

    window.__FLASH__ = [];
  }

  flash_boot();

  // ---------------------------
  // THEME
  // ---------------------------

  function setTheme(theme) {
    document.documentElement.dataset.theme = theme;
    localStorage.setItem('crm2026_theme', theme);
    if (themeSelect) themeSelect.value = theme;
    if (themeEcho) themeEcho.textContent = theme;
  }

  if (themeSelect) {
    const savedTheme = localStorage.getItem('crm2026_theme');
    if (savedTheme === 'color' || savedTheme === 'black' || savedTheme === 'light') {
      setTheme(savedTheme);
    } else {
      setTheme('color');
    }

    themeSelect.addEventListener('change', () => {
      const val = themeSelect.value;
      if (val === 'color' || val === 'black' || val === 'light') setTheme(val);
    });
  }


  // ---------------------------
  // LANGUAGE
  // ---------------------------

  if (langSelect && langForm) {
    langSelect.addEventListener('change', () => {
      if (typeof langForm.requestSubmit === 'function') {
        langForm.requestSubmit();
      } else {
        langForm.submit();
      }
    });
  }
  // Theme buttons are disabled; use select.

  // ---------------------------
  // BURGER / SIDEBAR
  // ---------------------------

  function isMobile() {
    return window.matchMedia('(max-width: 1024px)').matches;
  }

  if (app && btnBurger) {
    btnBurger.addEventListener('click', () => {
      if (isMobile()) {
        app.classList.toggle('is-mobile-menu');
      } else {
        app.classList.toggle('is-collapsed');
      }
    });

    document.addEventListener('click', (e) => {
      if (!isMobile()) return;
      if (!app.classList.contains('is-mobile-menu')) return;
      if (!sidebar) return;

      const insideSidebar = sidebar.contains(e.target);
      const clickedBurger = btnBurger.contains(e.target);
      if (!insideSidebar && !clickedBurger) {
        app.classList.remove('is-mobile-menu');
      }
    });

    window.addEventListener('resize', () => {
      if (isMobile()) return;
      app.classList.remove('is-mobile-menu');
    });
  }

  // ---------------------------
  // MODAL
  // ---------------------------

  if (modal && modalTitle && modalBody) {
    const App = window.App || (window.App = {});
    App.modal = App.modal || {};

    // Store scroll position before modal opens.
    let modalScrollY = 0;

    // Lock background scroll while modal is open.
    function modal_lock_scroll() {
      modalScrollY = window.scrollY || 0;
      document.body.classList.add('is-modal-open');
      document.body.style.position = 'fixed';
      document.body.style.top = (-modalScrollY) + 'px';
      document.body.style.left = '0';
      document.body.style.right = '0';
    }

    // Restore scroll on close.
    function modal_unlock_scroll() {
      document.body.classList.remove('is-modal-open');
      document.body.style.position = '';
      document.body.style.top = '';
      document.body.style.left = '';
      document.body.style.right = '';
      window.scrollTo(0, modalScrollY || 0);
      modalScrollY = 0;
    }

    App.modal.open = function (title, html) {
      modalTitle.textContent = title || STR.modalTitleDefault;
      modalBody.innerHTML = html || '';
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');

      if (App.ui && typeof App.ui.selectInit === 'function') {
        App.ui.selectInit(modalBody);
      }

      // [ADDED]
      modal_lock_scroll();
    };

    App.modal.close = function () {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      modalBody.innerHTML = '';

      // [ADDED]
      modal_unlock_scroll();
    };

    modal.addEventListener('click', (e) => {
      const t = e.target;
      if (!(t instanceof HTMLElement)) return;
      if (t.dataset.modalClose === '1') App.modal.close();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && modal.classList.contains('is-open')) {
        App.modal.close();
      }
    });

    if (btnTestModal) {
      btnTestModal.addEventListener('click', () => {
        App.modal.open(STR.testModalTitle, `
          <div class="muted" style="margin-bottom:10px;">
            ${STR.testModalIntro}
          </div>
          <div class="card" style="box-shadow:none; border-color: var(--border-soft);">
            <div class="card__head">
              <div class="card__title">${STR.cardTitle}</div>
              <div class="card__hint muted">${STR.cardHint}</div>
            </div>
            <div class="card__body">
              <label class="field field--stack">
                <span class="field__label">${STR.fieldLabel}</span>
                <input class="select" style="height:40px;" placeholder="${STR.fieldPlaceholder}" />
              </label>
            </div>
          </div>
        `);
      });
    }
  }

  // ---------------------------
  // CUSTOM SELECT
  // ---------------------------

  const App = window.App || (window.App = {});
  App.ui = App.ui || {};

  function ui_select_close() {
    document.querySelectorAll('.ui-select.is-open').forEach((el) => {
      el.classList.remove('is-open');
    });
  }

  function ui_select_sync(sel, wrap) {
    if (!sel || !wrap) return;
    const btn = wrap.querySelector('.ui-select__btn');
    const items = wrap.querySelectorAll('.ui-select__item');
    if (!btn) return;

    let currentText = '';
    Array.prototype.forEach.call(sel.options, (opt) => {
      if (opt.value === sel.value) currentText = opt.textContent || '';
    });
    if (!currentText) {
      const placeholder = sel.getAttribute('data-placeholder') || '';
      currentText = placeholder;
    }
    btn.textContent = currentText !== '' ? currentText : '\u2014';

    items.forEach((it) => {
      const val = it.getAttribute('data-value');
      it.classList.toggle('is-active', val === sel.value);
    });
  }

  function ui_select_build(sel) {
    if (!sel) return;

    const parent = sel.parentNode;
    if (!parent) return;

    const next = sel.nextElementSibling;
    if (next && next.classList && next.classList.contains('ui-select')) {
      next.remove();
    }

    sel.style.display = 'none';

    const wrap = document.createElement('div');
    wrap.className = 'ui-select';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'ui-select__btn select';

    const list = document.createElement('div');
    list.className = 'ui-select__list';

    Array.prototype.forEach.call(sel.options, (opt) => {
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'ui-select__item';
      item.textContent = (opt.textContent || '').toString();
      item.setAttribute('data-value', opt.value);

      if (opt.disabled) item.disabled = true;
      if (opt.value === sel.value) item.classList.add('is-active');

      list.appendChild(item);
    });

    btn.addEventListener('click', () => {
      const isOpen = wrap.classList.contains('is-open');
      ui_select_close();

      if (!isOpen) {
        list.style.display = 'block';
        list.style.visibility = 'hidden';
        const listH = Math.min(list.scrollHeight, 200) + 8;
        list.style.display = '';
        list.style.visibility = '';

        const rect = wrap.getBoundingClientRect();
        const selectScope = wrap.closest('[data-ui-select-scope="1"], .modal__body');
        let spaceBelow = window.innerHeight - rect.bottom;
        let spaceAbove = rect.top;

        // If select has dedicated scope (modal body or explicit container),
        // list must fit that viewport instead of whole window.
        if (selectScope) {
          const scopeRect = selectScope.getBoundingClientRect();
          spaceBelow = scopeRect.bottom - rect.bottom;
          spaceAbove = rect.top - scopeRect.top;
        }

        const openUp = spaceBelow < listH && spaceAbove > spaceBelow;

        wrap.classList.toggle('is-up', openUp);
      }

      wrap.classList.toggle('is-open', !isOpen);
    });

    list.addEventListener('click', (e) => {
      const t = e.target;
      if (!(t instanceof HTMLElement)) return;
      const val = t.getAttribute('data-value');
      if (val === null) return;

      sel.value = val;
      sel.dispatchEvent(new Event('change', { bubbles: true }));

      ui_select_sync(sel, wrap);
      wrap.classList.remove('is-open');
    });

    wrap.appendChild(btn);
    wrap.appendChild(list);
    parent.insertBefore(wrap, sel.nextSibling);

    ui_select_sync(sel, wrap);
  }

  App.ui.selectInit = function (root) {
    const scope = root || document;
    const selects = scope.querySelectorAll('select[data-ui-select="1"]');
    selects.forEach((sel) => ui_select_build(sel));
  };

  App.ui.selectRebuild = function (sel) {
    ui_select_build(sel);
  };

  document.addEventListener('click', (e) => {
    const t = e.target;
    if (!(t instanceof HTMLElement)) return;
    if (t.closest('.ui-select')) return;
    ui_select_close();
  });

  App.ui.selectInit(document);
})();
