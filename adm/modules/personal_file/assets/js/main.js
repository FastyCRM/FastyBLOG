/**
 * FILE: /adm/modules/personal_file/assets/js/main.js
 * ROLE: UI модуля personal_file (вкладки, модалка, доступы)
 * CONNECTIONS:
 *  - /adm/modules/personal_file/personal_file.php (HTML)
 *  - window.App.modal (глобальная модалка проекта)
 *
 * NOTES:
 *  - Никакой бизнес-логики, только UI-обвязка.
 *
 * СПИСОК ФУНКЦИЙ:
 *  - pf_open_modal(url): открыть модалку настроек/карточки
 *  - pf_fetch_secret(id, field, mode, btn): раскрыть/скопировать логин/пароль
 */

(function () {
  'use strict';

  var doc = document;
  var mainEl = doc.querySelector('.main');
  if (mainEl) mainEl.classList.add('pf-main');

  /**
   * Открытие модалки (fetch -> App.modal)
   */
  function pf_open_modal(url) {
    if (!url) return;
    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true || !payload.data) return;
        var title = (payload.data.title || 'Модалка').toString();
        var html = (payload.data.html || '').toString();
        if (window.App && window.App.modal && typeof window.App.modal.open === 'function') {
          window.App.modal.open(title, html);
        }
      })
      .catch(function () {});
  }

  /**
   * Кнопки модалок (настройки/изменить клиента)
   */
  doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;
    var btn = t.closest('[data-pf-open-modal="1"]');
    if (!btn) return;
    e.preventDefault();
    var url = btn.getAttribute('data-pf-modal') || '';
    pf_open_modal(url);
  });

  /**
   * Вкладки
   */
  doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;
    var btn = t.closest('[data-pf-tab]');
    if (!btn) return;

    var tab = btn.getAttribute('data-pf-tab') || '';
    if (!tab) return;

    doc.querySelectorAll('[data-pf-tab]').forEach(function (el) {
      el.classList.toggle('is-active', el === btn);
    });

    doc.querySelectorAll('[data-pf-panel]').forEach(function (panel) {
      panel.classList.toggle('is-active', panel.getAttribute('data-pf-panel') === tab);
    });
  });

  /**
   * Доступы: показать/скопировать
   */
  function pf_fetch_secret(id, field, mode, btn) {
    if (!id || !field) return;

    var url = '/adm/index.php?m=personal_file&do=access_reveal'
      + '&id=' + encodeURIComponent(id)
      + '&field=' + encodeURIComponent(field)
      + '&mode=' + encodeURIComponent(mode || 'view');

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true || !payload.data) return;
        var value = (payload.data.value || '').toString();

        if (mode === 'copy') {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).catch(function () {});
          } else {
            var tmp = doc.createElement('textarea');
            tmp.value = value;
            doc.body.appendChild(tmp);
            tmp.select();
            try { doc.execCommand('copy'); } catch (e) {}
            tmp.remove();
          }
          if (btn) {
            btn.classList.add('is-copied');
            setTimeout(function () { btn.classList.remove('is-copied'); }, 1200);
          }
          return;
        }

        var cell = doc.querySelector('[data-pf-secret="' + field + '"][data-id="' + id + '"]');
        if (cell) {
          cell.textContent = value || '—';
          cell.classList.add('is-revealed');
        }
      })
      .catch(function () {});
  }

  doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    var btnReveal = t.closest('[data-pf-reveal="1"]');
    if (btnReveal) {
      var id1 = btnReveal.getAttribute('data-id') || '';
      var field1 = btnReveal.getAttribute('data-field') || '';
      pf_fetch_secret(id1, field1, 'view', btnReveal);
      return;
    }

    var btnCopy = t.closest('[data-pf-copy="1"]');
    if (btnCopy) {
      var id2 = btnCopy.getAttribute('data-id') || '';
      var field2 = btnCopy.getAttribute('data-field') || '';
      pf_fetch_secret(id2, field2, 'copy', btnCopy);
    }
  });

  function pf_client_full_name(item) {
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

  function pf_is_valid_query(query) {
    var q = String(query || '').trim();
    if (q === '') return false;

    var digits = q.replace(/\D+/g, '');
    var compact = q.replace(/[\s()+\-\.]/g, '');
    var isNumeric = compact !== '' && /^[0-9]+$/.test(compact);

    if (isNumeric) return digits.length >= 4;
    return q.length >= 2;
  }

  function pf_search_url(api, query) {
    var sep = api.indexOf('?') === -1 ? '?' : '&';
    return api + sep + 'q=' + encodeURIComponent(query) + '&limit=40';
  }

  function pf_render_search_items(resultsEl, items, activeClientId) {
    resultsEl.innerHTML = '';

    (items || []).forEach(function (item) {
      var id = parseInt(String(item && item.id ? item.id : '0'), 10);
      if (!id) return;

      var link = doc.createElement('a');
      var isActive = (String(id) === String(activeClientId));
      link.className = 'pf-search-item' + (isActive ? ' is-active' : '');
      link.href = '/adm/index.php?m=personal_file&client_id=' + encodeURIComponent(String(id));

      var name = doc.createElement('span');
      name.className = 'pf-search-item__name';
      var fullName = pf_client_full_name(item);
      name.textContent = fullName !== '' ? fullName : ('Клиент #' + String(id));

      var meta = doc.createElement('span');
      meta.className = 'pf-search-item__meta';
      var metaParts = [];
      var phone = String(item && item.phone ? item.phone : '').trim();
      var inn = String(item && item.inn ? item.inn : '').trim();
      var lastVisitAt = String(item && item.last_visit_at ? item.last_visit_at : '').trim();
      metaParts.push(phone !== '' ? phone : '—');
      if (inn !== '') metaParts.push('ИНН ' + inn);
      if (lastVisitAt !== '') metaParts.push(lastVisitAt);
      meta.textContent = metaParts.join(' | ');

      link.appendChild(name);
      link.appendChild(meta);
      resultsEl.appendChild(link);
    });
  }

  function pf_init_client_search() {
    var root = doc.querySelector('[data-pf-client-search="1"]');
    if (!root) return;

    var api = root.getAttribute('data-pf-search-api') || '';
    if (!api) return;

    var activeClientId = root.getAttribute('data-pf-search-active-id') || '';
    var input = root.querySelector('[data-pf-search-input="1"]');
    var trigger = root.querySelector('[data-pf-search-trigger="1"]');
    var cardBody = root.closest('.card__body') || root.parentElement;
    if (!input || !cardBody) return;

    var resultsEl = cardBody.querySelector('[data-pf-search-results="1"]');
    var emptyEl = cardBody.querySelector('[data-pf-search-empty="1"]');
    var hintEl = cardBody.querySelector('[data-pf-search-hint="1"]');
    if (!resultsEl || !emptyEl || !hintEl) return;

    var timer = 0;
    var reqSeq = 0;

    function resetResultState() {
      resultsEl.innerHTML = '';
      resultsEl.classList.add('is-hidden');
      emptyEl.classList.add('is-hidden');
      hintEl.classList.remove('is-hidden');
    }

    function showResults(items) {
      if (!items.length) {
        resultsEl.innerHTML = '';
        resultsEl.classList.add('is-hidden');
        emptyEl.classList.remove('is-hidden');
        hintEl.classList.add('is-hidden');
        return;
      }

      pf_render_search_items(resultsEl, items, activeClientId);
      resultsEl.classList.remove('is-hidden');
      emptyEl.classList.add('is-hidden');
      hintEl.classList.add('is-hidden');
    }

    function runSearch(force) {
      var query = String(input.value || '').trim();
      if (!force && !pf_is_valid_query(query)) {
        resetResultState();
        return;
      }
      if (query === '') {
        resetResultState();
        return;
      }

      reqSeq += 1;
      var seqLocal = reqSeq;
      var url = pf_search_url(api, query);

      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (payload) {
          if (seqLocal !== reqSeq) return;
          var items = (payload && payload.ok === true && payload.data && Array.isArray(payload.data.items))
            ? payload.data.items
            : [];
          showResults(items);
        })
        .catch(function () {
          if (seqLocal !== reqSeq) return;
          resultsEl.innerHTML = '';
          resultsEl.classList.add('is-hidden');
          emptyEl.classList.remove('is-hidden');
          hintEl.classList.add('is-hidden');
        });
    }

    input.addEventListener('input', function () {
      if (timer) clearTimeout(timer);
      timer = window.setTimeout(function () { runSearch(false); }, 220);
    });

    input.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      runSearch(true);
    });

    if (trigger) {
      trigger.addEventListener('click', function () {
        runSearch(true);
      });
    }

    if (pf_is_valid_query(input.value)) {
      runSearch(true);
    } else {
      resetResultState();
    }
  }

  pf_init_client_search();
})();
