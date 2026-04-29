/**
 * FILE: /adm/modules/stopbot/assets/js/main.js
 * ROLE: UI модуля stopbot — открытие модалок через App.modal
 */

(function () {
  'use strict';

  function stopbot_esc(v) {
    return String(v || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /**
   * $doc — документ
   */
  var $doc = document;

  /**
   * stopbot_open_modal()
   * Загружает JSON модалки и открывает глобальную модалку.
   *
   * @param {string} url — URL do=modal_*
   * @returns {void}
   */
  function stopbot_open_modal(url) {
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

  function stopbot_render_channel_probe(box, data, ctx) {
    if (!box) return;
    if (!data || typeof data !== 'object') {
      box.innerHTML = '<div class="muted">No data</div>';
      return;
    }

    var platform = String(data.platform || '');
    var me = data.me || {};
    var bot = me.bot || {};
    var chats = data.chats || {};
    var rows = Array.isArray(chats.items) ? chats.items : [];
    var attachUrl = String((ctx && ctx.attachUrl) || '');
    var csrf = String((ctx && ctx.csrf) || '');
    var botId = String((ctx && ctx.botId) || '');
    var addLabel = String((ctx && ctx.addLabel) || 'Add');
    var addedLabel = String((ctx && ctx.addedLabel) || 'already added');

    var html = '';
    html += '<div class="muted">checked: ' + stopbot_esc(data.checked_at || '') + '</div>';
    html += '<div class="muted">token: <strong>' + (Number(data.token_present || 0) === 1 ? 'present' : 'missing') + '</strong></div>';
    html += '<div class="muted">/me: <strong>' + (me.ok === true ? 'OK' : 'FAIL') + '</strong> (HTTP ' + stopbot_esc(me.http_code || 0) + ') ' + stopbot_esc(me.error || '') + '</div>';
    if (bot && (bot.id || bot.username || bot.name)) {
      html += '<div class="muted">bot: <code>' + stopbot_esc(bot.id || '') + '</code> ' + stopbot_esc(bot.name || '') + ' @' + stopbot_esc(bot.username || '') + '</div>';
    }

    if (platform === 'max') {
      html += '<div class="muted">/chats: <strong>' + (chats.ok === true ? 'OK' : 'FAIL') + '</strong> (HTTP ' + stopbot_esc(chats.http_code || 0) + ') ' + stopbot_esc(chats.error || '') + ', count=' + stopbot_esc(chats.count || 0) + '</div>';
    } else {
      html += '<div class="muted">known chats: <strong>' + stopbot_esc(chats.count || 0) + '</strong>, resolved=' + stopbot_esc(chats.resolved_count || 0) + ', errors=' + stopbot_esc(chats.errors_count || 0) + (chats.error ? (', ' + stopbot_esc(chats.error)) : '') + '</div>';
    }

    if (!rows.length) {
      html += '<div class="muted" style="margin-top:8px;">Nothing found</div>';
      box.innerHTML = html;
      return;
    }

    html += '<table class="table" style="margin-top:8px;">';
    html += '<thead><tr><th>chat_id</th><th>title</th><th>type</th><th>status</th><th></th></tr></thead><tbody>';
    rows.forEach(function (row) {
      var cid = String(row.chat_id || '');
      var title = String(row.title || '');
      var ctype = String(row.type || '');
      var status = String(row.status || '');
      var isBound = Number(row.is_bound || 0) === 1;
      var isActive = Number(row.is_active || 0) === 1;
      var rowStatus = isBound ? (isActive ? 'bound' : 'bound_off') : (status || 'known');

      html += '<tr>';
      html += '<td class="mono">' + stopbot_esc(cid) + '</td>';
      html += '<td>' + stopbot_esc(title) + '</td>';
      html += '<td>' + stopbot_esc(ctype) + '</td>';
      html += '<td>' + stopbot_esc(rowStatus) + (row.error ? (' <span class="muted">' + stopbot_esc(row.error) + '</span>') : '') + '</td>';
      if (isBound) {
        html += '<td class="t-right"><span class="muted">' + stopbot_esc(addedLabel) + '</span></td>';
      } else {
        html += '<td class="t-right"><button class="btn btn--xs" type="button"'
          + ' data-stopbot-channel-attach="1"'
          + ' data-stopbot-attach-url="' + stopbot_esc(attachUrl) + '"'
          + ' data-csrf="' + stopbot_esc(csrf) + '"'
          + ' data-bot-id="' + stopbot_esc(botId) + '"'
          + ' data-chat-id="' + stopbot_esc(cid) + '"'
          + ' data-chat-title="' + stopbot_esc(title) + '"'
          + ' data-chat-type="' + stopbot_esc(ctype) + '"'
          + '>' + stopbot_esc(addLabel) + '</button></td>';
      }
      html += '</tr>';
    });
    html += '</tbody></table>';

    box.innerHTML = html;
  }

  function stopbot_probe_channels(btn) {
    if (!btn) return;

    var probeUrl = (btn.getAttribute('data-stopbot-probe-url') || '').trim();
    var attachUrl = (btn.getAttribute('data-stopbot-attach-url') || '').trim();
    var csrf = (btn.getAttribute('data-csrf') || '').trim();
    var botId = (btn.getAttribute('data-bot-id') || '').trim();
    var addLabel = (btn.getAttribute('data-label-add') || '').trim();
    var addedLabel = (btn.getAttribute('data-label-added') || '').trim();
    var loadingLabel = (btn.getAttribute('data-label-loading') || '').trim();
    if (!probeUrl || !csrf || !botId) return;

    var card = btn.closest('.card');
    var box = card ? card.querySelector('[data-stopbot-channel-probe-result="1"]') : null;
    if (!box) return;

    btn.disabled = true;
    box.innerHTML = '<div class="muted">' + stopbot_esc(loadingLabel || 'Loading...') + '</div>';

    fetch(probeUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: 'csrf=' + encodeURIComponent(csrf) + '&bot_id=' + encodeURIComponent(botId)
    })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        btn.disabled = false;
        if (!payload || payload.ok !== true || !payload.data) {
          var err = (payload && (payload.msg || payload.message || payload.error)) ? String(payload.msg || payload.message || payload.error) : 'Request failed';
          box.innerHTML = '<div class="muted">' + stopbot_esc(err) + '</div>';
          return;
        }
        stopbot_render_channel_probe(box, payload.data, {
          attachUrl: attachUrl,
          csrf: csrf,
          botId: botId,
          addLabel: addLabel,
          addedLabel: addedLabel
        });
      })
      .catch(function (e) {
        btn.disabled = false;
        box.innerHTML = '<div class="muted">' + stopbot_esc((e && e.message) ? e.message : 'Request failed') + '</div>';
      });
  }

  function stopbot_attach_channel(btn) {
    if (!btn) return;

    var action = (btn.getAttribute('data-stopbot-attach-url') || '').trim();
    var csrf = (btn.getAttribute('data-csrf') || '').trim();
    var botId = (btn.getAttribute('data-bot-id') || '').trim();
    var chatId = (btn.getAttribute('data-chat-id') || '').trim();
    var chatTitle = (btn.getAttribute('data-chat-title') || '').trim();
    var chatType = (btn.getAttribute('data-chat-type') || '').trim();
    if (!action || !csrf || !botId || !chatId) return;

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = action;
    form.style.display = 'none';

    function append(name, value) {
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      input.value = String(value || '');
      form.appendChild(input);
    }

    append('csrf', csrf);
    append('bot_id', botId);
    append('chat_id', chatId);
    append('chat_title', chatTitle);
    append('chat_type', chatType);

    document.body.appendChild(form);
    form.submit();
  }

  /**
   * stopbot_filter_promos()
   * Локально фильтрует список промокодов по ключевым словам.
   *
   * @param {HTMLInputElement} $input
   * @returns {void}
   */
  function stopbot_filter_promos($input) {
    if (!$input) return;

    var target = ($input.getAttribute('data-stopbot-promo-target') || '').trim();
    if (!target) return;

    var $table = $doc.querySelector('[data-stopbot-promo-table="' + target + '"]');
    if (!$table) return;

    var query = ($input.value || '').toLocaleLowerCase().trim();
    var $rows = $table.querySelectorAll('[data-stopbot-promo-row="1"]');
    var $empty = $table.querySelector('[data-stopbot-promo-empty="1"]');
    var $tbody = $table.querySelector('tbody');
    var visible = 0;

    if (!$empty && $tbody) {
      $empty = document.createElement('tr');
      $empty.setAttribute('data-stopbot-promo-empty', '1');
      $empty.style.display = 'none';
      $empty.innerHTML = '<td colspan="3" class="muted"></td>';
      $tbody.appendChild($empty);
    }

    $rows.forEach(function ($row) {
      var haystack = ($row.getAttribute('data-stopbot-promo-search-text') || $row.textContent || '')
        .toLocaleLowerCase()
        .trim();
      var match = (query === '' || haystack.indexOf(query) !== -1);

      $row.style.display = match ? '' : 'none';
      if (match) visible += 1;
    });

    if ($empty) {
      var $emptyCell = $empty.querySelector('td');
      if ($emptyCell && !$emptyCell.textContent) {
        $emptyCell.textContent = $table.getAttribute('data-stopbot-promo-empty-text') || '';
      }
      $empty.style.display = visible === 0 ? '' : 'none';
    }
  }

  /**
   * stopbot_filter_logs()
   * Локально фильтрует лог модерации.
   *
   * @param {HTMLInputElement} $input
   * @returns {void}
   */
  function stopbot_filter_logs($input) {
    if (!$input) return;

    var target = ($input.getAttribute('data-stopbot-log-target') || '').trim();
    if (!target) return;

    var $table = $doc.querySelector('[data-stopbot-log-table="' + target + '"]');
    if (!$table) return;

    var query = ($input.value || '').toLocaleLowerCase().trim();
    var $rows = $table.querySelectorAll('[data-stopbot-log-row="1"]');
    var $empty = $table.querySelector('[data-stopbot-log-empty="1"]');
    var visible = 0;

    $rows.forEach(function ($row) {
      var haystack = ($row.getAttribute('data-stopbot-log-search-text') || $row.textContent || '')
        .toLocaleLowerCase()
        .trim();
      var match = (query === '' || haystack.indexOf(query) !== -1);
      $row.style.display = match ? '' : 'none';
      if (match) visible += 1;
    });

    if ($empty) {
      var $emptyCell = $empty.querySelector('td');
      if ($emptyCell && !$emptyCell.textContent) {
        $emptyCell.textContent = $table.getAttribute('data-stopbot-log-empty-text') || '';
      }
      $empty.style.display = visible === 0 ? '' : 'none';
    }
  }

  /**
   * Делегирование клика по атрибуту data-stopbot-open-modal="1"
   */
  $doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    var $btn = t.closest('[data-stopbot-open-modal="1"]');
    if (!$btn) return;

    e.preventDefault();

    var url = $btn.getAttribute('data-stopbot-modal') || '';
    stopbot_open_modal(url);
  });

  $doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    var probeBtn = t.closest('[data-stopbot-channel-probe="1"]');
    if (probeBtn) {
      e.preventDefault();
      stopbot_probe_channels(probeBtn);
      return;
    }

    var attachBtn = t.closest('[data-stopbot-channel-attach="1"]');
    if (attachBtn) {
      e.preventDefault();
      stopbot_attach_channel(attachBtn);
    }
  });

  $doc.addEventListener('input', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLInputElement)) return;
    if (t.matches('[data-stopbot-promo-search="1"]')) {
      stopbot_filter_promos(t);
      return;
    }
    if (t.matches('[data-stopbot-log-search="1"]')) {
      stopbot_filter_logs(t);
      return;
    }
  });

  $doc.querySelectorAll('[data-stopbot-promo-search="1"]').forEach(function ($input) {
    if ($input instanceof HTMLInputElement) {
      stopbot_filter_promos($input);
    }
  });

  $doc.querySelectorAll('[data-stopbot-log-search="1"]').forEach(function ($input) {
    if ($input instanceof HTMLInputElement) {
      stopbot_filter_logs($input);
    }
  });
})();
