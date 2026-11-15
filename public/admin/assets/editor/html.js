/* LuxEditor · HTML ↔ modelo. Sin DOM: un tokenizador propio con lista blanca, usado para pegar desde Word, Google Docs, correo
   y otras páginas, y para leer lo escrito en los campos editables. Nada del HTML original sobrevive salvo lo que el modelo admite. */
(function (root) {
  'use strict';
  var LE = root.LuxEditor = root.LuxEditor || {};
  var M = (typeof require === 'function' && typeof module !== 'undefined') ? require('./model.js') : LE.model;

  var ENT = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ', ndash: '–', mdash: '—', hellip: '…', laquo: '«', raquo: '»', lsquo: '‘', rsquo: '’', ldquo: '“', rdquo: '”', euro: '€', copy: '©', reg: '®', middot: '·', bull: '•', iexcl: '¡', iquest: '¿', ntilde: 'ñ', Ntilde: 'Ñ', aacute: 'á', eacute: 'é', iacute: 'í', oacute: 'ó', uacute: 'ú', Aacute: 'Á', Eacute: 'É', Iacute: 'Í', Oacute: 'Ó', Uacute: 'Ú', uuml: 'ü', Uuml: 'Ü' };
  function decode(s) {
    return s.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+\d*);/gi, function (m, e) {
      if (e[0] === '#') {
        var n = e[1] === 'x' || e[1] === 'X' ? parseInt(e.slice(2), 16) : parseInt(e.slice(1), 10);
        if (!(n > 8) || n > 0x10FFFF || (n >= 0xD800 && n < 0xE000)) { return ''; }
        return n === 160 ? ' ' : String.fromCodePoint(n);
      }
      return Object.prototype.hasOwnProperty.call(ENT, e) ? ENT[e] : m;
    });
  }
  function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  /** Quita comentarios (incl. condicionales de Word), bloques style/script/head/xml y etiquetas de espacio de nombres. */
  function precleanHtml(html) {
    html = String(html).slice(0, 3000000);
    var i = html.search(/<!--\s*StartFragment\s*-->/i), j = html.search(/<!--\s*EndFragment\s*-->/i);
    if (i >= 0 && j > i) { html = html.slice(i, j); }
    html = html.replace(/<!--\[if[\s\S]*?<!\[endif\]-->/gi, '').replace(/<!--[\s\S]*?-->/g, '').replace(/<!\[if[\s\S]*?<!\[endif\]>/gi, '');
    html = html.replace(/<(style|script|head|title|xml|template|noscript|iframe|object|embed|svg|math)\b[\s\S]*?<\/\1\s*>/gi, '');
    return html.replace(/<\/?[a-z]+:[a-z]+[^>]*>/gi, '');
  }

  function tokenize(html) {
    var out = [], re = /<(\/?)([a-zA-Z][a-zA-Z0-9]*)((?:"[^"]*"|'[^']*'|[^>"'])*)>|([^<]+|<)/g, m;
    while ((m = re.exec(html)) !== null) {
      if (m[4] !== undefined) { out.push({ k: 'text', v: decode(m[4]) }); continue; }
      var n = m[2].toLowerCase();
      if (m[1]) { out.push({ k: 'close', n: n }); continue; }
      var attrs = {}, ar = /([a-zA-Z_:][-\w:.]*)\s*(?:=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+)))?/g, a;
      while ((a = ar.exec(m[3])) !== null) { attrs[a[1].toLowerCase()] = decode(a[2] !== undefined ? a[2] : (a[3] !== undefined ? a[3] : (a[4] || ''))); }
      out.push({ k: 'open', n: n, attrs: attrs, self: /\/\s*$/.test(m[3]) });
    }
    return out;
  }

  function styleMap(s) {
    var o = {};
    String(s || '').split(';').forEach(function (d) { var i = d.indexOf(':'); if (i > 0) { o[d.slice(0, i).trim().toLowerCase()] = d.slice(i + 1).trim().toLowerCase(); } });
    return o;
  }
  function rgbToHex(v) {
    var m = /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/.exec(v);
    if (!m) { return null; }
    return '#' + [m[1], m[2], m[3]].map(function (x) { return ('0' + Math.min(255, +x).toString(16)).slice(-2); }).join('');
  }
  function normColor(v) {
    if (!v) { return null; }
    v = v.trim().toLowerCase();
    if (/^#[0-9a-f]{3}$/.test(v)) { v = '#' + v[1] + v[1] + v[2] + v[2] + v[3] + v[3]; }
    var h = /^#[0-9a-f]{6}$/.test(v) ? v : rgbToHex(v);
    // Negro, "windowtext" y "automatic" de Word son el color por defecto: no se conservan como color elegido.
    return !h || h === '#000000' || h === '#ffffff' ? null : h;
  }
  /** Marcas que aporta una etiqueta (con sus estilos en línea, típicos de Word y Google Docs). */
  function marksOf(n, attrs) {
    var f = {}, st = styleMap(attrs.style), fw = st['font-weight'];
    var bold = fw ? (fw === 'bold' || fw === 'bolder' || parseInt(fw, 10) >= 600) : null;
    if (n === 'b' || n === 'strong') { if (bold !== false) { f.b = true; } } else if (bold) { f.b = true; }
    if (n === 'i' || n === 'em' || n === 'cite' || n === 'dfn' || st['font-style'] === 'italic' || st['font-style'] === 'oblique') { f.i = true; }
    var td = (st['text-decoration'] || '') + ' ' + (st['text-decoration-line'] || '');
    if (n === 'u' || n === 'ins' || /underline/.test(td)) { f.u = true; }
    if (n === 's' || n === 'strike' || n === 'del' || /line-through/.test(td)) { f.s = true; }
    if (n === 'sub' || st['vertical-align'] === 'sub') { f.sub = true; }
    if (n === 'sup' || st['vertical-align'] === 'super') { f.sup = true; }
    if (attrs['data-c']) { f.c = M.color(attrs['data-c']); }
    var c = normColor(attrs.color) || normColor(st.color);
    if (c && (n === 'font' || n === 'span' || n === 'p' || n === 'a' || n === 'b' || n === 'i' || n === 'u' || n === 'strong' || n === 'em')) { f.c = c; }
    if (n === 'a') { var h = M.safeHref(attrs.href); if (h) { f.a = h; } }
    return f;
  }

  var BLOCK_TAGS = { p: 1, div: 1, h1: 1, h2: 1, h3: 1, h4: 1, h5: 1, h6: 1, li: 1, blockquote: 1, pre: 1, ul: 1, ol: 1, table: 1, tr: 1, td: 1, th: 1, hr: 1, section: 1, article: 1, header: 1, footer: 1, figure: 1, figcaption: 1, address: 1, dd: 1, dt: 1, dl: 1, caption: 1, tbody: 1, thead: 1, tfoot: 1 };
  var VOID = { br: 1, hr: 1, img: 1, input: 1, meta: 1, link: 1, col: 1, wbr: 1 };

  function addText(cur, text, fmt, pre) {
    if (!pre) { text = text.replace(/[\r\n\t  ]+/g, ' '); if (!cur.runs.length || /\s$|\n$/.test(M.plain(cur.runs))) { text = text.replace(/^ /, ''); } }
    if (text === '') { return; }
    var r = { t: text };
    Object.keys(fmt).forEach(function (k) { r[k] = fmt[k]; });
    cur.runs.push(r);
  }
  function mergeFmt(stack) { var f = {}; stack.forEach(function (e) { Object.keys(e.f).forEach(function (k) { if (e.f[k]) { f[k] = e.f[k]; } }); }); if (f.sub) { delete f.sup; } return f; }

  /** HTML pegado (Word, Google Docs, correo, web) → lista de bloques del modelo, ya normalizada. */
  function parseHtml(html) {
    var toks = tokenize(precleanHtml(html)), blocks = [], stack = [], lists = [], cur = { kind: 'paragraph', runs: [], cls: '', align: null };
    var tbl = null, pre = 0, quote = 0;
    function trimRuns(rs) {
      rs = M.runs(rs);
      if (rs.length) { rs[0].t = rs[0].t.replace(/^\s+/, ''); rs[rs.length - 1].t = rs[rs.length - 1].t.replace(/[\s]+$/, ''); }
      return M.runs(rs);
    }
    function pushListItem(rs, ordered) {
      var last = blocks[blocks.length - 1], item = M.formatted(rs) ? { inlines: rs } : M.plain(rs);
      if (last && last.type === 'list' && !!last.ordered === !!ordered && last._open) { last.items.push(item); } else { blocks.push({ type: 'list', items: [item], ordered: !!ordered, _open: true }); }
    }
    function flush() {
      var rs = trimRuns(cur.runs), k = cur.kind;
      if (tbl && tbl.cell) { if (rs.length) { if (tbl.cell.runs.length) { tbl.cell.runs.push({ t: '\n' }); } tbl.cell.runs = tbl.cell.runs.concat(rs); } cur = { kind: 'paragraph', runs: [], cls: '', align: null }; return; }
      if (rs.length) {
        var txt = M.plain(rs);
        if (k === 'paragraph' && /MsoListParagraph/i.test(cur.cls)) { // listas de Word: párrafos con viñeta escrita a mano
          var ord = /^\s*(\d+|[a-z])[.)]\s/i.test(txt);
          rs[0].t = rs[0].t.replace(/^\s*(?:[·•▪◦○●■□o§–-]|\d+[.)]|[a-z][.)])\s+/i, '');
          pushListItem(trimRuns(rs), ord);
        } else if (k === 'li') { pushListItem(rs, cur.ordered); }
        else {
          blocks.forEach(function (b) { delete b._open; });
          var b = { type: k === 'heading' ? 'heading' : (k === 'quote' ? 'quote' : 'paragraph') };
          if (k === 'heading') { b.level = cur.level; }
          M.setRuns(b, rs);
          if (cur.align && ['center', 'right', 'justify'].indexOf(cur.align) >= 0 && k !== 'heading') { b.align = cur.align; }
          blocks.push(b);
        }
      }
      cur = { kind: quote ? 'quote' : 'paragraph', runs: [], cls: '', align: null };
    }
    function closeList() { if (lists.length) { lists.pop(); } blocks.forEach(function (b) { if (!lists.length) { delete b._open; } }); }

    toks.forEach(function (t) {
      if (t.k === 'text') { addText(tbl && tbl.cell ? cur : cur, t.v, mergeFmt(stack), pre > 0); return; }
      var n = t.n;
      if (t.k === 'open') {
        if (n === 'br') { cur.runs.push({ t: '\n' }); return; }
        if (n === 'hr') { flush(); blocks.push({ type: 'separator', style: 'line' }); return; }
        if (n === 'img' || VOID[n]) { return; }
        if (n === 'table') { flush(); tbl = { rows: [], row: null, cell: null, th: [], cap: '' }; return; }
        if (tbl) {
          if (n === 'tr') { flushCell(); tbl.row = []; tbl.thRow = true; tbl.rows.push(tbl.row); return; }
          if (n === 'td' || n === 'th') { flushCell(); if (!tbl.row) { tbl.row = []; tbl.thRow = true; tbl.rows.push(tbl.row); } tbl.cell = { runs: [], th: n === 'th' }; cur = { kind: 'paragraph', runs: [], cls: '', align: null }; return; }
          if (n === 'caption') { flush(); tbl.inCap = true; cur = { kind: 'paragraph', runs: [], cls: '', align: null }; return; }
        }
        if (/^h[1-6]$/.test(n)) { flush(); cur = { kind: 'heading', level: +n[1], runs: [], cls: '', align: null }; stack.push({ n: n, f: {} }); return; }
        if (n === 'ul' || n === 'ol') { flush(); lists.push(n); return; }
        if (n === 'li') { flush(); cur = { kind: 'li', ordered: lists[lists.length - 1] === 'ol', runs: [], cls: '', align: null }; return; }
        if (n === 'blockquote') { flush(); quote++; cur = { kind: 'quote', runs: [], cls: '', align: null }; return; }
        if (n === 'pre') { flush(); pre++; return; }
        if (BLOCK_TAGS[n]) { flush(); cur.cls = t.attrs['class'] || ''; var al = styleMap(t.attrs.style)['text-align'] || t.attrs.align; cur.align = al || null; var f0 = marksOf(n, t.attrs); delete f0.a; stack.push({ n: n, f: f0 }); return; }
        if (!t.self) { stack.push({ n: n, f: marksOf(n, t.attrs) }); if (n === 'span' || n === 'font') { if (/MsoListParagraph|mso-list/i.test(t.attrs['class'] + ' ' + (t.attrs.style || ''))) { cur.cls = 'MsoListParagraph'; } } }
        return;
      }
      // cierre
      if (n === 'table') { flushCell(); finishTable(); return; }
      if (tbl && (n === 'td' || n === 'th')) { flushCell(); return; }
      if (tbl && n === 'tr') { flushCell(); tbl.row = null; return; }
      if (tbl && n === 'caption') { flush(); tbl.inCap = false; return; }
      if (n === 'ul' || n === 'ol') { flush(); closeList(); return; }
      if (n === 'blockquote') { flush(); quote = Math.max(0, quote - 1); cur.kind = quote ? 'quote' : 'paragraph'; return; }
      if (n === 'pre') { flush(); pre = Math.max(0, pre - 1); return; }
      if (/^h[1-6]$/.test(n) || n === 'li' || BLOCK_TAGS[n]) { flush(); }
      for (var i = stack.length - 1; i >= 0; i--) { if (stack[i].n === n) { stack.splice(i, 1); break; } }
    });
    flush();
    function flushCell() {
      if (!tbl || !tbl.cell) { return; }
      var rs = trimRuns(cur.runs), c = tbl.cell;
      if (rs.length) { if (c.runs.length) { c.runs.push({ t: '\n' }); } c.runs = c.runs.concat(rs); }
      cur = { kind: 'paragraph', runs: [], cls: '', align: null };
      tbl.row.push({ runs: trimRuns(c.runs), th: c.th }); if (!c.th) { tbl.thRow = false; }
      tbl.cell = null;
    }
    function finishTable() {
      var t = tbl; tbl = null;
      var rows = t.rows.filter(function (r) { return r.length; });
      if (!rows.length) { return; }
      var w = Math.max.apply(null, rows.map(function (r) { return r.length; }));
      var hr = rows[0].every(function (c) { return c.th; });
      blocks.push({ type: 'table', caption: '', header_row: hr, header_col: false, aligns: [], rows: rows.map(function (r) { var o = []; for (var i = 0; i < w; i++) { var c = r[i]; o.push(c ? (M.formatted(c.runs) ? { inlines: c.runs } : M.plain(c.runs)) : ''); } return o; }) });
    }
    blocks.forEach(function (b) { delete b._open; });
    return M.normalize(blocks);
  }

  /** Texto plano pegado: tabulaciones → tabla, viñetas/números → lista, líneas en blanco → párrafos. */
  function parseText(text) {
    text = String(text).replace(/\r\n?/g, '\n').slice(0, 1000000);
    var lines = text.split('\n'), blocks = [], para = [], list = null;
    function flushPara() { if (para.length) { blocks.push({ type: 'paragraph', text: para.join('\n').trim() }); para = []; } }
    function flushList() { list = null; }
    var i = 0;
    while (i < lines.length) {
      var l = lines[i];
      if (/\t/.test(l)) { // bloque de líneas con tabulaciones = tabla (Excel, Word)
        var rows = [];
        while (i < lines.length && /\t/.test(lines[i])) { rows.push(lines[i].split('\t').map(function (c) { return c.trim(); })); i++; }
        flushPara(); flushList();
        blocks.push({ type: 'table', caption: '', header_row: rows.length > 1, header_col: false, aligns: [], rows: rows });
        continue;
      }
      var m = /^\s*(?:([-*•·▪◦])|(\d+)[.)])\s+(.*)$/.exec(l);
      if (m) {
        flushPara();
        var ord = !!m[2];
        if (!list || list.ordered !== ord) { list = { type: 'list', items: [], ordered: ord }; blocks.push(list); }
        list.items.push(m[3].trim());
      } else if (l.trim() === '') { flushPara(); flushList(); }
      else { flushList(); para.push(l.trim()); }
      i++;
    }
    flushPara();
    return M.normalize(blocks.map(function (b) { if (b.type === 'list' && !b.ordered) { delete b.ordered; } return b; }));
  }

  /** Contenido de un campo editable → tramos. Los saltos de bloque del navegador cuentan como salto de línea. */
  function parseInline(html) {
    var toks = tokenize(precleanHtml(html)), stack = [], cur = { runs: [] };
    toks.forEach(function (t) {
      if (t.k === 'text') {
        var s = t.v.replace(/[\r\n\t ]+/g, ' ');
        if (s) { var r = { t: s }, f = mergeFmt(stack); Object.keys(f).forEach(function (k) { r[k] = f[k]; }); cur.runs.push(r); }
        return;
      }
      if (t.k === 'open') {
        if (t.n === 'br') { cur.runs.push({ t: '\n' }); return; }
        if (VOID[t.n]) { return; }
        if ((t.n === 'div' || t.n === 'p') && cur.runs.length && !/\n$/.test(M.plain(cur.runs))) { cur.runs.push({ t: '\n' }); }
        if (!t.self) { stack.push({ n: t.n, f: marksOf(t.n, t.attrs) }); }
        return;
      }
      for (var i = stack.length - 1; i >= 0; i--) { if (stack[i].n === t.n) { stack.splice(i, 1); break; } }
    });
    var rs = M.runs(cur.runs);
    while (rs.length && /\n$/.test(rs[rs.length - 1].t)) { rs[rs.length - 1].t = rs[rs.length - 1].t.replace(/\n+$/, ''); rs = M.runs(rs); }
    return rs;
  }

  /** Tramos → HTML del campo editable (solo etiquetas de formato; los colores por rol con data-c, los libres con font[color]). */
  function runsToHtml(rs) {
    return M.runs(rs).map(function (r) {
      var h = esc(r.t).replace(/\n/g, '<br>');
      if (r.sub) { h = '<sub>' + h + '</sub>'; } else if (r.sup) { h = '<sup>' + h + '</sup>'; }
      if (r.c) { h = r.c[0] === '#' ? '<font color="' + r.c + '">' + h + '</font>' : '<span class="lux-c-' + r.c + '" data-c="' + r.c + '">' + h + '</span>'; }
      if (r.s) { h = '<s>' + h + '</s>'; }
      if (r.u) { h = '<u>' + h + '</u>'; }
      if (r.i) { h = '<i>' + h + '</i>'; }
      if (r.b) { h = '<b>' + h + '</b>'; }
      if (r.a) { h = '<a href="' + esc(r.a) + '">' + h + '</a>'; }
      return h;
    }).join('');
  }

  LE.html = { decode: decode, esc: esc, tokenize: tokenize, parseHtml: parseHtml, parseText: parseText, parseInline: parseInline, runsToHtml: runsToHtml };
  if (typeof module !== 'undefined' && module.exports) { module.exports = LE.html; }
})(typeof window !== 'undefined' ? window : globalThis);
