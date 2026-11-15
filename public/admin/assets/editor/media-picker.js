/* LuxEditor · selector de imágenes de la biblioteca (búsqueda, paginación, subida) en un diálogo accesible con trampa de foco. */
(function (root) {
  'use strict';
  var LE = root.LuxEditor = root.LuxEditor || {};
  var D = LE.dom;

  function open(cfg, done) {
    var opener = document.activeElement, page = 1, q = '';
    var list = D.el('ul', { 'class': 'lux-mp-grid' }), msg = D.el('p', { role: 'status', 'class': 'lux-mp-msg' }), pager = D.el('div', { 'class': 'lux-ed-row' });
    var search = D.el('input', { type: 'search', 'aria-label': 'Buscar imágenes', placeholder: 'Buscar por nombre o texto alternativo', maxlength: '80' });
    var file = D.el('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', 'aria-label': 'Subir una imagen nueva' });
    var close = D.button('Cerrar', 'Cerrar el selector', function () { shut(); });
    var box = D.el('div', { 'class': 'lux-mp', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Elegir imagen' }, [
      D.el('div', { 'class': 'lux-ed-row' }, [D.el('strong', { text: 'Elegir imagen' }), close]),
      D.el('div', { 'class': 'lux-ed-row' }, [search, D.button('Buscar', 'Buscar', function () { q = search.value; page = 1; load(); })]),
      D.el('div', { 'class': 'lux-ed-row' }, [D.el('label', { 'class': 'lux-ed-field' }, [D.el('span', { text: 'Subir imagen nueva' }), file])]),
      msg, list, pager]);
    var overlay = D.el('div', { 'class': 'lux-mp-overlay' }, [box]);
    function shut() { document.removeEventListener('keydown', onKey, true); overlay.remove(); if (opener && opener.focus) { opener.focus(); } }
    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); shut(); return; }
      if (e.key !== 'Tab') { return; }
      var f = box.querySelectorAll('button,input,select,a[href]'), a = [].filter.call(f, function (n) { return !n.disabled; });
      if (!a.length) { return; }
      if (e.shiftKey && document.activeElement === a[0]) { e.preventDefault(); a[a.length - 1].focus(); } else if (!e.shiftKey && document.activeElement === a[a.length - 1]) { e.preventDefault(); a[0].focus(); }
    }
    function pick(it) { shut(); done(it); }
    function load() {
      msg.textContent = 'Cargando…';
      fetch(cfg.list + '?q=' + encodeURIComponent(q) + '&page=' + page, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          list.textContent = ''; pager.textContent = '';
          if (!j.ok) { msg.textContent = 'No se pudo cargar la biblioteca.'; return; }
          msg.textContent = j.total ? j.total + ' imagen(es)' : 'No hay imágenes. Sube una desde aquí.';
          j.items.forEach(function (it) {
            var b = D.el('button', { type: 'button', 'class': 'lux-mp-item', onclick: function () { pick(it); }, 'aria-label': 'Elegir ' + (it.title || it.ref) }, [D.el('img', { src: it.thumb, alt: '', width: '120', loading: 'lazy' }), D.el('span', { text: it.title || it.ref })]);
            list.appendChild(D.el('li', {}, [b]));
          });
          var pages = Math.ceil(j.total / j.per_page);
          if (page > 1) { pager.appendChild(D.button('← Anterior', 'Página anterior', function () { page--; load(); })); }
          if (page < pages) { pager.appendChild(D.button('Siguiente →', 'Página siguiente', function () { page++; load(); })); }
        }).catch(function () { msg.textContent = 'Sin conexión con la biblioteca.'; });
    }
    file.addEventListener('change', function () {
      if (!file.files || !file.files[0]) { return; }
      var fd = new FormData(); fd.append('csrf', cfg.csrf); fd.append('files[]', file.files[0]);
      msg.textContent = 'Subiendo y preparando la imagen…';
      fetch(cfg.upload, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (j.ok) { pick(j.item); } else { msg.textContent = j.error || 'No se pudo subir la imagen.'; } })
        .catch(function () { msg.textContent = 'Fallo de red al subir la imagen.'; });
    });
    search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); q = search.value; page = 1; load(); } });
    document.addEventListener('keydown', onKey, true);
    document.body.appendChild(overlay);
    search.focus(); load();
  }
  /** Selector de galerías existentes (las crea y edita el panel de galerías). */
  function galleries(cfg, cb) {
    fetch(cfg.galleries, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) { cb(j); }).catch(function () { cb(null); });
  }
  function openGalleries(cfg, done) {
    var opener = document.activeElement;
    var list = D.el('ul', { 'class': 'lux-mp-grid' }), msg = D.el('p', { role: 'status', 'class': 'lux-mp-msg' });
    var close = D.button('Cerrar', 'Cerrar el selector', function () { shut(); });
    var box = D.el('div', { 'class': 'lux-mp', role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Elegir galería' }, [
      D.el('div', { 'class': 'lux-ed-row' }, [D.el('strong', { text: 'Elegir galería' }), close]),
      D.el('p', { 'class': 'lux-mp-msg' }, [D.el('a', { href: cfg.galleryEdit ? cfg.galleryEdit.replace(/\/[^\/]*$/, '') : '#', target: '_blank', rel: 'noopener', text: 'Crear o gestionar galerías ↗' })]), msg, list]);
    var overlay = D.el('div', { 'class': 'lux-mp-overlay' }, [box]);
    function shut() { document.removeEventListener('keydown', onKey, true); overlay.remove(); if (opener && opener.focus) { opener.focus(); } }
    function onKey(e) {
      if (e.key === 'Escape') { e.preventDefault(); shut(); return; }
      if (e.key !== 'Tab') { return; }
      var a = [].filter.call(box.querySelectorAll('button,input,select,a[href]'), function (n) { return !n.disabled; });
      if (!a.length) { return; }
      if (e.shiftKey && document.activeElement === a[0]) { e.preventDefault(); a[a.length - 1].focus(); } else if (!e.shiftKey && document.activeElement === a[a.length - 1]) { e.preventDefault(); a[0].focus(); }
    }
    document.addEventListener('keydown', onKey, true);
    document.body.appendChild(overlay);
    msg.textContent = 'Cargando…';
    galleries(cfg, function (j) {
      list.textContent = '';
      if (!j || !j.ok) { msg.textContent = 'No se pudieron cargar las galerías.'; return; }
      msg.textContent = j.items.length ? j.items.length + ' galería(s)' : 'Aún no hay galerías: créalas desde el enlace de arriba.';
      j.items.forEach(function (it) {
        var b = D.el('button', { type: 'button', 'class': 'lux-mp-item', onclick: function () { shut(); done(it); }, 'aria-label': 'Elegir ' + it.title }, [it.thumb ? D.el('img', { src: it.thumb, alt: '', width: '120', loading: 'lazy' }) : D.el('span', { text: '(vacía)' }), D.el('span', { text: it.title + ' · ' + it.count })]);
        list.appendChild(D.el('li', {}, [b]));
      });
      var first = list.querySelector('button'); if (first) { first.focus(); } else { close.focus(); }
    });
  }
  LE.mediaPicker = { open: open };
  LE.galleryPicker = { open: openGalleries, info: function (cfg, cb) { galleries(cfg, function (j) { var m = {}; if (j && j.ok) { j.items.forEach(function (i) { m[i.ref] = i; }); } cb(m); }); } };
})(window);
