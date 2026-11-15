/* LuxEditor · panel «Estilo del bloque»: alineación, tipografía (valores admitidos, nunca CSS libre), color, diseño de columnas, etc. */
(function (root) {
  'use strict';
  var LE = root.LuxEditor = root.LuxEditor || {};
  var M = LE.model, D = LE.dom;

  var LABELS = {
    size: ['Tamaño', [['', 'Normal'], ['sm', 'Pequeño'], ['lg', 'Grande'], ['xl', 'Muy grande']]],
    weight: ['Grosor', [['', 'Normal'], ['medium', 'Medio'], ['bold', 'Negrita']]],
    line: ['Interlineado', [['', 'Normal'], ['tight', 'Compacto'], ['loose', 'Amplio']]],
    letter: ['Espaciado de letras', [['', 'Normal'], ['wide', 'Ancho'], ['wider', 'Más ancho']]],
    family: ['Tipo de letra', [['', 'Heredada de la plantilla'], ['body', 'Texto'], ['heading', 'Títulos']]],
    spacing: ['Espacio entre párrafos', [['', 'Normal'], ['compact', 'Compacto'], ['loose', 'Amplio']]]
  };
  var ROLE_LABEL = { text: 'Texto', muted: 'Atenuado', primary: 'Principal', link: 'Enlace' };

  function alignRow(ed, b, path, values) {
    var row = D.el('div', { 'class': 'lux-ed-row', role: 'group', 'aria-label': 'Alineación' });
    values.forEach(function (v) {
      var names = { left: 'Izquierda', center: 'Centro', right: 'Derecha', justify: 'Justificado' };
      var on = (b.align || (v === 'left' && b.type !== 'image' && b.type !== 'button' ? 'left' : '')) === v;
      row.appendChild(D.button({ left: '⇤', center: '↔', right: '⇥', justify: '☰' }[v], 'Alinear: ' + names[v], function () { if (v === 'left' && (b.type === 'paragraph' || b.type === 'quote' || b.type === 'heading')) { delete b.align; } else { b.align = v; } ed.commit(true); ed.render({ path: path }); }, on ? 'is-on' : ''));
    });
    return row;
  }

  function colorControl(ed, b, path) {
    var cur = b.style && b.style.color, row = D.el('div', { 'class': 'lux-ed-row', role: 'group', 'aria-label': 'Color del bloque' });
    function set(v) { b.style = b.style || {}; if (v) { b.style.color = v; } else { delete b.style.color; } if (!Object.keys(b.style).length) { delete b.style; } ed.commit(true); ed.render({ path: path }); }
    M.ROLES.forEach(function (r) { row.appendChild(D.button(ROLE_LABEL[r], 'Color ' + ROLE_LABEL[r], function () { set(r); }, cur === r ? 'is-on' : '')); });
    var pick = D.el('input', { type: 'color', 'aria-label': 'Color personalizado', value: cur && cur[0] === '#' ? cur : '#3366cc', onchange: function (e) { set(e.target.value); } });
    row.appendChild(pick);
    row.appendChild(D.button('Sin color', 'Quitar el color del bloque', function () { set(null); }));
    return row;
  }

  function render(ed, host, path) {
    host.textContent = '';
    var b = path ? ed.get(path) : null;
    if (!b) { host.appendChild(D.el('p', { 'class': 'muted', text: 'Selecciona un bloque para ver su estilo.' })); return; }
    var add = function (n) { host.appendChild(n); };
    add(D.el('strong', { text: 'Estilo: ' + (D.TYPE_LABEL[b.type] || b.type) }));
    if (b.type === 'paragraph' || b.type === 'quote') {
      add(alignRow(ed, b, path, M.ALIGN));
      Object.keys(LABELS).forEach(function (k) {
        add(D.field(LABELS[k][0], D.select(LABELS[k][1], (b.style && b.style[k]) || '', function (v) { b.style = b.style || {}; if (v) { b.style[k] = v; } else { delete b.style[k]; } if (!Object.keys(b.style).length) { delete b.style; } ed.commit(true); ed.render({ path: path }); })));
      });
      add(D.el('label', { 'class': 'lux-ed-check' }, [D.el('input', { type: 'checkbox', checked: !!(b.style && b.style.italic), onchange: function (e) { b.style = b.style || {}; if (e.target.checked) { b.style.italic = true; } else { delete b.style.italic; } if (!Object.keys(b.style).length) { delete b.style; } ed.commit(true); ed.render({ path: path }); } }), ' Cursiva en todo el bloque']));
      add(colorControl(ed, b, path));
    } else if (b.type === 'heading') {
      add(alignRow(ed, b, path, ['left', 'center', 'right']));
      add(D.el('p', { 'class': 'muted', text: 'El tamaño de los títulos lo define la plantilla; cambia el nivel en el selector del bloque.' }));
    } else if (b.type === 'image') {
      add(alignRow(ed, b, path, ['left', 'center', 'right']));
    } else if (b.type === 'button') {
      add(D.field('Aspecto', D.select([['primary', 'Principal'], ['secondary', 'Secundario'], ['outline', 'Contorno']], b.variant, function (v) { b.variant = v; ed.commit(true); ed.render({ path: path }); })));
      add(alignRow(ed, b, path, ['left', 'center', 'right']));
    } else if (b.type === 'separator') {
      add(D.field('Tipo', D.select([['line', 'Línea'], ['dots', 'Puntos'], ['space', 'Solo espacio']], b.style, function (v) { b.style = v; ed.commit(true); ed.render({ path: path }); })));
    } else if (b.type === 'columns') {
      add(D.field('Distribución', D.select([['1-1', '2 iguales'], ['1-2', '2: estrecha + ancha'], ['2-1', '2: ancha + estrecha'], ['1-1-1', '3 iguales'], ['1-2-1', '3: centro ancho'], ['1-1-1-1', '4 iguales']], b.layout, function (v) { ed.setLayout(path, v); })));
      add(D.field('Separación', D.select([['s', 'Pequeña'], ['m', 'Media'], ['l', 'Grande']], b.gap, function (v) { b.gap = v; ed.commit(true); ed.render({ path: path }); })));
      add(D.field('Alineación vertical', D.select([['top', 'Arriba'], ['center', 'Centrada']], b.valign, function (v) { b.valign = v; ed.commit(true); ed.render({ path: path }); })));
      add(D.el('p', { 'class': 'muted', text: 'En móvil las columnas se apilan en el mismo orden de lectura (primera columna arriba).' }));
    } else {
      add(D.el('p', { 'class': 'muted', text: 'Este bloque no tiene opciones de estilo.' }));
    }
  }
  LE.inspector = { render: render };
})(window);
