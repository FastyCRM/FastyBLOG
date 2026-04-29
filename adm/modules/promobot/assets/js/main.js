/**
 * FILE: /adm/modules/promobot/assets/js/main.js
 * ROLE: UI модуля promobot — открытие модалок через App.modal
 */

(function () {
  'use strict';

  function promobot_esc(v) {
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

  function promobot_render_channel_probe(box, data, ctx) {
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
    html += '<div class="muted">checked: ' + promobot_esc(data.checked_at || '') + '</div>';
    html += '<div class="muted">token: <strong>' + (Number(data.token_present || 0) === 1 ? 'present' : 'missing') + '</strong></div>';
    html += '<div class="muted">/me: <strong>' + (me.ok === true ? 'OK' : 'FAIL') + '</strong> (HTTP ' + promobot_esc(me.http_code || 0) + ') ' + promobot_esc(me.error || '') + '</div>';
    if (bot && (bot.id || bot.username || bot.name)) {
      html += '<div class="muted">bot: <code>' + promobot_esc(bot.id || '') + '</code> ' + promobot_esc(bot.name || '') + ' @' + promobot_esc(bot.username || '') + '</div>';
    }

    if (platform === 'max') {
      html += '<div class="muted">/chats: <strong>' + (chats.ok === true ? 'OK' : 'FAIL') + '</strong> (HTTP ' + promobot_esc(chats.http_code || 0) + ') ' + promobot_esc(chats.error || '') + ', count=' + promobot_esc(chats.count || 0) + '</div>';
    } else {
      html += '<div class="muted">known chats: <strong>' + promobot_esc(chats.count || 0) + '</strong>, resolved=' + promobot_esc(chats.resolved_count || 0) + ', errors=' + promobot_esc(chats.errors_count || 0) + (chats.error ? (', ' + promobot_esc(chats.error)) : '') + '</div>';
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
      html += '<td class="mono">' + promobot_esc(cid) + '</td>';
      html += '<td>' + promobot_esc(title) + '</td>';
      html += '<td>' + promobot_esc(ctype) + '</td>';
      html += '<td>' + promobot_esc(rowStatus) + (row.error ? (' <span class="muted">' + promobot_esc(row.error) + '</span>') : '') + '</td>';
      if (isBound) {
        html += '<td class="t-right"><span class="muted">' + promobot_esc(addedLabel) + '</span></td>';
      } else {
        html += '<td class="t-right"><button class="btn btn--xs" type="button"'
          + ' data-promobot-channel-attach="1"'
          + ' data-promobot-attach-url="' + promobot_esc(attachUrl) + '"'
          + ' data-csrf="' + promobot_esc(csrf) + '"'
          + ' data-bot-id="' + promobot_esc(botId) + '"'
          + ' data-chat-id="' + promobot_esc(cid) + '"'
          + ' data-chat-title="' + promobot_esc(title) + '"'
          + ' data-chat-type="' + promobot_esc(ctype) + '"'
          + '>' + promobot_esc(addLabel) + '</button></td>';
      }
      html += '</tr>';
    });
    html += '</tbody></table>';

    box.innerHTML = html;
  }

  function promobot_probe_channels(btn) {
    if (!btn) return;

    var probeUrl = (btn.getAttribute('data-promobot-probe-url') || '').trim();
    var attachUrl = (btn.getAttribute('data-promobot-attach-url') || '').trim();
    var csrf = (btn.getAttribute('data-csrf') || '').trim();
    var botId = (btn.getAttribute('data-bot-id') || '').trim();
    var addLabel = (btn.getAttribute('data-label-add') || '').trim();
    var addedLabel = (btn.getAttribute('data-label-added') || '').trim();
    var loadingLabel = (btn.getAttribute('data-label-loading') || '').trim();
    if (!probeUrl || !csrf || !botId) return;

    var card = btn.closest('.card');
    var box = card ? card.querySelector('[data-promobot-channel-probe-result="1"]') : null;
    if (!box) return;

    btn.disabled = true;
    box.innerHTML = '<div class="muted">' + promobot_esc(loadingLabel || 'Loading...') + '</div>';

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
          box.innerHTML = '<div class="muted">' + promobot_esc(err) + '</div>';
          return;
        }
        promobot_render_channel_probe(box, payload.data, {
          attachUrl: attachUrl,
          csrf: csrf,
          botId: botId,
          addLabel: addLabel,
          addedLabel: addedLabel
        });
      })
      .catch(function (e) {
        btn.disabled = false;
        box.innerHTML = '<div class="muted">' + promobot_esc((e && e.message) ? e.message : 'Request failed') + '</div>';
      });
  }

  function promobot_attach_channel(btn) {
    if (!btn) return;

    var action = (btn.getAttribute('data-promobot-attach-url') || '').trim();
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

  $doc.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof HTMLElement)) return;

    var probeBtn = t.closest('[data-promobot-channel-probe="1"]');
    if (probeBtn) {
      e.preventDefault();
      promobot_probe_channels(probeBtn);
      return;
    }

    var attachBtn = t.closest('[data-promobot-channel-attach="1"]');
    if (attachBtn) {
      e.preventDefault();
      promobot_attach_channel(attachBtn);
    }
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
