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
  var maxWebhookDot = document.getElementById('ymlbMaxWebhookDot');
  var maxWebhookText = document.getElementById('ymlbMaxWebhookText');
  var settingsPanel = document.getElementById('ymlbSettingsPanel');

  var settingsForm = document.getElementById('ymlbSettingsForm');
  var bindingForm = document.getElementById('ymlbBindingForm');
  var channelCodeForm = document.getElementById('ymlbChannelCodeForm');
  var chatCodeForm = document.getElementById('ymlbChatCodeForm');
  var siteForm = document.getElementById('ymlbSiteForm');
  var cleanupForm = document.getElementById('ymlbCleanupForm');
  var modalMap = {};

  function ensureChatSitesForm() {
    var existing = document.getElementById('ymlbChatSitesForm');
    if (existing) return existing;

    var form = document.createElement('form');
    form.className = 'ymlb-form ymlb-form-grid';
    form.id = 'ymlbChatSitesForm';
    form.setAttribute('data-ymlb-form', 'chat-sites');
    form.innerHTML =
      '<input type="hidden" name="csrf" value="' + esc(csrf) + '">' +
      '<input type="hidden" name="chat_id" value="">' +
      '<div class="field field--stack">' +
        '<span class="field__label">Target</span>' +
        '<div class="input input--readonly" id="ymlbChatSitesMeta">-</div>' +
      '</div>' +
      '<label class="field field--stack">' +
        '<span class="field__label">Attached sites</span>' +
        '<select class="select ymlb-select-multi" name="site_ids[]" id="ymlbChatSiteSelectEdit" multiple size="8"></select>' +
        '<span class="field__hint muted">Admin can attach any active sites, including sites from different owners.</span>' +
      '</label>' +
      '<button class="btn btn--accent" type="submit">Save target sites</button>';

    (root.parentNode || document.body).appendChild(form);
    return form;
  }

  function ensureTargetCreateSitesSelect(form, selectId) {
    if (!form) return null;
    var existing = document.getElementById(selectId);
    if (existing) return existing;

    var bindingSelect = form.querySelector('select[name="binding_id"]');
    var bindingLabel = bindingSelect ? bindingSelect.closest('label') : null;
    if (!bindingLabel || !bindingLabel.parentNode) return null;

    var label = document.createElement('label');
    label.className = 'field field--stack';
    label.innerHTML =
      '<span class="field__label">Sites for target</span>' +
      '<select class="select ymlb-select-multi" name="site_ids[]" id="' + esc(selectId) + '" multiple size="5"></select>' +
      '<span class="field__hint muted">Admin can attach any active sites, including sites from different owners.</span>';
    bindingLabel.parentNode.insertBefore(label, bindingLabel.nextSibling);
    return label.querySelector('select');
  }

  var chatSitesForm = ensureChatSitesForm();
  var channelCodeSitesSelect = ensureTargetCreateSitesSelect(channelCodeForm, 'ymlbChannelSiteSelectCreate');
  var chatCodeSitesSelect = ensureTargetCreateSitesSelect(chatCodeForm, 'ymlbChatSiteSelectCreate');
  var chatEditSitesSelect = document.getElementById('ymlbChatSiteSelectEdit');
  var chatSitesMeta = document.getElementById('ymlbChatSitesMeta');

  var crmUserSelect = bindingForm && bindingForm.elements ? bindingForm.elements.crm_user_id : null;

  function ensurePlatformUi() {
    if (channelCodeForm) {
      Array.prototype.slice.call(channelCodeForm.querySelectorAll('select[name="platform"]')).forEach(function (node) {
        var label = node.closest('label');
        if (label) label.remove();
      });
    }

    if (chatCodeForm && !chatCodeForm.querySelector('select[name="platform"]')) {
      var usernameLabel = chatCodeForm.querySelector('input[name="channel_username"]');
      usernameLabel = usernameLabel ? usernameLabel.closest('label') : null;
      if (usernameLabel) {
        var platformLabelEl = document.createElement('label');
        platformLabelEl.className = 'field field--stack';
        platformLabelEl.innerHTML =
          '<span class="field__label">Platform</span>' +
          '<select class="select" name="platform">' +
            '<option value="tg">Telegram</option>' +
            '<option value="max">MAX</option>' +
          '</select>';
        usernameLabel.parentNode.insertBefore(platformLabelEl, usernameLabel);
      }
    }

    if (chatsBody) {
      var chatsTable = chatsBody.closest('table');
      var chatsHead = chatsTable ? chatsTable.querySelector('thead tr') : null;
      if (chatsHead && !Array.prototype.some.call(chatsHead.children, function (node) {
        return String(node.textContent || '').trim() === 'Platform';
      })) {
        var chatPlatformHead = document.createElement('th');
        chatPlatformHead.textContent = 'Platform';
        chatsHead.insertBefore(chatPlatformHead, chatsHead.children[2] || null);
      }
      if (chatsHead && !Array.prototype.some.call(chatsHead.children, function (node) {
        return String(node.textContent || '').trim() === 'Sites';
      })) {
        var chatSitesHead = document.createElement('th');
        var chatIdHead = Array.prototype.find.call(chatsHead.children, function (node) {
          return String(node.textContent || '').trim() === 'Chat ID';
        });
        chatSitesHead.textContent = 'Sites';
        chatsHead.insertBefore(chatSitesHead, chatIdHead || chatsHead.children[4] || null);
      }
      if (chatsBody.children.length === 1) {
        var chatsOnlyRow = chatsBody.children[0];
        if (chatsOnlyRow.children.length === 1) {
          chatsOnlyRow.children[0].setAttribute('colspan', '9');
        }
      }
    }

    if (channelsBody) {
      var channelsTable = channelsBody.closest('table');
      var channelsHead = channelsTable ? channelsTable.querySelector('thead tr') : null;
      if (channelsHead && channelsHead.children.length === 7) {
        var channelPlatformHead = document.createElement('th');
        channelPlatformHead.textContent = 'Platform';
        channelsHead.insertBefore(channelPlatformHead, channelsHead.children[2] || null);
      } else if (channelsHead && channelsHead.children.length > 3) {
        var existingPlatformHead = channelsHead.children[3];
        if (existingPlatformHead && String(existingPlatformHead.textContent || '').trim() === 'Platform') {
          channelsHead.insertBefore(existingPlatformHead, channelsHead.children[2] || null);
        }
      }
      if (channelsHead && !Array.prototype.some.call(channelsHead.children, function (node) {
        return String(node.textContent || '').trim() === 'Sites';
      })) {
        var channelSitesHead = document.createElement('th');
        var channelChatIdHead = Array.prototype.find.call(channelsHead.children, function (node) {
          return String(node.textContent || '').trim() === 'Chat ID';
        });
        channelSitesHead.textContent = 'Sites';
        channelsHead.insertBefore(channelSitesHead, channelChatIdHead || channelsHead.children[4] || null);
      }
      if (channelsBody.children.length === 1) {
        var channelsOnlyRow = channelsBody.children[0];
        if (channelsOnlyRow.children.length === 1) {
          channelsOnlyRow.children[0].setAttribute('colspan', '9');
        }
      }
    }
  }

  ensurePlatformUi();

  [channelCodeSitesSelect, chatCodeSitesSelect].forEach(function (select) {
    if (!select) return;
    var createSitesLabel = select.closest('label');
    var createSitesTitle = createSitesLabel ? createSitesLabel.querySelector('.field__label') : null;
    var createSitesHint = createSitesLabel ? createSitesLabel.querySelector('.field__hint') : null;
    if (createSitesTitle) createSitesTitle.textContent = 'Sites for target';
    if (createSitesHint) createSitesHint.textContent = 'Admin can attach any active sites, including sites from different owners.';
  });

  function organizeSettingsForm() {
    if (!settingsForm || settingsForm.getAttribute('data-ymlb-settings-ready') === '1') return;

    var hiddenInputs = Array.prototype.slice.call(settingsForm.querySelectorAll('input[type="hidden"]'));
    var submitBtn = settingsForm.querySelector('button[type="submit"]');
    var chatHint = settingsForm.querySelector('.ymlb-mini-hint');

    function takeField(name) {
      var input = settingsForm.elements ? settingsForm.elements[name] : null;
      if (!input || typeof input.closest !== 'function') return null;
      return input.closest('.field');
    }

    function markFull(node) {
      if (node && node.classList) node.classList.add('ymlb-field--full');
      return node;
    }

    function createGroup(title, hint, nodes) {
      var section = document.createElement('section');
      section.className = 'ymlb-settings-group';

      var head = document.createElement('div');
      head.className = 'ymlb-settings-group__head';

      var titleEl = document.createElement('div');
      titleEl.className = 'ymlb-settings-group__title';
      titleEl.textContent = title;
      head.appendChild(titleEl);

      if (hint) {
        var hintEl = document.createElement('div');
        hintEl.className = 'ymlb-settings-group__hint muted';
        hintEl.textContent = hint;
        head.appendChild(hintEl);
      }

      var grid = document.createElement('div');
      grid.className = 'ymlb-settings-grid';

      (nodes || []).forEach(function (node) {
        if (node && node.parentNode === settingsForm) {
          grid.appendChild(node);
        }
      });

      section.appendChild(head);
      section.appendChild(grid);
      return section;
    }

    settingsForm.classList.remove('ymlb-form-grid');

    var groups = [
      createGroup('General', 'Main module switches and common routing.', [
        takeField('enabled'),
        takeField('chat_mode_enabled'),
        takeField('geo_id'),
        markFull(takeField('link_static_params'))
      ]),
      createGroup('Telegram Main Bot', 'Channel webhook and default Telegram sender.', [
        takeField('bot_token'),
        takeField('bot_username'),
        takeField('webhook_secret'),
        takeField('listener_path')
      ]),
      createGroup('Telegram Chat Bot', 'Optional separate bot for chat mode.', [
        takeField('chat_bot_separate'),
        markFull(chatHint),
        takeField('chat_bot_token'),
        takeField('chat_bot_username'),
        takeField('chat_webhook_secret'),
        takeField('chat_listener_path')
      ]),
      createGroup('MAX', 'MAX API credentials, sender route and webhook listener.', [
        takeField('max_enabled'),
        takeField('max_api_key'),
        takeField('max_base_url'),
        takeField('max_send_path'),
        markFull(takeField('max_listener_path'))
      ]),
      createGroup('Links and Affiliate', 'Partner API usage and manual fallback generation.', [
        markFull(takeField('affiliate_api_key')),
        takeField('partner_mode_enabled'),
        takeField('manual_mode_enabled')
      ])
    ];

    var actions = document.createElement('div');
    actions.className = 'ymlb-settings-actions';
    if (submitBtn && submitBtn.parentNode === settingsForm) {
      actions.appendChild(submitBtn);
    }

    settingsForm.innerHTML = '';
    hiddenInputs.forEach(function (node) { settingsForm.appendChild(node); });
    groups.forEach(function (group) { settingsForm.appendChild(group); });
    settingsForm.appendChild(actions);
    settingsForm.setAttribute('data-ymlb-settings-ready', '1');

    var titles = [
      'Telegram Main Webhook',
      'Telegram Chat Webhook',
      'MAX Webhook'
    ];
    Array.prototype.slice.call(document.querySelectorAll('#ymlbSettingsPanel .ymlb-webhook-box')).forEach(function (box, index) {
      if (!box.querySelector('.ymlb-webhook-box__title')) {
        var title = document.createElement('div');
        title.className = 'ymlb-webhook-box__title';
        title.textContent = titles[index] || 'Webhook';
        box.insertBefore(title, box.firstChild);
      }
    });
  }

  organizeSettingsForm();

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
      refreshChannelCodeSiteOptions([]);
    }

    if (name === 'chat' && chatCodeForm) {
      chatCodeForm.reset();
      if (chatCodeForm.elements.platform) {
        chatCodeForm.elements.platform.value = 'tg';
      }
      renderBindingSelects((state.bootstrap && state.bootstrap.bindings) || []);
      refreshChatCodeSiteOptions([]);
    }

    if (name === 'chatSites' && chatSitesForm) {
      chatSitesForm.reset();
      if (chatSitesForm.elements.chat_id) {
        chatSitesForm.elements.chat_id.value = '';
      }
      if (chatSitesMeta) {
        chatSitesMeta.textContent = '-';
      }
      renderSiteSelect(chatEditSitesSelect, 0, []);
    }
  }

  modalMap.binding = mountFormModal(bindingForm, 'binding', 'Binding');
  modalMap.channel = mountFormModal(channelCodeForm, 'channel', 'Channel Link');
  modalMap.chat = mountFormModal(chatCodeForm, 'chat', 'Chat Link');
  modalMap.chatSites = mountFormModal(chatSitesForm, 'chatSites', 'Target Sites');
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
    if (settingsForm.elements.max_enabled) {
      settingsForm.elements.max_enabled.checked = Number(settings.max_enabled || 0) === 1;
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
    if (settingsForm.elements.max_api_key) {
      settingsForm.elements.max_api_key.value = settings.max_api_key || '';
    }
    if (settingsForm.elements.max_base_url) {
      settingsForm.elements.max_base_url.value = settings.max_base_url || '';
    }
    if (settingsForm.elements.max_send_path) {
      settingsForm.elements.max_send_path.value = settings.max_send_path || '';
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
    if (settingsForm.elements.max_listener_path) {
      settingsForm.elements.max_listener_path.value = settings.max_listener_path || '';
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

    function applyOptions(select) {
      if (!select) return;
      var current = String(select.value || '');
      select.innerHTML = html;
      if (!list.length) {
        select.value = '';
      } else {
        var exists = list.some(function (row) { return String(row.id || '') === current; });
        select.value = exists ? current : String(list[0].id || '');
      }
    }

    applyOptions(document.getElementById('ymlbBindingSelectForSite'));
    applyOptions(document.getElementById('ymlbBindingSelectForChannel'));
    applyOptions(document.getElementById('ymlbBindingSelectForChat'));
    refreshChatCodeSiteOptions();
  }

  function toIdList(values) {
    if (!Array.isArray(values)) return [];
    return values
      .map(function (value) { return Number(value || 0); })
      .filter(function (value, index, list) {
        return value > 0 && list.indexOf(value) === index;
      });
  }

  function setSelectValues(select, values) {
    if (!select) return;
    var selected = toIdList(values).reduce(function (acc, value) {
      acc[String(value)] = true;
      return acc;
    }, {});

    Array.prototype.slice.call(select.options || []).forEach(function (option) {
      option.selected = !!selected[String(option.value || '')];
    });
  }

  function selectedSelectValues(select) {
    if (!select || !select.options) return [];
    return Array.prototype.slice.call(select.options).filter(function (option) {
      return !!option.selected && String(option.value || '').trim() !== '';
    }).map(function (option) {
      return option.value;
    });
  }

  function sitesForBinding(bindingId, onlyActive) {
    var list = (state.bootstrap && state.bootstrap.sites) || [];
    var normalizedBindingId = Number(bindingId || 0);
    return list.filter(function (row) {
      if (Number(row.binding_id || 0) !== normalizedBindingId) return false;
      if (onlyActive && Number(row.is_active || 0) !== 1) return false;
      return true;
    });
  }

  function sitesForSelection(bindingId, onlyActive) {
    var list = (state.bootstrap && state.bootstrap.sites) || [];
    var normalizedBindingId = Number(bindingId || 0);
    return list.filter(function (row) {
      if (onlyActive && Number(row.is_active || 0) !== 1) return false;
      if (canManage) return true;
      return Number(row.binding_id || 0) === normalizedBindingId;
    });
  }

  function siteLabel(site) {
    var name = String(site && site.name ? site.name : ('Site #' + Number(site && site.id || 0)));
    var clid = String(site && site.clid ? site.clid : '').trim();
    return clid ? (name + ' [' + clid + ']') : name;
  }

  function siteSelectionLabel(site) {
    var label = siteLabel(site);
    if (!canManage) return label;
    var owner = String(site && (site.binding_title || ('binding #' + Number(site.binding_id || 0))) || '').trim();
    return owner ? (owner + ' / ' + label) : label;
  }

  function renderSiteSelect(select, bindingId, selectedIds) {
    if (!select) return;

    var sites = sitesForSelection(bindingId, true);
    if (!sites.length) {
      select.innerHTML = '<option value="" disabled>No active sites available</option>';
      return;
    }

    select.innerHTML = sites.map(function (site) {
      return '<option value="' + esc(site.id) + '">' + esc(siteSelectionLabel(site)) + '</option>';
    }).join('');
    setSelectValues(select, selectedIds);
  }

  function refreshChannelCodeSiteOptions(selectedIds) {
    if (!channelCodeForm || !channelCodeSitesSelect || !channelCodeForm.elements || !channelCodeForm.elements.binding_id) return;
    renderSiteSelect(channelCodeSitesSelect, channelCodeForm.elements.binding_id.value, selectedIds || []);
  }

  function refreshChatCodeSiteOptions(selectedIds) {
    if (!chatCodeForm || !chatCodeSitesSelect || !chatCodeForm.elements || !chatCodeForm.elements.binding_id) return;
    renderSiteSelect(chatCodeSitesSelect, chatCodeForm.elements.binding_id.value, selectedIds || []);
  }

  function targetSitesSummary(row, targetKind) {
    var explicit = Number(row && row.linked_sites_explicit || 0) === 1;
    var titles = Array.isArray(row && row.linked_site_titles) ? row.linked_site_titles.filter(Boolean) : [];
    if (explicit) {
      if (!titles.length) {
        return '<span class="muted">Explicit list is empty</span>';
      }
      return titles.map(function (title) { return esc(title); }).join('<br>');
    }

    var bindingSites = sitesForBinding(row && row.binding_id, true);
    if (targetKind === 'channel') {
      var allActiveSites = sitesForSelection(row && row.binding_id, true);
      if (!allActiveSites.length) {
        return '<span class="muted">No active sites available</span>';
      }
      return '<span class="muted">All active sites (' + allActiveSites.length + ')</span>';
    }

    if (!bindingSites.length) {
      return '<span class="muted">No active owner sites</span>';
    }

    if (bindingSites.length <= 3) {
      return '<span class="muted">All active sites</span><br>' + bindingSites.map(function (site) {
        return esc(siteLabel(site));
      }).join('<br>');
    }

    return '<span class="muted">All active sites (' + bindingSites.length + ')</span>';
  }

  function fillChatSitesMeta(row) {
    if (!chatSitesMeta) return;
    var parts = [
      '#' + Number(row && row.id || 0),
      String(row && (row.chat_kind || 'channel') || 'channel').trim(),
      platformLabel(row && row.platform || 'tg'),
      String(row && (row.binding_title || ('binding #' + row.binding_id)) || '').trim(),
      String(row && (row.channel_username ? '@' + row.channel_username : (row.channel_title || row.channel_chat_id || '-')) || '').trim()
    ].filter(Boolean);
    chatSitesMeta.textContent = parts.join(' / ');
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

  function platformCode(value) {
    return String(value || '').trim().toLowerCase() === 'max' ? 'max' : 'tg';
  }

  function platformLabel(value) {
    return platformCode(value) === 'max' ? 'MAX' : 'Telegram';
  }

  function platformBadge(value) {
    var code = platformCode(value);
    return '<span class="ymlb-platform ymlb-platform--' + code + '">' + esc(platformLabel(code)) + '</span>';
  }

  function renderChannels(channels) {
    if (!channelsBody) return;
    if (!channels || !channels.length) {
      channelsBody.innerHTML = '<tr><td colspan="9" class="muted">Channels not added.</td></tr>';
      return;
    }

    if (!channels || !channels.length) {
      channelsBody.innerHTML = '<tr><td colspan="7" class="muted">Каналы не добавлены.</td></tr>';
      return;
    }

    channelsBody.innerHTML = channels.map(function (row) {
      var isActive = Number(row.is_active || 0) === 1;
      var isConfirmed = !!row.confirmed_at;
      var channelName = row.channel_username ? '@' + row.channel_username : (row.channel_title || '-');

      var controls = canManageData
        ? '<button class="iconbtn iconbtn--sm" type="button" data-action="edit-channel-sites" data-id="' + esc(row.id) + '"><i class="bi bi-diagram-3"></i></button>' +
          '<button class="iconbtn iconbtn--sm" type="button" data-action="toggle-channel" data-id="' + esc(row.id) + '" data-next="' + (isActive ? '0' : '1') + '"><i class="bi ' + (isActive ? 'bi-toggle-on' : 'bi-toggle-off') + '"></i></button>' +
          '<button class="iconbtn iconbtn--sm" type="button" data-action="delete-channel" data-id="' + esc(row.id) + '"><i class="bi bi-trash"></i></button>'
        : '';

      return (
        '<tr>' +
          '<td>' + Number(row.id || 0) + '</td>' +
          '<td>' + esc(row.binding_title || ('#' + row.binding_id)) + '</td>' +
          '<td>' + platformBadge(row.platform || 'tg') + '</td>' +
          '<td>' + esc(channelName) + '</td>' +
          '<td>' + targetSitesSummary(row, 'channel') + '</td>' +
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
      chatsBody.innerHTML = '<tr><td colspan="9" class="muted">Chats not added.</td></tr>';
      return;
    }

    chatsBody.innerHTML = chats.map(function (row) {
      var isActive = Number(row.is_active || 0) === 1;
      var isConfirmed = !!row.confirmed_at;
      var chatName = row.channel_username ? '@' + row.channel_username : (row.channel_title || '-');

      var controls = canManageData
        ? '<button class="iconbtn iconbtn--sm" type="button" data-action="edit-chat-sites" data-id="' + esc(row.id) + '"><i class="bi bi-diagram-3"></i></button>' +
          '<button class="iconbtn iconbtn--sm" type="button" data-action="toggle-chat" data-id="' + esc(row.id) + '" data-next="' + (isActive ? '0' : '1') + '"><i class="bi ' + (isActive ? 'bi-toggle-on' : 'bi-toggle-off') + '"></i></button>' +
          '<button class="iconbtn iconbtn--sm" type="button" data-action="delete-chat" data-id="' + esc(row.id) + '"><i class="bi bi-trash"></i></button>'
        : '';

      return (
        '<tr>' +
          '<td>' + Number(row.id || 0) + '</td>' +
          '<td>' + esc(row.binding_title || ('#' + row.binding_id)) + '</td>' +
          '<td>' + platformBadge(row.platform || 'tg') + '</td>' +
          '<td>' + esc(chatName) + '</td>' +
          '<td>' + targetSitesSummary(row, 'chat') + '</td>' +
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
    ensurePlatformUi();
    refreshChannelCodeSiteOptions(selectedSelectValues(channelCodeSitesSelect));
    refreshChatCodeSiteOptions(selectedSelectValues(chatCodeSitesSelect));
  }

  function findBindingById(id) {
    var list = (state.bootstrap && state.bootstrap.bindings) || [];
    return list.find(function (row) { return Number(row.id || 0) === Number(id || 0); }) || null;
  }

  function findSiteById(id) {
    var list = (state.bootstrap && state.bootstrap.sites) || [];
    return list.find(function (row) { return Number(row.id || 0) === Number(id || 0); }) || null;
  }

  function findChatById(id) {
    var list = (state.bootstrap && state.bootstrap.chats) || [];
    return list.find(function (row) { return Number(row.id || 0) === Number(id || 0); }) || null;
  }

  function normalizeUrl(value) {
    return String(value || '').trim().replace(/\/+$/, '');
  }

  function webhookReasonLabel(code) {
    switch (String(code || '').trim()) {
      case 'MAX_API_KEY_EMPTY': return 'MAX API key is empty';
      case 'MAX_BASE_URL_EMPTY': return 'MAX base URL is empty';
      case 'MAX_ENDPOINT_EMPTY': return 'MAX send endpoint is empty';
      case 'MAX_WEBHOOK_URL_EMPTY': return 'MAX webhook URL is empty';
      case 'MAX_WEBHOOK_URL_INVALID': return 'MAX webhook URL is invalid';
      case 'MAX_WEBHOOK_NOT_FOUND': return 'MAX webhook is not registered';
      case 'MAX_WEBHOOK_RECREATE_REQUIRED': return 'MAX subscription exists but must be recreated';
      case 'MAX_WEBHOOK_DELETE_FAILED': return 'Old MAX subscription could not be removed';
      case 'MAX_SUBSCRIPTIONS_FAILED': return 'MAX subscriptions request failed';
      case 'MAX_SUBSCRIBE_FAILED': return 'MAX refused webhook subscription';
      case 'missing_message_created': return 'Current subscription does not include message updates';
      case 'missing_version': return 'Current subscription has no API version';
      case 'version_mismatch': return 'Current subscription uses another API version';
      default: return String(code || '').trim();
    }
  }

  function describeWebhookIssue(fallback, payload) {
    var parts = [];
    var error = webhookReasonLabel(payload && payload.error);
    var recreate = webhookReasonLabel(payload && payload.recreate_reason);

    if (error) parts.push(error);
    if (recreate) parts.push(recreate);
    if (payload && payload.http_code) parts.push('HTTP ' + String(payload.http_code));

    return parts.length ? parts.join(' / ') : String(fallback || 'Webhook error');
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

  function applyMaxWebhookState(meta) {
    if (!maxWebhookDot || !maxWebhookText) return;

    var on = !!(meta && meta.on);
    var text = String((meta && meta.text) || (on ? 'MAX webhook connected' : 'MAX webhook disconnected'));

    maxWebhookDot.classList.toggle('is-on', on);
    maxWebhookDot.classList.toggle('is-off', !on);

    maxWebhookText.classList.toggle('is-on', on);
    maxWebhookText.classList.toggle('is-off', !on);
    maxWebhookText.classList.add('ymlb-webhook-text');
    maxWebhookText.textContent = text;
  }

  function resolveMaxWebhookState(data) {
    var listener = normalizeUrl((data && data.listener_url) || (state.bootstrap && state.bootstrap.max_listener_url) || '');
    var remote = data && data.remote ? data.remote : null;
    var remoteOk = !!(remote && remote.ok === true);
    var remoteUrl = normalizeUrl(remote && remote.result ? (remote.result.url || '') : '');

    if (!remoteOk || !remoteUrl) {
      return { on: false, text: describeWebhookIssue('MAX webhook not connected', remote) };
    }

    if (listener !== '' && remoteUrl === listener) {
      return { on: true, text: 'MAX webhook connected' };
    }

    return { on: false, text: 'MAX webhook is connected to another URL' };
  }

  function isChatSeparateEnabled() {
    var settings = (state.bootstrap && state.bootstrap.settings) || {};
    return Number(settings.chat_bot_separate || 0) === 1;
  }

  function isMaxEnabled() {
    var settings = (state.bootstrap && state.bootstrap.settings) || {};
    return Number(settings.max_enabled || 0) === 1;
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

  function fetchMaxWebhookInfo(silent) {
    if (!canManage) {
      applyMaxWebhookState({ on: false, text: 'MAX webhook: manager only' });
      return Promise.resolve(null);
    }

    if (!isMaxEnabled()) {
      applyMaxWebhookState({ on: false, text: 'MAX webhook: disabled in settings' });
      return Promise.resolve(null);
    }

    return api('api_max_webhook_info', 'GET')
      .then(function (data) {
        var meta = resolveMaxWebhookState(data);
        applyMaxWebhookState(meta);
        if (!silent) notify(meta.text, meta.on ? 'success' : 'warn');
        return data;
      })
      .catch(function (err) {
        applyMaxWebhookState({ on: false, text: 'MAX webhook check failed' });
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
            fetchChatWebhookInfo(true).catch(function () { return null; }),
            fetchMaxWebhookInfo(true).catch(function () { return null; })
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
    if (channelCodeForm.elements && channelCodeForm.elements.binding_id) {
      channelCodeForm.elements.binding_id.addEventListener('change', function () {
        refreshChannelCodeSiteOptions([]);
      });
    }

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

      if (action === 'edit-channel-sites') {
        var channelRow = ((state.bootstrap && state.bootstrap.channels) || []).find(function (row) {
          return Number(row.id || 0) === id;
        }) || null;
        if (!channelRow || !chatSitesForm) return;

        if (chatSitesForm.elements.chat_id) {
          chatSitesForm.elements.chat_id.value = String(channelRow.id || '');
        }
        fillChatSitesMeta(channelRow);
        renderSiteSelect(chatEditSitesSelect, channelRow.binding_id, toIdList(channelRow.linked_site_ids || []));
        openModal('chatSites');
      }

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
    if (chatCodeForm.elements && chatCodeForm.elements.binding_id) {
      chatCodeForm.elements.binding_id.addEventListener('change', function () {
        refreshChatCodeSiteOptions([]);
      });
    }

    chatCodeForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!canManageData) return;

      api('api_chat_code', 'POST', new FormData(chatCodeForm))
        .then(function (data) {
          var gen = data.generated || {};
          if (chatCodeOut) {
            chatCodeOut.textContent =
              'Platform: ' + platformLabel(gen.platform || (chatCodeForm.elements.platform ? chatCodeForm.elements.platform.value : 'tg')) + '\n' +
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

      if (action === 'edit-chat-sites') {
        var chatRow = findChatById(id);
        if (!chatRow || !chatSitesForm) return;

        if (chatSitesForm.elements.chat_id) {
          chatSitesForm.elements.chat_id.value = String(chatRow.id || '');
        }
        fillChatSitesMeta(chatRow);
        renderSiteSelect(chatEditSitesSelect, chatRow.binding_id, toIdList(chatRow.linked_site_ids || []));
        openModal('chatSites');
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

  if (chatSitesForm) {
    chatSitesForm.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!canManageData) return;

      api('api_chat_sites_save', 'POST', new FormData(chatSitesForm))
        .then(function () { return refreshBootstrap({ silent: true }); })
        .then(function () {
          closeModal('chatSites');
          notify('Target sites saved.', 'success');
        })
        .catch(function (err) { notify(err.message, 'error'); });
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

  var maxWebhookCheckBtn = document.querySelector('[data-ymlb-action="max-webhook-check"]');
  if (maxWebhookCheckBtn) {
    maxWebhookCheckBtn.addEventListener('click', function () {
      fetchMaxWebhookInfo(false).catch(function () {});
    });
  }

  var maxWebhookSetBtn = document.querySelector('[data-ymlb-action="max-webhook-set"]');
  if (maxWebhookSetBtn) {
    maxWebhookSetBtn.addEventListener('click', function () {
      if (!canManage) return;

      api('api_max_webhook_set', 'POST', { csrf: csrf })
        .then(function (data) {
          if (!data.apply || data.apply.ok !== true) {
            throw new Error(describeWebhookIssue('MAX webhook setup failed', data.apply));
          }

          var meta = resolveMaxWebhookState(data);
          applyMaxWebhookState(meta);
          notify(meta.text, meta.on ? 'success' : 'warn');
          return refreshBootstrap({ silent: true });
        })
        .catch(function (err) { notify(err.message, 'error'); });
    });
  }

  refreshBootstrap({ silent: true });
})();
