/**
 * FILE: /adm/modules/promobot/assets/js/main.js
 * ROLE: UI модуля promobot — открытие модалок через App.modal
 */

(function () {
  'use strict';

  /**
   * $doc — документ
   */
  var $doc = document;

  /**
   * promobot_open_modal()
   * Загружает JSON модалки и открывает глобальную модалку.
   *
   * @param {string} url — URL do=modal_*
   * @returns {void}
   */
  function promobot_open_modal(url) {
    if (!url) return;

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
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
   * promobot_filter_promos()
   * Локально фильтрует список промокодов по ключевым словам.
   *
   * @param {HTMLInputElement} $input
   * @returns {void}
   */
  function promobot_filter_promos($input) {
    if (!$input) return;

    var target = ($input.getAttribute('data-promobot-promo-target') || '').trim();
    if (!target) return;

    var $table = $doc.querySelector('[data-promobot-promo-table="' + target + '"]');
    if (!$table) return;

    var query = ($input.value || '').toLocaleLowerCase().trim();
    var $rows = $table.querySelectorAll('[data-promobot-promo-row="1"]');
    var $empty = $table.querySelector('[data-promobot-promo-empty="1"]');
    var $tbody = $table.querySelector('tbody');
    var visible = 0;

    if (!$empty && $tbody) {
      $empty = document.createElement('tr');
      $empty.setAttribute('data-promobot-promo-empty', '1');
      $empty.style.display = 'none';
      $empty.innerHTML = '<td colspan="4" class="muted"></td>';
      $tbody.appendChild($empty);
    }

    $rows.forEach(function ($row) {
      var haystack = ($row.getAttribute('data-promobot-promo-search-text') || $row.textContent || '')
        .toLocaleLowerCase()
        .trim();
      var match = (query === '' || haystack.indexOf(query) !== -1);

      $row.style.display = match ? '' : 'none';
      if (match) visible += 1;
    });

    if ($empty) {
      var $emptyCell = $empty.querySelector('td');
      if ($emptyCell && !$emptyCell.textContent) {
        $emptyCell.textContent = $table.getAttribute('data-promobot-promo-empty-text') || '';
      }
      $empty.style.display = visible === 0 ? '' : 'none';
    }
  }

  /**
   * Делегирование клика по атрибуту data-promobot-open-modal="1"
   */
  $doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    var $btn = t.closest('[data-promobot-open-modal="1"]');
    if (!$btn) return;

    e.preventDefault();

    var url = $btn.getAttribute('data-promobot-modal') || '';
    promobot_open_modal(url);
  });

  $doc.addEventListener('input', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLInputElement)) return;
    if (t.matches('[data-promobot-promo-search="1"]')) {
      promobot_filter_promos(t);
    }
  });

  $doc.querySelectorAll('[data-promobot-promo-search="1"]').forEach(function ($input) {
    if ($input instanceof HTMLInputElement) {
      promobot_filter_promos($input);
    }
  });
})();
