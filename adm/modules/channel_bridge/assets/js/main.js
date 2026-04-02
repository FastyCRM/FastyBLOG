/**
 * FILE: /adm/modules/channel_bridge/assets/js/main.js
 * ROLE: UI модуля channel_bridge (открытие modal_*).
 */

(function () {
  'use strict';

  function cb_esc(v) {
    return String(v || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /**
   * cb_open_modal()
   * Загружает do=modal_* и открывает глобальную модалку shell.
   *
   * @param {string} url
   * @returns {void}
   */
  function cb_open_modal(url) {
    if (!url) return;

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true || !payload.data) return;

        var title = (payload.data.title || 'Modal').toString();
        var html = (payload.data.html || '').toString();

        if (window.App && window.App.modal && typeof window.App.modal.open === 'function') {
          window.App.modal.open(title, html);
        }
      })
      .catch(function () {});
  }

  function cb_render_max_probe(box, data) {
    if (!box) return;
    if (!data) {
      box.innerHTML = '<div class="muted">No data</div>';
      return;
    }

    var me = data.me || {};
    var chats = data.chats || {};
    var bot = me.bot || {};
    var rows = Array.isArray(chats.items) ? chats.items : [];

    var html = '';
    html += '<div class="muted">checked: ' + cb_esc(data.checked_at || '') + '</div>';
    html += '<div class="muted">base_url: <code>' + cb_esc(data.base_url || '') + '</code></div>';
    html += '<div class="muted">send_path: <code>' + cb_esc(data.send_path || '') + '</code></div>';
    html += '<div class="muted">token: <strong>' + ((Number(data.token_present || 0) === 1) ? 'present' : 'missing') + '</strong></div>';
    html += '<div class="muted">/me: <strong>' + ((me.ok === true) ? 'OK' : 'FAIL') + '</strong> (HTTP ' + cb_esc(me.http_code || 0) + ') ' + cb_esc(me.error || '') + '</div>';
    html += '<div class="muted">/chats: <strong>' + ((chats.ok === true) ? 'OK' : 'FAIL') + '</strong> (HTTP ' + cb_esc(chats.http_code || 0) + ') ' + cb_esc(chats.error || '') + ', count=' + cb_esc(chats.count || 0) + '</div>';

    if (bot && (bot.id || bot.username || bot.name)) {
      html += '<div class="muted">bot: <code>' + cb_esc(bot.id || '') + '</code> ' + cb_esc(bot.name || '') + ' @' + cb_esc(bot.username || '') + '</div>';
    }

    if (!rows.length) {
      html += '<div class="muted" style="margin-top:8px;">Chats list is empty</div>';
      box.innerHTML = html;
      return;
    }

    html += '<table class="table" style="margin-top:8px;">';
    html += '<thead><tr><th>chat_id</th><th>title</th><th>type</th><th>status</th><th>link</th><th></th></tr></thead><tbody>';
    rows.forEach(function (row) {
      var cid = cb_esc(row.chat_id || '');
      var link = cb_esc(row.link || '');
      html += '<tr>';
      html += '<td class="mono">' + cid + '</td>';
      html += '<td>' + cb_esc(row.title || '') + '</td>';
      html += '<td>' + cb_esc(row.type || '') + '</td>';
      html += '<td>' + cb_esc(row.status || '') + '</td>';
      html += '<td>' + (link ? ('<a href="' + link + '" target="_blank" rel="noopener noreferrer">open</a>') : '') + '</td>';
      html += '<td class="t-right"><button class="iconbtn iconbtn--sm" type="button" data-cb-copy-chat-id="' + cid + '" title="Copy chat_id"><i class="bi bi-clipboard"></i></button></td>';
      html += '</tr>';
    });
    html += '</tbody></table>';

    box.innerHTML = html;
  }

  function cb_render_tg_probe(box, data) {
    if (!box) return;
    if (!data) {
      box.innerHTML = '<div class="muted">No data</div>';
      return;
    }

    var me = data.me || {};
    var bot = me.bot || {};
    var chats = data.chats || {};
    var rows = Array.isArray(chats.items) ? chats.items : [];

    var html = '';
    html += '<div class="muted">checked: ' + cb_esc(data.checked_at || '') + '</div>';
    html += '<div class="muted">token: <strong>' + ((Number(data.token_present || 0) === 1) ? 'present' : 'missing') + '</strong></div>';
    html += '<div class="muted">/getMe: <strong>' + ((me.ok === true) ? 'OK' : 'FAIL') + '</strong> (HTTP ' + cb_esc(me.http_code || 0) + ') ' + cb_esc(me.error || '') + '</div>';
    if (bot && (bot.id || bot.username || bot.name)) {
      html += '<div class="muted">bot: <code>' + cb_esc(bot.id || '') + '</code> ' + cb_esc(bot.name || '') + (bot.username ? (' @' + cb_esc(bot.username)) : '') + '</div>';
    }
    html += '<div class="muted">known=' + cb_esc(chats.known_count || 0) + ', resolved=' + cb_esc(chats.resolved_count || 0) + ', errors=' + cb_esc(chats.errors_count || 0) + ', skipped=' + cb_esc(chats.skipped_count || 0) + ', limit=' + cb_esc(chats.resolve_limit || 0) + '</div>';

    if (!rows.length) {
      html += '<div class="muted" style="margin-top:8px;">Chats list is empty</div>';
      box.innerHTML = html;
      return;
    }

    html += '<table class="table" style="margin-top:8px;">';
    html += '<thead><tr><th>chat_id</th><th>title</th><th>username</th><th>type</th><th>roles</th><th>last_seen</th><th>status</th><th>error</th><th></th></tr></thead><tbody>';
    rows.forEach(function (row) {
      var cid = cb_esc(row.chat_id || '');
      var username = cb_esc(row.username || '');
      var link = cb_esc(row.link || '');
      var roles = [];
      if (Number(row.used_as_source || 0) === 1) roles.push('source');
      if (Number(row.used_as_target || 0) === 1) roles.push('target');
      var rolesText = roles.length ? roles.join(' / ') : '—';

      html += '<tr>';
      html += '<td class="mono">' + cid + '</td>';
      html += '<td>' + cb_esc(row.title || '') + '</td>';
      html += '<td>' + (username ? (link ? ('<a href="' + link + '" target="_blank" rel="noopener noreferrer">@' + username + '</a>') : ('@' + username)) : '') + '</td>';
      html += '<td>' + cb_esc(row.type || '') + '</td>';
      html += '<td>' + cb_esc(rolesText) + '</td>';
      html += '<td>' + cb_esc(row.last_seen_at || '') + '</td>';
      html += '<td>' + cb_esc(row.status || '') + '</td>';
      html += '<td>' + cb_esc(row.error || '') + '</td>';
      html += '<td class="t-right"><button class="iconbtn iconbtn--sm" type="button" data-cb-copy-chat-id="' + cid + '" title="Copy chat_id"><i class="bi bi-clipboard"></i></button></td>';
      html += '</tr>';
    });
    html += '</tbody></table>';

    box.innerHTML = html;
  }

  function cb_render_route_tg_probe(box, data, labels) {
    if (!box) return;
    if (!data) {
      box.innerHTML = '<div class="muted">No data</div>';
      return;
    }

    var chats = data.chats || {};
    var rows = Array.isArray(chats.items) ? chats.items : [];
    var sourceLabel = cb_esc((labels && labels.source) ? labels.source : 'To source');
    var targetLabel = cb_esc((labels && labels.target) ? labels.target : 'To target');

    var html = '';
    html += '<div class="muted">known=' + cb_esc(chats.known_count || 0) + ', resolved=' + cb_esc(chats.resolved_count || 0) + ', errors=' + cb_esc(chats.errors_count || 0) + '</div>';

    if (!rows.length) {
      html += '<div class="muted" style="margin-top:8px;">Chats list is empty</div>';
      box.innerHTML = html;
      return;
    }

    html += '<table class="table" style="margin-top:8px;">';
    html += '<thead><tr><th>chat_id</th><th>title</th><th>username</th><th>type</th><th></th></tr></thead><tbody>';
    rows.forEach(function (row) {
      var cid = cb_esc(row.chat_id || '');
      var username = cb_esc(row.username || '');
      var link = cb_esc(row.link || '');

      html += '<tr>';
      html += '<td class="mono">' + cid + '</td>';
      html += '<td>' + cb_esc(row.title || '') + '</td>';
      html += '<td>' + (username ? (link ? ('<a href="' + link + '" target="_blank" rel="noopener noreferrer">@' + username + '</a>') : ('@' + username)) : '') + '</td>';
      html += '<td>' + cb_esc(row.type || '') + '</td>';
      html += '<td class="t-right">';
      html += '<button class="btn" type="button" data-cb-fill-chat-id="source_chat_id" data-cb-fill-chat-value="' + cid + '">' + sourceLabel + '</button> ';
      html += '<button class="btn" type="button" data-cb-fill-chat-id="target_chat_id" data-cb-fill-chat-value="' + cid + '">' + targetLabel + '</button>';
      html += '</td>';
      html += '</tr>';
    });
    html += '</tbody></table>';

    box.innerHTML = html;
  }

  function cb_render_route_max_probe(box, data, labels) {
    if (!box) return;
    if (!data) {
      box.innerHTML = '<div class="muted">No data</div>';
      return;
    }

    var chats = data.chats || {};
    var rows = Array.isArray(chats.items) ? chats.items : [];
    var targetLabel = cb_esc((labels && labels.target) ? labels.target : 'To target');

    var html = '';
    html += '<div class="muted">count=' + cb_esc(chats.count || 0) + ', http=' + cb_esc(chats.http_code || 0) + ', error=' + cb_esc(chats.error || '') + '</div>';

    if (!rows.length) {
      html += '<div class="muted" style="margin-top:8px;">Chats list is empty</div>';
      box.innerHTML = html;
      return;
    }

    html += '<table class="table" style="margin-top:8px;">';
    html += '<thead><tr><th>chat_id</th><th>title</th><th>type</th><th>status</th><th></th></tr></thead><tbody>';
    rows.forEach(function (row) {
      var cid = cb_esc(row.chat_id || '');

      html += '<tr>';
      html += '<td class="mono">' + cid + '</td>';
      html += '<td>' + cb_esc(row.title || '') + '</td>';
      html += '<td>' + cb_esc(row.type || '') + '</td>';
      html += '<td>' + cb_esc(row.status || '') + '</td>';
      html += '<td class="t-right">';
      html += '<button class="btn" type="button" data-cb-fill-chat-id="target_chat_id" data-cb-fill-chat-value="' + cid + '">' + targetLabel + '</button>';
      html += '</td>';
      html += '</tr>';
    });
    html += '</tbody></table>';

    box.innerHTML = html;
  }

  function cb_probe_tg(btn) {
    if (!btn) return;
    var url = (btn.getAttribute('data-cb-tg-probe-url') || '').trim();
    var csrf = (btn.getAttribute('data-csrf') || '').trim();
    if (!url || !csrf) return;

    var root = btn.closest('.card__body') || document;
    var box = root.querySelector('[data-cb-tg-probe-result="1"]');
    if (!box) return;

    box.innerHTML = '<div class="muted">Loading...</div>';

    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: 'csrf=' + encodeURIComponent(csrf)
    })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true || !payload.data) {
          var err = (payload && (payload.msg || payload.message || payload.error)) ? String(payload.msg || payload.message || payload.error) : 'Request failed';
          box.innerHTML = '<div class="muted">' + cb_esc(err) + '</div>';
          return;
        }
        cb_render_tg_probe(box, payload.data);
      })
      .catch(function (e) {
        box.innerHTML = '<div class="muted">' + cb_esc((e && e.message) ? e.message : 'Request failed') + '</div>';
      });
  }

  function cb_probe_route_tg(btn) {
    if (!btn) return;
    var url = (btn.getAttribute('data-cb-tg-probe-url') || '').trim();
    var csrf = (btn.getAttribute('data-csrf') || '').trim();
    if (!url || !csrf) return;

    var root = btn.closest('.card__body') || document;
    var box = root.querySelector('[data-cb-route-tg-probe-result="1"]');
    if (!box) return;

    box.innerHTML = '<div class="muted">Loading...</div>';

    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: 'csrf=' + encodeURIComponent(csrf)
    })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true || !payload.data) {
          var err = (payload && (payload.msg || payload.message || payload.error)) ? String(payload.msg || payload.message || payload.error) : 'Request failed';
          box.innerHTML = '<div class="muted">' + cb_esc(err) + '</div>';
          return;
        }
        cb_render_route_tg_probe(box, payload.data, {
          source: (btn.getAttribute('data-cb-pick-source-label') || '').trim(),
          target: (btn.getAttribute('data-cb-pick-target-label') || '').trim()
        });
      })
      .catch(function (e) {
        box.innerHTML = '<div class="muted">' + cb_esc((e && e.message) ? e.message : 'Request failed') + '</div>';
      });
  }

  function cb_probe_route_max(btn) {
    if (!btn) return;
    var url = (btn.getAttribute('data-cb-max-probe-url') || '').trim();
    var csrf = (btn.getAttribute('data-csrf') || '').trim();
    if (!url || !csrf) return;

    var root = btn.closest('.card__body') || document;
    var box = root.querySelector('[data-cb-route-max-probe-result="1"]');
    if (!box) return;

    box.innerHTML = '<div class="muted">Loading...</div>';

    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: 'csrf=' + encodeURIComponent(csrf)
    })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true || !payload.data) {
          var err = (payload && (payload.msg || payload.message || payload.error)) ? String(payload.msg || payload.message || payload.error) : 'Request failed';
          box.innerHTML = '<div class="muted">' + cb_esc(err) + '</div>';
          return;
        }
        cb_render_route_max_probe(box, payload.data, {
          target: (btn.getAttribute('data-cb-pick-target-label') || '').trim()
        });
      })
      .catch(function (e) {
        box.innerHTML = '<div class="muted">' + cb_esc((e && e.message) ? e.message : 'Request failed') + '</div>';
      });
  }

  function cb_probe_max(btn) {
    if (!btn) return;
    var url = (btn.getAttribute('data-cb-max-probe-url') || '').trim();
    var csrf = (btn.getAttribute('data-csrf') || '').trim();
    if (!url || !csrf) return;

    var root = btn.closest('.card__body') || document;
    var box = root.querySelector('[data-cb-max-probe-result="1"]');
    if (!box) return;

    box.innerHTML = '<div class="muted">Loading...</div>';

    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: 'csrf=' + encodeURIComponent(csrf)
    })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        if (!payload || payload.ok !== true || !payload.data) {
          var err = (payload && (payload.msg || payload.message || payload.error)) ? String(payload.msg || payload.message || payload.error) : 'Request failed';
          box.innerHTML = '<div class="muted">' + cb_esc(err) + '</div>';
          return;
        }
        cb_render_max_probe(box, payload.data);
      })
      .catch(function (e) {
        box.innerHTML = '<div class="muted">' + cb_esc((e && e.message) ? e.message : 'Request failed') + '</div>';
      });
  }

  function cb_add_link_rule_row(btn) {
    if (!btn) return;
    var card = btn.closest('.card') || document;
    var body = card.querySelector('[data-cb-link-rules-body="1"]');
    if (!body) return;

    var rows = body.querySelectorAll('[data-cb-link-rule-row="1"]');
    if (!rows || !rows.length) return;
    var tpl = rows[rows.length - 1];
    var clone = tpl.cloneNode(true);

    var fields = clone.querySelectorAll('input, textarea, select');
    fields.forEach(function (el) {
      if (el.tagName === 'SELECT') {
        el.value = (el.name === 'rule_enabled[]') ? '1' : '';
        return;
      }
      if (el.name === 'rule_sort[]') {
        el.value = '100';
        return;
      }
      el.value = '';
    });

    body.appendChild(clone);
  }

  function cb_remove_link_rule_row(btn) {
    if (!btn) return;
    var row = btn.closest('[data-cb-link-rule-row="1"]');
    if (!row) return;
    var body = row.parentElement;
    if (!body) return;

    var rows = body.querySelectorAll('[data-cb-link-rule-row="1"]');
    if (rows.length <= 1) {
      var fields = row.querySelectorAll('input, textarea, select');
      fields.forEach(function (el) {
        if (el.tagName === 'SELECT') {
          el.value = (el.name === 'rule_enabled[]') ? '1' : '';
          return;
        }
        if (el.name === 'rule_sort[]') {
          el.value = '100';
          return;
        }
        el.value = '';
      });
      return;
    }

    row.remove();
  }

  document.addEventListener('click', function (e) {
    var target = e.target;
    if (!(target instanceof HTMLElement)) return;

    var tgProbeBtn = target.closest('[data-cb-tg-probe="1"]');
    if (tgProbeBtn) {
      e.preventDefault();
      cb_probe_tg(tgProbeBtn);
      return;
    }

    var routeTgProbeBtn = target.closest('[data-cb-route-tg-probe="1"]');
    if (routeTgProbeBtn) {
      e.preventDefault();
      cb_probe_route_tg(routeTgProbeBtn);
      return;
    }

    var routeMaxProbeBtn = target.closest('[data-cb-route-max-probe="1"]');
    if (routeMaxProbeBtn) {
      e.preventDefault();
      cb_probe_route_max(routeMaxProbeBtn);
      return;
    }

    var probeBtn = target.closest('[data-cb-max-probe="1"]');
    if (probeBtn) {
      e.preventDefault();
      cb_probe_max(probeBtn);
      return;
    }

    var copyBtn = target.closest('[data-cb-copy-chat-id]');
    if (copyBtn) {
      e.preventDefault();
      var value = (copyBtn.getAttribute('data-cb-copy-chat-id') || '').trim();
      if (!value) return;
      if (navigator && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
        navigator.clipboard.writeText(value).catch(function () {});
      }
      return;
    }

    var fillChatBtn = target.closest('[data-cb-fill-chat-id]');
    if (fillChatBtn) {
      e.preventDefault();
      var inputName = (fillChatBtn.getAttribute('data-cb-fill-chat-id') || '').trim();
      var inputValue = (fillChatBtn.getAttribute('data-cb-fill-chat-value') || '').trim();
      if (!inputName || !inputValue) return;

      var modalRoot = fillChatBtn.closest('form') || document;
      var input = modalRoot.querySelector('[name="' + inputName + '"]');
      if (!input) return;
      input.value = inputValue;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }

    var addRuleBtn = target.closest('[data-cb-rule-add="1"]');
    if (addRuleBtn) {
      e.preventDefault();
      cb_add_link_rule_row(addRuleBtn);
      return;
    }

    var delRuleBtn = target.closest('[data-cb-rule-remove="1"]');
    if (delRuleBtn) {
      e.preventDefault();
      cb_remove_link_rule_row(delRuleBtn);
      return;
    }

    var btn = target.closest('[data-cb-open-modal="1"]');
    if (!btn) return;

    e.preventDefault();
    var url = btn.getAttribute('data-cb-modal') || '';
    cb_open_modal(url);
  });
})();
