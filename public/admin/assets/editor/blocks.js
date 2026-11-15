/* LuxEditor · bloques WYSIWYG: cada bloque se dibuja con las etiquetas y clases reales de la plantilla (la página se edita tal como se verá).
   Los controles del bloque (mover, duplicar, borrar, tipo) aparecen solo al pasar o al seleccionar. */
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

  /** Clases del tema para alineación y tipografía (las mismas que genera el servidor). El color libre se aplica con CSSOM, que la CSP permite. */
  function themeCls(b) {
    var c = [];
    if (b.align) { c.push('lux-a-' + b.align); }
    Object.keys(b.style || {}).forEach(function (k) {
      var v = b.style[k];
      if (k === 'italic') { c.push('lux-t-italic'); } else if (k === 'color') { if (v[0] !== '#') { c.push('lux-c-' + v); } } else { c.push('lux-t-' + k + '-' + v); }
    });
    return c.join(' ');
  }
  function themeColor(e, b) { if (b.style && b.style.color && b.style.color[0] === '#') { e.style.color = b.style.color; } }

  /** Campo con formato en línea. `tag` es la etiqueta real del sitio (p, h2, li…); lo escrito se lee con el tokenizador con lista blanca. */
  function editable(ed, runs, opts) {
    var composing = false;
    var e = el(opts.tag || 'div', { 'class': 'lux-ed-text ' + (opts['class'] || ''), contenteditable: 'true', role: 'textbox', 'aria-multiline': opts.single ? 'false' : 'true', 'aria-label': opts.label || 'Texto', spellcheck: 'true', 'data-ph': opts.placeholder || '' });
    if (opts.tag === 'li') { e.removeAttribute('role'); }
    e.innerHTML = H.runsToHtml(runs);
    function read() { return opts.plain ? (e.textContent ? [{ t: e.textContent.replace(/\s+/g, ' ').trim() }] : []) : H.parseInline(e.innerHTML); }
    function sync(now) { opts.onChange(read(), now); }
    e.addEventListener('input', function () { if (!composing) { sync(false); } });
    e.addEventListener('compositionstart', function () { composing = true; });
    e.addEventListener('compositionend', function () { composing = false; sync(false); });
    e.addEventListener('focus', function () { ed.active = { el: e, sync: sync, plain: !!opts.plain }; ed.select(opts.path); });
    e.addEventListener('beforeinput', function (ev) {
      if (ev.inputType === 'historyUndo') { ev.preventDefault(); ed.undo(); } else if (ev.inputType === 'historyRedo') { ev.preventDefault(); ed.redo(); }
      else if (opts.single && (ev.inputType === 'insertParagraph' || ev.inputType === 'insertLineBreak')) { ev.preventDefault(); }
    });
    e.addEventListener('keydown', function (ev) {
      if (ev.isComposing || ev.keyCode === 229) { return; } // IME: Intro y flechas pertenecen al teclado de composición
      if (ev.key === 'Enter' && !ev.shiftKey && !ev.altKey && !ev.ctrlKey && !ev.metaKey && opts.onEnter) { ev.preventDefault(); opts.onEnter(); return; }
      if (ev.key === 'Backspace' && opts.onBackspaceEmpty && read().length === 0) { ev.preventDefault(); opts.onBackspaceEmpty(); return; }
      if ((ev.ctrlKey || ev.metaKey) && !ev.altKey && ev.key.toLowerCase() === 'k' && !opts.plain) { ev.preventDefault(); ed.askLink(); }
    });
    e.addEventListener('paste', function (ev) {
      var cd = ev.clipboardData; if (!cd) { return; }
      ev.preventDefault();
      var html = cd.getData('text/html'), text = cd.getData('text/plain');
      if (opts.plain) { document.execCommand('insertText', false, text.replace(/\s+/g, ' ')); return; }
      var blocks = html ? H.parseHtml(html) : H.parseText(text);
      if (!blocks.length && text) { blocks = H.parseText(text); }
      if (!blocks.length) { return; }
      var first = blocks[0];
      if (first.type === 'paragraph' || first.type === 'heading' || first.type === 'quote') {
        document.execCommand('insertHTML', false, H.runsToHtml(M.runsOf(first)));
        blocks = blocks.slice(1);
      } else if (opts.single) { document.execCommand('insertText', false, text.replace(/\s+/g, ' ')); blocks = []; }
      if (blocks.length && !opts.single) { ed.insertMany(opts.path, blocks.filter(function (b) { return opts.path.length === 1 || M.NESTABLE.indexOf(b.type) >= 0; })); }
      sync(true);
    });
    e.addEventListener('drop', function (ev) { ev.preventDefault(); }); // arrastrar HTML ajeno al campo no está admitido
    themeColor(e, opts.block || {});
    return e;
  }

  var renderers = {};

  /** Marco del bloque: controles flotantes + contenido real + opciones (solo visibles con el bloque seleccionado). */
  function frame(ed, b, path, nodes, extras) {
    var top = path.length === 1, list = ed.list(path), i = path[path.length - 1], typeSel = null;
    if (b.type === 'paragraph' || b.type === 'heading' || b.type === 'quote') {
      var cur = b.type === 'heading' ? 'h' + b.level : b.type;
      typeSel = select([['paragraph', 'Párrafo'], ['h1', 'Título 1'], ['h2', 'Título 2'], ['h3', 'Título 3'], ['h4', 'Título 4'], ['h5', 'Título 5'], ['h6', 'Título 6'], ['quote', 'Cita']], cur, function (v) { ed.convert(path, v); });
      typeSel.setAttribute('aria-label', 'Tipo de bloque');
    }
    var head = el('div', { 'class': 'lux-ed-head', contenteditable: 'false' }, [
      typeSel || el('strong', { 'class': 'lux-ed-type', text: TYPE_LABEL[b.type] || b.type }),
      button('↑', 'Subir bloque (Alt+↑)', function () { ed.move(path, -1); }, i === 0 ? 'is-off' : ''),
      button('↓', 'Bajar bloque (Alt+↓)', function () { ed.move(path, 1); }, i === list.length - 1 ? 'is-off' : ''),
      button('⧉', 'Duplicar bloque (Alt+Mayús+D)', function () { ed.duplicate(path); }),
      button('✕', 'Borrar bloque (Alt+Supr)', function () { ed.remove(path); }, 'danger')
    ]);
    var kids = [head].concat(nodes);
    if (extras && extras.length) { kids.push(el('div', { 'class': 'lux-ed-extra', contenteditable: 'false' }, extras)); }
    var s = el('section', { 'class': 'lux-blk lux-blk--' + b.type + (top ? '' : ' is-nested'), tabindex: b.type === 'separator' || b.type === 'image' || b.type === 'gallery' || b.type === 'extension' ? '0' : '-1', 'data-path': path.join('.'),
      'aria-label': (TYPE_LABEL[b.type] || b.type) + ' ' + (i + 1) + ' de ' + list.length }, kids);
    s.addEventListener('focusin', function () { ed.select(path); });
    s.addEventListener('click', function (ev) { if (ev.target === s || !ev.target.closest('[contenteditable=true],button,input,select,label')) { ed.select(path); if (ev.target === s) { s.focus(); } } });
    s.addEventListener('keydown', function (ev) {
      if (!ev.altKey || ev.ctrlKey || ev.metaKey) { return; }
      if (ev.key === 'ArrowUp') { ev.preventDefault(); ev.stopPropagation(); ed.move(path, -1); }
      else if (ev.key === 'ArrowDown') { ev.preventDefault(); ev.stopPropagation(); ed.move(path, 1); }
      else if (ev.shiftKey && ev.key.toLowerCase() === 'd') { ev.preventDefault(); ev.stopPropagation(); ed.duplicate(path); }
      else if (ev.key === 'Delete') { ev.preventDefault(); ev.stopPropagation(); ed.remove(path); }
    });
    return s;
  }

  function textOpts(ed, b, path, label, ph) {
    return { path: path, block: b, label: label, placeholder: ph || 'Escribe aquí…', 'class': themeCls(b),
      onChange: function (rs, now) { M.setRuns(b, rs); ed.commit(now); },
      onEnter: function () { ed.addAfter(path, 'paragraph'); },
      onBackspaceEmpty: function () { if (ed.list(path).length > 1) { ed.remove(path, true); } } };
  }
  renderers.paragraph = function (ed, b, path) { var o = textOpts(ed, b, path, 'Párrafo'); o.tag = 'p'; return frame(ed, b, path, [editable(ed, M.runsOf(b), o)]); };
  renderers.heading = function (ed, b, path) { var o = textOpts(ed, b, path, 'Título', 'Título…'); o.tag = 'h' + b.level; return frame(ed, b, path, [editable(ed, M.runsOf(b), o)]); };
  renderers.quote = function (ed, b, path) {
    var o = textOpts(ed, b, path, 'Cita', 'Cita…'); o.tag = 'p'; o['class'] = '';
    var bq = el('blockquote', { 'class': themeCls(b) }, [editable(ed, M.runsOf(b), o)]);
    themeColor(bq, b);
    var foot = el('footer', { text: b.cite || '' }); foot.hidden = !b.cite; bq.appendChild(foot);
    var cite = field('Autor o fuente de la cita (opcional)', el('input', { type: 'text', maxlength: '200', value: b.cite || '', oninput: function (e) { var v = e.target.value.trim(); if (v) { b.cite = v; } else { delete b.cite; } foot.textContent = v; foot.hidden = !v; ed.commit(false); } }));
    return frame(ed, b, path, [bq], [cite]);
  };

  renderers.list = function (ed, b, path) {
    var ul = el(b.ordered ? 'ol' : 'ul');
    b.items.forEach(function (it, idx) {
      var rs = typeof it === 'string' ? M.runs(it) : M.runs(it.inlines || []);
      var li = editable(ed, rs, { tag: 'li', path: path, label: 'Elemento ' + (idx + 1), single: true, placeholder: 'Elemento…',
        onChange: function (r, now) { b.items[idx] = M.cellOf({ inlines: r }); ed.commit(now); },
        onEnter: function () { b.items.splice(idx + 1, 0, ''); ed.commit(true); ed.render({ path: path, focus: 'li:' + (idx + 1) }); },
        onBackspaceEmpty: function () { if (b.items.length > 1) { b.items.splice(idx, 1); ed.commit(true); ed.render({ path: path, focus: 'li:' + Math.max(0, idx - 1) }); } else { ed.remove(path, true); } } });
      li.setAttribute('data-li', String(idx));
      ul.appendChild(li);
    });
    return frame(ed, b, path, [ul], [
      button('+ Elemento', 'Añadir elemento', function () { b.items.push(''); ed.commit(true); ed.render({ path: path, focus: 'li:' + (b.items.length - 1) }); }),
      button(b.ordered ? 'Numerada ✓' : 'Con viñetas ✓', 'Cambiar entre lista con viñetas y numerada', function () { if (b.ordered) { delete b.ordered; } else { b.ordered = true; } ed.commit(true); ed.render({ path: path }); })
    ]);
  };

  renderers.image = function (ed, b, path) {
    var cfg = ed.cfg, fig = el('figure', { 'class': 'lux-img' + (b.align ? ' lux-img--' + b.align : '') });
    if (b.ref) { fig.appendChild(el('img', { src: cfg.thumb + encodeURIComponent(b.ref) + '/thumb', alt: b.alt || '' })); } else { fig.appendChild(el('div', { 'class': 'lux-ed-placeholder', text: 'Haz clic en «Elegir imagen»' })); }
    var cap = editable(ed, b.caption ? [{ t: b.caption }] : [], { tag: 'figcaption', plain: true, single: true, path: path, label: 'Pie de foto', placeholder: 'Pie de foto (opcional)', onChange: function (rs, now) { b.caption = M.plain(rs).trim(); ed.commit(now); } });
    fig.appendChild(cap);
    var warn = el('p', { 'class': 'lux-ed-warn', role: 'status', text: b.ref && !b.alt ? '⚠ Falta el texto alternativo: ayuda a la accesibilidad y al SEO.' : '' });
    return frame(ed, b, path, [fig], [
      button(b.ref ? 'Cambiar imagen' : 'Elegir imagen', 'Elegir o subir una imagen de la biblioteca', function () {
        LE.mediaPicker.open(cfg, function (it) { b.ref = it.ref; if (!b.alt) { b.alt = it.alt || ''; } if (!b.caption) { b.caption = it.caption || ''; } ed.commit(true); ed.render({ path: path }); });
      }),
      field('Texto alternativo (describe la imagen)', el('input', { type: 'text', maxlength: '300', value: b.alt || '', oninput: function (e) { b.alt = e.target.value; warn.textContent = b.ref && !b.alt.trim() ? '⚠ Falta el texto alternativo: ayuda a la accesibilidad y al SEO.' : ''; ed.commit(false); } })), warn]);
  };

  renderers.gallery = function (ed, b, path) { return frame(ed, b, path, [el('p', { 'class': 'lux-ed-placeholder', text: 'Galería «' + b.ref + '»: se publicará con el módulo de galerías (Fase 8). Puedes moverla o quitarla.' })]); };
  renderers.extension = function (ed, b, path) { return frame(ed, b, path, [el('p', { 'class': 'lux-ed-placeholder', text: 'Bloque de extensión «' + b.kind + '» (se conserva tal cual).' })]); };

  renderers.button = function (ed, b, path) {
    var label = editable(ed, b.label ? [{ t: b.label }] : [], { tag: 'span', plain: true, single: true, path: path, label: 'Texto del botón', placeholder: 'Texto del botón', 'class': 'lux-btn lux-btn--' + b.variant, onChange: function (rs, now) { b.label = M.plain(rs).trim(); ed.commit(now); } });
    var row = el('p', { 'class': 'lux-btn-row' + (b.align ? ' lux-a-' + b.align : '') }, [label]);
    return frame(ed, b, path, [row], [field('Enlace (/ruta/, https://…, mailto:, tel:)', el('input', { type: 'text', maxlength: '2000', value: b.href, inputmode: 'url', autocapitalize: 'off', oninput: function (e) { b.href = e.target.value; ed.commit(false); } }))]);
  };
  renderers.separator = function (ed, b, path) { return frame(ed, b, path, [b.style === 'space' ? el('div', { 'class': 'lux-space lux-ed-space', 'aria-hidden': 'true' }) : el('hr', { 'class': 'lux-sep lux-sep--' + b.style })]); };

  renderers.columns = function (ed, b, path) {
    var grid = el('div', { 'class': 'lux-cols lux-cols--' + b.layout + ' lux-cols--gap-' + b.gap + (b.valign === 'center' ? ' lux-cols--center' : '') });
    b.cols.forEach(function (col, ci) {
      var wrap = el('div', { 'class': 'lux-col lux-ed-col', role: 'group', 'aria-label': 'Columna ' + (ci + 1) + ' de ' + b.cols.length });
      col.forEach(function (child, k) { wrap.appendChild(ed.renderBlock(child, path.concat([ci, k]))); });
      var add = select([['', '+ Añadir aquí']].concat(M.NESTABLE.map(function (t) { return [t, TYPE_LABEL[t]]; })), '', function (v) { if (v) { ed.addInto(path, ci, v); } });
      add.setAttribute('aria-label', 'Añadir bloque en la columna ' + (ci + 1));
      wrap.appendChild(el('div', { 'class': 'lux-ed-add', contenteditable: 'false' }, [add]));
      grid.appendChild(wrap);
    });
    return frame(ed, b, path, [grid]);
  };

  renderers.table = function (ed, b, path) {
    var pos = { r: 0, c: 0 }, tbl = el('table', { 'class': 'lux-table' });
    if (b.caption) { tbl.appendChild(el('caption', { text: b.caption })); }
    b.rows.forEach(function (row, r) {
      var tr = el('tr');
      row.forEach(function (cell, c) {
        var th = (b.header_row && r === 0) || (b.header_col && c === 0);
        var td = el(th ? 'th' : 'td', { scope: th ? (b.header_row && r === 0 ? 'col' : 'row') : null, 'class': b.aligns[c] !== 'left' ? 'lux-a-' + b.aligns[c] : null });
        var e = editable(ed, M.runs(typeof cell === 'string' ? cell : (cell.inlines || [])), { path: path, 'class': 'lux-ed-cell', label: 'Fila ' + (r + 1) + ', columna ' + (c + 1), single: true,
          onChange: function (rs, now) { b.rows[r][c] = M.cellOf({ inlines: rs }); ed.commit(now); } });
        e.addEventListener('focus', function () { pos.r = r; pos.c = c; });
        e.addEventListener('keydown', function (ev) { // Tab recorre las celdas; Intro baja una fila
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
    var wrap = el('div', { 'class': 'lux-table-wrap', role: 'region', tabindex: '0', 'aria-label': b.caption || 'Tabla' }, [tbl]);
    return frame(ed, b, path, [wrap], [
      button('+ Fila', 'Añadir fila debajo de la celda activa', function () { ed.tableOp(b, path, 'addRow', pos); }),
      button('− Fila', 'Quitar la fila de la celda activa', function () { ed.tableOp(b, path, 'delRow', pos); }),
      button('+ Columna', 'Añadir columna a la derecha de la celda activa', function () { ed.tableOp(b, path, 'addCol', pos); }),
      button('− Columna', 'Quitar la columna de la celda activa', function () { ed.tableOp(b, path, 'delCol', pos); }),
      button('Alinear columna', 'Cambiar la alineación de la columna de la celda activa', function () { ed.tableOp(b, path, 'align', pos); }),
      el('label', { 'class': 'lux-ed-check' }, [el('input', { type: 'checkbox', checked: b.header_row, onchange: function (e) { b.header_row = e.target.checked; ed.commit(true); ed.render({ path: path }); } }), ' Fila de encabezados']),
      el('label', { 'class': 'lux-ed-check' }, [el('input', { type: 'checkbox', checked: b.header_col, onchange: function (e) { b.header_col = e.target.checked; ed.commit(true); ed.render({ path: path }); } }), ' Columna de encabezados']),
      field('Título de la tabla (leyenda)', el('input', { type: 'text', maxlength: '200', value: b.caption, oninput: function (e) { b.caption = e.target.value; ed.commit(false); } }))
    ]);
  };

  LE.dom = { el: el, button: button, field: field, select: select, TYPE_LABEL: TYPE_LABEL };
  LE.renderers = renderers;
})(window);
