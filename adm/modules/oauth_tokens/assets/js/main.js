/**
 * FILE: /adm/modules/oauth_tokens/assets/js/main.js
 * ROLE: UI-логика модуля oauth_tokens (глобальная модалка, CRUD, OAuth start)
 */

(function () {
  'use strict';

  /**
   * oauth_tokens_csrf()
   * Возвращает CSRF токен из скрытого поля.
   */
  function oauth_tokens_csrf() {
    var el = document.getElementById('oauthTokensCsrf');
    return el ? String(el.value || '') : '';
  }

  /**
   * oauth_tokens_json()
   * Выполняет POST и возвращает JSON.
   */
  function oauth_tokens_json(url, formData) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (json) {
        return { res: res, json: json };
      });
    });
  }

  /**
   * oauth_tokens_form_tpl_html()
   * Возвращает HTML шаблона формы из template.
   */
  function oauth_tokens_form_tpl_html() {
    var tpl = document.getElementById('oauthTokensFormTpl');
    if (!(tpl instanceof HTMLTemplateElement)) return '';
    return tpl.innerHTML || '';
  }

  var OauthTokensUI = {
    mode: 'create',

    _form: function () {
      return document.getElementById('oauth-form');
    },

    _open_form: function (title, data) {
      var html = oauth_tokens_form_tpl_html();
      if (!html) return;

      if (!(window.App && window.App.modal && typeof window.App.modal.open === 'function')) {
        return;
      }

      window.App.modal.open(title, html);

      var form = this._form();
      if (!form) return;

      data = data || {};
      form.id.value = data.id || '';
      form.name.value = data.name || '';
      form.client_id.value = data.client_id || '';
      form.client_secret.value = data.client_secret || '';
      if (form.assign_user_id) {
        form.assign_user_id.value = String(data.assign_user_id || 0);
      }

      form.addEventListener('submit', this.submit.bind(this));

      var cancelBtn = form.querySelector('[data-oauth-cancel="1"]');
      if (cancelBtn) {
        cancelBtn.addEventListener('click', this.close.bind(this));
      }
    },

    openCreate: function () {
      this.mode = 'create';
      this._open_form('Добавить токен', {
        id: '',
        name: '',
        client_id: '',
        client_secret: '',
        assign_user_id: 0
      });
    },

    openEdit: function (data) {
      this.mode = 'edit';
      this._open_form('Редактировать токен', data || {});
    },

    close: function () {
      if (window.App && window.App.modal && typeof window.App.modal.close === 'function') {
        window.App.modal.close();
      }
    },

    submit: function (e) {
      e.preventDefault();

      var form = this._form();
      if (!form) return false;

      var action = (this.mode === 'create') ? 'add' : 'update';
      var url = 'index.php?m=oauth_tokens&do=' + action;
      var formData = new FormData(form);

      oauth_tokens_json(url, formData).then(function (result) {
        var res = result.res;
        var json = result.json;

        if (!res.ok || !json || json.ok !== true) {
          alert((json && json.error) ? String(json.error) : 'Ошибка сохранения');
          return;
        }

        if (window.App && window.App.modal && typeof window.App.modal.close === 'function') {
          window.App.modal.close();
        }

        window.location.reload();
      }).catch(function () {
        alert('Ошибка запроса');
      });

      return false;
    },

    remove: function (id) {
      if (!window.confirm('Удалить токен?')) return;

      var csrf = oauth_tokens_csrf();
      if (!csrf) {
        alert('CSRF не найден');
        return;
      }

      var formData = new FormData();
      formData.append('csrf', csrf);
      formData.append('id', String(id));

      oauth_tokens_json('index.php?m=oauth_tokens&do=del', formData).then(function (result) {
        var res = result.res;
        var json = result.json;

        if (!res.ok || !json || json.ok !== true) {
          alert((json && json.error) ? String(json.error) : 'Ошибка удаления');
          return;
        }

        window.location.reload();
      }).catch(function () {
        alert('Ошибка запроса');
      });
    },

    start: function (id) {
      var csrf = oauth_tokens_csrf();
      if (!csrf) {
        alert('CSRF не найден');
        return;
      }

      var popup = window.open(
        '',
        'yandex_oauth',
        'width=520,height=720,menubar=no,toolbar=no,location=yes,status=no,scrollbars=yes'
      );

      if (!popup) {
        alert('Popup заблокирован браузером');
        return;
      }

      var form = document.createElement('form');
      form.method = 'POST';
      form.action = 'index.php?m=oauth_tokens&do=start';
      form.target = 'yandex_oauth';

      var csrfInput = document.createElement('input');
      csrfInput.type = 'hidden';
      csrfInput.name = 'csrf';
      csrfInput.value = csrf;
      form.appendChild(csrfInput);

      var idInput = document.createElement('input');
      idInput.type = 'hidden';
      idInput.name = 'id';
      idInput.value = String(id);
      form.appendChild(idInput);

      var popupInput = document.createElement('input');
      popupInput.type = 'hidden';
      popupInput.name = 'popup';
      popupInput.value = '1';
      form.appendChild(popupInput);

      document.body.appendChild(form);
      form.submit();
      form.remove();
    }
  };

  window.addEventListener('message', function (e) {
    if (!e || e.origin !== window.location.origin) return;
    if (!e.data || e.data.type !== 'oauth_tokens_updated') return;
    window.location.reload();
  });

  window.OauthTokensUI = OauthTokensUI;
})();
