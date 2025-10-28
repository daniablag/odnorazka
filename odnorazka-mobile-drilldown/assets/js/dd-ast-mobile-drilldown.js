(function(){
  document.addEventListener('DOMContentLoaded', function(){
    if (!document.body.classList.contains('ast-header-break-point')) return;

    var popup    = document.querySelector('.ast-mobile-popup-drawer .ast-mobile-popup-inner');
    var rootMenu = document.querySelector('.ast-mobile-popup-drawer .main-header-menu');
    if (!popup || !rootMenu) return;

    // === трек панелей
    var track = document.createElement('div');
    track.className = 'dd-nav-track';
    if (!track.style.transition) track.style.transition = 'transform 180ms ease';

    // === реестры
    var panelById   = new Map();     // id -> DOM панели
    var indexById   = new Map();     // id -> индекс в треке
    var metaById    = new Map();     // id -> {title, parent}
    var ulToPanelId = new Map();     // UL -> panelId

    var id = 0; function uid(){ id++; return 'ddp-' + id; }
    var ROOT_ID = 'root';
    ulToPanelId.set(rootMenu, ROOT_ID);
    metaById.set(ROOT_ID, { title: rootTitle(), parent: null });

    // 1) Регистрируем все UL/LI и присваиваем data-dd-target
    (function registerAll(ul){
  var pid = ulToPanelId.get(ul);
  ul.querySelectorAll(':scope > li').forEach(function(li){
    var sub = li.querySelector(':scope > .sub-menu');
    if (!sub) return;

    var childId = uid();
    li.dataset.ddTarget = childId;
    ulToPanelId.set(sub, childId);

    var a    = li.querySelector(':scope > a');
    var href = a ? (a.getAttribute('href') || '').trim() : '';

    metaById.set(childId, {
      title:  getItemText(li),
      parent: pid,
      href:   href
    });

    registerAll(sub);
  });
})(rootMenu);

    // 2) Создаём панели
    (function buildPanels(ul){
      var pid    = ulToPanelId.get(ul);
      var isRoot = (pid === ROOT_ID);

      var panel = makePanelFromList(ul, {
        title:  metaById.get(pid).title,
        isRoot: isRoot
      });

      panel.dataset.panelId = pid;
      indexById.set(pid, track.children.length);
      panelById.set(pid, panel);
      track.appendChild(panel);

      ul.querySelectorAll(':scope > li > .sub-menu').forEach(function(childUL){
        buildPanels(childUL);
      });
    })(rootMenu);

    // 3) Вставляем вместо исходного UL
    var holder = document.createElement('div');
    holder.className = 'dd-holder';
    holder.style.width = '100%';
    holder.style.position = 'relative';
    holder.style.overflow = 'hidden';

    rootMenu.parentNode.insertBefore(holder, rootMenu);
    holder.appendChild(track);
    rootMenu.style.display = 'none';

    // Собираем "виджеты" (всё, что шло ПОСЛЕ меню)
    var extrasWrap = document.createElement('div');
    extrasWrap.className = 'dd-extras';
    var sib = rootMenu.nextElementSibling;
    while (sib) {
      var next = sib.nextElementSibling;
      extrasWrap.appendChild(sib);
      sib = next;
    }

    // Вставляем виджеты внутрь корневой панели — ПОСЛЕ скролл-области
    (function placeExtrasIntoRoot(){
      var rootPanelEl = panelById.get(ROOT_ID);
      if (!rootPanelEl) return;
      var scrollWrap = rootPanelEl.querySelector('.dd-scroll');
      if (scrollWrap) {
        scrollWrap.insertAdjacentElement('afterend', extrasWrap);
      } else {
        rootPanelEl.appendChild(extrasWrap);
      }
    })();

    // ===== iOS/Android фиксы скролла =====
    function isIOS(){
      return /iP(ad|hone|od)/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    }
    var USE_LEFT_INSTEAD_OF_TRANSFORM = isIOS();

    if (USE_LEFT_INSTEAD_OF_TRANSFORM) {
      track.style.willChange = 'auto';
      track.style.transform  = '';                 // убрать 3D контекст
      track.style.left       = '0px';
      track.style.position   = 'relative';
      track.style.transition = 'left 180ms ease';  // анимируем left
    } else {
      // обычный путь с transform
      track.style.transition = 'transform 180ms ease';
    }

    // 4) Навигация по id
    var currentId = ROOT_ID;
    showPanel(ROOT_ID, false);

    // ======= ширина панелей и трансформ в PX (без субпикселей) =======
    function panelWidth(){
      return Math.max(1, Math.round(holder.clientWidth));
    }
    function setPanelsWidth(){
      var w = panelWidth();
      panelById.forEach(function(p){ p.style.width = w + 'px'; });
    }
    function translateToIndex(idx, animate){
      var w = panelWidth();
      var x = -Math.round(w * idx);

      if (USE_LEFT_INSTEAD_OF_TRANSFORM) {
        if (!animate){
          var prev = track.style.transition;
          track.style.transition = 'none';
          track.style.left = x + 'px';
          void track.offsetWidth;
          track.style.transition = prev || 'left 180ms ease';
        } else {
          track.style.left = x + 'px';
        }
        return;
      }

      // обычная ветка (transform)
      // Фикс Samsung/Android: на время анимации фиксируем ширину holder,
      // чтобы избежать «пол-экрана одного, пол-экрана другого» при пересчётах.
      if (animate) {
        try {
          var hw = holder.offsetWidth|0;
          holder.style.width = hw + 'px';
          var once = function(){ holder.style.width = ''; track.removeEventListener('transitionend', once); };
          track.addEventListener('transitionend', once, { once:true });
          setTimeout(once, 400);
        } catch(_) {}
      }

      if (!animate){
        var prev2 = track.style.transition;
        track.style.transition = 'none';
        track.style.transform = 'translate3d(' + x + 'px,0,0)';
        void track.offsetWidth; // reflow
        track.style.transition = prev2 || 'transform 180ms ease';
      } else {
        track.style.transform = 'translate3d(' + x + 'px,0,0)';
      }
    }
    function jumpToPanel(pid){
      var idx = indexById.get(pid) || 0;
      currentId = pid;
      translateToIndex(idx, false);

      var p = panelById.get(pid);
      if (p) {
        var wrap = p.querySelector('.dd-scroll');
        if (wrap) wrap.scrollTop = 0;
      }
    }

    // Открыть мобильный off-canvas Astra, если он закрыт, затем вызвать cb()
    function openMobileDrawerIfNeeded(cb){
      if (!document.body.classList.contains('ast-header-break-point')) { cb(); return; }
      if (document.body.classList.contains('ast-mobile-popup-active')) { cb(); return; }

      var toggle = document.querySelector(
        '.ast-mobile-header .menu-toggle, ' +
        '.ast-mobile-menu-trigger .menu-toggle, ' +
        '.main-header-menu-toggle.menu-toggle'
      );
      if (toggle) toggle.click();

      var tries = 0;
      var iv = setInterval(function(){
        if (document.body.classList.contains('ast-mobile-popup-active') || tries++ > 50) {
          clearInterval(iv);
          layout(); // ширины + высоты + трансформ/left
          cb();
        }
      }, 20);
    }

    // === PUBLIC API
    window.DD_ASTRA_MOBILE_MENU = {
      openToPanel: function(panelId, opts){
        var instant = !opts || opts.instant !== false;
        if (!panelId) return;

        if (!document.body.classList.contains('ast-mobile-popup-active')) {
          openMobileDrawerIfNeeded(function(){
            if (instant) {
              jumpToPanel(panelId);
              var p = panelById.get(panelId);
              var hdr = p ? p.querySelector('.dd-nav-header') : null;
              setTimeout(function(){ if (hdr && hdr.focus) hdr.focus(); }, 30);
            } else {
              showPanel(panelId, true);
            }
          });
          return;
        }

        if (instant) jumpToPanel(panelId);
        else showPanel(panelId, true);

        var p2 = panelById.get(panelId);
        var hdr2 = p2 ? p2.querySelector('.dd-nav-header') : null;
        setTimeout(function(){ if (hdr2 && hdr2.focus) hdr2.focus(); }, instant ? 30 : 180);
      },

      openToMenuItem: function(menuItemSelector, opts){
        var pid = getPanelIdByMenuItemSelector(menuItemSelector);
        if (pid) this.openToPanel(pid, opts);
      },

      openCatalog: function(opts){
        this.openToMenuItem('.menu-item-5692', opts); // подставь свой ID
      }
    };

    // Декларативные триггеры из вёрстки
    document.addEventListener('click', function(e){
      var btn = e.target.closest('.js-dd-open-to, .js-open-catalog');
      if (!btn) return;
      e.preventDefault();

      var instant = btn.getAttribute('data-dd-instant') !== '0';

      if (btn.classList.contains('js-open-catalog')) {
        window.DD_ASTRA_MOBILE_MENU.openCatalog({ instant: instant });
        return;
      }
      var sel = btn.getAttribute('data-dd-menu-item');
      if (sel) window.DD_ASTRA_MOBILE_MENU.openToMenuItem(sel, { instant: instant });
    });

    // подавляем закрытие попапа при клике по ссылкам
    popup.addEventListener('click', function(e){
      var deepLink = e.target.closest('a.dd-nav-link[data-dd-target]');
      if (deepLink) {
        e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
        showPanel(deepLink.getAttribute('data-dd-target'), true);
      }
      var backBtn = e.target.closest('.dd-back-btn');
      if (backBtn) {
        e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
        var parent = metaById.get(currentId).parent;
        if (parent) showPanel(parent, false);
      }
    }, true); // capture!

    window.addEventListener('resize', layout);
    try { if (window.visualViewport) { window.visualViewport.addEventListener('resize', function(){ setTimeout(layout, 0); }, {passive:true}); } } catch(_) {}

    // ===== helpers =====

    function layout(){
      setPanelsWidth();
      translateToIndex(indexById.get(currentId) || 0, false);
      setHeights();
    }

    function getPanelIdByMenuItemSelector(sel){
      var li = document.querySelector('.ast-mobile-popup-drawer .main-header-menu ' + sel);
      return li && li.dataset && li.dataset.ddTarget ? li.dataset.ddTarget : null;
    }

    // Берём HTML из исходного <a>, удаляя только служебные астровские стрелки/тогглеры
    function getLabelHTMLFromOriginalLink(a){
      if (!a) return '';
      var clone = a.cloneNode(true);
      clone.querySelectorAll(
        '.dropdown-menu-toggle, .ast-header-navigation-arrow, .ast-menu-toggle, button.ast-menu-toggle'
      ).forEach(function(el){ el.remove(); });
      clone.querySelectorAll('svg.ast-arrow-svg').forEach(function(svg){
        var p = svg.parentElement;
        if (p && p.classList.contains('ast-icon')) p.remove();
        else svg.remove();
      });
      return (clone.innerHTML || '').trim();
    }

    function makePanelFromList(ul, opts){
  var panel  = document.createElement('div');
  panel.className = 'dd-nav-panel';

  var title  = (opts && opts.title)  || '';
  var isRoot = !!(opts && opts.isRoot);

  // шапка
  var header = document.createElement('div');
  header.className = 'dd-nav-header';
  header.tabIndex = -1;

  if (!isRoot) {
    var backBtn = document.createElement('button');
    backBtn.className = 'dd-back-btn';
    backBtn.type = 'button';
    backBtn.setAttribute('aria-label','Назад');
    backBtn.innerHTML = svgBack();
    header.appendChild(backBtn);
  }
  var hText = document.createElement('div');
  hText.textContent = title;
  header.appendChild(hText);

  // === КЛИКАБЕЛЬНЫЙ ЗАГОЛОВОК ПАНЕЛИ ===
  // если это НЕ корневая панель и у неё в meta есть валидный href — делаем заголовок ссылкой
  if (!isRoot) {
    var pid  = ulToPanelId.get(ul);
    var meta = pid ? metaById.get(pid) : null;
    var href = meta && meta.href || '';

    if (href && href !== '#' && !/^javascript:/i.test(href)) {
      var linkEl = document.createElement('a');
      linkEl.className = 'dd-header-link';
      linkEl.href = href;
      linkEl.textContent = title;

      // заменить текстовый div на ссылку
      header.replaceChild(linkEl, hText);

      // не даём фокусу заголовка дёргать вьюпорт на Android
      header.addEventListener('focus', function(){
        try { linkEl.blur(); } catch(_) {}
      }, true);
    }
  }
  // === /КЛИКАБЕЛЬНЫЙ ЗАГОЛОВОК ПАНЕЛИ ===

  // список
  var list = document.createElement('ul');
  list.className = 'dd-nav-list';

  ul.querySelectorAll(':scope > li').forEach(function(li){
    var item = document.createElement('li');

    // Копируем классы исходного LI (для иконок/мега-меню и т.п.)
    var originalClasses = (li.getAttribute('class') || '').trim();
    item.className = ('dd-item' + (originalClasses ? ' ' + originalClasses : '')).trim();

    var a          = li.querySelector(':scope > a');
    var href       = a ? a.getAttribute('href') : '#';
    var labelHTML  = a ? getLabelHTMLFromOriginalLink(a) : escapeHtml(getItemText(li));
    var ariaLabel  = (a && (a.textContent || '').trim()) || '';

    var link = document.createElement('a');
    link.className = 'dd-nav-link';
    link.setAttribute('role','button');
    if (ariaLabel) link.setAttribute('aria-label', ariaLabel);

    var target = li.dataset.ddTarget;
    if (target) {
      link.href = '#';
      link.dataset.ddTarget = target;
      link.innerHTML = '<span class="dd-label">'+ labelHTML +'</span>' + svgNext();
    } else {
      link.href = href || '#';
      link.innerHTML = '<span class="dd-label">'+ labelHTML +'</span>';
    }

    item.appendChild(link);
    list.appendChild(item);
  });

  // ВАЖНО: скроллим не панель, а внутренний wrapper
  var scrollWrap = document.createElement('div');
  scrollWrap.className = 'dd-scroll';
  scrollWrap.appendChild(list);

  // Бубbling-stop (оставим как было)
  ['touchstart','touchmove','wheel'].forEach(function(ev){
    scrollWrap.addEventListener(ev, function(e){ e.stopPropagation(); }, { passive: false });
  });

  panel.appendChild(header);
  panel.appendChild(scrollWrap);
  return panel;
}

    function showPanel(pid, forward){
      currentId = pid;
      var idx = indexById.get(pid) || 0;
      translateToIndex(idx, true);

      var p = panelById.get(pid);
      if (!p) return;

      // сброс скролла
      var wrap = p.querySelector('.dd-scroll');
      if (wrap) wrap.scrollTop = 0;

      if (pid === ROOT_ID) {
        // Корень — без внутреннего скролла, по содержимому
        p.style.height = 'auto';
        if (wrap) {
          wrap.style.height    = 'auto';
          wrap.style.overflowY = 'visible';
        }
        holder.style.height = p.scrollHeight + 'px';
      } else {
        var avail = calcPanelHeight(p);
        var header = p.querySelector('.dd-nav-header');
        var hh = header ? header.getBoundingClientRect().height : 0;
        var innerH = Math.max(120, avail - Math.round(hh));

        p.style.height = avail + 'px';
        if (wrap) {
          wrap.style.height    = innerH + 'px';
          wrap.style.overflowY = 'auto';
          wrap.style.webkitOverflowScrolling = 'touch';
        }
        holder.style.height = avail + 'px';
      }

      var hdr = p.querySelector('.dd-nav-header');
      // предотвращаем автопрокрутку при фокусе (Android Chrome может дёргать вьюпорт)
      if (hdr && hdr.focus) setTimeout(function(){ try { hdr.focus({ preventScroll: true }); } catch(_) { hdr.focus(); } }, forward ? 180 : 0);
    }

    function setHeights(){
      var p = panelById.get(currentId);
      if (!p) return;

      var wrap = p.querySelector('.dd-scroll');

      if (currentId === ROOT_ID) {
        p.style.height = 'auto';
        if (wrap) {
          wrap.style.height    = 'auto';
          wrap.style.overflowY = 'visible';
        }
        holder.style.height = p.scrollHeight + 'px';
      } else {
        var avail = calcPanelHeight(p);
        var header = p.querySelector('.dd-nav-header');
        var hh = header ? header.getBoundingClientRect().height : 0;
        var innerH = Math.max(120, avail - Math.round(hh));

        p.style.height = avail + 'px';
        if (wrap) {
          wrap.style.height    = innerH + 'px';
          wrap.style.overflowY = 'auto';
          wrap.style.webkitOverflowScrolling = 'touch';
        }
        holder.style.height = avail + 'px';
      }
    }

    // расчёт высоты: ВСЕГДА от текущего viewport (исправляет «усадку» на Android)
    function calcPanelHeight(panelEl){
      var vv = window.visualViewport;
      var viewportH = (vv && vv.height) ? Math.round(vv.height) : (window.innerHeight || document.documentElement.clientHeight || 600);
      var popupRect  = popup.getBoundingClientRect();
      var holderRect = holder.getBoundingClientRect();
      var popupTop   = Math.max(0, Math.round(popupRect.top));
      var offsetTop  = Math.max(0, Math.round(holderRect.top - popupRect.top));
      var available  = viewportH - popupTop - offsetTop - 8; // небольшой нижний зазор
      return Math.max(240, Math.floor(available));
    }

    function rootTitle(){ return 'Odnorazka_kiev'; }
    function getItemText(li){ var a = li.querySelector(':scope > a'); return a ? (a.textContent || '').trim() : ''; }
    function svgNext(){ return '<span class="dd-next-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor"><path d="M8.59 16.59 13.17 12 8.59 7.41 10 6l6 6-6 6z"/></svg></span>'; }
    function svgBack(){ return '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>'; }
    function escapeHtml(str){ return String(str).replace(/[&<>\"']/g, function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]); }); }

    // ===== Guard против body-lock Astra: стопим события в capture-фазе на .dd-scroll =====
    function armScrollGuard(node){
      if (!node) return;
      ['touchstart','touchmove','wheel'].forEach(function(ev){
        node.addEventListener(ev, function(e){
          e.stopPropagation(); // не даём событию дойти до глобального preventDefault
        }, { capture: true, passive: false });
      });
    }
    // навесим guard на все панели один раз
    panelById.forEach(function(panel){
      var wrap = panel.querySelector('.dd-scroll');
      armScrollGuard(wrap);
    });

    // первый лэйаут (если меню уже открыто)
    layout();

    // Дополнительно: на всякий случай для контейнера Astra
    try {
      var inner = document.querySelector('.ast-mobile-popup-drawer, .ast-mobile-popup-drawer .ast-mobile-popup-inner');
      if (inner) {
        inner.style.touchAction = 'pan-y';
        inner.style.overscrollBehavior = 'contain';
      }
    } catch(_) {}
  });
})();

(function(){
  // если переменные из вашего скрипта недоступны — выходим
  if (typeof track === 'undefined' || typeof holder === 'undefined' ||
      typeof panelById === 'undefined' || typeof indexById === 'undefined') return;

  // (1) ширина панели — строго целое число пикселей
  panelWidth = function(){
    // clientWidth уже без полосы прокрутки, даёт стабильное целое
    return Math.max(1, Math.round(holder.clientWidth));
  };

  // (2) выставляем всем панелям одинаковую ширину в PX
  setPanelsWidth = function(){
    var w = panelWidth();
    panelById.forEach(function(p){ p.style.width = w + 'px'; });
  };

  // (3) безопасный перевод трека на индекс БЕЗ субпикселей
  translateToIndex = function(idx, animate){
    var w = panelWidth();
    var x = -Math.round(w * idx);

    // смотрим, включён ли iOS-режим (left вместо transform)
    var iosMode = (typeof USE_LEFT_INSTEAD_OF_TRANSFORM !== 'undefined') && USE_LEFT_INSTEAD_OF_TRANSFORM;

    if (iosMode) {
      if (!animate){
        var prev = track.style.transition;
        track.style.transition = 'none';
        track.style.left = x + 'px';
        void track.offsetWidth;
        track.style.transition = prev || 'left 180ms ease';
      } else {
        track.style.left = x + 'px';
      }
      return;
    }

    // Android/desktop: только translate3d с целыми PX
    if (!animate){
      var prev2 = track.style.transition;
      track.style.transition = 'none';
      track.style.transform = 'translate3d(' + x + 'px,0,0)';
      void track.offsetWidth; // форс рефлоу
      track.style.transition = prev2 || 'transform 180ms ease';
    } else {
      track.style.transform = 'translate3d(' + x + 'px,0,0)';
    }
  };

  // (4) layout: сначала выключаем анимацию, обновляем ширины, затем применяем позицию
  var _layoutBusy = false;
  layout = function(){
    if (_layoutBusy) return;
    _layoutBusy = true;
    try {
      var prev = track.style.transition;
      track.style.transition = 'none'; // чтобы не было «пол-экрана» при пересчёте
      setPanelsWidth();
      // текущий индекс
      var idx = (typeof currentId !== 'undefined') ? (indexById.get(currentId) || 0) : 0;
      translateToIndex(idx, false);
      void track.offsetWidth; // зафиксировать состояние
      track.style.transition = prev || ''; // вернуть анимацию
      setHeights && setHeights(); // пересчёт высот после обновления ширин
    } finally {
      _layoutBusy = false;
    }
  };

  // (5) пересчитываем при любом изменении размеров держателя
  try {
    var ro = new ResizeObserver(function(){ layout(); });
    ro.observe(holder);
  } catch(_) {
    // fallback
    window.addEventListener('resize', layout, {passive:true});
  }

  // (6) ориентация экрана меняется → жёсткий пересчёт
  window.addEventListener('orientationchange', function(){
    // задержка, чтобы браузер успел применить новый viewport
    setTimeout(layout, 60);
  });

  // (7) при открытии off-canvas Astra — пересчитать после фактического открытия
  // если в вашей теме нет события — setTimeout достаточно
  setTimeout(layout, 0);
})();


(function(){
  if (typeof track === 'undefined' || typeof holder === 'undefined' ||
      typeof panelById === 'undefined' || typeof indexById === 'undefined') return;

  // ---- helpers ----
  function px(n){ return Math.max(1, n|0); } // целое px

  // 1) Жёстко задаём ширины панелей и самого трека
  var _origSetPanelsWidth = (typeof setPanelsWidth === 'function') ? setPanelsWidth : null;
  setPanelsWidth = function(){
    var w = px(holder.offsetWidth);            // offsetWidth даёт целое без субпикселей
    // каждой панели — width и flex-basis в PX
    panelById.forEach(function(p){
      p.style.width = w + 'px';
      p.style.flex  = '0 0 ' + w + 'px';
      p.style.minWidth = w + 'px';
      p.style.maxWidth = w + 'px';
      p.style.boxSizing = 'border-box';
    });
    // ширина трека = сумма ширин панелей
    try {
      var count = track.children.length;
      track.style.width = (w * count) + 'px';
    } catch(_) {}
  };

  // 2) На время анимации фиксируем ширину holder, затем отпускаем
  function freezeHolderWidth(){
    var w = px(holder.offsetWidth);
    holder.style.width = w + 'px';
  }
  function unfreezeHolderWidth(){
    holder.style.width = ''; // вернуть авто
  }

  // 3) Оборачиваем translateToIndex: фиксируем холдер на время анимации
  var _origTranslate = (typeof translateToIndex === 'function') ? translateToIndex : null;
  translateToIndex = function(idx, animate){
    if (animate) {
      freezeHolderWidth();
      // снимем фиксацию после окончания перехода (или таймаутом на всякий)
      var done = false;
      function finish(){
        if (done) return;
        done = true;
        unfreezeHolderWidth();
        track.removeEventListener('transitionend', finish);
      }
      track.addEventListener('transitionend', finish, { once:true });
      setTimeout(finish, 400);
    }
    _origTranslate && _origTranslate(idx, animate);
  };

  // 4) layout: сначала выключаем transition, считаем ширины/позицию, затем включаем
  var _busy = false;
  var _origLayout = (typeof layout === 'function') ? layout : null;
  layout = function(){
    if (_busy) return;
    _busy = true;
    var prev = track.style.transition;
    track.style.transition = 'none';
    setPanelsWidth();
    var idx = (typeof currentId !== 'undefined') ? (indexById.get(currentId) || 0) : 0;
    _origTranslate && _origTranslate(idx, false);
    void track.offsetWidth;
    track.style.transition = prev || 'transform 180ms ease';
    if (typeof setHeights === 'function') setHeights();
    _busy = false;
  };

  // 5) Страховка: первый пересчёт через тик, чтобы учесть шрифты/иконки
  setTimeout(layout, 0);
})();

(function(){
  if (typeof track === 'undefined' || typeof holder === 'undefined' ||
      typeof panelById === 'undefined' || typeof indexById === 'undefined') return;

  function px(n){ return Math.max(1, n|0); }

  // переопределяем setPanelsWidth: width + flex-basis в PX и контроль трека
  var _origSetPanelsWidth = (typeof setPanelsWidth === 'function') ? setPanelsWidth : null;
  setPanelsWidth = function(){
    var w = px(holder.clientWidth); // целое без субпикселей

    // Убираем случайно проставленный gap у трека
    track.style.gap = '0px';

    // каждому panel: width и flex-basis ровно w
    var i = 0, maxW = 0, sum = 0;
    panelById.forEach(function(p){
      p.style.boxSizing = 'border-box';
      p.style.width     = w + 'px';
      p.style.flex      = '0 0 ' + w + 'px';
      p.style.minWidth  = '0px';
      p.style.maxWidth  = 'none';
      var ow = p.offsetWidth|0;
      maxW = Math.max(maxW, ow);
      sum += w;
      if (ow !== w) {
        // если какая-то панель «толще», принудительно сбросим всё, что может распирать
        p.style.padding = p.style.border = '0';
        p.style.width   = w + 'px';
      }
      i++;
    });

    // ширина трека — сумма ширин (для надёжности)
    track.style.width = sum + 'px';
  };

  // усиленный layout без анимации на время пересчёта
  var _busy = false;
  var _origTranslate = (typeof translateToIndex === 'function') ? translateToIndex : null;
  var _origLayout = (typeof layout === 'function') ? layout : null;
  layout = function(){
    if (_busy) return;
    _busy = true;
    var prev = track.style.transition;
    track.style.transition = 'none';
    setPanelsWidth();
    var idx = (typeof currentId !== 'undefined') ? (indexById.get(currentId) || 0) : 0;
    _origTranslate && _origTranslate(idx, false);
    void track.offsetWidth;
    track.style.transition = prev || '';
    if (typeof setHeights === 'function') setHeights();
    _busy = false;

    // Диагностика: логнём если какая-то панель > holder
    try {
      var w = holder.clientWidth|0, bad = [];
      Array.from(track.children).forEach(function(p, k){
        if ((p.offsetWidth|0) !== w) bad.push(k);
      });
      if (bad.length) console.warn('[dd] панели шире базовой:', bad, 'base=', w);
    } catch(_) {}
  };

  // первый пересчёт
  setTimeout(layout, 0);
})();


