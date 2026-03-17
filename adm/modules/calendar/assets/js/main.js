/**
 * FILE: /adm/modules/calendar/assets/js/main.js
 * ROLE: UI модуля calendar — открытие заявок в модалке
 */

(function () {
  'use strict';

  /**
   * calendar_open_modal()
   * @param {string} url
   * @returns {void}
   */
  function calendar_open_modal(url) {
    if (!url) return;

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true || !payload.data) return;

        var title = (payload.data.title || 'Модалка').toString();
        var html = (payload.data.html || '').toString();

        if (window.App && window.App.modal && typeof window.App.modal.open === 'function') {
          window.App.modal.open(title, html);
          calendar_init_settings_modal();
          if (window.RequestsUI && typeof window.RequestsUI.initModal === 'function') {
            setTimeout(function () {
              window.RequestsUI.initModal();
            }, 0);
          }
        }
      })
      .catch(function () {});
  }

  /**
   * calendar_init_settings_modal()
   * @returns {void}
   */
  function calendar_init_settings_modal() {
    var body = document.getElementById('modalBody');
    if (!body) return;

    var wrap = body.querySelector('[data-calendar-settings-modal="1"]');
    if (!wrap) return;

    var radios = wrap.querySelectorAll('input[name="calendar_mode"]');
    var managerWrap = wrap.querySelector('[data-calendar-manager-wrap="1"]');
    var managerUrl = managerWrap ? (managerWrap.getAttribute('data-calendar-manager-url') || '') : '';

    function loadManagerBlock() {
      if (!managerWrap || !managerUrl) return;
      if (managerWrap.getAttribute('data-loaded') === '1') return;

      managerWrap.setAttribute('data-loaded', '1');
      managerWrap.innerHTML = '<div class="muted" style="font-size:12px;">Загрузка…</div>';

      fetch(managerUrl, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (payload) {
          if (!payload || payload.ok !== true || !payload.data) return;
          managerWrap.innerHTML = (payload.data.html || '').toString();
          if (window.App && window.App.ui && typeof window.App.ui.selectInit === 'function') {
            window.App.ui.selectInit(managerWrap);
          }
        })
        .catch(function () {});
    }

    function toggleManager() {
      var mode = 'user';
      Array.prototype.forEach.call(radios, function (r) {
        if (r.checked) mode = r.value;
      });

      if (!managerWrap) return;
      if (mode === 'manager') {
        managerWrap.style.display = '';
        loadManagerBlock();
      } else {
        managerWrap.style.display = 'none';
      }
    }

    Array.prototype.forEach.call(radios, function (r) {
      r.addEventListener('change', toggleManager);
    });

    toggleManager();
  }

  /**
   * calendar_init_mini()
   * @returns {void}
   */
  function calendar_init_mini() {
    var wrap = document.querySelector('[data-calendar-mini="1"]');
    if (!wrap) return;

    var grid = wrap.querySelector('[data-cal-grid]');
    var title = wrap.querySelector('[data-cal-title]');
    var baseUrl = (wrap.getAttribute('data-calendar-base') || '').toString();
    var selectedStr = (wrap.getAttribute('data-calendar-date') || '').toString();
    var todayStr = (wrap.getAttribute('data-calendar-today') || '').toString();

    function parseDate(value) {
      var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');
      if (!m) return null;
      return new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
    }

    function formatDate(d) {
      if (!d) return '';
      var y = d.getFullYear();
      var m = String(d.getMonth() + 1).padStart(2, '0');
      var day = String(d.getDate()).padStart(2, '0');
      return y + '-' + m + '-' + day;
    }

    var selectedDate = parseDate(selectedStr) || parseDate(todayStr) || new Date();
    var todayDate = parseDate(todayStr) || new Date();
    var viewDate = new Date(selectedDate.getFullYear(), selectedDate.getMonth(), 1);

    try {
      var u = new URL(window.location.href);
      if (u.searchParams.has('date')) {
        u.searchParams.delete('date');
        window.history.replaceState({}, '', u.toString());
      }
    } catch (e) {}

    function render() {
      if (!grid || !title) return;

      var monthLabel = viewDate.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' });
      title.textContent = monthLabel;

      var first = new Date(viewDate.getFullYear(), viewDate.getMonth(), 1);
      var startDay = (first.getDay() + 6) % 7;
      var daysInMonth = new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, 0).getDate();
      var prevDays = new Date(viewDate.getFullYear(), viewDate.getMonth(), 0).getDate();

      grid.innerHTML = '';
      for (var i = 0; i < 42; i++) {
        var dayNum = i - startDay + 1;
        var cellDate;
        var muted = false;

        if (dayNum < 1) {
          cellDate = new Date(viewDate.getFullYear(), viewDate.getMonth() - 1, prevDays + dayNum);
          muted = true;
        } else if (dayNum > daysInMonth) {
          cellDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + 1, dayNum - daysInMonth);
          muted = true;
        } else {
          cellDate = new Date(viewDate.getFullYear(), viewDate.getMonth(), dayNum);
        }

        var cellStr = formatDate(cellDate);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'calendar-mini__day';
        btn.textContent = String(cellDate.getDate());
        btn.setAttribute('data-cal-date', cellStr);

        if (muted) btn.classList.add('is-muted');
        if (cellStr === formatDate(todayDate)) btn.classList.add('is-today');
        if (cellStr === formatDate(selectedDate)) btn.classList.add('is-selected');

        grid.appendChild(btn);
      }
    }

    wrap.addEventListener('click', function (e) {
      var t = e.target;
      if (!(t instanceof HTMLElement)) return;

      var nav = t.closest('[data-cal-nav]');
      if (nav) {
        e.preventDefault();
        var dir = nav.getAttribute('data-cal-nav');
        viewDate = new Date(viewDate.getFullYear(), viewDate.getMonth() + (dir === 'prev' ? -1 : 1), 1);
        render();
        return;
      }

      var reset = t.closest('[data-cal-reset]');
      if (reset) {
        e.preventDefault();
        if (baseUrl) window.location.href = baseUrl;
        return;
      }

      var cell = t.closest('[data-cal-date]');
      if (!cell) return;
      e.preventDefault();

      var dateStr = cell.getAttribute('data-cal-date') || '';
      if (!dateStr || !baseUrl) return;
      var join = baseUrl.indexOf('?') >= 0 ? '&' : '?';
      window.location.href = baseUrl + join + 'date=' + encodeURIComponent(dateStr);
    });

    render();
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    var btn = t.closest('[data-calendar-open-modal="1"]');
    if (btn) {
      e.preventDefault();
      var murl = btn.getAttribute('data-calendar-modal') || '';
      calendar_open_modal(murl);
      return;
    }

    if (t.closest('[data-calendar-client-link="1"]')) {
      return;
    }

    var card = t.closest('[data-calendar-request-id]');
    if (!card) return;

    e.preventDefault();

    var url = card.getAttribute('data-calendar-modal') || '';
    calendar_open_modal(url);
  });

  calendar_init_mini();
})();
