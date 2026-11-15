/* LuxEditor · avisos de SEO y accesibilidad sobre el modelo (no bloquean: informan). Rutas = posición de primer nivel. */
(function (root) {
  'use strict';
  var LE = root.LuxEditor = root.LuxEditor || {};
  var M = (typeof require === 'function' && typeof module !== 'undefined') ? require('./model.js') : LE.model;

  /** @returns {{level:'warn'|'info',msg:string,path:number[]}[]} */
  function lint(blocks) {
    var out = [], prev = 1, h1 = 0;
    function walk(list, base) {
      list.forEach(function (b, i) {
        var path = base.concat([i]);
        if (b.type === 'heading') {
          var text = M.plain(M.runsOf(b)).trim();
          if (b.level === 1) { h1++; out.push({ level: 'warn', msg: 'Título de nivel 1 en el contenido: la plantilla ya muestra el título de la página como H1. Usa nivel 2 salvo que sepas que tu plantilla no lo hace.', path: path }); }
          else if (b.level > prev + 1) { out.push({ level: 'warn', msg: 'Salto de título: de nivel ' + prev + ' a nivel ' + b.level + '. Los lectores de pantalla y buscadores esperan niveles consecutivos.', path: path }); }
          if (text === '') { out.push({ level: 'warn', msg: 'Título vacío.', path: path }); }
          prev = b.level;
        } else if (b.type === 'image') {
          if (!b.ref) { out.push({ level: 'warn', msg: 'Imagen sin seleccionar.', path: path }); }
          else if (!b.alt) { out.push({ level: 'warn', msg: 'Imagen sin texto alternativo: ponlo, o déjalo vacío solo si es puramente decorativa.', path: path }); }
          else if (b.alt.length > 125) { out.push({ level: 'info', msg: 'Texto alternativo muy largo (más de 125 caracteres).', path: path }); }
        } else if (b.type === 'button') {
          if (!b.label) { out.push({ level: 'warn', msg: 'Botón sin texto.', path: path }); }
          if (!M.safeHref(b.href)) { out.push({ level: 'warn', msg: 'El botón no tiene un enlace válido (usa /ruta/, https://… , mailto: o tel:).', path: path }); }
        } else if (b.type === 'table') {
          if (!b.header_row && !b.header_col) { out.push({ level: 'warn', msg: 'Tabla sin fila ni columna de encabezados: actívalas para que sea accesible.', path: path }); }
          if (!b.caption) { out.push({ level: 'info', msg: 'Tabla sin título (leyenda).', path: path }); }
          if (b.rows[0] && b.rows[0].length > 6) { out.push({ level: 'info', msg: 'Tabla ancha: en móvil se desplaza horizontalmente.', path: path }); }
        } else if (b.type === 'columns') {
          b.cols.forEach(function (c, ci) { walk(c, path.concat([ci])); });
        } else if (b.type === 'gallery') {
          out.push({ level: 'warn', msg: 'Las galerías se publican a partir de la Fase 8: quita este bloque antes de publicar.', path: path });
        }
      });
    }
    walk(blocks, []);
    if (h1 > 1) { out.push({ level: 'warn', msg: 'Hay ' + h1 + ' títulos de nivel 1: una página debería tener uno solo.', path: [0] }); }
    return out;
  }
  LE.lint = lint;
  if (typeof module !== 'undefined' && module.exports) { module.exports = lint; }
})(typeof window !== 'undefined' ? window : globalThis);
