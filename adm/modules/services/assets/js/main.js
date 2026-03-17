/**
 * FILE: /adm/modules/services/assets/js/main.js
 * ROLE: UI модуля services - модалки + поиск
 */

(function () {
  'use strict';

  /**
   * $doc - документ
   */
  var $doc = document;
  var dict = (window.CRM_SERVICES_I18N && typeof window.CRM_SERVICES_I18N === 'object')
    ? window.CRM_SERVICES_I18N
    : {};

  function tr(key, fallback) {
    var val = dict[key];
    if (typeof val !== 'string' || val === '') return fallback;
    return val;
  }

  /**
   * services_open_modal()
   * @param {string} url
   * @returns {void}
   */
  function services_open_modal(url) {
    if (!url) return;

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true || !payload.data) return;

        var title = (payload.data.title || tr('modal_title', 'Modal')).toString();
        var html = (payload.data.html || '').toString();

        if (window.App && window.App.modal && typeof window.App.modal.open === 'function') {
          window.App.modal.open(title, html);
        }
      })
      .catch(function () {});
  }

  /**
   * Делегирование клика по data-services-open-modal="1"
   */
  $doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    var $btn = t.closest('[data-services-open-modal="1"]');
    if (!$btn) return;

    e.preventDefault();

    var url = $btn.getAttribute('data-services-modal') || '';
    services_open_modal(url);
  });

  /**
   * Поиск + подсказки + фильтр по категории
   */
  var $search = $doc.querySelector('[data-services-search="1"]');
  if (!$search) return;

  var $wrap = $doc.querySelector('[data-services-search-wrap="1"]');
  var $list = $doc.querySelector('[data-services-search-list="1"]');
  var $rows = Array.prototype.slice.call($doc.querySelectorAll('[data-services-search-item="1"]'));

  var suggest = [];
  try {
    suggest = JSON.parse($search.getAttribute('data-services-suggest') || '[]');
  } catch (e) {
    suggest = [];
  }

  /**
   * $activeCategoryId - активный фильтр категории
   */
  var $activeCategoryId = 0;

  /**
   * services_filter()
   * @param {string} q
   */
  function services_filter(q) {
    var needle = (q || '').toString().trim().toLowerCase();

    $rows.forEach(function (row) {
      var type = (row.getAttribute('data-services-type') || '').toString();
      var hay = (row.getAttribute('data-search') || '').toString().toLowerCase();
      var rowCat = parseInt(row.getAttribute('data-category-id') || '0', 10) || 0;
      var match = true;

      if (needle !== '') {
        match = (hay.indexOf(needle) !== -1);
      }

      if (type === 'service' && $activeCategoryId > 0) {
        match = match && (rowCat === $activeCategoryId);
      }

      row.style.display = match ? '' : 'none';
    });

    services_update_empty();
  }

  /**
   * services_update_empty()
   */
  function services_update_empty() {
    var empties = $doc.querySelectorAll('[data-services-empty="1"]');
    empties.forEach(function (empty) {
      var tbody = empty.parentElement;
      if (!tbody) return;
      var visible = 0;
      tbody.querySelectorAll('[data-services-search-item="1"]').forEach(function (row) {
        if (row.style.display !== 'none') visible += 1;
      });
      empty.style.display = (visible === 0) ? '' : 'none';

      var isServices = empty.getAttribute('data-services-empty-type') === 'services';
      if (isServices) {
        var text = empty.querySelector('[data-services-empty-text="1"]');
        if (text) {
          text.textContent = ($activeCategoryId > 0)
            ? tr('empty_services_in_category', 'No services in this category.')
            : tr('empty_services', 'No services.');
        }
      }
    });
  }

  /**
   * services_build_suggest()
   * @param {string} q
   */
  function services_build_suggest(q) {
    if (!$list || !$wrap) return;

    var needle = (q || '').toString().trim().toLowerCase();
    $list.innerHTML = '';

    if (needle === '') {
      $wrap.classList.remove('is-open');
      return;
    }

    var matched = [];
    for (var i = 0; i < suggest.length; i += 1) {
      var item = suggest[i] || {};
      var name = (item.name || '').toString();
      if (!name) continue;
      if (name.toLowerCase().indexOf(needle) === -1) continue;
      matched.push(item);
      if (matched.length >= 8) break;
    }

    if (!matched.length) {
      $wrap.classList.remove('is-open');
      return;
    }

    matched.forEach(function (item) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'services-search__item';
      btn.setAttribute('data-value', (item.name || '').toString());

      var title = document.createElement('span');
      title.className = 'services-search__name';
      title.textContent = (item.name || '').toString();

      var type = document.createElement('span');
      type.className = 'services-search__type';
      type.textContent = (item.type === 'category')
        ? tr('suggest_type_category', 'Category')
        : tr('suggest_type_service', 'Service');

      btn.appendChild(title);
      btn.appendChild(type);
      $list.appendChild(btn);
    });

    $wrap.classList.add('is-open');
  }

  $search.addEventListener('input', function () {
    var val = $search.value || '';
    services_filter(val);
    services_build_suggest(val);
  });

  $search.addEventListener('focus', function () {
    var val = $search.value || '';
    services_build_suggest(val);
  });

  $doc.addEventListener('click', function (e) {
    if (!$wrap) return;
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;
    if (t.closest('[data-services-search-wrap="1"]')) return;
    $wrap.classList.remove('is-open');
  });

  if ($list) {
    $list.addEventListener('click', function (e) {
      var t = e.target;
      if (!(t instanceof HTMLElement)) return;
      var btn = t.closest('.services-search__item');
      if (!btn) return;
      var val = btn.getAttribute('data-value') || '';
      $search.value = val;
      services_filter(val);
      if ($wrap) $wrap.classList.remove('is-open');
    });
  }

  /**
   * Фильтр по клику на категорию
   */
  $doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    var row = t.closest('tr[data-services-type="category"]');
    if (!row) return;
    if (t.closest('.table__actions')) return;

    var id = parseInt(row.getAttribute('data-category-id') || '0', 10) || 0;
    if (id <= 0) return;

    if ($activeCategoryId === id) {
      $activeCategoryId = 0;
      row.classList.remove('is-selected');
    } else {
      $activeCategoryId = id;
      $doc.querySelectorAll('tr[data-services-type="category"].is-selected').forEach(function (el) {
        el.classList.remove('is-selected');
      });
      row.classList.add('is-selected');
    }

    services_filter($search.value || '');
  });

  /**
   * Сброс фильтров
   */
  $doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;
    var btn = t.closest('[data-services-reset-filter="1"]');
    if (!btn) return;

    e.preventDefault();

    $activeCategoryId = 0;
    $doc.querySelectorAll('tr[data-services-type="category"].is-selected').forEach(function (el) {
      el.classList.remove('is-selected');
    });

    $search.value = '';
    services_filter('');
    if ($wrap) $wrap.classList.remove('is-open');
  });

  /**
   * Поиск по специалистам в модалке
   */
  $doc.addEventListener('input', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;
    if (!t.matches('[data-services-spec-search="1"]')) return;

    var wrap = t.closest('[data-services-spec-wrap="1"]');
    if (!wrap) return;

    var needle = (t.value || '').toString().trim().toLowerCase();
    var items = wrap.querySelectorAll('[data-services-spec-item="1"]');

    items.forEach(function (item) {
      var name = (item.getAttribute('data-name') || '').toString().toLowerCase();
      var match = (needle === '' || name.indexOf(needle) !== -1);
      item.style.display = match ? '' : 'none';
    });
  });
})();