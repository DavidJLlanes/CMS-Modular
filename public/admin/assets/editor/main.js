/* LuxEditor · arranque: barra de herramientas, historial, recuperación, avisos, guardado (campo JSON) y atajos de teclado. */
(function (root) {
  'use strict';
  var LE = root.LuxEditor = root.LuxEditor || {};
  var M = LE.model, H = LE.html, D = LE.dom, R = LE.renderers;
  var TYPES = [['paragraph', 'Párrafo'], ['heading', 'Título'], ['list', 'Lista'], ['quote', 'Cita'], ['image', 'Imagen'], ['button', 'Botón'], ['separator', 'Separador'], ['columns', 'Columnas'], ['table', 'Tabla']];

  /** Se ejecuta dentro de la página de edición visual (un iframe del mismo origen con la plantilla real); habla con el formulario del panel. */
  function init(mount) {
    var frameEl = root.frameElement, rootEl = frameEl && frameEl.closest('[data-lux-editor]');
    if (!rootEl) { return; }
    var P = root.parent, hidden = rootEl.querySelector('[data-body-json]'), form = hidden && hidden.closest('form');
    if (!hidden || !form) { return; }
    var host = mount;
    var initial;
    try { initial = M.normalize(JSON.parse(hidden.value || '[]')); } catch (e) { return; } // si el JSON no se entiende, no se toma el control: el servidor conserva el contenido
    var csrfInput = form.querySelector('input[name=csrf]');
    var cfg = { list: rootEl.getAttribute('data-media-list'), upload: rootEl.getAttribute('data-media-upload'), thumb: rootEl.getAttribute('data-media-thumb'), csrf: csrfInput ? csrfInput.value : '' };
    var key = rootEl.getAttribute('data-key') || 'lux-editor';
    var base = JSON.stringify(initial);
    var history = new LE.History(base), saving = false, timers = {};

    var ed = { cfg: cfg, blocks: initial, selected: null, active: null };
    [].forEach.call(rootEl.querySelectorAll('.lux-fallback textarea'), function (t) { t.disabled = true; });
    hidden.name = 'body_json';
    document.documentElement.classList.add('lux-editing');
    // Los enlaces de la plantilla (menú, logo…) no deben sacar al editor de la página.
    document.addEventListener('click', function (e) { var a = e.target.closest && e.target.closest('a'); if (a) { e.preventDefault(); } }, true);
    document.addEventListener('submit', function (e) { e.preventDefault(); }, true);

    /* ---- estructura: barra fija arriba, ventana emergente para estilo/enlace/color/SEO, bloques sobre la propia página ---- */
    var live = D.el('div', { 'class': 'lux-ed-live', 'aria-live': 'polite', role: 'status' });
    var undoB = D.button('↶', 'Deshacer (Ctrl+Z)', function () { ed.undo(); }), redoB = D.button('↷', 'Rehacer (Ctrl+Y)', function () { ed.redo(); });
    function fmtBtn(label, title, cmd, cls) { return D.button(label, title, function () { ed.format(cmd); }, cls); }
    var addSel = D.select([['', '+ Bloque']].concat(TYPES), '', function (v) { if (v) { ed.addAfter(ed.selected ? [ed.selected[0]] : (ed.blocks.length ? [ed.blocks.length - 1] : null), v); addSel.value = ''; } });
    addSel.setAttribute('aria-label', 'Añadir bloque');
    var styleB = D.button('Estilo', 'Estilo del bloque seleccionado', function () { ed.pop('style'); }), lintB = D.button('SEO ✓', 'Revisión de SEO y accesibilidad', function () { ed.pop('lint'); });
    var tools = D.el('div', { 'class': 'lux-ed-toolbar', role: 'toolbar', 'aria-label': 'Herramientas de formato' }, [
      undoB, redoB,
      fmtBtn('N', 'Negrita (Ctrl+B)', 'bold', 'b'), fmtBtn('K', 'Cursiva (Ctrl+I)', 'italic', 'i'), fmtBtn('S', 'Subrayado (Ctrl+U)', 'underline', 'u'), fmtBtn('T', 'Tachado', 'strikeThrough', 's'),
      fmtBtn('x₂', 'Subíndice', 'subscript'), fmtBtn('x²', 'Superíndice', 'superscript'),
      D.button('🔗', 'Enlace (Ctrl+K)', function () { ed.askLink(); }), D.button('A▾', 'Color del texto seleccionado', function () { ed.askColor(); }),
      D.button('Tx', 'Quitar formato de la selección', function () { ed.clearFormat(); }), addSel, styleB, lintB
    ]);
    var popEl = D.el('div', { 'class': 'lux-ed-pop', hidden: true, role: 'region', 'aria-label': 'Opciones' }), popKind = '';
    var blocksEl = D.el('div', { 'class': 'lux-ed-blocks' });
    var addRow = D.el('div', { 'class': 'lux-ed-addrow', role: 'group', 'aria-label': 'Añadir bloque al final' });
    TYPES.forEach(function (t) { addRow.appendChild(D.button('+ ' + t[1], 'Añadir ' + t[1].toLowerCase(), function () { ed.addAfter(ed.selected ? [ed.selected[0]] : (ed.blocks.length ? [ed.blocks.length - 1] : null), t[0]); })); });
    var inspBody = D.el('div'), lintList = D.el('ul', { 'class': 'lux-ed-lintlist' });
    var banner = D.el('div', { 'class': 'lux-ed-banner', hidden: true });
    document.body.appendChild(tools); document.body.appendChild(popEl); document.body.appendChild(live);
    host.appendChild(banner); host.appendChild(blocksEl); host.appendChild(addRow);
    function layout() { var h = tools.offsetHeight; document.body.style.paddingTop = h + 'px'; popEl.style.top = h + 'px'; }
    root.addEventListener('resize', layout); layout();
    ed.pop = function (kind, node) {
      if (!kind || (popKind === kind && kind !== 'custom' && !popEl.hidden)) { popEl.hidden = true; popKind = ''; return; }
      popKind = kind; popEl.textContent = ''; popEl.hidden = false;
      popEl.appendChild(D.button('Cerrar', 'Cerrar', function () { ed.pop(null); }, 'lux-ed-popclose'));
      popEl.appendChild(kind === 'style' ? inspBody : (kind === 'lint' ? lintList : node));
      if (kind === 'style') { LE.inspector.render(ed, inspBody, ed.selected); }
    };

    /* ---- acceso al modelo por ruta: [i] o [i, columna, k] ---- */
    ed.list = function (path) { return path.length === 1 ? ed.blocks : ed.blocks[path[0]].cols[path[1]]; };
    ed.get = function (path) { var l = path ? ed.list(path) : null; return l ? l[path[path.length - 1]] || null : null; };
    function say(t) { live.textContent = ''; setTimeout(function () { live.textContent = t; }, 30); }

    /* ---- guardado en el campo, historial, recuperación y avisos ---- */
    function refreshState() { undoB.classList.toggle('is-off', !history.canUndo()); redoB.classList.toggle('is-off', !history.canRedo()); }
    function lintNow() {
      var w = LE.lint(ed.blocks);
      lintB.textContent = 'SEO ' + (w.length ? '(' + w.length + ')' : '✓');
      lintList.textContent = ''; if (!w.length) { lintList.appendChild(D.el('li', { text: 'Sin avisos de SEO ni accesibilidad.' })); }
      w.forEach(function (x) {
        var li = D.el('li', { 'class': 'is-' + x.level }, [D.el('button', { type: 'button', 'class': 'lux-ed-link', text: x.msg, onclick: function () { ed.pop(null); ed.focusPath(x.path.length === 1 ? x.path : x.path.slice(0, 1)); } })]);
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
      if (popKind === 'style') { LE.inspector.render(ed, inspBody, path); }
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
      var linkBox = D.el('div', { 'class': 'lux-ed-row' });
      var inp = D.el('input', { type: 'text', 'aria-label': 'Dirección del enlace', placeholder: '/ruta/ · https://… · mailto: · tel:', inputmode: 'url', autocapitalize: 'off', maxlength: '2000' });
      var err = D.el('span', { 'class': 'lux-ed-warn', role: 'alert' });
      function apply() { var h = M.safeHref(inp.value); if (!h) { err.textContent = 'Enlace no válido. Usa una ruta (/…), https://, mailto: o tel:.'; return; } restoreRange(); document.execCommand('createLink', false, h); ed.active.sync(true); ed.pop(null); }
      inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); apply(); } else if (e.key === 'Escape') { ed.pop(null); restoreRange(); } });
      [].forEach.call([inp, D.button('Aplicar', 'Aplicar enlace', apply), D.button('Quitar enlace', 'Quitar el enlace', function () { restoreRange(); document.execCommand('unlink', false, null); ed.active.sync(true); ed.pop(null); }), D.button('Cancelar', 'Cancelar', function () { ed.pop(null); restoreRange(); }), err], function (n) { linkBox.appendChild(n); });
      ed.pop('custom', linkBox); inp.focus();
    };
    function mapSelection(fn) { // aplica fn a los tramos de la selección y la sustituye por HTML saneado
      var r = savedRange; if (!r) { return; }
      var tmp = document.createElement('div'); tmp.appendChild(r.cloneContents());
      var rs = H.parseInline(tmp.innerHTML).map(function (x) { return fn(Object.assign({}, x)); });
      restoreRange(); document.execCommand('insertHTML', false, H.runsToHtml(rs)); ed.active.sync(true);
    }
    ed.askColor = function () {
      if (!saveRange() || savedRange.collapsed) { say('Selecciona primero el texto que quieres colorear.'); return; }
      var colorBox = D.el('div', { 'class': 'lux-ed-row' });
      var roles = { text: 'Texto', muted: 'Atenuado', primary: 'Principal', link: 'Enlace' };
      Object.keys(roles).forEach(function (r) { colorBox.appendChild(D.button(roles[r], 'Color ' + roles[r], function () { mapSelection(function (x) { x.c = r; return x; }); ed.pop(null); })); });
      colorBox.appendChild(D.el('input', { type: 'color', 'aria-label': 'Color personalizado', value: '#3366cc', onchange: function (e) { mapSelection(function (x) { x.c = e.target.value; return x; }); ed.pop(null); } }));
      colorBox.appendChild(D.button('Sin color', 'Quitar color', function () { mapSelection(function (x) { delete x.c; return x; }); ed.pop(null); }));
      ed.pop('custom', colorBox);
    };
    ed.clearFormat = function () { if (!saveRange() || savedRange.collapsed) { say('Selecciona primero el texto.'); return; } mapSelection(function (x) { return { t: x.t }; }); };

    /* ---- atajos globales dentro del editor ---- */
    document.addEventListener('keydown', function (e) {
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
    P.addEventListener('beforeunload', function (e) { if (!saving && hidden.value && hidden.value !== base && host.getAttribute('data-dirty') === '1') { e.preventDefault(); e.returnValue = ''; } });
    P.addEventListener('pagehide', function () { if (!saving) { flushTyping(); } });

    ed.lint = lintNow;
    ed.render({}); lintNow(); refreshState();
    rootEl.classList.add('is-ready');
    LE.instance = ed;
  }

  function start() { var m = document.getElementById('lux-editor-mount'); if (m) { init(m); } }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})(window);
