/**
 * FILE: /adm/modules/dashboard/assets/js/main.js
 * ROLE: UI dashboard (модалка настроек + управление dropdown уведомлений)
 */

(function () {
  'use strict';

  /**
   * $doc — document
   */
  var $doc = document;

  /**
   * dashboard_open_modal()
   * Загружает do=modal_* и открывает глобальную модалку.
   *
   * @param {string} url
   * @returns {void}
   */
  function dashboard_open_modal(url) {
    if (!url) return;

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true || !payload.data) return;

        var modalTitleEl = document.getElementById('modalTitle');
        var fallbackTitle = modalTitleEl ? (modalTitleEl.textContent || '') : '';
        var title = (payload.data.title || fallbackTitle || 'Modal').toString();
        var html = (payload.data.html || '').toString();

        if (window.App && window.App.modal && typeof window.App.modal.open === 'function') {
          window.App.modal.open(title, html);
        }
      })
      .catch(function () {});
  }

  /**
   * dashboard_close_notifications()
   * Закрывает все раскрытые details-уведомления.
   *
   * @returns {void}
   */
  function dashboard_close_notifications() {
    $doc.querySelectorAll('[data-dashboard-notify="1"]').forEach(function (el) {
      if (el instanceof HTMLDetailsElement) {
        el.removeAttribute('open');
      }
    });
  }

  $doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    var modalBtn = t.closest('[data-dashboard-open-modal="1"]');
    if (modalBtn) {
      e.preventDefault();
      var modalUrl = modalBtn.getAttribute('data-dashboard-modal') || '';
      dashboard_open_modal(modalUrl);
      return;
    }

    var notifyWrap = t.closest('[data-dashboard-notify="1"]');
    if (!notifyWrap) {
      dashboard_close_notifications();
    }
  });
})();
