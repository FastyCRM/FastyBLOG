/**
 * FILE: /adm/modules/tg_system_users/assets/js/main.js
 * ROLE: UI-обвязка модуля tg_system_users
 */

(function () {
  'use strict';

  /**
   * $doc — документ страницы.
   */
  var $doc = document;

  /**
   * tg_su_open_modal()
   * Загружает do=modal_* и открывает глобальную модалку.
   *
   * @param {string} url
   * @returns {void}
   */
  function tg_su_open_modal(url) {
    if (!url) return;

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true || !payload.data) return;

        var title = String(payload.data.title || 'Модалка');
        var html = String(payload.data.html || '');

        if (window.App && window.App.modal && typeof window.App.modal.open === 'function') {
          window.App.modal.open(title, html);
        }
      })
      .catch(function () {});
  }

  $doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    var btn = t.closest('[data-tg-su-open-modal="1"]');
    if (!btn) return;

    e.preventDefault();
    var modalUrl = btn.getAttribute('data-tg-su-modal') || '';
    tg_su_open_modal(modalUrl);
  });
})();

