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
          window.setTimeout(function () {
            document.querySelectorAll('[data-cb-vk-cleanup-status="1"]').forEach(function (box) {
              cb_request_vk_cleanup_status(box, false);
            });
          }, 0);
        }
      })
      .catch(function () {});
  }

  function cb_render_vk_cleanup_status(box, task) {
    if (!box) return;
    if (!task) {
      box.innerHTML = '<div class="muted">No cleanup task yet</div>';
      return;
    }

    var percent = Math.max(0, Math.min(100, Number(task.percent || 0)));
    var recent = Array.isArray(task.recent_items) ? task.recent_items : [];
    var logs = Array.isArray(task.log_lines) ? task.log_lines : [];
    var html = '';
    html += '<div style="display:grid; gap:8px;">';
    html += '<div><strong>' + cb_esc(task.route_title || '') + '</strong> <span class="muted">[' + cb_esc(task.owner_id || '') + ']</span></div>';
    if (task.status_text) {
      html += '<div><strong>' + cb_esc(task.status_text || '') + '</strong></div>';
    }
    html += '<div class="muted">status: <strong>' + cb_esc(task.status || '') + '</strong>, requested=' + cb_esc(task.requested_display || task.requested_count || 0) + ', found=' + cb_esc(task.total_count || 0) + '</div>';
    if (task.link_substring) {
      html += '<div class="muted">' + cb_esc(box.dataset.cbVkCleanupLinkFilterLabel || 'Link filter') + ': <code>' + cb_esc(task.link_substring || '') + '</code></div>';
    }
    if (Number(task.current_post_id || 0) > 0) {
      html += '<div class="muted">current post: <code>' + cb_esc(task.current_post_id || 0) + '</code> #' + cb_esc(task.current_sort || 0) + '</div>';
    }
    html += '<div style="height:8px; background:#e5e7eb; border-radius:999px; overflow:hidden;"><div style="height:100%; width:' + percent + '%; background:#0ea5e9;"></div></div>';
    html += '<div class="muted">progress: <strong>' + percent + '%</strong> | deleted=' + cb_esc(task.deleted_count || 0) + ', failed=' + cb_esc(task.failed_count || 0) + ', pending=' + cb_esc(task.pending_count || 0) + ', retries=' + cb_esc(task.retries_count || 0) + '</div>';
    html += '<div class="muted">scanned=' + cb_esc(task.scanned_count || 0) + ', wall=' + cb_esc(task.wall_total || 0) + ', pinned skipped=' + cb_esc(task.pinned_skipped || 0) + ', started=' + cb_esc(task.started_at || '') + ', updated=' + cb_esc(task.updated_at || '') + '</div>';
    if (task.last_error) {
      html += '<div class="muted">last error: ' + cb_esc(task.last_error) + '</div>';
    }
    if (logs.length) {
      html += '<div class="muted">activity:</div>';
      html += '<pre class="mono" style="margin:0; white-space:pre-wrap; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:10px; max-height:180px; overflow:auto;">' + cb_esc(logs.join('\n')) + '</pre>';
    }
    if (recent.length) {
      html += '<table class="table" style="margin-top:4px;">';
      html += '<thead><tr><th>#</th><th>post_id</th><th>status</th><th>attempts</th><th>last_error</th></tr></thead><tbody>';
      recent.forEach(function (row) {
        html += '<tr>';
        html += '<td>' + cb_esc(row.sort || '') + '</td>';
        html += '<td class="mono">' + cb_esc(row.post_id || '') + '</td>';
        html += '<td>' + cb_esc(row.status || '') + '</td>';
        html += '<td>' + cb_esc(row.attempts || 0) + '</td>';
        html += '<td>' + cb_esc(row.last_error || '') + '</td>';
        html += '</tr>';
      });
      html += '</tbody></table>';
    }
    html += '</div>';

    box.innerHTML = html;
  }

  function cb_request_vk_cleanup_status(box, quiet) {
    if (!box || box.dataset.cbVkCleanupBusy === '1') return;

    var url = (box.getAttribute('data-cb-vk-cleanup-status-url') || '').trim();
    var csrf = (box.getAttribute('data-csrf') || '').trim();
    if (!url || !csrf) return;

    var root = box.closest('.card__body') || document;
    var routeInput = root.querySelector('[name="vk_cleanup_route_id"]');
    var routeId = routeInput ? String(routeInput.value || '').trim() : '';
    if (!routeId) {
      return;
    }

    box.dataset.cbVkCleanupBusy = '1';
    if (!quiet) {
      box.innerHTML = '<div class="muted">Loading...</div>';
    }

    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: 'csrf=' + encodeURIComponent(csrf) + '&vk_cleanup_route_id=' + encodeURIComponent(routeId)
    })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        box.dataset.cbVkCleanupBusy = '0';
        box.dataset.cbVkCleanupLastTs = String(Date.now());
        if (!payload || payload.ok !== true || !payload.data) {
          var err = (payload && (payload.msg || payload.message || payload.error)) ? String(payload.msg || payload.message || payload.error) : 'Request failed';
          box.innerHTML = '<div class="muted">' + cb_esc(err) + '</div>';
          return;
        }
        cb_render_vk_cleanup_status(box, payload.data.task || null);
      })
      .catch(function (e) {
        box.dataset.cbVkCleanupBusy = '0';
        box.dataset.cbVkCleanupLastTs = String(Date.now());
        box.innerHTML = '<div class="muted">' + cb_esc((e && e.message) ? e.message : 'Request failed') + '</div>';
      });
  }

  function cb_start_vk_cleanup(btn) {
    if (!btn) return;
    var url = (btn.getAttribute('data-cb-vk-cleanup-start-url') || '').trim();
    var confirmText = (btn.getAttribute('data-confirm') || '').trim();
    var csrf = (btn.getAttribute('data-csrf') || '').trim();
    if (!url || !csrf) return;
    if (confirmText && !window.confirm(confirmText)) return;

    var root = btn.closest('.card__body') || document;
    var routeInput = root.querySelector('[name="vk_cleanup_route_id"]');
    var countInput = root.querySelector('[name="vk_delete_last_count"]');
    var linkSubstringInput = root.querySelector('[name="vk_delete_link_substring"]');
    var box = root.querySelector('[data-cb-vk-cleanup-status="1"]');
    var routeId = routeInput ? String(routeInput.value || '').trim() : '';
    var count = countInput ? String(countInput.value || '').trim() : '';
    var linkSubstring = linkSubstringInput ? String(linkSubstringInput.value || '').trim() : '';

    if (!box) return;
    box.innerHTML = '<div class="muted">Starting...</div>';
    btn.disabled = true;

    fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: 'csrf=' + encodeURIComponent(csrf)
        + '&vk_cleanup_route_id=' + encodeURIComponent(routeId)
        + '&vk_delete_last_count=' + encodeURIComponent(count)
        + '&vk_delete_link_substring=' + encodeURIComponent(linkSubstring)
    })
      .then(function (r) { return r.json(); })
      .then(function (payload) {
        btn.disabled = false;
        box.dataset.cbVkCleanupLastTs = '0';
        if (!payload || payload.ok !== true || !payload.data) {
          var err = (payload && (payload.msg || payload.message || payload.error)) ? String(payload.msg || payload.message || payload.error) : 'Request failed';
          box.innerHTML = '<div class="muted">' + cb_esc(err) + '</div>';
          return;
        }
        cb_render_vk_cleanup_status(box, payload.data.task || null);
        cb_request_vk_cleanup_status(box, true);
      })
      .catch(function (e) {
        btn.disabled = false;
        box.innerHTML = '<div class="muted">' + cb_esc((e && e.message) ? e.message : 'Request failed') + '</div>';
      });
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

    var vkCleanupBtn = target.closest('[data-cb-vk-cleanup-start="1"]');
    if (vkCleanupBtn) {
      e.preventDefault();
      cb_start_vk_cleanup(vkCleanupBtn);
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

  document.addEventListener('change', function (e) {
    var target = e.target;
    if (!(target instanceof HTMLElement)) return;
    if (target.matches('[name="vk_cleanup_route_id"]')) {
      var root = target.closest('.card__body') || document;
      var box = root.querySelector('[data-cb-vk-cleanup-status="1"]');
      if (box) {
        box.dataset.cbVkCleanupLastTs = '0';
        cb_request_vk_cleanup_status(box, false);
      }
    }
  });

  window.setInterval(function () {
    document.querySelectorAll('[data-cb-vk-cleanup-status="1"]').forEach(function (box) {
      if (!(box instanceof HTMLElement)) return;
      var lastTs = parseInt(box.dataset.cbVkCleanupLastTs || '0', 10);
      if (Date.now() - lastTs < 1800) return;
      cb_request_vk_cleanup_status(box, true);
    });
  }, 2000);
})();
