/* LuxEditor · modelo de bloques (espejo del validador PHP; el servidor vuelve a validar siempre). Sin dependencias. */
(function (root) {
  'use strict';
  var LE = root.LuxEditor = root.LuxEditor || {};

  var ROLES = ['text', 'muted', 'primary', 'link'];
  var ALIGN = ['left', 'center', 'right', 'justify'];
  var STYLE = { size: ['sm', 'lg', 'xl'], weight: ['medium', 'bold'], line: ['tight', 'loose'], letter: ['wide', 'wider'], family: ['body', 'heading'], spacing: ['compact', 'loose'] };
  var LAYOUTS = { '1-1': 2, '1-2': 2, '2-1': 2, '1-1-1': 3, '1-2-1': 3, '1-1-1-1': 4 };
  var NESTABLE = ['paragraph', 'heading', 'list', 'quote', 'image', 'button', 'separator', 'table'];
  var MARKS = ['b', 'i', 's', 'u'];

  function str(v, max) { return typeof v === 'string' ? v.slice(0, max) : ''; }
  function clean(s) { return String(s).replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F​﻿]/g, ''); }

  /** Enlaces permitidos: ruta interna, ancla, http(s), mailto y tel. Nunca javascript:, data: ni nada con espacios o comillas. */
  function safeHref(h) {
    if (typeof h !== 'string') { return null; }
    h = h.trim();
    if (h === '' || h.length > 2000 || /[\s"'<>\u0000-\u001F]/.test(h)) { return null; }
    if (/^\/(?!\/)/.test(h) || /^#[\w-]*$/.test(h) || /^https?:\/\/[^\s/]+/i.test(h) || /^mailto:[^\s@]+@[^\s@]+$/i.test(h) || /^tel:[+\d][\d\s().-]*$/i.test(h)) { return h; }
    return null;
  }

  function color(c) {
    if (typeof c !== 'string') { return null; }
    c = c.trim().toLowerCase();
    if (ROLES.indexOf(c) >= 0) { return c; }
    return /^#[0-9a-f]{6}$/.test(c) ? c : null;
  }

  /** Normaliza una lista de tramos: marcas permitidas, sin vacíos, fusiona los contiguos iguales. */
  function runs(list) {
    if (typeof list === 'string') { list = [{ t: list }]; }
    if (!Array.isArray(list)) { return []; }
    var out = [];
    list.slice(0, 400).forEach(function (r) {
      if (!r || typeof r.t !== 'string') { return; }
      var t = clean(r.t);
      if (t === '') { return; }
      var o = { t: t };
      MARKS.forEach(function (m) { if (r[m]) { o[m] = true; } });
      if (r.sub && !r.sup) { o.sub = true; } else if (r.sup && !r.sub) { o.sup = true; }
      var c = color(r.c); if (c) { o.c = c; }
      var a = safeHref(r.a); if (a) { o.a = a; }
      var last = out[out.length - 1];
      if (last && sameFmt(last, o)) { last.t += o.t; } else { out.push(o); }
    });
    return out;
  }
  function sameFmt(a, b) {
    var k = MARKS.concat(['sub', 'sup', 'c', 'a']);
    for (var i = 0; i < k.length; i++) { if ((a[k[i]] || false) !== (b[k[i]] || false)) { return false; } }
    return true;
  }
  function formatted(rs) { return rs.some(function (r) { return MARKS.concat(['sub', 'sup', 'c', 'a']).some(function (k) { return !!r[k]; }); }); }
  function plain(rs) { return rs.map(function (r) { return r.t; }).join(''); }
  /** Texto o tramos de un bloque como tramos. */
  function runsOf(b) { return b.inlines ? runs(b.inlines) : (b.text ? runs(b.text) : []); }
  function setRuns(b, rs) {
    rs = runs(rs);
    delete b.text; delete b.inlines;
    if (formatted(rs)) { b.inlines = rs; } else { b.text = plain(rs).trim(); }
    return b;
  }
  function cellOf(c) { var rs = runs(c && typeof c === 'object' && !Array.isArray(c) ? (c.inlines || (c.text || '')) : c); return formatted(rs) ? { inlines: rs } : plain(rs).trim(); }

  function textStyle(b, o, withStyle) {
    if (ALIGN.indexOf(b.align) >= 0) { o.align = b.align; }
    if (withStyle === false || !b.style || typeof b.style !== 'object') { return o; }
    var s = {};
    Object.keys(STYLE).forEach(function (k) { if (STYLE[k].indexOf(b.style[k]) >= 0) { s[k] = b.style[k]; } });
    if (b.style.italic) { s.italic = true; }
    var c = color(b.style.color); if (c) { s.color = c; }
    if (Object.keys(s).length) { o.style = s; }
    return o;
  }

  /** Un bloque → forma canónica (mismo resultado que el validador de PHP) o null si no es válido. */
  function block(b, nested) {
    if (!b || typeof b !== 'object') { return null; }
    var t = b.type, o;
    if (nested && NESTABLE.indexOf(t) < 0) { return null; }
    switch (t) {
      case 'paragraph': case 'quote':
        o = setRuns({ type: t }, runsOf(b));
        if (t === 'quote' && str(b.cite, 200).trim() !== '') { o.cite = str(b.cite, 200).trim(); }
        return textStyle(b, o);
      case 'heading':
        var lv = parseInt(b.level, 10);
        o = setRuns({ type: 'heading', level: lv >= 1 && lv <= 6 ? lv : 2 }, runsOf(b));
        return textStyle(b, o, false);
      case 'list':
        o = { type: 'list', items: (Array.isArray(b.items) ? b.items : []).slice(0, 200).map(cellOf) };
        if (!o.items.length) { o.items = ['']; }
        if (b.ordered) { o.ordered = true; }
        return o;
      case 'image':
        o = { type: 'image', ref: str(b.ref, 64), alt: str(b.alt, 300).trim(), caption: str(b.caption, 500).trim() };
        if (['left', 'center', 'right'].indexOf(b.align) >= 0) { o.align = b.align; }
        return o;
      case 'gallery': return { type: 'gallery', ref: str(b.ref, 64) };
      case 'button':
        o = { type: 'button', label: str(b.label, 80).trim(), href: str(b.href, 2000).trim(), variant: ['secondary', 'outline'].indexOf(b.variant) >= 0 ? b.variant : 'primary' };
        if (['center', 'right'].indexOf(b.align) >= 0) { o.align = b.align; }
        return o;
      case 'separator': return { type: 'separator', style: ['dots', 'space'].indexOf(b.style) >= 0 ? b.style : 'line' };
      case 'columns':
        var layout = LAYOUTS[b.layout] ? b.layout : '1-1', n = LAYOUTS[layout], cols = [];
        for (var i = 0; i < n; i++) { cols.push(((Array.isArray(b.cols) && Array.isArray(b.cols[i])) ? b.cols[i] : []).slice(0, 50).map(function (x) { return block(x, true); }).filter(Boolean)); }
        return { type: 'columns', layout: layout, gap: ['s', 'l'].indexOf(b.gap) >= 0 ? b.gap : 'm', valign: b.valign === 'center' ? 'center' : 'top', cols: cols };
      case 'table':
        var rows = (Array.isArray(b.rows) ? b.rows : []).slice(0, 50), w = 0;
        rows.forEach(function (r) { if (Array.isArray(r)) { w = Math.max(w, Math.min(12, r.length)); } });
        if (!rows.length || !w) { return null; }
        var aligns = [];
        for (var k = 0; k < w; k++) { aligns.push(['center', 'right'].indexOf((b.aligns || [])[k]) >= 0 ? b.aligns[k] : 'left'); }
        return { type: 'table', caption: str(b.caption, 200).trim(), header_row: !!b.header_row, header_col: !!b.header_col, aligns: aligns,
          rows: rows.map(function (r) { var cells = []; for (var j = 0; j < w; j++) { cells.push(cellOf(Array.isArray(r) ? r[j] : '')); } return cells; }) };
      case 'extension': return { type: 'extension', kind: str(b.kind, 31), data: b.data && typeof b.data === 'object' ? b.data : {} };
      default: return null;
    }
  }
  function normalize(blocks) {
    return (Array.isArray(blocks) ? blocks : []).slice(0, 500).map(function (b) { return block(b, false); }).filter(Boolean);
  }

  function empty(type) {
    switch (type) {
      case 'paragraph': return { type: 'paragraph', text: '' };
      case 'heading': return { type: 'heading', level: 2, text: '' };
      case 'quote': return { type: 'quote', text: '' };
      case 'list': return { type: 'list', items: [''] };
      case 'image': return { type: 'image', ref: '', alt: '', caption: '' };
      case 'button': return { type: 'button', label: 'Botón', href: '/', variant: 'primary' };
      case 'separator': return { type: 'separator', style: 'line' };
      case 'columns': return { type: 'columns', layout: '1-1', gap: 'm', valign: 'top', cols: [[{ type: 'paragraph', text: '' }], [{ type: 'paragraph', text: '' }]] };
      case 'table': return { type: 'table', caption: '', header_row: true, header_col: false, aligns: ['left', 'left'], rows: [['', ''], ['', '']] };
      default: return null;
    }
  }
  function clone(x) { return JSON.parse(JSON.stringify(x)); }
  function serialize(blocks) { return JSON.stringify(normalize(blocks)); }

  LE.model = { ROLES: ROLES, ALIGN: ALIGN, STYLE: STYLE, LAYOUTS: LAYOUTS, NESTABLE: NESTABLE, safeHref: safeHref, color: color, runs: runs, formatted: formatted, plain: plain,
    runsOf: runsOf, setRuns: setRuns, cellOf: cellOf, block: block, normalize: normalize, empty: empty, clone: clone, serialize: serialize };
  if (typeof module !== 'undefined' && module.exports) { module.exports = LE.model; }
})(typeof window !== 'undefined' ? window : globalThis);
