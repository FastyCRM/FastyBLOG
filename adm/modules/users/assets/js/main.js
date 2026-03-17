/**
 * FILE: /adm/modules/users/assets/js/main.js
 * ROLE: UI модуля users — открытие модалок через инфраструктурную App.modal
 * CONNECTIONS:
 *  - window.App.modal.open(title, html)
 *  - do=modal_* возвращает json_ok({title, html})
 *
 * RULES:
 *  - Никакой бизнес-логики
 *  - Никаких прямых action URL кроме do=modal_*
 */

(function () {
  'use strict';

  /**
   * $doc — документ
   */
  var $doc = document;

  /**
   * users_open_modal()
   * Загружает JSON модалки и открывает глобальную модалку.
   *
   * @param {string} url — URL do=modal_*
   * @returns {void}
   */
  function users_open_modal(url) {
    if (!url) return;

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        /**
         * Ожидаем: { ok:true, data:{title, html} }
         */
        if (!payload || payload.ok !== true || !payload.data) return;

        var title = (payload.data.title || '').toString();
        var html = (payload.data.html || '').toString();

        if (window.App && window.App.modal && typeof window.App.modal.open === 'function') {
          window.App.modal.open(title, html);
        }
      })
      .catch(function () {});
  }

  /**
   * Делегирование клика по атрибуту data-users-open-modal="1"
   */
  $doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    /**
     * $btn — ближайшая кнопка открытия модалки
     */
    var $btn = t.closest('[data-users-open-modal="1"]');
    if (!$btn) return;

    e.preventDefault();

    /**
     * $url — URL модалки
     */
    var $url = $btn.getAttribute('data-users-modal') || '';
    users_open_modal($url);
  });
})();
