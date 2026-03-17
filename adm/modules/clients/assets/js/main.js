/**
 * FILE: /adm/modules/clients/assets/js/main.js
 * ROLE: clients module UI: modal open + client autocomplete search.
 */

(function () {
  'use strict';

  var doc = document;
  var dict = (window.CRM_CLIENTS_I18N && typeof window.CRM_CLIENTS_I18N === 'object')
    ? window.CRM_CLIENTS_I18N
    : {};

  function tr(key, fallback) {
    var val = dict[key];
    if (typeof val !== 'string' || val === '') return fallback;
    return val;
  }

  function trf(key, fallback, data) {
    var text = tr(key, fallback);
    if (!data || typeof data !== 'object') return text;

    Object.keys(data).forEach(function (k) {
      var token = '{' + k + '}';
      text = text.split(token).join(String(data[k]));
    });

    return text;
  }

  function clientsOpenModal(url) {
    if (!url) return;

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true || !payload.data) return;

        var title = String(payload.data.title || tr('modal_title', 'Modal'));
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

  function renderSearchItems(resultsEl, items, activeClientId) {
    resultsEl.innerHTML = '';

    (items || []).forEach(function (item) {
      var id = parseInt(String(item && item.id ? item.id : '0'), 10);
      if (!id) return;

      var link = doc.createElement('a');
      var isActive = String(id) === String(activeClientId);
      link.className = 'clients-search-item' + (isActive ? ' is-active' : '');
      link.href = '/adm/index.php?m=clients&client_id=' + encodeURIComponent(String(id));

      var name = doc.createElement('span');
      name.className = 'clients-search-item__name';
      var full = fullName(item);
      name.textContent = full !== ''
        ? full
        : trf('client_fallback', 'Client #{id}', { id: id });

      var meta = doc.createElement('span');
      meta.className = 'clients-search-item__meta';
      var phone = String(item && item.phone ? item.phone : '').trim();
      var inn = String(item && item.inn ? item.inn : '').trim();
      var lastVisitAt = String(item && item.last_visit_at ? item.last_visit_at : '').trim();
      var parts = [];
      parts.push(phone !== '' ? phone : tr('dash', '-'));
      if (inn !== '') parts.push(tr('inn_prefix', 'INN') + ' ' + inn);
      if (lastVisitAt !== '') parts.push(lastVisitAt);
      meta.textContent = parts.join(' | ');

      link.appendChild(name);
      link.appendChild(meta);
      resultsEl.appendChild(link);
    });
  }

  function initClientSearch() {
    var root = doc.querySelector('[data-clients-search-root="1"]');
    if (!root) return;

    var api = root.getAttribute('data-clients-search-api') || '';
    if (!api) return;

    var activeClientId = root.getAttribute('data-clients-search-active-id') || '';
    var input = root.querySelector('[data-clients-search-input="1"]');
    var trigger = root.querySelector('[data-clients-search-trigger="1"]');
    var clientIdInput = root.querySelector('[data-clients-search-client-id="1"]');
    if (!input) return;

    var resultsEl = root.querySelector('[data-clients-search-results="1"]');
    var emptyEl = root.querySelector('[data-clients-search-empty="1"]');
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

      renderSearchItems(resultsEl, items, activeClientId);
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

    var btn = t.closest('[data-clients-open-modal="1"]');
    if (!btn) return;

    e.preventDefault();
    var url = btn.getAttribute('data-clients-modal') || '';
    clientsOpenModal(url);
  });

  initClientSearch();
})();
