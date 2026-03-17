/**
 * FILE: /adm/modules/modules/assets/js/main.js
 * ROLE: UI выбора иконки (минимально)
 */

document.addEventListener('click', function (e) {
  const btn = e.target.closest('.js-icon-picker');
  if (!btn) return;

  const id = btn.dataset.id || '';
  const icon = prompt('Введите класс Bootstrap Icon, например: bi bi-gear');

  if (!icon) return;

  const csrf = (window.__CSRF__ || '');

  const form = document.createElement('form');
  form.method = 'post';
  form.action = '/adm/index.php?m=modules&do=update_icon';

  const inCsrf = document.createElement('input');
  inCsrf.type = 'hidden';
  inCsrf.name = 'csrf';
  inCsrf.value = csrf;

  const inId = document.createElement('input');
  inId.type = 'hidden';
  inId.name = 'id';
  inId.value = id;

  const inIcon = document.createElement('input');
  inIcon.type = 'hidden';
  inIcon.name = 'icon';
  inIcon.value = icon;

  form.appendChild(inCsrf);
  form.appendChild(inId);
  form.appendChild(inIcon);

  document.body.appendChild(form);
  form.submit();
});
