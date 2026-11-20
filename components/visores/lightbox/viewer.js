/* CMS-Modular · visor de galerías. Sin dependencias. Mejora progresiva: sin JS, cada foto es un enlace a la imagen grande. */
(function () {
  'use strict';
  var T = (document.documentElement.lang || 'es').slice(0, 2) === 'es'
    ? { close: 'Cerrar', prev: 'Anterior', next: 'Siguiente', dlg: 'Visor de imágenes', of: ' de ' }
    : { close: 'Close', prev: 'Previous', next: 'Next', dlg: 'Image viewer', of: ' of ' };
  var root = null, img, cap, cnt, bPrev, bNext, bClose;
  var st = { items: [], i: 0, opener: null, g: null, loop: false, counter: false, preload: false, nav: true };

  function build() {
    root = document.createElement('div');
    root.className = 'lux-vw';
    root.hidden = true;
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-label', T.dlg);
    var stage = document.createElement('div');
    stage.className = 'lux-vw__stage';
    img = document.createElement('img');
    img.className = 'lux-vw__img';
    img.draggable = false;
    stage.appendChild(img);
    bClose = btn('lux-vw__close', '×', T.close);
    bPrev = btn('lux-vw__prev', '‹', T.prev);
    bNext = btn('lux-vw__next', '›', T.next);
    stage.appendChild(bClose); stage.appendChild(bPrev); stage.appendChild(bNext);
    var bar = document.createElement('div');
    bar.className = 'lux-vw__bar';
    cap = document.createElement('p');
    cap.className = 'lux-vw__cap';
    cnt = document.createElement('span');
    cnt.className = 'lux-vw__count';
    cnt.setAttribute('aria-live', 'polite');
    bar.appendChild(cap); bar.appendChild(cnt);
    root.appendChild(stage); root.appendChild(bar);
    document.body.appendChild(root);
    bClose.addEventListener('click', close);
    bPrev.addEventListener('click', function () { go(-1); });
    bNext.addEventListener('click', function () { go(1); });
    root.addEventListener('click', function (e) { if (e.target === root || e.target === stage) { close(); } });
    root.addEventListener('keydown', key);
    var x0 = null;
    root.addEventListener('touchstart', function (e) { x0 = e.touches.length === 1 ? e.touches[0].clientX : null; }, { passive: true });
    root.addEventListener('touchend', function (e) {
      if (x0 === null || !e.changedTouches.length) { return; }
      var dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) > 50) { go(dx < 0 ? 1 : -1); }
    }, { passive: true });
  }

  function btn(cls, txt, label) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'lux-vw__btn ' + cls;
    b.textContent = txt;
    b.setAttribute('aria-label', label);
    return b;
  }

  function key(e) {
    if (e.key === 'Escape') { e.preventDefault(); close(); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); go(1); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
    else if (e.key === 'Tab') { // el foco no sale del visor
      var f = [bClose, bPrev, bNext].filter(function (b) { return !b.hidden; });
      var k = f.indexOf(document.activeElement);
      e.preventDefault();
      f[(k + (e.shiftKey ? f.length - 1 : 1)) % f.length].focus();
    }
  }

  function items(g) {
    return Array.prototype.map.call(g.querySelectorAll('a.lux-g-a'), function (a) {
      var t = a.querySelector('img');
      return { a: a, href: a.getAttribute('href'), srcset: a.getAttribute('data-srcset') || '', w: a.getAttribute('data-w'), h: a.getAttribute('data-h'), cap: a.getAttribute('data-cap') || '', alt: a.getAttribute('data-alt') || (t ? t.alt : '') };
    });
  }

  function show(dir) {
    var it = st.items[st.i];
    img.className = 'lux-vw__img';
    img.alt = it.alt;
    img.removeAttribute('srcset');
    if (it.srcset) { img.sizes = '100vw'; img.srcset = it.srcset; }
    img.src = it.href;
    if (it.w) { img.width = +it.w; img.height = +it.h; }
    void img.offsetWidth; // reinicia la animación
    img.className = 'lux-vw__img is-in' + (dir > 0 ? ' is-next' : dir < 0 ? ' is-prev' : '');
    cap.textContent = it.cap;
    cap.hidden = it.cap === '';
    var n = st.items.length;
    cnt.textContent = st.counter ? (st.i + 1) + T.of + n : '';
    var many = n > 1 && st.nav;
    bPrev.hidden = bNext.hidden = !many;
    if (many && !st.loop) { bPrev.disabled = st.i === 0; bNext.disabled = st.i === n - 1; } else { bPrev.disabled = bNext.disabled = false; }
    if (st.preload && n > 1) {
      [1, -1].forEach(function (d) {
        var j = st.i + d;
        if (st.loop) { j = (j + n) % n; }
        if (st.items[j]) { var p = new Image(); p.src = st.items[j].href; }
      });
    }
  }

  function go(d) {
    var n = st.items.length, j = st.i + d;
    if (st.loop) { j = (j + n) % n; } else if (j < 0 || j >= n) { return; }
    st.i = j;
    show(d);
  }

  function open(g, a) {
    if (!root) { build(); }
    st.g = g; st.items = items(g); st.opener = a;
    st.i = Math.max(0, st.items.findIndex(function (x) { return x.a === a; }));
    st.loop = g.hasAttribute('data-loop');
    st.counter = g.hasAttribute('data-counter');
    st.preload = g.hasAttribute('data-preload');
    st.nav = g.hasAttribute('data-arrows');
    root.className = 'lux-vw lux-vw--' + (g.getAttribute('data-viewer') || 'fade');
    root.hidden = false;
    document.documentElement.classList.add('lux-vw-lock');
    show(0);
    bClose.focus();
  }

  function close() {
    if (!root || root.hidden) { return; }
    root.hidden = true;
    img.removeAttribute('src'); img.removeAttribute('srcset');
    document.documentElement.classList.remove('lux-vw-lock');
    if (st.opener) { st.opener.focus(); }
  }

  function init() {
    var gs = document.querySelectorAll('[data-lux-gallery]');
    Array.prototype.forEach.call(gs, function (g) {
      if (g.hasAttribute('data-nodrag')) { g.addEventListener('dragstart', function (e) { e.preventDefault(); }); }
      if (g.hasAttribute('data-noctx')) { g.addEventListener('contextmenu', function (e) { e.preventDefault(); }); }
      if (!g.hasAttribute('data-viewer')) { return; }
      g.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a.lux-g-a') : null;
        if (!a || !g.contains(a) || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
        e.preventDefault();
        open(g, a);
      });
      if (g.hasAttribute('data-noctx')) { document.addEventListener('contextmenu', function (e) { if (root && !root.hidden) { e.preventDefault(); } }); }
    });
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
