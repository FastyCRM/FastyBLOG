/**
 * FILE: /adm/modules/bot_adv_calendar/assets/js/main.js
 * ROLE: UI для модалок bot_adv_calendar
 */

(function () {
  'use strict';

  var doc = document;

  function openModal(url) {
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

  doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    var btn = t.closest('[data-bac-open-modal="1"]');
    if (!btn) return;

    e.preventDefault();
    openModal(btn.getAttribute('data-bac-modal') || '');
  });
})();

