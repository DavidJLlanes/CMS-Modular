/* LuxEditor · arranque: barra de herramientas, historial, recuperación, avisos, guardado (campo JSON) y atajos de teclado. */
(function (root) {
  'use strict';
  var LE = root.LuxEditor = root.LuxEditor || {};
  var M = LE.model, H = LE.html, D = LE.dom, R = LE.renderers;
  var TYPES = [['paragraph', 'Párrafo'], ['heading', 'Título'], ['list', 'Lista'], ['quote', 'Cita'], ['image', 'Imagen'], ['button', 'Botón'], ['separator', 'Separador'], ['columns', 'Columnas'], ['table', 'Tabla']];

  function init(host) {
    var hidden = host.querySelector('[data-body-json]'), form = host.closest('form');
    if (!hidden || !form) { return; }
    var initial;
    try { initial = M.normalize(JSON.parse(hidden.value || '[]')); } catch (e) { return; } // si el JSON no se entiende, no se toma el control: el servidor conserva el contenido
    var csrfInput = form.querySelector('input[name=csrf]');
    var cfg = { list: host.getAttribute('data-media-list'), upload: host.getAttribute('data-media-upload'), thumb: host.getAttribute('data-media-thumb'), csrf: csrfInput ? csrfInput.value : '' };
    var key = host.getAttribute('data-key') || 'lux-editor';
    var base = JSON.stringify(initial);
    var history = new LE.History(base), saving = false, timers = {};

    var ed = { cfg: cfg, blocks: initial, selected: null, active: null };
    var fallback = host.querySelector('.lux-fallback');
    if (fallback) { fallback.hidden = true; [].forEach.call(fallback.querySelectorAll('textarea'), function (t) { t.disabled = true; }); }
    hidden.name = 'body_json';

    /* ---- estructura de la pantalla ---- */
    var live = D.el('div', { 'class': 'lux-ed-live', 'aria-live': 'polite', role: 'status' });
    var undoB = D.button('↶', 'Deshacer (Ctrl+Z)', function () { ed.undo(); }), redoB = D.button('↷', 'Rehacer (Ctrl+Y)', function () { ed.redo(); });
    function fmtBtn(label, title, cmd, cls) { return D.button(label, title, function () { ed.format(cmd); }, cls); }
    var linkBox = D.el('div', { 'class': 'lux-ed-row lux-ed-linkbox', hidden: true });
    var colorBox = D.el('div', { 'class': 'lux-ed-row lux-ed-linkbox', hidden: true });
    var addSel = D.select([['', '+ Añadir bloque']].concat(TYPES), '', function (v) { if (v) { ed.addAfter(ed.selected || (ed.blocks.length ? [ed.blocks.length - 1] : null), v); addSel.value = ''; } });
    addSel.setAttribute('aria-label', 'Añadir bloque');
    var tools = D.el('div', { 'class': 'lux-ed-toolbar', role: 'toolbar', 'aria-label': 'Herramientas de formato' }, [
      undoB, redoB,
      fmtBtn('N', 'Negrita (Ctrl+B)', 'bold', 'b'), fmtBtn('K', 'Cursiva (Ctrl+I)', 'italic', 'i'), fmtBtn('S', 'Subrayado (Ctrl+U)', 'underline', 'u'), fmtBtn('T', 'Tachado', 'strikeThrough', 's'),
      fmtBtn('x₂', 'Subíndice', 'subscript'), fmtBtn('x²', 'Superíndice', 'superscript'),
      D.button('🔗', 'Enlace (Ctrl+K)', function () { ed.askLink(); }), D.button('A▾', 'Color del texto seleccionado', function () { ed.askColor(); }),
      D.button('Tx', 'Quitar formato de la selección', function () { ed.clearFormat(); }), addSel
    ]);
    var blocksEl = D.el('div', { 'class': 'lux-ed-blocks' });
    var addRow = D.el('div', { 'class': 'lux-ed-row lux-ed-addrow', role: 'group', 'aria-label': 'Añadir bloque al final' });
    TYPES.forEach(function (t) { addRow.appendChild(D.button('+ ' + t[1], 'Añadir ' + t[1].toLowerCase(), function () { ed.addAfter(ed.selected ? [ed.selected[0]] : (ed.blocks.length ? [ed.blocks.length - 1] : null), t[0]); })); });
    var inspectorEl = D.el('details', { 'class': 'lux-ed-inspector', open: true });
    var inspBody = D.el('div'); inspectorEl.appendChild(D.el('summary', { text: 'Estilo del bloque' })); inspectorEl.appendChild(inspBody);
    var lintEl = D.el('details', { 'class': 'lux-ed-lint' }); var lintSum = D.el('summary', { text: 'Revisión de SEO y accesibilidad' }); var lintList = D.el('ul'); lintEl.appendChild(lintSum); lintEl.appendChild(lintList);
    var banner = D.el('div', { 'class': 'lux-ed-banner', hidden: true });
    var help = D.el('p', { 'class': 'muted lux-ed-help', text: 'Atajos: Ctrl+B/I/U formato · Ctrl+K enlace · Ctrl+Z/Y deshacer y rehacer · Alt+↑/↓ mover bloque · Alt+Mayús+D duplicar · Alt+Supr borrar · Intro nuevo bloque · Mayús+Intro salto de línea.' });
    host.appendChild(banner); host.appendChild(tools); host.appendChild(linkBox); host.appendChild(colorBox); host.appendChild(blocksEl); host.appendChild(addRow); host.appendChild(inspectorEl); host.appendChild(lintEl); host.appendChild(help); host.appendChild(live);

    /* ---- acceso al modelo por ruta: [i] o [i, columna, k] ---- */
    ed.list = function (path) { return path.length === 1 ? ed.blocks : ed.blocks[path[0]].cols[path[1]]; };
    ed.get = function (path) { var l = path ? ed.list(path) : null; return l ? l[path[path.length - 1]] || null : null; };
    function say(t) { live.textContent = ''; setTimeout(function () { live.textContent = t; }, 30); }

    /* ---- guardado en el campo, historial, recuperación y avisos ---- */
    function refreshState() { undoB.classList.toggle('is-off', !history.canUndo()); redoB.classList.toggle('is-off', !history.canRedo()); }
    function lintNow() {
      var w = LE.lint(ed.blocks);
      lintSum.textContent = 'Revisión de SEO y accesibilidad' + (w.length ? ' (' + w.length + ')' : ' ✓');
      lintList.textContent = '';
      w.forEach(function (x) {
        var li = D.el('li', { 'class': 'is-' + x.level }, [D.el('button', { type: 'button', 'class': 'lux-ed-link', text: x.msg, onclick: function () { ed.focusPath(x.path.length === 1 ? x.path : x.path.slice(0, 1)); } })]);
        lintList.appendChild(li);
      });
    }
    ed.commit = function (now) {
      var json = M.serialize(ed.blocks); hidden.value = json;
      clearTimeout(timers.h); clearTimeout(timers.l);
      var push = function () { if (history.push(json)) { LE.storage.save(key, base, json); } refreshState(); };
      if (now) { push(); } else { timers.h = setTimeout(push, 600); }
      timers.l = setTimeout(lintNow, 500);
      host.setAttribute('data-dirty', json === base ? '0' : '1');
    };
    ed.restore = function (json) { ed.blocks = JSON.parse(json); hidden.value = json; ed.render({}); LE.storage.save(key, base, json); lintNow(); refreshState(); };
    ed.undo = function () { flushTyping(); var s = history.undo(); if (s !== null) { ed.restore(s); say('Deshecho'); } };
    ed.redo = function () { flushTyping(); var s = history.redo(); if (s !== null) { ed.restore(s); say('Rehecho'); } };
    function flushTyping() { if (timers.h) { clearTimeout(timers.h); timers.h = 0; var j = M.serialize(ed.blocks); if (history.push(j)) { LE.storage.save(key, base, j); } } }

    /* ---- dibujo ---- */
    ed.renderBlock = function (b, path) { return R[b.type] ? R[b.type](ed, b, path) : D.el('p', { text: 'Bloque no soportado' }); };
    ed.render = function (opts) {
      opts = opts || {};
      var y = root.scrollY;
      blocksEl.textContent = '';
      if (!ed.blocks.length) { blocksEl.appendChild(D.el('p', { 'class': 'lux-ed-empty', text: 'Aún no hay contenido. Añade un bloque con los botones de abajo.' })); }
      ed.blocks.forEach(function (b, i) { blocksEl.appendChild(ed.renderBlock(b, [i])); });
      if (opts.path) { ed.focusPath(opts.path, opts.focus); } else { ed.select(null); }
      root.scrollTo(0, y);
    };
    ed.select = function (path) {
      var key2 = path ? path.join('.') : '';
      if ((ed.selected ? ed.selected.join('.') : '') === key2 && inspBody.firstChild) { return; }
      ed.selected = path;
      [].forEach.call(blocksEl.querySelectorAll('.lux-blk.is-sel'), function (n) { n.classList.remove('is-sel'); });
      var node = path ? blocksEl.querySelector('[data-path="' + path.join('.') + '"]') : null;
      if (node) { node.classList.add('is-sel'); }
      LE.inspector.render(ed, inspBody, path);
    };
    ed.focusPath = function (path, focus) {
      var node = blocksEl.querySelector('[data-path="' + path.join('.') + '"]');
      if (!node) { return; }
      var t = null;
      if (focus && focus.indexOf('li:') === 0) { t = node.querySelector('[data-li="' + focus.slice(3) + '"]'); }
      t = t || node.querySelector('[contenteditable=true], input, select, button.lux-ed-btn:not(.is-off)');
      (t || node).focus(); ed.selected = null; ed.select(path);
      if (node.scrollIntoView) { node.scrollIntoView({ block: 'nearest' }); }
    };

    /* ---- operaciones sobre bloques ---- */
    ed.addAfter = function (path, type) {
      var nb = M.empty(type); if (!nb) { return; }
      var list = path ? ed.list(path) : ed.blocks, idx = path ? path[path.length - 1] + 1 : 0, np = path ? path.slice(0, -1).concat([idx]) : [0];
      list.splice(idx, 0, nb); ed.commit(true); ed.render({ path: np }); say(D.TYPE_LABEL[type] + ' añadido');
    };
    ed.addInto = function (path, ci, type) {
      var nb = M.empty(type); if (!nb) { return; }
      var col = ed.get(path).cols[ci]; col.push(nb); ed.commit(true); ed.render({ path: path.concat([ci, col.length - 1]) });
    };
    ed.insertMany = function (path, blocks) {
      var list = ed.list(path), idx = path[path.length - 1] + 1;
      blocks.forEach(function (b, k) { list.splice(idx + k, 0, b); });
      ed.commit(true); ed.render({ path: path.slice(0, -1).concat([idx + blocks.length - 1]) }); say(blocks.length + ' bloque(s) pegado(s)');
    };
    ed.move = function (path, d) {
      var list = ed.list(path), i = path[path.length - 1], j = i + d;
      if (j < 0 || j >= list.length) { return; }
      var t = list[i]; list[i] = list[j]; list[j] = t; ed.commit(true);
      var np = path.slice(0, -1).concat([j]); ed.render({ path: np }); say('Bloque movido a la posición ' + (j + 1) + ' de ' + list.length);
    };
    ed.duplicate = function (path) { var list = ed.list(path), i = path[path.length - 1]; list.splice(i + 1, 0, M.clone(list[i])); ed.commit(true); ed.render({ path: path.slice(0, -1).concat([i + 1]) }); say('Bloque duplicado'); };
    ed.remove = function (path, quiet) {
      var list = ed.list(path), i = path[path.length - 1]; list.splice(i, 1); ed.commit(true);
      var np = list.length ? path.slice(0, -1).concat([Math.max(0, i - 1)]) : (path.length > 1 ? path.slice(0, 1) : null);
      ed.render(np ? { path: np } : {}); say('Bloque borrado');
    };
    ed.convert = function (path, v) {
      var b = ed.get(path), rs = M.runsOf(b), nb;
      if (v === 'paragraph' || v === 'quote') { nb = { type: v }; } else { nb = { type: 'heading', level: +v[1] }; }
      if (b.align) { nb.align = b.align; }
      M.setRuns(nb, rs); ed.list(path)[path[path.length - 1]] = nb; ed.commit(true); ed.render({ path: path });
    };
    ed.setLayout = function (path, layout) {
      var b = ed.get(path), n = M.LAYOUTS[layout];
      while (b.cols.length < n) { b.cols.push([{ type: 'paragraph', text: '' }]); }
      while (b.cols.length > n) { var extra = b.cols.pop(); b.cols[n - 1] = b.cols[n - 1].concat(extra); } // no se pierde contenido: pasa a la última columna
      b.layout = layout; ed.commit(true); ed.render({ path: path });
    };
    ed.tableOp = function (b, path, op, pos) {
      var w = b.rows[0].length;
      if (op === 'addRow' && b.rows.length < 50) { var nr = []; for (var i = 0; i < w; i++) { nr.push(''); } b.rows.splice(pos.r + 1, 0, nr); }
      else if (op === 'delRow' && b.rows.length > 1) { b.rows.splice(pos.r, 1); }
      else if (op === 'addCol' && w < 12) { b.rows.forEach(function (r) { r.splice(pos.c + 1, 0, ''); }); b.aligns.splice(pos.c + 1, 0, 'left'); }
      else if (op === 'delCol' && w > 1) { b.rows.forEach(function (r) { r.splice(pos.c, 1); }); b.aligns.splice(pos.c, 1); }
      else if (op === 'align') { var o = ['left', 'center', 'right']; b.aligns[pos.c] = o[(o.indexOf(b.aligns[pos.c]) + 1) % 3]; }
      else { say('No se puede hacer esa operación en la tabla.'); return; }
      ed.commit(true); ed.render({ path: path });
    };

    /* ---- formato en línea ---- */
    function selectionInside() {
      var s = root.getSelection && root.getSelection();
      return ed.active && ed.active.el.isConnected && s && s.rangeCount && ed.active.el.contains(s.anchorNode) ? s : null;
    }
    ed.format = function (cmd) {
      if (!ed.active || !ed.active.el.isConnected) { say('Haz clic en un texto para darle formato.'); return; }
      ed.active.el.focus(); document.execCommand(cmd, false, null); ed.active.sync(true);
    };
    var savedRange = null;
    function saveRange() { var s = selectionInside(); savedRange = s ? s.getRangeAt(0).cloneRange() : null; return savedRange; }
    function restoreRange() { if (savedRange && ed.active) { ed.active.el.focus(); var s = root.getSelection(); s.removeAllRanges(); s.addRange(savedRange); } }
    ed.askLink = function () {
      if (!saveRange()) { say('Selecciona el texto que quieres enlazar.'); return; }
      colorBox.hidden = true; linkBox.hidden = false; linkBox.textContent = '';
      var inp = D.el('input', { type: 'text', 'aria-label': 'Dirección del enlace', placeholder: '/ruta/ · https://… · mailto: · tel:', inputmode: 'url', autocapitalize: 'off', maxlength: '2000' });
      var err = D.el('span', { 'class': 'lux-ed-warn', role: 'alert' });
      function apply() { var h = M.safeHref(inp.value); if (!h) { err.textContent = 'Enlace no válido. Usa una ruta (/…), https://, mailto: o tel:.'; return; } restoreRange(); document.execCommand('createLink', false, h); ed.active.sync(true); linkBox.hidden = true; }
      inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); apply(); } else if (e.key === 'Escape') { linkBox.hidden = true; restoreRange(); } });
      [].forEach.call([inp, D.button('Aplicar', 'Aplicar enlace', apply), D.button('Quitar enlace', 'Quitar el enlace', function () { restoreRange(); document.execCommand('unlink', false, null); ed.active.sync(true); linkBox.hidden = true; }), D.button('Cancelar', 'Cancelar', function () { linkBox.hidden = true; restoreRange(); }), err], function (n) { linkBox.appendChild(n); });
      inp.focus();
    };
    function mapSelection(fn) { // aplica fn a los tramos de la selección y la sustituye por HTML saneado
      var r = savedRange; if (!r) { return; }
      var tmp = document.createElement('div'); tmp.appendChild(r.cloneContents());
      var rs = H.parseInline(tmp.innerHTML).map(function (x) { return fn(Object.assign({}, x)); });
      restoreRange(); document.execCommand('insertHTML', false, H.runsToHtml(rs)); ed.active.sync(true);
    }
    ed.askColor = function () {
      if (!saveRange() || savedRange.collapsed) { say('Selecciona primero el texto que quieres colorear.'); return; }
      linkBox.hidden = true; colorBox.hidden = false; colorBox.textContent = '';
      var roles = { text: 'Texto', muted: 'Atenuado', primary: 'Principal', link: 'Enlace' };
      Object.keys(roles).forEach(function (r) { colorBox.appendChild(D.button(roles[r], 'Color ' + roles[r], function () { mapSelection(function (x) { x.c = r; return x; }); colorBox.hidden = true; })); });
      colorBox.appendChild(D.el('input', { type: 'color', 'aria-label': 'Color personalizado', value: '#3366cc', onchange: function (e) { mapSelection(function (x) { x.c = e.target.value; return x; }); colorBox.hidden = true; } }));
      colorBox.appendChild(D.button('Sin color', 'Quitar color', function () { mapSelection(function (x) { delete x.c; return x; }); colorBox.hidden = true; }));
      colorBox.appendChild(D.button('Cerrar', 'Cerrar', function () { colorBox.hidden = true; restoreRange(); }));
    };
    ed.clearFormat = function () { if (!saveRange() || savedRange.collapsed) { say('Selecciona primero el texto.'); return; } mapSelection(function (x) { return { t: x.t }; }); };

    /* ---- atajos globales dentro del editor ---- */
    host.addEventListener('keydown', function (e) {
      var mod = e.ctrlKey || e.metaKey;
      if (!mod || e.altKey) { return; }
      var k = e.key.toLowerCase();
      if (k === 'z' && !e.shiftKey) { e.preventDefault(); ed.undo(); } else if (k === 'y' || (k === 'z' && e.shiftKey)) { e.preventDefault(); ed.redo(); }
    });

    /* ---- recuperación tras recarga, aviso al salir y envío ---- */
    var rec = LE.storage.load(key, base);
    if (rec) {
      banner.hidden = false;
      banner.appendChild(D.el('span', { text: 'Hay cambios de esta sesión sin guardar (' + new Date(rec.at).toLocaleString() + '). ' }));
      banner.appendChild(D.button('Recuperarlos', 'Recuperar los cambios sin guardar', function () { history.push(rec.json); ed.restore(rec.json); banner.hidden = true; say('Cambios recuperados'); }));
      banner.appendChild(D.button('Descartarlos', 'Descartar los cambios sin guardar', function () { LE.storage.clear(key); banner.hidden = true; }));
    }
    form.addEventListener('submit', function () { flushTyping(); hidden.value = M.serialize(ed.blocks); saving = true; LE.storage.clear(key); });
    root.addEventListener('beforeunload', function (e) { if (!saving && hidden.value && hidden.value !== base && host.getAttribute('data-dirty') === '1') { e.preventDefault(); e.returnValue = ''; } });
    root.addEventListener('pagehide', function () { if (!saving) { flushTyping(); } });

    ed.lint = lintNow;
    ed.render({}); lintNow(); refreshState();
    host.classList.add('is-ready');
    LE.instance = ed;
  }

  function start() { [].forEach.call(document.querySelectorAll('[data-lux-editor]'), init); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})(window);
