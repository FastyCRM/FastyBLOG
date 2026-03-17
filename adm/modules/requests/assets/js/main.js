/**
 * FILE: /adm/modules/requests/assets/js/main.js
 * ROLE: UI requests (modal handling)
 * CONNECTIONS:
 *  - window.App.modal.open(title, html)
 *  - do=modal_* returns json_ok({title, html})
 */

(function () {
  'use strict';

  /**
   * $doc - document
   */
  var $doc = document;

  /**
   * requests_open_modal()
   * @param {string} url
   * @returns {void}
   */
  function requests_open_modal(url) {
    if (!url) return;

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true || !payload.data) return;

        var title = (payload.data.title || "\u041c\u043e\u0434\u0430\u043b\u043a\u0430").toString();
        var html = (payload.data.html || '').toString();

        if (window.App && window.App.modal && typeof window.App.modal.open === 'function') {
          window.App.modal.open(title, html);
          setTimeout(function () {
            requests_init_modal();
          }, 0);
        }
      })
      .catch(function () {});
  }

  /**
   * requests_parse_specmap()
   * @param {HTMLElement} block
   * @returns {Object}
   */
  function requests_parse_specmap(block) {
    var raw = block.getAttribute('data-requests-specmap') || '{}';
    try {
      return JSON.parse(raw);
    } catch (e) {
      return {};
    }
  }

  /**
   * requests_select_items()
   * @param {HTMLSelectElement} sel
   * @returns {Array}
   */
  function requests_select_items(sel) {
    var out = [];
    if (!sel) return out;
    Array.prototype.forEach.call(sel.options, function (opt) {
      if (!opt || !opt.value) return;
      out.push({
        id: String(opt.value || ''),
        name: String(opt.textContent || '')
      });
    });
    return out;
  }

  /**
   * requests_position_search_suggest()
   * Calculates open direction for req-search suggest inside modal viewport.
   *
   * @param {HTMLElement|null} suggest
   * @returns {void}
   */
  function requests_position_search_suggest(suggest) {
    if (!suggest) return;
    suggest.classList.remove('is-up');

    var host = suggest.closest('.req-search');
    if (!host) return;
    if (!suggest.classList.contains('is-open')) return;

    var rect = host.getBoundingClientRect();
    var listH = Math.min(suggest.scrollHeight || 0, 200) + 8;
    var scope = host.closest('.modal__body');
    var spaceBelow = window.innerHeight - rect.bottom;
    var spaceAbove = rect.top;

    if (scope) {
      var scopeRect = scope.getBoundingClientRect();
      spaceBelow = scopeRect.bottom - rect.bottom;
      spaceAbove = rect.top - scopeRect.top;
    }

    var openUp = (spaceBelow < listH && spaceAbove > spaceBelow);
    suggest.classList.toggle('is-up', openUp);
  }

  /**
   * requests_build_suggest()
   * @param {Array} items
   * @param {string} query
   * @param {HTMLElement} suggest
   * @param {boolean} allowEmpty
   * @returns {void}
   */
  function requests_build_suggest(items, query, suggest, allowEmpty) {
    if (!suggest) return;
    var q = (query || '').toLowerCase().trim();
    suggest.innerHTML = '';

    var list = items || [];
    if (q) {
      list = list.filter(function (it) {
        return String(it.name || '').toLowerCase().indexOf(q) !== -1;
      });
    } else if (!allowEmpty) {
      list = [];
    }

    list = list.slice(0, 8);
    if (!list.length) {
      suggest.classList.remove('is-open');
      suggest.classList.remove('is-up');
      return;
    }

    list.forEach(function (it) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = String(it.name || '');
      btn.setAttribute('data-value', String(it.id || ''));
      suggest.appendChild(btn);
    });

    suggest.classList.add('is-open');
    requests_position_search_suggest(suggest);
  }

  /**
   * requests_apply_select()
   * @param {HTMLSelectElement} sel
   * @param {string} value
   * @returns {void}
   */
  function requests_apply_select(sel, value) {
    if (!sel) return;
    sel.value = value || '';
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  }

  /**
   * requests_build_api_url()
   * @param {string} base
   * @param {Object} params
   * @returns {string}
   */
  function requests_build_api_url(base, params) {
    if (!base) return '';
    var sep = base.indexOf('?') === -1 ? '?' : '&';
    var out = [];
    Object.keys(params || {}).forEach(function (key) {
      if (params[key] === undefined || params[key] === null || params[key] === '') return;
      out.push(encodeURIComponent(key) + '=' + encodeURIComponent(String(params[key])));
    });
    return base + (out.length ? (sep + out.join('&')) : '');
  }

  /**
   * requests_duration_from_service_select()
   * @param {HTMLSelectElement|null} serviceSelect
   * @returns {number}
   */
  function requests_duration_from_service_select(serviceSelect) {
    if (!serviceSelect || !serviceSelect.value) return 0;
    var opt = serviceSelect.options[serviceSelect.selectedIndex];
    if (!opt) return 0;
    var durAttr = opt.getAttribute('data-duration');
    var durNum = parseInt(durAttr || '0', 10);
    return (durNum > 0) ? durNum : 0;
  }

  /**
   * requests_duration_from_invoice()
   * Считает суммарную длительность по позициям счёта.
   *
   * @param {HTMLElement|null} form
   * @returns {number}
   */
  function requests_duration_from_invoice(form) {
    if (!form) return 0;

    var invoice = form.querySelector('[data-requests-invoice="1"]');
    if (!invoice) return 0;

    var raw = invoice.getAttribute('data-requests-services') || '[]';
    var services = [];
    try {
      services = JSON.parse(raw);
    } catch (e) {
      services = [];
    }

    var durMap = {};
    (services || []).forEach(function (srv) {
      var sid = parseInt(String(srv && srv.id !== undefined ? srv.id : '0'), 10);
      var dur = parseInt(String(srv && srv.duration_min !== undefined ? srv.duration_min : '0'), 10);
      if (sid > 0) {
        durMap[String(sid)] = (dur > 0 ? dur : 30);
      }
    });

    var total = 0;
    var rows = invoice.querySelectorAll('[data-requests-item-row="1"]');
    rows.forEach(function (row) {
      var sidInput = row.querySelector('input[name="item_service_id[]"]');
      var qtyInput = row.querySelector('[data-requests-item-qty="1"]');

      var sid = sidInput ? String(sidInput.value || '').trim() : '';
      if (!sid) return;

      var qty = qtyInput ? parseInt(qtyInput.value || '1', 10) : 1;
      if (!qty || qty < 1) qty = 1;

      var dur = parseInt(String(durMap[sid] || 30), 10);
      if (!dur || dur < 1) dur = 30;

      total += dur * qty;
    });

    return total > 0 ? total : 0;
  }

  /**
   * requests_duration_for_slots()
   * Возвращает длительность для запроса слотов:
   * 1) сумма по позициям счета (если есть),
   * 2) длительность выбранной услуги,
   * 3) дефолт 30.
   *
   * @param {HTMLElement|null} form
   * @param {HTMLSelectElement|null} serviceSelect
   * @returns {number}
   */
  function requests_duration_for_slots(form, serviceSelect) {
    var fromInvoice = requests_duration_from_invoice(form);
    if (fromInvoice > 0) return fromInvoice;

    var fromService = requests_duration_from_service_select(serviceSelect || null);
    if (fromService > 0) return fromService;

    return 30;
  }

  /**
   * requests_refresh_slots_for_form()
   * Обновляет варианты времени во всех slot-блоках формы.
   *
   * @param {HTMLElement|null} form
   * @returns {void}
   */
  function requests_refresh_slots_for_form(form) {
    if (!form) return;
    form.querySelectorAll('[data-requests-use-slots]').forEach(function (slotBlock) {
      requests_load_slots(slotBlock);
    });
  }

  /**
   * requests_client_full_name()
   * @param {Object} item
   * @returns {string}
   */
  function requests_client_full_name(item) {
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

  /**
   * requests_init_client_picker()
   * Поиск существующего клиента + режим "Новый клиент" в форме внутренней заявки.
   *
   * @returns {void}
   */
  function requests_init_client_picker() {
    var pickers = document.querySelectorAll('[data-requests-client-picker="1"]');
    pickers.forEach(function (picker) {
      if (picker.getAttribute('data-requests-client-init') === '1') return;
      picker.setAttribute('data-requests-client-init', '1');

      var form = picker.closest('form');
      if (!form) return;

      var api = picker.getAttribute('data-requests-clients-api') || '';
      var idInput = picker.querySelector('[data-requests-client-id="1"]');
      var queryInput = picker.querySelector('[data-requests-client-query="1"]');
      var suggest = picker.querySelector('[data-requests-client-suggest="1"]');
      var toggleNewBtn = picker.querySelector('[data-requests-client-toggle-new="1"]');
      var clearBtn = picker.querySelector('[data-requests-client-clear="1"]');
      var selectedEl = picker.querySelector('[data-requests-client-selected="1"]');

      var newWrap = form.querySelector('[data-requests-client-new-wrap="1"]');
      var nameInput = form.querySelector('[data-requests-client-name="1"]');
      var phoneInput = form.querySelector('[data-requests-client-phone="1"]');
      var emailInput = form.querySelector('[data-requests-client-email="1"]');

      if (!idInput || !queryInput || !suggest || !toggleNewBtn || !clearBtn || !selectedEl || !newWrap || !nameInput || !phoneInput || !emailInput) {
        return;
      }

      var mode = 'idle'; // idle | existing | new
      var timer = 0;
      var reqSeq = 0;
      var itemsById = {};

      function setManualRequired(on) {
        nameInput.required = !!on;
        phoneInput.required = !!on;
      }

      function clearSuggest() {
        suggest.innerHTML = '';
        suggest.classList.remove('is-open');
        suggest.classList.remove('is-up');
      }

      function updateSelectedInfo(item) {
        if (!item) {
          selectedEl.textContent = '';
          selectedEl.classList.add('is-hidden');
          return;
        }

        var parts = [];
        var fullName = requests_client_full_name(item);
        if (fullName !== '') parts.push(fullName);
        if (item.phone) parts.push(String(item.phone));
        if (item.inn) parts.push('ИНН ' + String(item.inn));
        selectedEl.textContent = parts.join(' | ');
        selectedEl.classList.remove('is-hidden');
      }

      function setMode(nextMode) {
        mode = nextMode;

        if (mode === 'new') {
          idInput.value = '';
          newWrap.classList.remove('is-hidden');
          clearBtn.classList.add('is-hidden');
          updateSelectedInfo(null);
          setManualRequired(true);
          toggleNewBtn.textContent = 'Отмена нового клиента';
          clearSuggest();
          return;
        }

        toggleNewBtn.textContent = 'Новый клиент';
        setManualRequired(false);
        newWrap.classList.add('is-hidden');
        clearSuggest();

        if (mode === 'existing') {
          clearBtn.classList.remove('is-hidden');
        } else {
          clearBtn.classList.add('is-hidden');
          updateSelectedInfo(null);
        }
      }

      function applyExisting(item) {
        if (!item || !item.id) return;

        idInput.value = String(item.id);
        nameInput.value = requests_client_full_name(item);
        phoneInput.value = String(item.phone || '');
        emailInput.value = String(item.email || '');

        var queryName = requests_client_full_name(item);
        queryInput.value = queryName !== '' ? queryName : String(item.label || '');
        updateSelectedInfo(item);
        setMode('existing');
      }

      function renderFound(items) {
        suggest.innerHTML = '';
        itemsById = {};
        if (!items || !items.length) {
          suggest.classList.remove('is-open');
          suggest.classList.remove('is-up');
          return;
        }

        items.slice(0, 10).forEach(function (item) {
          var id = String(item && item.id ? item.id : '');
          if (!id) return;
          itemsById[id] = item;

          var btn = document.createElement('button');
          btn.type = 'button';
          btn.setAttribute('data-requests-client-item-id', id);
          btn.textContent = String(item.label || requests_client_full_name(item) || ('Клиент #' + id));
          suggest.appendChild(btn);
        });

        var hasItems = suggest.children.length > 0;
        suggest.classList.toggle('is-open', hasItems);
        if (!hasItems) {
          suggest.classList.remove('is-up');
        } else {
          requests_position_search_suggest(suggest);
        }
      }

      function runSearch() {
        if (mode === 'new') return;
        var q = String(queryInput.value || '').trim();
        if (api === '') {
          clearSuggest();
          return;
        }
        if (!q) {
          clearSuggest();
          return;
        }

        var digits = q.replace(/\D+/g, '');
        var compact = q.replace(/[\s()+\-\.]/g, '');
        var isNumericQuery = compact !== '' && /^[0-9]+$/.test(compact);

        // Numeric query: INN from 4 digits, phone from 5 digits.
        if (isNumericQuery && digits.length < 4) {
          clearSuggest();
          return;
        }
        // Text query: start search from 2 chars to avoid noisy empty matches.
        if (!isNumericQuery && q.length < 2) {
          clearSuggest();
          return;
        }

        reqSeq += 1;
        var localReq = reqSeq;
        var searchQuery = { limit: 10, q: q };
        var url = requests_build_api_url(api, searchQuery);
        if (!url) {
          clearSuggest();
          return;
        }

        fetch(url, { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (payload) {
            if (localReq !== reqSeq) return;
            var items = (payload && payload.ok === true && payload.data && Array.isArray(payload.data.items))
              ? payload.data.items
              : [];
            renderFound(items);
          })
          .catch(function () {
            if (localReq !== reqSeq) return;
            clearSuggest();
          });
      }

      queryInput.addEventListener('input', function () {
        if (mode === 'existing') {
          idInput.value = '';
          updateSelectedInfo(null);
          setMode('idle');
        }

        if (timer) {
          clearTimeout(timer);
        }
        timer = window.setTimeout(runSearch, 220);
      });

      suggest.addEventListener('click', function (e) {
        var t = e.target;
        if (!(t instanceof HTMLElement)) return;
        var btn = t.closest('[data-requests-client-item-id]');
        if (!btn) return;
        var id = btn.getAttribute('data-requests-client-item-id') || '';
        if (!id || !itemsById[id]) return;
        applyExisting(itemsById[id]);
      });

      toggleNewBtn.addEventListener('click', function () {
        if (mode === 'new') {
          setMode('idle');
          return;
        }

        queryInput.value = '';
        idInput.value = '';
        nameInput.value = '';
        phoneInput.value = '';
        emailInput.value = '';
        setMode('new');
      });

      clearBtn.addEventListener('click', function () {
        idInput.value = '';
        queryInput.value = '';
        nameInput.value = '';
        phoneInput.value = '';
        emailInput.value = '';
        setMode('idle');
      });

      if (form.getAttribute('data-requests-client-submit-init') !== '1') {
        form.setAttribute('data-requests-client-submit-init', '1');
        form.addEventListener('submit', function (e) {
          var hasClientId = String(idInput.value || '').trim() !== '';
          var isNewMode = (mode === 'new');
          if (hasClientId || isNewMode) return;

          e.preventDefault();
          alert('Выберите клиента из поиска или нажмите "Новый клиент".');
          queryInput.focus();
        });
      }

      setMode('idle');
    });
  }

  /**
   * requests_slot_note_set()
   * @param {HTMLElement} block
   * @param {string} text
   * @param {boolean} warn
   * @returns {void}
   */
  function requests_slot_note_set(block, text, warn) {
    if (!block) return;
    var note = block.querySelector('[data-requests-slot-note="1"]');
    if (!note) return;

    var value = String(text || '').trim();
    if (!value) {
      note.textContent = '';
      note.classList.add('is-hidden');
      note.classList.remove('req-slot-note--warn');
      return;
    }

    note.textContent = value;
    note.classList.remove('is-hidden');
    note.classList.toggle('req-slot-note--warn', !!warn);
  }

  /**
   * requests_slot_time_visibility()
   * @param {HTMLElement} block
   * @param {boolean} visible
   * @returns {void}
   */
  function requests_slot_time_visibility(block, visible) {
    if (!block) return;
    var show = !!visible;
    var timeWrap = block.querySelector('[data-requests-slot-time-wrap="1"]');
    var timeSelect = block.querySelector('[data-requests-slot-select="1"]');

    if (timeWrap) {
      timeWrap.classList.toggle('is-hidden', !show);
    } else if (timeSelect) {
      timeSelect.classList.toggle('is-hidden', !show);
    }

    if (timeSelect) {
      var uiWrap = timeSelect.nextElementSibling;
      if (uiWrap && uiWrap.classList && uiWrap.classList.contains('ui-select')) {
        uiWrap.classList.toggle('is-hidden', !show);
      }
    }
  }

  /**
   * requests_slot_select_rebuild()
   * Rebuild custom select and keep visibility state for its wrapper.
   *
   * @param {HTMLElement} block
   * @param {HTMLSelectElement} timeSelect
   * @param {boolean} visible
   * @returns {void}
   */
  function requests_slot_select_rebuild(block, timeSelect, visible) {
    if (!timeSelect) return;
    if (timeSelect.getAttribute('data-ui-select') === '1'
      && window.App && window.App.ui && typeof window.App.ui.selectRebuild === 'function') {
      window.App.ui.selectRebuild(timeSelect);
    }
    requests_slot_time_visibility(block, visible);
  }

  /**
   * requests_slot_submit_state()
   * Блокирует submit в форме, когда на выбранную дату запись недоступна.
   *
   * @param {HTMLElement|null} form
   * @param {boolean} blocked
   * @returns {void}
   */
  function requests_slot_submit_state(form, blocked) {
    if (!form) return;
    var lock = !!blocked;
    var current = form.getAttribute('data-requests-slot-blocked') === '1';
    if (current === lock) return;

    form.setAttribute('data-requests-slot-blocked', lock ? '1' : '0');
    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (btn) {
      if (!(btn instanceof HTMLElement)) return;
      if (lock) {
        if (!btn.hasAttribute('data-requests-slot-prev-disabled')) {
          btn.setAttribute('data-requests-slot-prev-disabled', btn.disabled ? '1' : '0');
        }
        btn.disabled = true;
        return;
      }

      var prev = btn.getAttribute('data-requests-slot-prev-disabled');
      if (prev !== null) {
        btn.disabled = (prev === '1');
        btn.removeAttribute('data-requests-slot-prev-disabled');
      }
    });
  }

  /**
   * requests_load_slots()
   * @param {HTMLElement} block
   * @returns {void}
   */
  function requests_load_slots(block) {
    if (!block) return;
    var useSlots = block.getAttribute('data-requests-use-slots') === '1';
    if (!useSlots) return;

    var api = block.getAttribute('data-requests-slots-api') || '';
    var dateInput = block.querySelector('[data-requests-slot-date="1"]');
    var timeSelect = block.querySelector('[data-requests-slot-select="1"]');
    if (!api || !dateInput || !timeSelect) return;

    var form = block.closest('form') || document;
    var specSelect = form.querySelector('[data-requests-specialist-select="1"]');
    var serviceSelect = form.querySelector('[data-requests-service-select="1"]');

    var dateVal = (dateInput.value || '').trim();
    var specVal = specSelect ? (specSelect.value || '').trim() : '';

    var current = timeSelect.value || '';
    var currentAttr = timeSelect.getAttribute('data-requests-slot-current') || '';
    if (!current && currentAttr) current = currentAttr;
    var placeholder = timeSelect.getAttribute('data-placeholder') || "\u0412\u0440\u0435\u043c\u044f";
    timeSelect.innerHTML = "<option value=\"\">" + placeholder + "</option>";
    requests_slot_note_set(block, '', false);
    requests_slot_submit_state(form, false);

    if (!specVal) {
      timeSelect.disabled = true;
      requests_slot_select_rebuild(block, timeSelect, false);
      return;
    }

    if (!dateVal) {
      timeSelect.disabled = true;
      requests_slot_select_rebuild(block, timeSelect, true);
      return;
    }

    requests_slot_time_visibility(block, true);
    timeSelect.disabled = true;
    timeSelect.innerHTML = "<option value=\"\">\u0417\u0430\u0433\u0440\u0443\u0437\u043a\u0430...</option>";
    requests_slot_select_rebuild(block, timeSelect, true);

    var durationVal = requests_duration_for_slots(form, serviceSelect);
    var requestId = 0;
    var requestIdInput = form.querySelector('input[name="id"]');
    if (requestIdInput) {
      requestId = parseInt(requestIdInput.value || '0', 10) || 0;
    }

    var query = {
      specialist_id: specVal,
      date: dateVal,
      duration_min: durationVal
    };
    if (requestId > 0) {
      query.exclude_request_id = requestId;
    }

    var url = requests_build_api_url(api, query);
    if (!url) return;

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true || !payload.data) {
          requests_slot_note_set(block, "\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u0441\u043b\u043e\u0442\u044b", true);
          requests_slot_submit_state(form, true);
          return;
        }

        var data = payload.data || {};
        var slots = Array.isArray(data.slots) ? data.slots : [];
        var reason = String(data.reason || '');
        var message = String(data.message || '').trim();
        var hasSchedule = (data.has_schedule !== false);
        var scheduleBlocked = (reason === 'day_off' || reason === 'no_schedule' || reason === 'past_date' || !hasSchedule);

        timeSelect.innerHTML = "<option value=\"\">" + placeholder + "</option>";

        if (!scheduleBlocked && current && slots.indexOf(current) === -1) {
          slots.unshift(current);
        }

        var timeVisible = true;
        if (!slots.length) {
          var empty = document.createElement('option');
          empty.value = '';
          if (reason === 'day_off') {
            empty.textContent = "\u0412\u044b\u0445\u043e\u0434\u043d\u043e\u0439";
          } else if (reason === 'past_date') {
            empty.textContent = "\u041f\u0440\u043e\u0448\u0435\u0434\u0448\u0430\u044f \u0434\u0430\u0442\u0430";
          } else if (!hasSchedule || reason === 'no_schedule') {
            empty.textContent = "\u041d\u0435\u0442 \u0440\u0430\u0441\u043f\u0438\u0441\u0430\u043d\u0438\u044f";
          } else {
            empty.textContent = "\u041d\u0435\u0442 \u0441\u043b\u043e\u0442\u043e\u0432";
          }
          timeSelect.appendChild(empty);
          timeSelect.disabled = true;

          if (reason === 'day_off') {
            timeVisible = false;
            requests_slot_note_set(block, message || "\u0423 \u0441\u043f\u0435\u0446\u0438\u0430\u043b\u0438\u0441\u0442\u0430 \u0432\u044b\u0445\u043e\u0434\u043d\u043e\u0439 \u0432 \u0432\u044b\u0431\u0440\u0430\u043d\u043d\u0443\u044e \u0434\u0430\u0442\u0443", true);
          } else if (reason === 'past_date') {
            timeVisible = false;
            requests_slot_note_set(block, message || "\u0417\u0430\u043f\u0438\u0441\u044c \u043d\u0430 \u043f\u0440\u043e\u0448\u0435\u0434\u0448\u0443\u044e \u0434\u0430\u0442\u0443 \u043d\u0435\u0434\u043e\u0441\u0442\u0443\u043f\u043d\u0430", true);
          } else if (!hasSchedule || reason === 'no_schedule') {
            timeVisible = false;
            requests_slot_note_set(block, message || "\u041d\u0435\u0442 \u0440\u0430\u0431\u043e\u0447\u0435\u0433\u043e \u0440\u0430\u0441\u043f\u0438\u0441\u0430\u043d\u0438\u044f \u043d\u0430 \u0432\u044b\u0431\u0440\u0430\u043d\u043d\u0443\u044e \u0434\u0430\u0442\u0443", true);
          } else {
            timeVisible = true;
            requests_slot_note_set(block, message || "\u041d\u0435\u0442 \u0441\u0432\u043e\u0431\u043e\u0434\u043d\u044b\u0445 \u0441\u043b\u043e\u0442\u043e\u0432 \u043d\u0430 \u0432\u044b\u0431\u0440\u0430\u043d\u043d\u0443\u044e \u0434\u0430\u0442\u0443", false);
          }
          requests_slot_submit_state(form, true);
        } else {
          slots.forEach(function (slot) {
            var opt = document.createElement('option');
            opt.value = String(slot);
            opt.textContent = String(slot);
            timeSelect.appendChild(opt);
          });
          timeSelect.disabled = false;
          if (current && slots.indexOf(current) !== -1) {
            timeSelect.value = current;
          }
          if (currentAttr) {
            timeSelect.setAttribute('data-requests-slot-current', '');
          }
          timeVisible = true;
          requests_slot_note_set(block, '', false);
          requests_slot_submit_state(form, false);
        }

        requests_slot_select_rebuild(block, timeSelect, timeVisible);
      })
      .catch(function () {
        timeSelect.disabled = true;
        timeSelect.innerHTML = "<option value=\"\">" + placeholder + "</option>";
        requests_slot_note_set(block, "\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u0441\u043b\u043e\u0442\u044b, \u043f\u043e\u043f\u0440\u043e\u0431\u0443\u0439\u0442\u0435 \u0435\u0449\u0451 \u0440\u0430\u0437", true);
        requests_slot_submit_state(form, true);
        requests_slot_select_rebuild(block, timeSelect, true);
      });
  }

  /**
   * requests_fill_specialists()
   * @param {HTMLElement} block
   * @param {string} serviceId
   * @returns {void}
   */
  function requests_fill_specialists(block, serviceId) {
    var sel = block.querySelector('[data-requests-specialist-select="1"]');
    if (!sel) return;

    sel.innerHTML = "<option value=\"\">\u0421\u043f\u0435\u0446\u0438\u0430\u043b\u0438\u0441\u0442</option>";
    if (!serviceId) {
      if (sel && sel.getAttribute('data-ui-select') === '1'
        && window.App && window.App.ui && typeof window.App.ui.selectRebuild === 'function') {
        window.App.ui.selectRebuild(sel);
      }
      var formEmpty = block.closest('form');
      var slotBlockEmpty = formEmpty ? formEmpty.querySelector('[data-requests-use-slots]') : null;
      if (slotBlockEmpty) {
        requests_load_slots(slotBlockEmpty);
      }
      return;
    }

    var map = requests_parse_specmap(block);
    var list = map[serviceId] || map[String(serviceId)] || [];
    if (!Array.isArray(list)) return;

    list.forEach(function (item) {
      var opt = document.createElement('option');
      opt.value = String(item.id || '');
      opt.textContent = String(item.name || '');
      sel.appendChild(opt);
    });
    var currentSpec = block.getAttribute('data-requests-specialist-current') || '';
    if (currentSpec) {
      sel.value = String(currentSpec);
    }
    if (sel && sel.getAttribute('data-ui-select') === '1'
      && window.App && window.App.ui && typeof window.App.ui.selectRebuild === 'function') {
      window.App.ui.selectRebuild(sel);
    }

    var specInput = block.querySelector('[data-requests-specialist-search="1"]');
    var specSuggest = block.querySelector('[data-requests-specialist-suggest="1"]');
    if (specInput) {
      if (currentSpec && sel.value) {
        var curOpt = sel.options[sel.selectedIndex];
        if (curOpt) specInput.value = curOpt.textContent || '';
      } else {
        specInput.value = '';
      }
    }
    if (specSuggest) {
      specSuggest.innerHTML = '';
      specSuggest.classList.remove('is-open');
    }

    var form = block.closest('form');
    var slotBlock = form ? form.querySelector('[data-requests-use-slots]') : null;
    if (slotBlock) {
      requests_load_slots(slotBlock);
    }
  }

  /**
  /**
   * requests_init_modal()
   * Init modal UI (service -> specialist -> date/time)
   * @returns {void}
   */
  function requests_init_modal() {
    var blocks = document.querySelectorAll('[data-requests-specmap]');
    blocks.forEach(function (block) {
      if (block.getAttribute('data-requests-init') === '1') return;

      var serviceSelect = block.querySelector('[data-requests-service-select="1"]');
      var serviceInput = block.querySelector('[data-requests-service-search="1"]');
      var serviceSuggest = block.querySelector('[data-requests-service-suggest="1"]');
      var specSelect = block.querySelector('[data-requests-specialist-select="1"]');
      var specInput = block.querySelector('[data-requests-specialist-search="1"]');
      var specSuggest = block.querySelector('[data-requests-specialist-suggest="1"]');
      if (!serviceSelect) return;

      block.setAttribute('data-requests-init', '1');

      function updateSteps() {
        var specWrap = block.querySelector('[data-requests-specialist-wrap="1"]');
        var hasService = serviceSelect && serviceSelect.value;
        var hasSpec = specSelect && specSelect.value;
        if (specWrap) {
          specWrap.classList.toggle('is-hidden', !hasService);
        }
        var form = block.closest('form') || document;
        form.querySelectorAll('[data-requests-slot-wrap="1"]').forEach(function (el) {
          el.classList.toggle('is-hidden', !hasSpec);
        });
        if (!hasSpec) {
          form.querySelectorAll('[data-requests-use-slots]').forEach(function (slotBlock) {
            requests_slot_note_set(slotBlock, '', false);
            requests_slot_time_visibility(slotBlock, false);
          });
          requests_slot_submit_state(form, false);
        }
      }

      requests_fill_specialists(block, serviceSelect.value || '');
      updateSteps();

      serviceSelect.addEventListener('change', function () {
        requests_fill_specialists(block, serviceSelect.value || '');
        updateSteps();
      });
      if (specSelect) {
        specSelect.addEventListener('change', function () {
          updateSteps();
        });
      }

      if (serviceInput && serviceSuggest) {
        var serviceItems = requests_select_items(serviceSelect);
        if (serviceSelect && serviceSelect.value) {
          var curOpt = serviceSelect.options[serviceSelect.selectedIndex];
          if (curOpt) serviceInput.value = curOpt.textContent || '';
        }
        serviceInput.addEventListener('input', function () {
          requests_build_suggest(serviceItems, serviceInput.value || '', serviceSuggest, false);
        });
        serviceInput.addEventListener('blur', function () {
          if (serviceSelect.value || !serviceInput.value) return;
          var val = serviceInput.value.trim().toLowerCase();
          if (!val) return;
          var picked = serviceItems.find(function (it) {
            return String(it.name || '').toLowerCase() === val;
          });
          if (picked) {
            serviceInput.value = picked.name || '';
            requests_apply_select(serviceSelect, String(picked.id));
          }
        });
        serviceSuggest.addEventListener('click', function (e) {
          var t = e.target;
          if (!(t instanceof HTMLElement)) return;
          var val = t.getAttribute('data-value');
          if (!val) return;
          var picked = serviceItems.find(function (it) { return String(it.id) === String(val); });
          if (picked) {
            serviceInput.value = picked.name || '';
          }
          serviceSuggest.classList.remove('is-open');
          requests_apply_select(serviceSelect, String(val));
          requests_fill_specialists(block, String(val));
          updateSteps();
        });
      }

      if (specSelect && specInput && specSuggest) {
        if (specSelect && specSelect.value) {
          var curSpec = specSelect.options[specSelect.selectedIndex];
          if (curSpec) specInput.value = curSpec.textContent || '';
        }
        function rebuildSpecSuggest(openOnEmpty) {
          var specItems = requests_select_items(specSelect);
          requests_build_suggest(specItems, specInput.value || '', specSuggest, !!openOnEmpty);
        }

        specInput.addEventListener('input', function () {
          rebuildSpecSuggest(false);
        });
        specInput.addEventListener('focus', function () {
          rebuildSpecSuggest(true);
        });
        specInput.addEventListener('blur', function () {
          if (specSelect.value || !specInput.value) return;
          var val = specInput.value.trim().toLowerCase();
          if (!val) return;
          var specItems = requests_select_items(specSelect);
          var picked = specItems.find(function (it) {
            return String(it.name || '').toLowerCase() === val;
          });
          if (picked) {
            specInput.value = picked.name || '';
            requests_apply_select(specSelect, String(picked.id));
          }
        });
        specSuggest.addEventListener('click', function (e) {
          var t = e.target;
          if (!(t instanceof HTMLElement)) return;
          var val = t.getAttribute('data-value');
          if (!val) return;
          var specItems = requests_select_items(specSelect);
          var picked = specItems.find(function (it) { return String(it.id) === String(val); });
          if (picked) {
            specInput.value = picked.name || '';
          }
          specSuggest.classList.remove('is-open');
          requests_apply_select(specSelect, String(val));
        });
      }
    });
    document.querySelectorAll('[data-requests-reset-chain="1"]').forEach(function (btn) {
      if (btn.getAttribute('data-requests-reset-init') === '1') return;
      btn.setAttribute('data-requests-reset-init', '1');
      btn.addEventListener('click', function () {
        var root = btn.closest('.card') || document;
        var block = root.querySelector('[data-requests-specmap]');
        if (!block) return;

        var serviceSelect = block.querySelector('[data-requests-service-select="1"]');
        var serviceInput = block.querySelector('[data-requests-service-search="1"]');
        var serviceSuggest = block.querySelector('[data-requests-service-suggest="1"]');
        var specSelect = block.querySelector('[data-requests-specialist-select="1"]');
        var specInput = block.querySelector('[data-requests-specialist-search="1"]');
        var specSuggest = block.querySelector('[data-requests-specialist-suggest="1"]');

        if (serviceSelect) serviceSelect.value = '';
        if (serviceInput) serviceInput.value = '';
        if (serviceSuggest) {
          serviceSuggest.innerHTML = '';
          serviceSuggest.classList.remove('is-open');
        }
        if (specSelect) specSelect.innerHTML = "<option value=\"\">\u0421\u043f\u0435\u0446\u0438\u0430\u043b\u0438\u0441\u0442</option>";
        if (specInput) specInput.value = '';
        if (specSuggest) {
          specSuggest.innerHTML = '';
          specSuggest.classList.remove('is-open');
        }
        block.setAttribute('data-requests-service-current', '');
        block.setAttribute('data-requests-specialist-current', '');

        var form = block.closest('form') || document;
        form.querySelectorAll('[data-requests-invoice="1"]').forEach(function (inv) {
          var list = inv.querySelector('[data-requests-invoice-items="1"]');
          var totalEl = inv.querySelector('[data-requests-total="1"]');
          var totalInput = inv.querySelector('[data-requests-total-input="1"]');
          var searchInput = inv.querySelector('[data-requests-service-search="1"]');
          var suggest = inv.querySelector('[data-requests-service-suggest="1"]');
          if (list) list.innerHTML = '';
          if (totalEl) totalEl.textContent = '0';
          if (totalInput) totalInput.value = '0';
          if (searchInput) searchInput.value = '';
          if (suggest) {
            suggest.innerHTML = '';
            suggest.classList.remove('is-open');
          }
        });
        form.querySelectorAll('[data-requests-slot-wrap="1"]').forEach(function (el) {
          el.classList.add('is-hidden');
          var dateInput = el.querySelector('[data-requests-slot-date="1"]');
          var timeSelect = el.querySelector('[data-requests-slot-select="1"]');
          requests_slot_note_set(el, '', false);
          requests_slot_time_visibility(el, false);
          if (dateInput) {
            var today = new Date();
            var y = today.getFullYear();
            var m = String(today.getMonth() + 1).padStart(2, '0');
            var d = String(today.getDate()).padStart(2, '0');
            dateInput.value = y + '-' + m + '-' + d;
          }
          if (timeSelect) {
            var resetPlaceholder = timeSelect.getAttribute('data-placeholder') || "\u0412\u0440\u0435\u043c\u044f";
            timeSelect.innerHTML = "<option value=\"\">" + resetPlaceholder + "</option>";
            timeSelect.disabled = true;
            timeSelect.setAttribute('data-requests-slot-current', '');
            if (timeSelect.getAttribute('data-ui-select') === '1'
              && window.App && window.App.ui && typeof window.App.ui.selectRebuild === 'function') {
              window.App.ui.selectRebuild(timeSelect);
            }
          }
        });
        requests_slot_submit_state(form, false);

        var specWrap = block.querySelector('[data-requests-specialist-wrap="1"]');
        if (specWrap) specWrap.classList.add('is-hidden');
        if (serviceSelect && serviceSelect.getAttribute('data-ui-select') === '1'
          && window.App && window.App.ui && typeof window.App.ui.selectRebuild === 'function') {
          window.App.ui.selectRebuild(serviceSelect);
        }
      });
    });
    if (window.App && window.App.ui && typeof window.App.ui.selectInit === 'function') {
      window.App.ui.selectInit(document.querySelector('.modal__body') || document);
    }
    requests_init_client_picker();
    requests_init_slots();
    requests_init_invoice();
  }

  /**
   * requests_init_slots()
   * @returns {void}
   */
  function requests_init_slots() {
    var blocks = document.querySelectorAll('[data-requests-use-slots]');
    blocks.forEach(function (block) {
      if (block.getAttribute('data-requests-slots-init') === '1') return;
      block.setAttribute('data-requests-slots-init', '1');

      var dateInput = block.querySelector('[data-requests-slot-date="1"]');
      var form = block.closest('form') || document;
      var specSelect = form.querySelector('[data-requests-specialist-select="1"]');

      if (dateInput) {
        dateInput.addEventListener('change', function () {
          requests_load_slots(block);
        });
      }
      if (specSelect) {
        specSelect.addEventListener('change', function () {
          requests_load_slots(block);
        });
      }

      requests_load_slots(block);
    });
  }

  /**
   * requests_init_invoice()
   * @returns {void}
   */
  function requests_init_invoice() {
    var blocks = document.querySelectorAll('[data-requests-invoice]');
    blocks.forEach(function (block) {
      if (block.getAttribute('data-requests-init') === '1') return;
      block.setAttribute('data-requests-init', '1');

      var raw = block.getAttribute('data-requests-services') || '[]';
      var services = [];
      try { services = JSON.parse(raw); } catch (e) { services = []; }

      var searchInput = block.querySelector('[data-requests-service-search="1"]');
      var suggest = block.querySelector('[data-requests-service-suggest="1"]');
      var list = block.querySelector('[data-requests-invoice-items="1"]');
      var totalEl = block.querySelector('[data-requests-total="1"]');
      var totalInput = block.querySelector('[data-requests-total-input="1"]');
      var addBtn = block.querySelector('[data-requests-add-service="1"]');

      if (!searchInput || !suggest || !list || !totalEl || !totalInput || !addBtn) return;

      function esc(text) {
        return String(text || '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/\"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function findServiceById(id) {
        for (var i = 0; i < services.length; i++) {
          if (String(services[i].id) === String(id)) return services[i];
        }
        return null;
      }

      function rebuildTotal() {
        var sum = 0;
        list.querySelectorAll('[data-requests-item-row="1"]').forEach(function (row) {
          var qty = parseInt(row.querySelector('[data-requests-item-qty="1"]').value || '0', 10);
          var price = parseInt(row.querySelector('[data-requests-item-price="1"]').value || '0', 10);
          if (qty < 0) qty = 0;
          if (price < 0) price = 0;
          var rowSum = qty * price;
          var sumEl = row.querySelector('[data-requests-item-sum="1"]');
          if (sumEl) sumEl.textContent = String(rowSum);
          sum += rowSum;
        });
        totalEl.textContent = String(sum);
        totalInput.value = String(sum);
      }

      function addItem(service) {
        if (!service) return;
        var isCustom = !!service.custom;
        var qtyVal = parseInt(service.qty || '1', 10);
        if (qtyVal <= 0 || isNaN(qtyVal)) qtyVal = 1;
        if (!isCustom) {
          var existing = list.querySelector('[data-requests-item-row="1"][data-service-id=\"' + service.id + '\"]');
          if (existing) {
            var qtyInput = existing.querySelector('[data-requests-item-qty="1"]');
            var priceInput = existing.querySelector('[data-requests-item-price="1"]');
            if (service.forceQty) {
              qtyInput.value = String(qtyVal);
            } else {
              qtyInput.value = String(parseInt(qtyInput.value || '1', 10) + qtyVal);
            }
            if (service.forcePrice && priceInput && service.price !== undefined) {
              priceInput.value = String(parseInt(service.price || '0', 10));
            }
            rebuildTotal();
            requests_refresh_slots_for_form(block.closest('form'));
            return;
          }
        }

        var price = parseInt(service.price || '0', 10);
        if (price < 0 || isNaN(price)) price = 0;
        var rowId = isCustom
          ? ('custom-' + Date.now() + '-' + Math.floor(Math.random() * 1000))
          : String(service.id || '');
        var serviceId = isCustom ? '' : String(service.id || '');
        var serviceName = String(service.name || '');

        var html = ''
          + '<div class=\"req-invoice__row\" data-requests-item-row=\"1\" data-service-id=\"' + esc(rowId) + '\">'
          +   '<div class=\"req-invoice__name\">' + esc(serviceName) + '</div>'
          +   '<input type=\"hidden\" name=\"item_service_id[]\" value=\"' + esc(serviceId) + '\">'
          +   '<input type=\"hidden\" name=\"item_name[]\" value=\"' + esc(serviceName) + '\">'
          +   '<input class=\"select\" type=\"number\" min=\"1\" step=\"1\" name=\"item_qty[]\" value=\"' + esc(qtyVal) + '\" data-requests-item-qty=\"1\">'
          +   '<input class=\"select\" type=\"number\" min=\"0\" step=\"1\" name=\"item_price[]\" value=\"' + esc(price) + '\" data-requests-item-price=\"1\">'
          +   '<div class=\"req-invoice__sum\" data-requests-item-sum=\"1\">' + esc(price) + '</div>'
          +   '<button class=\"iconbtn iconbtn--sm\" type=\"button\" data-requests-item-remove=\"1\"><i class=\"bi bi-x\"></i></button>'
          + '</div>';
        list.insertAdjacentHTML('beforeend', html);
        rebuildTotal();
        requests_refresh_slots_for_form(block.closest('form'));
      }

      function buildSuggest(q) {
        var val = (q || '').toLowerCase().trim();
        suggest.innerHTML = '';
        if (!val) {
          suggest.classList.remove('is-open');
          return [];
        }

        var items = services.filter(function (s) {
          return String(s.name || '').toLowerCase().indexOf(val) !== -1;
        }).slice(0, 8);

        if (!items.length) {
          suggest.classList.remove('is-open');
          return [];
        }

        items.forEach(function (s) {
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.textContent = String(s.name || '');
          btn.setAttribute('data-requests-suggest-id', String(s.id || ''));
          suggest.appendChild(btn);
        });

        suggest.classList.add('is-open');
        return items;
      }

      searchInput.addEventListener('input', function () {
        buildSuggest(searchInput.value || '');
      });

      suggest.addEventListener('click', function (e) {
        var t = e.target;
        if (!(t instanceof HTMLElement)) return;
        if (t.tagName !== 'BUTTON') return;
        var id = t.getAttribute('data-requests-suggest-id') || '';
        var service = findServiceById(id);
        if (service) {
          addItem(service);
          searchInput.value = '';
          suggest.classList.remove('is-open');
        }
      });

      addBtn.addEventListener('click', function () {
        var service = null;
        var rawName = (searchInput.value || '').trim();
        if (!rawName) return;
        var name = rawName.toLowerCase();
        service = services.find(function (s) {
          return String(s.name || '').toLowerCase() === name;
        }) || null;
        if (!service) {
          var items = buildSuggest(rawName);
          if (items && items[0]) service = items[0];
        }
        if (service) {
          addItem(service);
          searchInput.value = '';
          suggest.classList.remove('is-open');
        } else {
          addItem({ id: '', name: rawName, price: 0, custom: true });
          searchInput.value = '';
          suggest.classList.remove('is-open');
        }
      });

      var initialRaw = block.getAttribute('data-requests-initial-items') || '[]';
      var initialItems = [];
      try { initialItems = JSON.parse(initialRaw); } catch (e) { initialItems = []; }
      if (Array.isArray(initialItems) && initialItems.length) {
        initialItems.forEach(function (it) {
          addItem({
            id: it.service_id || '',
            name: it.service_name || it.name || '',
            price: it.price || 0,
            qty: it.qty || 1,
            custom: !it.service_id,
            forceQty: true,
            forcePrice: true
          });
        });
      }

      list.addEventListener('input', function (e) {
        var t = e.target;
        if (!(t instanceof HTMLElement)) return;
        if (t.matches('[data-requests-item-qty=\"1\"], [data-requests-item-price=\"1\"]')) {
          rebuildTotal();
          requests_refresh_slots_for_form(block.closest('form'));
        }
      });

      list.addEventListener('click', function (e) {
        var t = e.target;
        if (!(t instanceof HTMLElement)) return;
        var btn = t.closest('[data-requests-item-remove=\"1\"]');
        if (!btn) return;
        var row = btn.closest('[data-requests-item-row=\"1\"]');
        if (row) row.remove();
        rebuildTotal();
        requests_refresh_slots_for_form(block.closest('form'));
      });

      buildSuggest('');
      rebuildTotal();
    });
  }

  /**
  /**
   * Open modals by data-requests-open-modal="1"
   */
  $doc.addEventListener("click", function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    if (!t.closest(".req-search")) {
      document.querySelectorAll(".req-search__suggest.is-open").forEach(function (el) {
        el.classList.remove("is-open");
      });
    }

    if (t.closest("[data-requests-client-link=\"1\"]")) {
      return;
    }

    var $btn = t.closest("[data-requests-open-modal=\"1\"]");
    if (!$btn) return;

    e.preventDefault();

    var url = $btn.getAttribute("data-requests-modal") || "";
    requests_open_modal(url);
  });

  if (typeof window !== "undefined") {
    window.RequestsUI = window.RequestsUI || {};
    window.RequestsUI.initModal = requests_init_modal;
  }

})();
