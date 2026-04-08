(function () {
  'use strict';

  var root = document.getElementById('ymlbRoot');
  if (!root) return;

  var moduleCode = String(root.dataset.moduleCode || '').trim();
  var canManage = String(root.dataset.canManage || '0') === '1';
  var canManageData = String(root.dataset.canManageData || '0') === '1';
  var csrf = String(root.dataset.csrf || '').trim();
  var state = { bootstrap: null };

  var bindingsBody = document.getElementById('ymlbBindingsBody');
  var channelsBody = document.getElementById('ymlbChannelsBody');
  var chatsBody = document.getElementById('ymlbChatsBody');
  var sitesBody = document.getElementById('ymlbSitesBody');
  var channelCodeOut = document.getElementById('ymlbChannelCodeOut');
  var chatCodeOut = document.getElementById('ymlbChatCodeOut');
  var cleanupOut = document.getElementById('ymlbCleanupOut');

  var webhookDot = document.getElementById('ymlbWebhookDot');
  var webhookText = document.getElementById('ymlbWebhookText');
  var chatWebhookDot = document.getElementById('ymlbChatWebhookDot');
  var chatWebhookText = document.getElementById('ymlbChatWebhookText');
  var settingsPanel = document.getElementById('ymlbSettingsPanel');

  var settingsForm = document.getElementById('ymlbSettingsForm');
  var bindingForm = document.getElementById('ymlbBindingForm');
  var channelCodeForm = document.getElementById('ymlbChannelCodeForm');
  var chatCodeForm = document.getElementById('ymlbChatCodeForm');
  var siteForm = document.getElementById('ymlbSiteForm');
  var cleanupForm = document.getElementById('ymlbCleanupForm');
  var modalMap = {};

  var crmUserSelect = bindingForm && bindingForm.elements ? bindingForm.elements.crm_user_id : null;

  function esc(value) {
    var s = String(value == null ? '' : value);
    return s
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function notify(message, type) {
    var text = String(message || '').trim();
    if (!text) return;

    var bg = 'info';
    if (type === 'error') bg = 'danger';
    if (type === 'success') bg = 'ok';
    if (type === 'warn') bg = 'warn';
    if (type === 'accent') bg = 'accent';

    if (window.App && typeof window.App.flash === 'function') {
      window.App.flash({ text: text, bg: bg, beep: 0 });
      return;
    }

    var flashbar = document.getElementById('flashbar');
    if (!flashbar) return;

    var el = document.createElement('div');
    el.className = 'flash';

    var t = document.createElement('div');
    t.className = 'flash__text';
    t.textContent = text;

    var x = document.createElement('button');
    x.className = 'flash__close';
    x.type = 'button';
    x.setAttribute('aria-label', 'Закрыть');
    x.textContent = '×';
    x.addEventListener('click', function () { el.remove(); });

    el.appendChild(t);
    el.appendChild(x);
    flashbar.appendChild(el);

    window.setTimeout(function () {
      if (el.isConnected) el.remove();
    }, 6000);
  }

  function formDataFromObject(obj) {
    var fd = new FormData();
    Object.keys(obj || {}).forEach(function (key) {
      fd.append(key, obj[key]);
    });
    return fd;
  }

  function api(doName, method, payload) {
    var url = 'index.php?m=' + encodeURIComponent(moduleCode) + '&do=' + encodeURIComponent(doName);
    var opts = { method: method || 'GET', credentials: 'same-origin' };

    if (opts.method === 'POST') {
      var fd = payload instanceof FormData ? payload : formDataFromObject(payload || {});
      if (!fd.has('csrf') && csrf !== '') {
        fd.append('csrf', csrf);
      }
      opts.body = fd;
    }

    return fetch(url, opts)
      .then(function (resp) {
        return resp.json().catch(function () {
          throw new Error('Invalid JSON response');
        });
      })
      .then(function (json) {
        if (!json || json.ok !== true) {
          var msg = (json && (json.error || (json.data && json.data.message))) || 'Request failed';
          throw new Error(String(msg));
        }
        return json.data || {};
      });
  }

  function mountFormModal(form, key, title) {
    if (!form || typeof window.HTMLDialogElement === 'undefined') return null;
    if (!form.parentNode) return null;

    var dialog = document.createElement('dialog');
    dialog.className = 'ymlb-modal';
    dialog.setAttribute('data-ymlb-modal', key);

    var head = document.createElement('div');
    head.className = 'ymlb-modal__head';

    var caption = document.createElement('div');
    caption.className = 'ymlb-modal__title';
    caption.textContent = title;

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'iconbtn';
    closeBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
    closeBtn.addEventListener('click', function () {
      dialog.close();
    });

    head.appendChild(caption);
    head.appendChild(closeBtn);

    var body = document.createElement('div');
    body.className = 'ymlb-modal__body';
    body.appendChild(form);

    dialog.appendChild(head);
    dialog.appendChild(body);
    document.body.appendChild(dialog);

    dialog.addEventListener('click', function (e) {
      var rect = dialog.getBoundingClientRect();
      var inDialog = (
        e.clientX >= rect.left &&
        e.clientX <= rect.right &&
        e.clientY >= rect.top &&
        e.clientY <= rect.bottom
      );
      if (!inDialog) dialog.close();
    });

    return dialog;
  }

  function mountPanelModal(panel, key, title) {
    if (!panel || typeof window.HTMLDialogElement === 'undefined') return null;
    if (!panel.parentNode) return null;

    panel.classList.remove('l-col', 'l-col--3', 'l-col--xl-4', 'l-col--lg-6', 'l-col--sm-12');
    panel.classList.add('ymlb-settings-panel');

    var dialog = document.createElement('dialog');
    dialog.className = 'ymlb-modal';
    dialog.setAttribute('data-ymlb-modal', key);

    var head = document.createElement('div');
    head.className = 'ymlb-modal__head';

    var caption = document.createElement('div');
    caption.className = 'ymlb-modal__title';
    caption.textContent = title;

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'iconbtn';
    closeBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
    closeBtn.addEventListener('click', function () {
      dialog.close();
    });

    head.appendChild(caption);
    head.appendChild(closeBtn);

    var body = document.createElement('div');
    body.className = 'ymlb-modal__body';
    body.appendChild(panel);

    dialog.appendChild(head);
    dialog.appendChild(body);
    document.body.appendChild(dialog);

    dialog.addEventListener('click', function (e) {
      var rect = dialog.getBoundingClientRect();
      var inDialog = (
        e.clientX >= rect.left &&
        e.clientX <= rect.right &&
        e.clientY >= rect.top &&
        e.clientY <= rect.bottom
      );
      if (!inDialog) dialog.close();
    });

    return dialog;
  }

  function openModal(name) {
    var modal = modalMap[name] || null;
    if (!modal) return;
    if (typeof modal.showModal === 'function' && !modal.open) {
      modal.showModal();
    }
  }

  function closeModal(name) {
    var modal = modalMap[name] || null;
    if (!modal) return;
    if (modal.open && typeof modal.close === 'function') {
      modal.close();
    }
  }

  function prepareModalForm(name) {
    if (name === 'binding' && bindingForm) {
      bindingForm.reset();
      if (bindingForm.elements.id) bindingForm.elements.id.value = '';
      if (bindingForm.elements.oauth_access_token) {
        bindingForm.elements.oauth_access_token.value = '';
      }
      if (bindingForm.elements.oauth_access_token_clear) {
        bindingForm.elements.oauth_access_token_clear.checked = false;
      }
      if (bindingForm.elements.is_active) {
        bindingForm.elements.is_active.checked = true;
      }
      renderCrmUsers((state.bootstrap && state.bootstrap.crm_users) || [], '');
    }

    if (name === 'site' && siteForm) {
      siteForm.reset();
      if (siteForm.elements.id) siteForm.elements.id.value = '';
      if (siteForm.elements.is_active) siteForm.elements.is_active.checked = true;
      renderBindingSelects((state.bootstrap && state.bootstrap.bindings) || []);
    }

    if (name === 'channel' && channelCodeForm) {
      channelCodeForm.reset();
      renderBindingSelects((state.bootstrap && state.bootstrap.bindings) || []);
    }

    if (name === 'chat' && chatCodeForm) {
      chatCodeForm.reset();
      renderBindingSelects((state.bootstrap && state.bootstrap.bindings) || []);
    }
  }

  modalMap.binding = mountFormModal(bindingForm, 'binding', 'Binding');
  modalMap.channel = mountFormModal(channelCodeForm, 'channel', 'Channel Link');
  modalMap.chat = mountFormModal(chatCodeForm, 'chat', 'Chat Link');
  modalMap.site = mountFormModal(siteForm, 'site', 'Site (CLID)');
  modalMap.settings = mountPanelModal(settingsPanel, 'settings', 'Bot Settings');

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-ymlb-open-modal]');
    if (!btn) return;
    var name = String(btn.getAttribute('data-ymlb-open-modal') || '').trim();
    if (!name) return;
    prepareModalForm(name);
    openModal(name);
  });

  function fillSettings(settings) {
    if (!settingsForm || !settings) return;
    settingsForm.elements.enabled.checked = Number(settings.enabled || 0) === 1;
    if (settingsForm.elements.chat_mode_enabled) {
      settingsForm.elements.chat_mode_enabled.checked = Number(settings.chat_mode_enabled || 0) === 1;
    }
    if (settingsForm.elements.chat_bot_separate) {
      settingsForm.elements.chat_bot_separate.checked = Number(settings.chat_bot_separate || 0) === 1;
    }
    settingsForm.elements.bot_token.value = settings.bot_token || '';
    settingsForm.elements.bot_username.value = settings.bot_username || '';
    settingsForm.elements.webhook_secret.value = settings.webhook_secret || '';
    if (settingsForm.elements.chat_bot_token) {
      settingsForm.elements.chat_bot_token.value = settings.chat_bot_token || '';
    }
    if (settingsForm.elements.chat_bot_username) {
      settingsForm.elements.chat_bot_username.value = settings.chat_bot_username || '';
    }
    if (settingsForm.elements.chat_webhook_secret) {
      settingsForm.elements.chat_webhook_secret.value = settings.chat_webhook_secret || '';
    }
    settingsForm.elements.affiliate_api_key.value = settings.affiliate_api_key || '';
    if (settingsForm.elements.partner_mode_enabled) {
      settingsForm.elements.partner_mode_enabled.checked = Number(settings.partner_mode_enabled || 0) === 1;
    }
    if (settingsForm.elements.manual_mode_enabled) {
      settingsForm.elements.manual_mode_enabled.checked = Number(settings.manual_mode_enabled || 0) === 1;
    }
    settingsForm.elements.geo_id.value = settings.geo_id || 213;
    settingsForm.elements.link_static_params.value = settings.link_static_params || '';
    settingsForm.elements.listener_path.value = settings.listener_path || '';
    if (settingsForm.elements.chat_listener_path) {
      settingsForm.elements.chat_listener_path.value = settings.chat_listener_path || '';
    }
  }

  function renderCrmUsers(users, selectedValue) {
    if (!crmUserSelect) return;
    var list = Array.isArray(users) ? users : [];
    var selected = String(selectedValue == null ? '' : selectedValue);

    if (!list.length) {
      crmUserSelect.innerHTML = '<option value="">Пользователи не найдены</option>';
      crmUserSelect.value = '';
      return;
    }

    var hasSelected = !selected || list.some(function (row) {
      return String(row.id || '') === selected;
    });

    var html = '<option value="">Выберите пользователя</option>';
    html += list.map(function (row) {
      var id = Number(row.id || 0);
      var title = String(row.title || ('user#' + id));
      return '<option value="' + esc(id) + '">#' + esc(id) + ' - ' + esc(title) + '</option>';
    }).join('');

    if (selected && !hasSelected) {
      html += '<option value="' + esc(selected) + '">#' + esc(selected) + ' (not found)</option>';
    }

    crmUserSelect.innerHTML = html;
    crmUserSelect.value = selected;
  }

  function renderBindingSelects(bindings) {
    var list = Array.isArray(bindings) ? bindings : [];
    var html = '';
    if (!list.length) {
      html = '<option value="">Нет пользователей</option>';
    } else {
      html = list.map(function (row) {
        var label = '#' + Number(row.id || 0) + ' ' + String(row.title || 'Без имени');
        return '<option value="' + esc(row.id) + '">' + esc(label) + '</option>';
      }).join('');
    }

    var selectSite = document.getElementById('ymlbBindingSelectForSite');
    if (selectSite) selectSite.innerHTML = html;

    if (channelCodeForm && channelCodeForm.elements && channelCodeForm.elements.binding_id) {
      var input = channelCodeForm.elements.binding_id;
      var current = String(input.value || '');
      if (!list.length) {
        input.value = '';
      } else {
        var exists = list.some(function (row) { return String(row.id || '') === current; });
        if (!exists) input.value = String(list[0].id || '');
      }
    }

    if (chatCodeForm && chatCodeForm.elements && chatCodeForm.elements.binding_id) {
      var chatInput = chatCodeForm.elements.binding_id;
      var chatCurrent = String(chatInput.value || '');
      if (!list.length) {
        chatInput.value = '';
      } else {
        var chatExists = list.some(function (row) { return String(row.id || '') === chatCurrent; });
        if (!chatExists) chatInput.value = String(list[0].id || '');
      }
    }
  }

  function renderBindings(bindings) {
    if (!bindings || !bindings.length) {
      if (bindingsBody) {
        bindingsBody.innerHTML = '<tr><td colspan="7" class="muted">Записей нет.</td></tr>';
      }
      renderBindingSelects([]);
      return;
    }

    renderBindingSelects(bindings);
    if (!bindingsBody) return;

    bindingsBody.innerHTML = bindings.map(function (row) {
      var isActive = Number(row.is_active || 0) === 1;
      var tgUser = row.telegram_user_id || '';
      var crmUser = Number(row.crm_user_id || 0) > 0
        ? ('#' + Number(row.crm_user_id || 0) + (row.crm_user_title ? (' - ' + String(row.crm_user_title)) : ''))
        : '-';

      var oauthReady = Number(row.oauth_ready || 0) === 1;
      var oauthMode = String(row.oauth_mode || 'auto');
      var oauthText = 'no token';
      if (oauthReady) {
        oauthText = (oauthMode === 'manual')
          ? 'manual'
          : ('auto #' + Number(row.oauth_token_id || 0));
      }
      if (row.oauth_token_received_at) {
        oauthText += ' / ' + String(row.oauth_token_received_at);
      }

      var controls = canManage
        ? '<button class="iconbtn iconbtn--sm" type="button" data-action="edit-binding" data-id="' + esc(row.id) + '"><i class="bi bi-pencil"></i></button>' +
          '<button class="iconbtn iconbtn--sm" type="button" data-action="delete-binding" data-id="' + esc(row.id) + '"><i class="bi bi-trash"></i></button>'
        : '';

      return (
        '<tr>' +
          '<td>' + Number(row.id || 0) + '</td>' +
          '<td>' + esc(row.title || '') + '</td>' +
          '<td>' + esc(crmUser) + '</td>' +
          '<td>' + esc(tgUser || '-') + '</td>' +
          '<td><code>' + esc(oauthText) + '</code></td>' +
          '<td>' + (isActive ? 'ON' : 'OFF') + '</td>' +
          '<td class="t-right">' + controls + '</td>' +
        '</tr>'
      );
    }).join('');
  }

  function renderChannels(channels) {
    if (!channelsBody) return;

    if (!channels || !channels.length) {
      channelsBody.innerHTML = '<tr><td colspan="7" class="muted">Каналы не добавлены.</td></tr>';
      return;
    }

    channelsBody.innerHTML = channels.map(function (row) {
      var isActive = Number(row.is_active || 0) === 1;
      var isConfirmed = !!row.confirmed_at;
      var channelName = row.channel_username ? '@' + row.channel_username : (row.channel_title || '-');

      var controls = canManageData
        ? '<button class="iconbtn iconbtn--sm" type="button" data-action="toggle-channel" data-id="' + esc(row.id) + '" data-next="' + (isActive ? '0' : '1') + '"><i class="bi ' + (isActive ? 'bi-toggle-on' : 'bi-toggle-off') + '"></i></button>' +
          '<button class="iconbtn iconbtn--sm" type="button" data-action="delete-channel" data-id="' + esc(row.id) + '"><i class="bi bi-trash"></i></button>'
        : '';

      return (
        '<tr>' +
          '<td>' + Number(row.id || 0) + '</td>' +
          '<td>' + esc(row.binding_title || ('#' + row.binding_id)) + '</td>' +
          '<td>' + esc(channelName) + '</td>' +
          '<td><code>' + esc(row.channel_chat_id || '-') + '</code></td>' +
          '<td><code>' + esc(row.confirm_code || '-') + '</code></td>' +
          '<td>' + (isConfirmed ? (isActive ? 'confirmed / ON' : 'confirmed / OFF') : 'pending') + '</td>' +
          '<td class="t-right">' + controls + '</td>' +
        '</tr>'
      );
    }).join('');
  }

  function renderChats(chats) {
    if (!chatsBody) return;

    if (!chats || !chats.length) {
      chatsBody.innerHTML = '<tr><td colspan="7" class="muted">Chats not added.</td></tr>';
      return;
    }

    chatsBody.innerHTML = chats.map(function (row) {
      var isActive = Number(row.is_active || 0) === 1;
      var isConfirmed = !!row.confirmed_at;
      var chatName = row.channel_username ? '@' + row.channel_username : (row.channel_title || '-');

      var controls = canManageData
        ? '<button class="iconbtn iconbtn--sm" type="button" data-action="toggle-chat" data-id="' + esc(row.id) + '" data-next="' + (isActive ? '0' : '1') + '"><i class="bi ' + (isActive ? 'bi-toggle-on' : 'bi-toggle-off') + '"></i></button>' +
          '<button class="iconbtn iconbtn--sm" type="button" data-action="delete-chat" data-id="' + esc(row.id) + '"><i class="bi bi-trash"></i></button>'
        : '';

      return (
        '<tr>' +
          '<td>' + Number(row.id || 0) + '</td>' +
          '<td>' + esc(row.binding_title || ('#' + row.binding_id)) + '</td>' +
          '<td>' + esc(chatName) + '</td>' +
          '<td><code>' + esc(row.channel_chat_id || '-') + '</code></td>' +
          '<td><code>' + esc(row.confirm_code || '-') + '</code></td>' +
          '<td>' + (isConfirmed ? (isActive ? 'confirmed / ON' : 'confirmed / OFF') : 'pending') + '</td>' +
          '<td class="t-right">' + controls + '</td>' +
        '</tr>'
      );
    }).join('');
  }

  function renderSites(sites) {
    if (!sitesBody) return;

    if (!sites || !sites.length) {
      sitesBody.innerHTML = '<tr><td colspan="6" class="muted">Площадки не добавлены.</td></tr>';
      return;
    }

    sitesBody.innerHTML = sites.map(function (row) {
      var isActive = Number(row.is_active || 0) === 1;
      var controls = canManageData
        ? '<button class="iconbtn iconbtn--sm" type="button" data-action="edit-site" data-id="' + esc(row.id) + '"><i class="bi bi-pencil"></i></button>' +
          '<button class="iconbtn iconbtn--sm" type="button" data-action="delete-site" data-id="' + esc(row.id) + '"><i class="bi bi-trash"></i></button>'
        : '';

      return (
        '<tr>' +
          '<td>' + Number(row.id || 0) + '</td>' +
          '<td>' + esc(row.binding_title || ('#' + row.binding_id)) + '</td>' +
          '<td>' + esc(row.name || '') + '</td>' +
          '<td><code>' + esc(row.clid || '') + '</code></td>' +
          '<td>' + (isActive ? 'ON' : 'OFF') + '</td>' +
          '<td class="t-right">' + controls + '</td>' +
        '</tr>'
      );
    }).join('');
  }

  function applyBootstrap(bootstrap) {
    state.bootstrap = bootstrap || null;
    if (!bootstrap) return;

    fillSettings(bootstrap.settings || {});
    renderCrmUsers(bootstrap.crm_users || [], crmUserSelect ? crmUserSelect.value : '');
    renderBindings(bootstrap.bindings || []);
    renderChannels(bootstrap.channels || []);
    renderChats(bootstrap.chats || []);
    renderSites(bootstrap.sites || []);
  }

  function findBindingById(id) {
    var list = (state.bootstrap && state.bootstrap.bindings) || [];
    return list.find(function (row) { return Number(row.id || 0) === Number(id || 0); }) || null;
  }

  function findSiteById(id) {
    var list = (state.bootstrap && state.bootstrap.sites) || [];
    return list.find(function (row) { return Number(row.id || 0) === Number(id || 0); }) || null;
  }

  function normalizeUrl(value) {
    return String(value || '').trim().replace(/\/+$/, '');
  }

  function resolveWebhookState(data) {
    var listener = normalizeUrl((data && data.listener_url) || (state.bootstrap && state.bootstrap.listener_url) || '');
    var remote = data && data.remote ? data.remote : null;
    var remoteOk = !!(remote && remote.ok === true);
    var remoteUrl = normalizeUrl(remote && remote.result ? (remote.result.url || '') : '');

    if (!remoteOk || !remoteUrl) {
      return { on: false, text: 'Webhook не подключен' };
    }

    if (listener !== '' && remoteUrl === listener) {
      return { on: true, text: 'Webhook подключен' };
    }

    return { on: false, text: 'Webhook подключен на другой URL' };
  }

  function applyWebhookState(meta) {
    if (!webhookDot || !webhookText) return;

    var on = !!(meta && meta.on);
    var text = String((meta && meta.text) || (on ? 'Webhook подключен' : 'Webhook не подключен'));

    webhookDot.classList.toggle('is-on', on);
    webhookDot.classList.toggle('is-off', !on);

    webhookText.classList.toggle('is-on', on);
    webhookText.classList.toggle('is-off', !on);
    webhookText.classList.add('ymlb-webhook-text');
    webhookText.textContent = text;
  }

  function applyChatWebhookState(meta) {
    if (!chatWebhookDot || !chatWebhookText) return;

    var on = !!(meta && meta.on);
    var text = String((meta && meta.text) || (on ? 'Chat webhook connected' : 'Chat webhook disconnected'));

    chatWebhookDot.classList.toggle('is-on', on);
    chatWebhookDot.classList.toggle('is-off', !on);

    chatWebhookText.classList.toggle('is-on', on);
    chatWebhookText.classList.toggle('is-off', !on);
    chatWebhookText.classList.add('ymlb-webhook-text');
    chatWebhookText.textContent = text;
  }

  function isChatSeparateEnabled() {
    var settings = (state.bootstrap && state.bootstrap.settings) || {};
    return Number(settings.chat_bot_separate || 0) === 1;
  }

  function fetchWebhookInfo(silent) {
    if (!canManage) {
      applyWebhookState({ on: false, text: 'Webhook: доступ только менеджеру' });
      return Promise.resolve(null);
    }

    return api('api_webhook_info', 'GET')
      .then(function (data) {
        applyWebhookState(resolveWebhookState(data));
        if (!silent) notify('Статус webhook обновлен.', 'success');
        return data;
      })
      .catch(function (err) {
        applyWebhookState({ on: false, text: 'Ошибка проверки webhook' });
        if (!silent) notify(err.message, 'error');
        throw err;
      });
  }

  function fetchChatWebhookInfo(silent) {
    if (!canManage) {
      applyChatWebhookState({ on: false, text: 'Chat webhook: manager only' });
      return Promise.resolve(null);
    }

    if (!isChatSeparateEnabled()) {
      return api('api_webhook_info', 'GET')
        .then(function (data) {
          var mainState = resolveWebhookState(data);
          applyChatWebhookState({
            on: !!mainState.on,
            text: mainState.on
              ? 'Chat webhook: uses main bot'
              : 'Chat webhook: main bot webhook is not connected'
          });
          if (!silent) notify('Chat webhook status updated (main bot).', 'success');
          return data;
        })
        .catch(function (err) {
          applyChatWebhookState({ on: false, text: 'Chat webhook check failed' });
          if (!silent) notify(err.message, 'error');
          throw err;
        });
    }

    return api('api_chat_webhook_info', 'GET')
      .then(function (data) {
        if (Number(data.separate || 0) !== 1 || (data.remote && data.remote.error === 'CHAT_BOT_NOT_SEPARATE')) {
          applyChatWebhookState({ on: false, text: 'Chat webhook: separate bot disabled' });
          return data;
        }

        applyChatWebhookState(resolveWebhookState(data));
        if (!silent) notify('Chat webhook status updated.', 'success');
        return data;
      })
      .catch(function (err) {
        applyChatWebhookState({ on: false, text: 'Chat webhook check failed' });
        if (!silent) notify(err.message, 'error');
        throw err;
      });
  }

  function refreshBootstrap(opts) {
    opts = opts || {};
    var silent = !!opts.silent;

    return api('api_bootstrap', 'GET')
      .then(function (data) {
        applyBootstrap(data.bootstrap || null);

        if (canManage) {
          return Promise.all([
            fetchWebhookInfo(true).catch(function () { return null; }),
            fetchChatWebhookInfo(true).catch(function () { return null; })
          ]).then(function () {
            if (!silent) notify('Данные обновлены.', 'success');
            return data;
          });
        }

        if (!silent) notify('Данные обновлены.', 'success');
        return data;
      })
      .catch(function (err) {
        notify(err.message, 'error');
        throw err;
      });
  }

  if (settingsForm) {
    settingsForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!canManage) return;

      api('api_save_settings', 'POST', new FormData(settingsForm))
        .then(function () { return refreshBootstrap({ silent: true }); })
        .then(function () { notify('Настройки сохранены.', 'success'); })
        .catch(function (err) { notify(err.message, 'error'); });
    });
  }

  if (bindingForm) {
    bindingForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!canManage) return;

      api('api_binding_save', 'POST', new FormData(bindingForm))
        .then(function () { return refreshBootstrap({ silent: true }); })
        .then(function () {
          bindingForm.reset();
          bindingForm.elements.id.value = '';
          if (bindingForm.elements.oauth_access_token) {
            bindingForm.elements.oauth_access_token.value = '';
          }
          if (bindingForm.elements.oauth_access_token_clear) {
            bindingForm.elements.oauth_access_token_clear.checked = false;
          }
          closeModal('binding');
          notify('Пользователь сохранен.', 'success');
        })
        .catch(function (err) { notify(err.message, 'error'); });
    });
  }

  if (bindingsBody) {
    bindingsBody.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-action]');
      if (!btn) return;

      var action = btn.getAttribute('data-action');
      var id = Number(btn.getAttribute('data-id') || 0);
      if (!id) return;

      if (action === 'edit-binding') {
        var row = findBindingById(id);
        if (!row || !bindingForm) return;

        bindingForm.elements.id.value = row.id || '';
        bindingForm.elements.title.value = row.title || '';
        renderCrmUsers((state.bootstrap && state.bootstrap.crm_users) || [], row.crm_user_id || '');
        bindingForm.elements.telegram_user_id.value = row.telegram_user_id || '';
        bindingForm.elements.telegram_username.value = row.telegram_username || '';
        if (bindingForm.elements.oauth_access_token) {
          bindingForm.elements.oauth_access_token.value = '';
        }
        if (bindingForm.elements.oauth_access_token_clear) {
          bindingForm.elements.oauth_access_token_clear.checked = false;
        }
        bindingForm.elements.is_active.checked = Number(row.is_active || 0) === 1;
        openModal('binding');
        notify('Данные пользователя загружены в форму.', 'accent');
      }

      if (action === 'delete-binding' && canManage) {
        if (!window.confirm('Удалить пользователя и его каналы/площадки?')) return;

        api('api_binding_delete', 'POST', { id: id })
          .then(function () { return refreshBootstrap({ silent: true }); })
          .then(function () { notify('Пользователь удален.', 'success'); })
          .catch(function (err) { notify(err.message, 'error'); });
      }
    });
  }

  if (channelCodeForm) {
    channelCodeForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!canManageData) return;

      api('api_channel_code', 'POST', new FormData(channelCodeForm))
        .then(function (data) {
          var gen = data.generated || {};
          if (channelCodeOut) {
            channelCodeOut.textContent =
              'Код: ' + (gen.code || '-') + '\n' +
              'Истекает: ' + (gen.expires_at || '-') + '\n' +
              (gen.instruction || '');
          }
          closeModal('channel');
          return refreshBootstrap({ silent: true });
        })
        .then(function () { notify('Код подтверждения создан.', 'success'); })
        .catch(function (err) { notify(err.message, 'error'); });
    });
  }

  if (channelsBody) {
    channelsBody.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-action]');
      if (!btn || !canManageData) return;

      var action = btn.getAttribute('data-action');
      var id = Number(btn.getAttribute('data-id') || 0);
      if (!id) return;

      if (action === 'toggle-channel') {
        var next = Number(btn.getAttribute('data-next') || 0) === 1 ? 1 : 0;

        api('api_channel_toggle', 'POST', { id: id, is_active: next })
          .then(function () { return refreshBootstrap({ silent: true }); })
          .then(function () { notify('Статус канала обновлен.', 'success'); })
          .catch(function (err) { notify(err.message, 'error'); });
      }

      if (action === 'delete-channel') {
        if (!window.confirm('Удалить канал?')) return;

        api('api_channel_delete', 'POST', { id: id })
          .then(function () { return refreshBootstrap({ silent: true }); })
          .then(function () { notify('Канал удален.', 'success'); })
          .catch(function (err) { notify(err.message, 'error'); });
      }
    });
  }

  if (chatCodeForm) {
    chatCodeForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!canManageData) return;

      api('api_chat_code', 'POST', new FormData(chatCodeForm))
        .then(function (data) {
          var gen = data.generated || {};
          if (chatCodeOut) {
            chatCodeOut.textContent =
              'Code: ' + (gen.code || '-') + '\n' +
              'Expires: ' + (gen.expires_at || '-') + '\n' +
              (gen.instruction || '');
          }
          closeModal('chat');
          return refreshBootstrap({ silent: true });
        })
        .then(function () { notify('Chat bind code created.', 'success'); })
        .catch(function (err) { notify(err.message, 'error'); });
    });
  }

  if (chatsBody) {
    chatsBody.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-action]');
      if (!btn || !canManageData) return;

      var action = btn.getAttribute('data-action');
      var id = Number(btn.getAttribute('data-id') || 0);
      if (!id) return;

      if (action === 'toggle-chat') {
        var next = Number(btn.getAttribute('data-next') || 0) === 1 ? 1 : 0;

        api('api_channel_toggle', 'POST', { id: id, is_active: next })
          .then(function () { return refreshBootstrap({ silent: true }); })
          .then(function () { notify('Chat status updated.', 'success'); })
          .catch(function (err) { notify(err.message, 'error'); });
      }

      if (action === 'delete-chat') {
        if (!window.confirm('Delete chat binding?')) return;

        api('api_channel_delete', 'POST', { id: id })
          .then(function () { return refreshBootstrap({ silent: true }); })
          .then(function () { notify('Chat binding deleted.', 'success'); })
          .catch(function (err) { notify(err.message, 'error'); });
      }
    });
  }

  if (siteForm) {
    siteForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!canManageData) return;

      api('api_site_save', 'POST', new FormData(siteForm))
        .then(function () { return refreshBootstrap({ silent: true }); })
        .then(function () {
          siteForm.reset();
          siteForm.elements.id.value = '';
          closeModal('site');
          notify('Площадка сохранена.', 'success');
        })
        .catch(function (err) { notify(err.message, 'error'); });
    });
  }

  if (sitesBody) {
    sitesBody.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-action]');
      if (!btn || !canManageData) return;

      var action = btn.getAttribute('data-action');
      var id = Number(btn.getAttribute('data-id') || 0);
      if (!id) return;

      if (action === 'edit-site') {
        var row = findSiteById(id);
        if (!row || !siteForm) return;

        siteForm.elements.id.value = row.id || '';
        siteForm.elements.binding_id.value = row.binding_id || '';
        siteForm.elements.name.value = row.name || '';
        siteForm.elements.clid.value = row.clid || '';
        siteForm.elements.is_active.checked = Number(row.is_active || 0) === 1;
        openModal('site');
        notify('Данные площадки загружены в форму.', 'accent');
      }

      if (action === 'delete-site') {
        if (!window.confirm('Удалить площадку?')) return;

        api('api_site_delete', 'POST', { id: id })
          .then(function () { return refreshBootstrap({ silent: true }); })
          .then(function () { notify('Площадка удалена.', 'success'); })
          .catch(function (err) { notify(err.message, 'error'); });
      }
    });
  }

  if (cleanupForm) {
    cleanupForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!canManage) return;
      if (!window.confirm('Удалить фото за выбранный период?')) return;

      api('api_cleanup_photos', 'POST', new FormData(cleanupForm))
        .then(function (data) {
          var cleanup = data.cleanup || {};
          if (cleanupOut) {
            cleanupOut.textContent =
              'Удалено записей: ' + Number(cleanup.deleted_rows || 0) + '\n' +
              'Удалено файлов: ' + Number(cleanup.deleted_files || 0) + '\n' +
              'Отсутствующих файлов: ' + Number(cleanup.missing_files || 0) + '\n' +
              'Период: ' + String(cleanup.from || '') + ' - ' + String(cleanup.to || '');
          }
          notify('Очистка выполнена.', 'success');
        })
        .catch(function (err) { notify(err.message, 'error'); });
    });
  }

  var webhookCheckBtn = document.querySelector('[data-ymlb-action="webhook-check"]');
  if (webhookCheckBtn) {
    webhookCheckBtn.addEventListener('click', function () {
      fetchWebhookInfo(false).catch(function () {});
    });
  }

  var webhookSetBtn = document.querySelector('[data-ymlb-action="webhook-set"]');
  if (webhookSetBtn) {
    webhookSetBtn.addEventListener('click', function () {
      if (!canManage) return;

      api('api_webhook_set', 'POST', { csrf: csrf })
        .then(function (data) {
          applyWebhookState(resolveWebhookState(data));
          notify('Webhook подключен/обновлен.', 'success');
          return refreshBootstrap({ silent: true });
        })
        .catch(function (err) { notify(err.message, 'error'); });
    });
  }

  var chatWebhookCheckBtn = document.querySelector('[data-ymlb-action="chat-webhook-check"]');
  if (chatWebhookCheckBtn) {
    chatWebhookCheckBtn.addEventListener('click', function () {
      fetchChatWebhookInfo(false).catch(function () {});
    });
  }

  var chatWebhookSetBtn = document.querySelector('[data-ymlb-action="chat-webhook-set"]');
  if (chatWebhookSetBtn) {
    chatWebhookSetBtn.addEventListener('click', function () {
      if (!canManage) return;
      if (!isChatSeparateEnabled()) {
        api('api_webhook_set', 'POST', { csrf: csrf })
          .then(function (data) {
            var mainState = resolveWebhookState(data);
            applyWebhookState(mainState);
            applyChatWebhookState({
              on: !!mainState.on,
              text: mainState.on
                ? 'Chat webhook: uses main bot'
                : 'Chat webhook: main bot webhook is not connected'
            });
            notify(mainState.on ? 'Chat webhook works via main bot.' : 'Main webhook is not connected yet.', mainState.on ? 'success' : 'warn');
            return refreshBootstrap({ silent: true });
          })
          .catch(function (err) { notify(err.message, 'error'); });
        return;
      }

      api('api_chat_webhook_set', 'POST', { csrf: csrf })
        .then(function (data) {
          if (data.apply && data.apply.ok === false && data.apply.error === 'CHAT_BOT_NOT_SEPARATE') {
            applyChatWebhookState({ on: false, text: 'Chat webhook: separate bot disabled' });
            notify('Separate chat bot is disabled in settings.', 'warn');
            return refreshBootstrap({ silent: true });
          }
          applyChatWebhookState(resolveWebhookState(data));
          notify('Chat webhook connected/updated.', 'success');
          return refreshBootstrap({ silent: true });
        })
        .catch(function (err) { notify(err.message, 'error'); });
    });
  }

  refreshBootstrap({ silent: true });
})();
