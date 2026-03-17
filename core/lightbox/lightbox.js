/**
 * FILE: /core/lightbox/lightbox.js
 * ROLE: Универсальный lightbox для просмотра изображений (adm/site)
 * CONNECTIONS:
 *  - HTML: элементы с data-lightbox, data-lightbox-group, data-lightbox-src
 *  - CSS: /adm/view/assets/css/lightbox/lightbox.css (стили оверлея)
 *
 * NOTES:
 *  - Работает без зависимостей.
 *  - Группировка по data-lightbox-group (листает только внутри группы).
 *  - Закрытие: крестик, клик по фону, Esc.
 *
 * СПИСОК ФУНКЦИЙ:
 *  - lb_build()
 *  - lb_open(el)
 *  - lb_close()
 *  - lb_show()
 *  - lb_collect(group)
 */

(function(){
  'use strict';

  var overlay = null;
  var img = null;
  var btnPrev = null;
  var btnNext = null;
  var btnClose = null;
  var index = 0;
  var groupKey = '';
  var groupEls = [];
  var touchStartX = 0;
  var touchStartY = 0;

  function lb_build() {
    if (overlay) return;

    overlay = document.createElement('div');
    overlay.className = 'lb-overlay';
    overlay.innerHTML =
      '<div class="lb-backdrop" data-lb-close="1"></div>' +
      '<div class="lb-frame" role="dialog" aria-modal="true">' +
        '<button class="lb-btn lb-close" type="button" data-lb-close="1" aria-label="Закрыть">&times;</button>' +
        '<button class="lb-btn lb-prev" type="button" data-lb-prev="1" aria-label="Назад">&#10094;</button>' +
        '<button class="lb-btn lb-next" type="button" data-lb-next="1" aria-label="Вперёд">&#10095;</button>' +
        '<div class="lb-imgwrap"><img class="lb-img" alt=""></div>' +
      '</div>';

    document.body.appendChild(overlay);
    img = overlay.querySelector('.lb-img');
    btnPrev = overlay.querySelector('[data-lb-prev]');
    btnNext = overlay.querySelector('[data-lb-next]');
    btnClose = overlay.querySelector('[data-lb-close]');

    overlay.addEventListener('click', function(e){
      var t = e.target;
      if (t && t.getAttribute('data-lb-prev')) {
        e.preventDefault();
        if (index > 0) { index--; lb_show(); }
        return;
      }
      if (t && t.getAttribute('data-lb-next')) {
        e.preventDefault();
        if (index < groupEls.length - 1) { index++; lb_show(); }
        return;
      }
      if (t && t.getAttribute('data-lb-close')) {
        lb_close();
        return;
      }
      if (t && (t.classList.contains('lb-frame') || t.classList.contains('lb-imgwrap'))) {
        lb_close();
      }
    });

    overlay.addEventListener('touchstart', function(e){
      if (!e.touches || e.touches.length !== 1) return;
      touchStartX = e.touches[0].clientX;
      touchStartY = e.touches[0].clientY;
    }, {passive:true});

    overlay.addEventListener('touchend', function(e){
      if (!e.changedTouches || e.changedTouches.length !== 1) return;
      var dx = e.changedTouches[0].clientX - touchStartX;
      var dy = e.changedTouches[0].clientY - touchStartY;
      if (Math.abs(dx) > 40 && Math.abs(dy) < 60) {
        if (dx < 0) {
          if (index < groupEls.length - 1) { index++; lb_show(); }
        } else {
          if (index > 0) { index--; lb_show(); }
        }
      }
    }, {passive:true});

    document.addEventListener('keydown', function(e){
      if (!overlay || !overlay.classList.contains('is-open')) return;
      if (e.key === 'Escape') { lb_close(); }
      if (e.key === 'ArrowRight') { if (index < groupEls.length - 1) { index++; lb_show(); } }
      if (e.key === 'ArrowLeft') { if (index > 0) { index--; lb_show(); } }
    });
  }

  function lb_collect(group) {
    if (!group) return [];
    return Array.prototype.slice.call(document.querySelectorAll('[data-lightbox][data-lightbox-group="' + group + '"]'));
  }

  function lb_open(el) {
    if (!el) return;
    lb_build();

    groupKey = el.getAttribute('data-lightbox-group') || '';
    groupEls = groupKey ? lb_collect(groupKey) : [el];
    index = groupEls.indexOf(el);
    if (index < 0) index = 0;

    lb_show();
    overlay.classList.add('is-open');
    document.body.classList.add('lb-open');
  }

  function lb_close() {
    if (!overlay) return;
    overlay.classList.remove('is-open');
    document.body.classList.remove('lb-open');
    if (img) img.removeAttribute('src');
  }

  function lb_show() {
    if (!overlay || !img) return;
    var el = groupEls[index];
    if (!el) return;

    var src = el.getAttribute('data-lightbox-src') || el.getAttribute('href') || '';
    img.setAttribute('src', src);

    if (btnPrev) btnPrev.style.display = (index > 0 ? 'flex' : 'none');
    if (btnNext) btnNext.style.display = (index < groupEls.length - 1 ? 'flex' : 'none');
  }

  document.addEventListener('click', function(e){
    var target = e.target;
    if (!target) return;
    var el = target.closest('[data-lightbox]');
    if (!el) return;
    e.preventDefault();
    lb_open(el);
  });
})();
