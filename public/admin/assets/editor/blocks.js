/* LuxEditor · bloques en pantalla: campos editables, listas, imágenes, botones, columnas y tablas. Cada bloque modifica el modelo y avisa al editor. */
(function (root) {
  'use strict';
  var LE = root.LuxEditor = root.LuxEditor || {};
  var M = LE.model, H = LE.html;

  function el(tag, props, kids) {
    var e = document.createElement(tag);
    Object.keys(props || {}).forEach(function (k) {
      var v = props[k];
      if (v === false || v == null) { return; }
      if (k === 'class') { e.className = v; } else if (k === 'text') { e.textContent = v; } else if (k.slice(0, 2) === 'on') { e.addEventListener(k.slice(2), v); } else { e.setAttribute(k, v === true ? '' : v); }
    });
    (kids || []).forEach(function (c) { if (c != null) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
    return e;
  }
  function button(label, title, onclick, cls) {
    return el('button', { type: 'button', 'class': 'lux-ed-btn' + (cls ? ' ' + cls : ''), title: title, 'aria-label': title, text: label, onclick: onclick, onmousedown: function (e) { e.preventDefault(); } });
  }
  function field(label, input) { return el('label', { 'class': 'lux-ed-field' }, [el('span', { text: label }), input]); }
  function select(opts, value, onchange) {
    var s = el('select', { onchange: function () { onchange(s.value); } });
    opts.forEach(function (o) { var op = el('option', { value: o[0], text: o[1] }); if (o[0] === value) { op.selected = true; } s.appendChild(op); });
    return s;
  }

  var TYPE_LABEL = { paragraph: 'Párrafo', heading: 'Título', quote: 'Cita', list: 'Lista', image: 'Imagen', gallery: 'Galería', button: 'Botón', separator: 'Separador', columns: 'Columnas', table: 'Tabla', extension: 'Extensión' };

  /** Campo de texto con formato en línea (contenteditable). Lo escrito se lee con el tokenizador con lista blanca, nunca como HTML. */
  function editable(ed, runs, opts) {
    var composing = false;
    var e = el('div', { 'class': 'lux-ed-text ' + (opts['class'] || ''), contenteditable: 'true', role: 'textbox', 'aria-multiline': opts.single ? 'false' : 'true', 'aria-label': opts.label || 'Texto', spellcheck: 'true', 'data-ph': opts.placeholder || '' });
    e.innerHTML = H.runsToHtml(runs);
    function read() { return H.parseInline(e.innerHTML); }
    function sync(now) { opts.onChange(read(), now); }
    e.addEventListener('input', function () { if (!composing) { sync(false); } });
    e.addEventListener('compositionstart', function () { composing = true; });
    e.addEventListener('compositionend', function () { composing = false; sync(false); });
    e.addEventListener('focus', function () { ed.active = { el: e, sync: sync }; ed.select(opts.path); });
    e.addEventListener('beforeinput', function (ev) {
      if (ev.inputType === 'historyUndo') { ev.preventDefault(); ed.undo(); } else if (ev.inputType === 'historyRedo') { ev.preventDefault(); ed.redo(); }
      else if (opts.single && (ev.inputType === 'insertParagraph' || ev.inputType === 'insertLineBreak')) { ev.preventDefault(); }
    });
    e.addEventListener('keydown', function (ev) {
      if (ev.isComposing || ev.keyCode === 229) { return; } // IME: Enter y flechas pertenecen al teclado de composición
      if (ev.key === 'Enter' && !ev.shiftKey && !ev.altKey && !ev.ctrlKey && !ev.metaKey && opts.onEnter) { ev.preventDefault(); opts.onEnter(); return; }
      if (ev.key === 'Backspace' && opts.onBackspaceEmpty && read().length === 0) { ev.preventDefault(); opts.onBackspaceEmpty(); return; }
      if ((ev.ctrlKey || ev.metaKey) && !ev.altKey && ev.key.toLowerCase() === 'k') { ev.preventDefault(); ed.askLink(); }
    });
    e.addEventListener('paste', function (ev) {
      var cd = ev.clipboardData; if (!cd) { return; }
      ev.preventDefault();
      var html = cd.getData('text/html'), text = cd.getData('text/plain');
      var blocks = html ? H.parseHtml(html) : H.parseText(text);
      if (!blocks.length && text) { blocks = H.parseText(text); }
      if (!blocks.length) { return; }
      var first = blocks[0], inline = !opts.single || (first.type === 'paragraph' || first.type === 'heading');
      if (inline && (first.type === 'paragraph' || first.type === 'heading' || first.type === 'quote')) {
        document.execCommand('insertHTML', false, H.runsToHtml(M.runsOf(first)));
        blocks = blocks.slice(1);
      } else if (opts.single && first.type !== 'paragraph') { document.execCommand('insertText', false, text.replace(/\s+/g, ' ')); blocks = []; }
      if (blocks.length && !opts.single && opts.path.length === 1) { ed.insertMany(opts.path, blocks); } else if (blocks.length && !opts.single) { ed.insertMany(opts.path, blocks.filter(function (b) { return M.NESTABLE.indexOf(b.type) >= 0; })); }
      sync(true);
    });
    e.addEventListener('drop', function (ev) { ev.preventDefault(); }); // arrastrar HTML ajeno al campo no está admitido
    return e;
  }

  var renderers = {};

  function frame(ed, b, path, body) {
    var top = path.length === 1, list = ed.list(path), i = path[path.length - 1];
    var typeSel = null;
    if (b.type === 'paragraph' || b.type === 'heading' || b.type === 'quote') {
      var cur = b.type === 'heading' ? 'h' + b.level : b.type;
      typeSel = select([['paragraph', 'Párrafo'], ['h1', 'Título 1'], ['h2', 'Título 2'], ['h3', 'Título 3'], ['h4', 'Título 4'], ['h5', 'Título 5'], ['h6', 'Título 6'], ['quote', 'Cita']], cur, function (v) { ed.convert(path, v); });
      typeSel.setAttribute('aria-label', 'Tipo de bloque');
    }
    var head = el('div', { 'class': 'lux-ed-head' }, [
      typeSel || el('strong', { 'class': 'lux-ed-type', text: TYPE_LABEL[b.type] || b.type }),
      el('span', { 'class': 'lux-ed-ops' }, [
        button('↑', 'Subir bloque (Alt+↑)', function () { ed.move(path, -1); }, i === 0 ? 'is-off' : ''),
        button('↓', 'Bajar bloque (Alt+↓)', function () { ed.move(path, 1); }, i === list.length - 1 ? 'is-off' : ''),
        button('⧉', 'Duplicar bloque (Alt+Mayús+D)', function () { ed.duplicate(path); }),
        button('✕', 'Borrar bloque (Alt+Supr)', function () { ed.remove(path); }, 'danger')
      ])
    ]);
    var s = el('section', { 'class': 'lux-blk lux-blk--' + b.type + (top ? '' : ' is-nested'), tabindex: '-1', 'data-path': path.join('.'), 'aria-label': (TYPE_LABEL[b.type] || b.type) + ' ' + (i + 1) + ' de ' + list.length }, [head, body]);
    s.addEventListener('focusin', function () { ed.select(path); });
    s.addEventListener('keydown', function (ev) {
      if (!ev.altKey || ev.ctrlKey || ev.metaKey) { return; }
      if (ev.key === 'ArrowUp') { ev.preventDefault(); ev.stopPropagation(); ed.move(path, -1); }
      else if (ev.key === 'ArrowDown') { ev.preventDefault(); ev.stopPropagation(); ed.move(path, 1); }
      else if (ev.shiftKey && ev.key.toLowerCase() === 'd') { ev.preventDefault(); ev.stopPropagation(); ed.duplicate(path); }
      else if (ev.key === 'Delete') { ev.preventDefault(); ev.stopPropagation(); ed.remove(path); }
    });
    return s;
  }

  function textBlock(ed, b, path, cls) {
    var body = [editable(ed, M.runsOf(b), { path: path, 'class': cls, label: TYPE_LABEL[b.type], placeholder: b.type === 'quote' ? 'Cita…' : 'Escribe aquí…',
      onChange: function (rs, now) { M.setRuns(b, rs); ed.commit(now); },
      onEnter: function () { ed.addAfter(path, 'paragraph'); },
      onBackspaceEmpty: function () { if (ed.list(path).length > 1) { ed.remove(path, true); } } })];
    if (b.type === 'quote') {
      body.push(el('input', { type: 'text', 'class': 'lux-ed-cite', maxlength: '200', placeholder: 'Autor o fuente (opcional)', 'aria-label': 'Autor o fuente de la cita', value: b.cite || '', oninput: function (e) { if (e.target.value.trim()) { b.cite = e.target.value.trim(); } else { delete b.cite; } ed.commit(false); } }));
    }
    return body;
  }
  renderers.paragraph = function (ed, b, path) { return frame(ed, b, path, el('div', { 'class': 'lux-ed-body' }, textBlock(ed, b, path, 'lux-ed-p'))); };
  renderers.heading = function (ed, b, path) { return frame(ed, b, path, el('div', { 'class': 'lux-ed-body' }, textBlock(ed, b, path, 'lux-ed-h lux-ed-h' + b.level))); };
  renderers.quote = function (ed, b, path) { return frame(ed, b, path, el('div', { 'class': 'lux-ed-body' }, textBlock(ed, b, path, 'lux-ed-q'))); };

  renderers.list = function (ed, b, path) {
    var ul = el(b.ordered ? 'ol' : 'ul', { 'class': 'lux-ed-list' });
    function item(it, idx) {
      var rs = typeof it === 'string' ? M.runs(it) : M.runs(it.inlines || []);
      var li = el('li');
      var e = editable(ed, rs, { path: path, 'class': 'lux-ed-li', label: 'Elemento ' + (idx + 1), single: true, placeholder: 'Elemento…',
        onChange: function (r, now) { b.items[idx] = M.cellOf({ inlines: r }); ed.commit(now); },
        onEnter: function () { b.items.splice(idx + 1, 0, ''); ed.commit(true); ed.render({ path: path, focus: 'li:' + (idx + 1) }); },
        onBackspaceEmpty: function () { if (b.items.length > 1) { b.items.splice(idx, 1); ed.commit(true); ed.render({ path: path, focus: 'li:' + Math.max(0, idx - 1) }); } else { ed.remove(path, true); } } });
      e.setAttribute('data-li', String(idx));
      li.appendChild(e); return li;
    }
    b.items.forEach(function (it, idx) { ul.appendChild(item(it, idx)); });
    var tools = el('div', { 'class': 'lux-ed-row' }, [
      button('+ Elemento', 'Añadir elemento', function () { b.items.push(''); ed.commit(true); ed.render({ path: path, focus: 'li:' + (b.items.length - 1) }); }),
      button(b.ordered ? 'Numerada ✓' : 'Con viñetas ✓', 'Cambiar entre lista con viñetas y numerada', function () { if (b.ordered) { delete b.ordered; } else { b.ordered = true; } ed.commit(true); ed.render({ path: path }); })
    ]);
    return frame(ed, b, path, el('div', { 'class': 'lux-ed-body' }, [ul, tools]));
  };

  renderers.image = function (ed, b, path) {
    var cfg = ed.cfg;
    var img = b.ref ? el('img', { 'class': 'lux-ed-img', src: cfg.thumb + encodeURIComponent(b.ref) + '/thumb', alt: b.alt || '', width: '240' }) : el('p', { 'class': 'lux-ed-empty', text: 'Ninguna imagen seleccionada.' });
    var warn = el('p', { 'class': 'lux-ed-warn', role: 'status', text: b.ref && !b.alt ? '⚠ Falta el texto alternativo: ayuda a la accesibilidad y al SEO.' : '' });
    var body = el('div', { 'class': 'lux-ed-body' }, [img, warn,
      el('div', { 'class': 'lux-ed-row' }, [button(b.ref ? 'Cambiar imagen' : 'Elegir imagen', 'Elegir o subir una imagen de la biblioteca', function () {
        LE.mediaPicker.open(cfg, function (it) { b.ref = it.ref; if (!b.alt) { b.alt = it.alt || ''; } if (!b.caption) { b.caption = it.caption || ''; } ed.commit(true); ed.render({ path: path }); });
      })]),
      field('Texto alternativo (describe la imagen)', el('input', { type: 'text', maxlength: '300', value: b.alt || '', oninput: function (e) { b.alt = e.target.value; warn.textContent = b.ref && !b.alt.trim() ? '⚠ Falta el texto alternativo: ayuda a la accesibilidad y al SEO.' : ''; ed.commit(false); } })),
      field('Pie de foto (opcional)', el('input', { type: 'text', maxlength: '500', value: b.caption || '', oninput: function (e) { b.caption = e.target.value; ed.commit(false); } }))]);
    return frame(ed, b, path, body);
  };

  renderers.gallery = function (ed, b, path) { return frame(ed, b, path, el('div', { 'class': 'lux-ed-body' }, [el('p', { 'class': 'lux-ed-empty', text: 'Galería «' + b.ref + '». Se publicará con el módulo de galerías (Fase 8); mientras tanto puedes moverla o quitarla.' })])); };
  renderers.extension = function (ed, b, path) { return frame(ed, b, path, el('div', { 'class': 'lux-ed-body' }, [el('p', { 'class': 'lux-ed-empty', text: 'Bloque de extensión «' + b.kind + '» (se conserva tal cual).' })])); };

  renderers.button = function (ed, b, path) {
    var prev = el('span', { 'class': 'lux-ed-btnprev lux-ed-btnprev--' + b.variant, text: b.label || 'Botón' });
    var body = el('div', { 'class': 'lux-ed-body' }, [el('p', {}, [prev]),
      field('Texto del botón', el('input', { type: 'text', maxlength: '80', value: b.label, oninput: function (e) { b.label = e.target.value; prev.textContent = b.label || 'Botón'; ed.commit(false); } })),
      field('Enlace (/ruta/, https://…, mailto:, tel:)', el('input', { type: 'text', maxlength: '2000', value: b.href, inputmode: 'url', autocapitalize: 'off', oninput: function (e) { b.href = e.target.value; ed.commit(false); } }))]);
    return frame(ed, b, path, body);
  };
  renderers.separator = function (ed, b, path) { return frame(ed, b, path, el('div', { 'class': 'lux-ed-body' }, [el('div', { 'class': 'lux-ed-sep lux-ed-sep--' + b.style, role: 'separator' })])); };

  renderers.columns = function (ed, b, path) {
    var grid = el('div', { 'class': 'lux-ed-cols lux-ed-cols--' + b.layout });
    b.cols.forEach(function (col, ci) {
      var wrap = el('div', { 'class': 'lux-ed-col', role: 'group', 'aria-label': 'Columna ' + (ci + 1) + ' de ' + b.cols.length });
      col.forEach(function (child, k) { wrap.appendChild(ed.renderBlock(child, path.concat([ci, k]))); });
      wrap.appendChild(el('div', { 'class': 'lux-ed-add' }, [select([['', '+ Añadir en esta columna']].concat(M.NESTABLE.map(function (t) { return [t, TYPE_LABEL[t]]; })), '', function (v) { if (v) { ed.addInto(path, ci, v); } })]));
      grid.appendChild(wrap);
    });
    return frame(ed, b, path, el('div', { 'class': 'lux-ed-body' }, [grid]));
  };

  renderers.table = function (ed, b, path) {
    var pos = { r: 0, c: 0 };
    var tbl = el('table', { 'class': 'lux-ed-table' });
    if (b.caption) { tbl.appendChild(el('caption', { text: b.caption })); }
    b.rows.forEach(function (row, r) {
      var tr = el('tr');
      row.forEach(function (cell, c) {
        var th = (b.header_row && r === 0) || (b.header_col && c === 0);
        var td = el(th ? 'th' : 'td', { 'class': b.aligns[c] !== 'left' ? 'lux-a-' + b.aligns[c] : null });
        var e = editable(ed, M.runs(typeof cell === 'string' ? cell : (cell.inlines || [])), { path: path, 'class': 'lux-ed-cell', label: 'Fila ' + (r + 1) + ', columna ' + (c + 1), single: true,
          onChange: function (rs, now) { b.rows[r][c] = M.cellOf({ inlines: rs }); ed.commit(now); } });
        e.addEventListener('focus', function () { pos.r = r; pos.c = c; });
        e.addEventListener('keydown', function (ev) { // Tab recorre las celdas; Enter baja una fila
          if (ev.isComposing || ev.keyCode === 229) { return; }
          if (ev.key === 'Enter' || ev.key === 'Tab') {
            var cells = tbl.querySelectorAll('.lux-ed-cell'), idx = r * row.length + c, nx = ev.key === 'Tab' ? idx + (ev.shiftKey ? -1 : 1) : idx + row.length;
            if (cells[nx]) { ev.preventDefault(); cells[nx].focus(); } else if (ev.key === 'Tab' && !ev.shiftKey) { ev.preventDefault(); ed.tableOp(b, path, 'addRow', pos); }
          }
        });
        td.appendChild(e); tr.appendChild(td);
      });
      tbl.appendChild(tr);
    });
    var tools = el('div', { 'class': 'lux-ed-row' }, [
      button('+ Fila', 'Añadir fila debajo de la celda activa', function () { ed.tableOp(b, path, 'addRow', pos); }),
      button('− Fila', 'Quitar la fila de la celda activa', function () { ed.tableOp(b, path, 'delRow', pos); }),
      button('+ Columna', 'Añadir columna a la derecha de la celda activa', function () { ed.tableOp(b, path, 'addCol', pos); }),
      button('− Columna', 'Quitar la columna de la celda activa', function () { ed.tableOp(b, path, 'delCol', pos); }),
      button('Alinear columna', 'Cambiar la alineación de la columna de la celda activa', function () { ed.tableOp(b, path, 'align', pos); })
    ]);
    var opts = el('div', { 'class': 'lux-ed-row' }, [
      el('label', { 'class': 'lux-ed-check' }, [el('input', { type: 'checkbox', checked: b.header_row, onchange: function (e) { b.header_row = e.target.checked; ed.commit(true); ed.render({ path: path }); } }), ' Fila de encabezados']),
      el('label', { 'class': 'lux-ed-check' }, [el('input', { type: 'checkbox', checked: b.header_col, onchange: function (e) { b.header_col = e.target.checked; ed.commit(true); ed.render({ path: path }); } }), ' Columna de encabezados'])
    ]);
    var cap = field('Título de la tabla (leyenda)', el('input', { type: 'text', maxlength: '200', value: b.caption, oninput: function (e) { b.caption = e.target.value; ed.commit(false); } }));
    return frame(ed, b, path, el('div', { 'class': 'lux-ed-body' }, [cap, el('div', { 'class': 'lux-ed-tablewrap', role: 'region', tabindex: '0', 'aria-label': 'Tabla, desplazable' }, [tbl]), tools, opts]));
  };

  LE.dom = { el: el, button: button, field: field, select: select, TYPE_LABEL: TYPE_LABEL };
  LE.renderers = renderers;
})(window);
