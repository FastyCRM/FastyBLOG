/**
 * FILE: /adm/modules/tg_system_clients/assets/js/main.js
 * ROLE: UI for tg_system_clients module.
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

  function isValidQuery(query) {
    var q = String(query || '').trim();
    if (q === '') return false;

    var digits = q.replace(/\D+/g, '');
    var compact = q.replace(/[\s()+\-\.]/g, '');
    var isNumeric = compact !== '' && /^[0-9]+$/.test(compact);

    if (isNumeric) return digits.length >= 4;
    return q.length >= 2;
  }

  function searchUrl(api, query) {
    var sep = api.indexOf('?') === -1 ? '?' : '&';
    return api + sep + 'q=' + encodeURIComponent(query) + '&limit=40';
  }

  function fullName(item) {
    var first = String(item && item.first_name ? item.first_name : '').trim();
    var last = String(item && item.last_name ? item.last_name : '').trim();
    var middle = String(item && item.middle_name ? item.middle_name : '').trim();
    var display = String(item && item.display_name ? item.display_name : '').trim();

    var full = [last, first, middle].filter(Boolean).join(' ').trim();
    if (full !== '') return full;
    if (display !== '') return display;
    if (first !== '') return first;
    return '';
  }

  function renderItems(resultsEl, items, activeClientId) {
    resultsEl.innerHTML = '';

    (items || []).forEach(function (item) {
      var id = parseInt(String(item && item.id ? item.id : '0'), 10);
      if (!id) return;

      var link = doc.createElement('a');
      var isActive = String(id) === String(activeClientId);
      link.className = 'tg-su-search-item' + (isActive ? ' is-active' : '');
      link.href = '/adm/index.php?m=tg_system_clients&client_id=' + encodeURIComponent(String(id));

      var name = doc.createElement('span');
      name.className = 'tg-su-search-item__name';
      var full = fullName(item);
      name.textContent = full !== '' ? full : ('Клиент #' + String(id));

      var meta = doc.createElement('span');
      meta.className = 'tg-su-search-item__meta';
      var phone = String(item && item.phone ? item.phone : '').trim();
      var inn = String(item && item.inn ? item.inn : '').trim();
      var lastVisitAt = String(item && item.last_visit_at ? item.last_visit_at : '').trim();
      var parts = [];
      parts.push(phone !== '' ? phone : '—');
      if (inn !== '') parts.push('ИНН ' + inn);
      if (lastVisitAt !== '') parts.push(lastVisitAt);
      meta.textContent = parts.join(' | ');

      link.appendChild(name);
      link.appendChild(meta);
      resultsEl.appendChild(link);
    });
  }

  function initClientSearch() {
    var root = doc.querySelector('[data-tg-su-client-search="1"]');
    if (!root) return;

    var api = root.getAttribute('data-tg-su-search-api') || '';
    if (!api) return;

    var activeClientId = root.getAttribute('data-tg-su-search-active-id') || '';
    var input = root.querySelector('[data-tg-su-search-input="1"]');
    var trigger = root.querySelector('[data-tg-su-search-trigger="1"]');
    var clientIdInput = root.querySelector('[data-tg-su-client-id="1"]');
    if (!input) return;

    var resultsEl = root.querySelector('[data-tg-su-search-results="1"]');
    var emptyEl = root.querySelector('[data-tg-su-search-empty="1"]');
    if (!resultsEl || !emptyEl) return;

    var timer = 0;
    var reqSeq = 0;

    function resetState() {
      resultsEl.innerHTML = '';
      resultsEl.classList.add('is-hidden');
      emptyEl.classList.add('is-hidden');
    }

    function showResults(items) {
      if (!items.length) {
        resultsEl.innerHTML = '';
        resultsEl.classList.add('is-hidden');
        emptyEl.classList.remove('is-hidden');
        return;
      }

      renderItems(resultsEl, items, activeClientId);
      resultsEl.classList.remove('is-hidden');
      emptyEl.classList.add('is-hidden');
    }

    function runSearch(force) {
      var query = String(input.value || '').trim();
      if (!force && !isValidQuery(query)) {
        resetState();
        return;
      }
      if (query === '') {
        resetState();
        return;
      }

      reqSeq += 1;
      var localSeq = reqSeq;

      fetch(searchUrl(api, query), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (payload) {
          if (localSeq !== reqSeq) return;
          var items = (payload && payload.ok === true && payload.data && Array.isArray(payload.data.items))
            ? payload.data.items
            : [];
          showResults(items);
        })
        .catch(function () {
          if (localSeq !== reqSeq) return;
          resultsEl.innerHTML = '';
          resultsEl.classList.add('is-hidden');
          emptyEl.classList.remove('is-hidden');
        });
    }

    input.addEventListener('input', function () {
      if (clientIdInput) clientIdInput.value = '';
      if (timer) clearTimeout(timer);
      timer = window.setTimeout(function () { runSearch(false); }, 220);
    });

    input.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      if (clientIdInput) clientIdInput.value = '';
      e.preventDefault();
      runSearch(true);
    });

    if (trigger) {
      trigger.addEventListener('click', function (e) {
        e.preventDefault();
        if (clientIdInput) clientIdInput.value = '';
        runSearch(true);
      });
    }

    doc.addEventListener('click', function (e) {
      var target = e.target;
      if (!(target instanceof Node)) return;
      if (!root.contains(target)) {
        resetState();
      }
    });

    resetState();
  }

  doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    var btn = t.closest('[data-tg-su-open-modal="1"]');
    if (!btn) return;

    e.preventDefault();
    openModal(btn.getAttribute('data-tg-su-modal') || '');
  });

  initClientSearch();
})();
