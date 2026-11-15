import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const M = require('../../public/admin/assets/editor/model.js');
const H = require('../../public/admin/assets/editor/html.js');

test('modelo: normaliza como el servidor (sin formato → text; enlaces y colores filtrados)', () => {
  const b = M.normalize([{ type: 'paragraph', inlines: [{ t: 'a' }, { t: 'b' }] }, { type: 'paragraph', inlines: [{ t: 'x', a: 'javascript:alert(1)', c: 'red', onclick: 'x' }] },
    { type: 'heading', level: 1, text: 'H' }, { type: 'bogus' }, { type: 'html', html: '<script>' }]);
  assert.deepEqual(b, [{ type: 'paragraph', text: 'ab' }, { type: 'paragraph', text: 'x' }, { type: 'heading', level: 1, text: 'H' }]);
  assert.equal(M.safeHref('//evil.es'), null);
  assert.equal(M.safeHref('/ok/'), '/ok/');
  assert.equal(M.safeHref('https://a.es/x'), 'https://a.es/x');
  assert.equal(M.safeHref('data:text/html,x'), null);
  assert.equal(M.color('#AABBCC'), '#aabbcc');
});

test('modelo: columnas sin anidar columnas y tablas rectangulares', () => {
  const c = M.normalize([{ type: 'columns', layout: '1-1', cols: [[{ type: 'columns', layout: '1-1', cols: [[], []] }, { type: 'paragraph', text: 'A' }]] }])[0];
  assert.equal(c.cols.length, 2);
  assert.deepEqual(c.cols[0], [{ type: 'paragraph', text: 'A' }]);
  const t = M.normalize([{ type: 'table', rows: [['a', 'b'], ['c']] }])[0];
  assert.deepEqual(t.rows, [['a', 'b'], ['c', '']]);
});

test('pegado: Word (MsoNormal, o:p, comentarios condicionales, estilos mso) queda limpio', () => {
  const word = `<html xmlns:o="urn:schemas-microsoft-com:office:office"><head><style>p.MsoNormal{mso-style-parent:"";margin:0}</style></head><body lang=ES>
<!--StartFragment--><p class=MsoNormal style='mso-margin-top-alt:auto'><span style='font-size:12.0pt;font-family:"Times New Roman",serif;mso-fareast-font-family:"Times New Roman"'>Hola <b>mundo</b><o:p></o:p></span></p>
<!--[if gte mso 9]><xml><o:OfficeDocumentSettings></o:OfficeDocumentSettings></xml><![endif]-->
<p class=MsoListParagraphCxSpFirst style='text-indent:-18.0pt;mso-list:l0 level1 lfo1'><![if !supportLists]><span style='font-family:Symbol'>·<span style='font:7.0pt "Times New Roman"'>&nbsp;&nbsp;&nbsp; </span></span><![endif]>Primero</p>
<p class=MsoListParagraphCxSpLast style='mso-list:l0 level1 lfo1'><![if !supportLists]><span>·&nbsp;</span><![endif]>Segundo</p><!--EndFragment--></body></html>`;
  const b = H.parseHtml(word);
  assert.deepEqual(b[0], { type: 'paragraph', inlines: [{ t: 'Hola ' }, { t: 'mundo', b: true }] });
  assert.equal(b[1].type, 'list');
  assert.deepEqual(b[1].items, ['Primero', 'Segundo']);
  assert.equal(JSON.stringify(b).includes('mso'), false);
  assert.equal(JSON.stringify(b).includes('Times'), false);
});

test('pegado: Google Docs (b con font-weight:normal, spans con estilos) y colores', () => {
  const g = `<meta charset="utf-8"><b style="font-weight:normal;" id="docs-internal-guid-1234"><h2 dir="ltr" style="line-height:1.38"><span style="font-size:16pt;font-weight:700">Título</span></h2><p dir="ltr"><span style="font-weight:400;font-style:italic;text-decoration:underline;color:#ff0000">rojo</span> <span style="font-weight:700">neg</span> <span style="vertical-align:super">2</span></p></b>`;
  const b = H.parseHtml(g);
  assert.deepEqual(b[0], { type: 'heading', level: 2, inlines: [{ t: 'Título', b: true }] });
  assert.deepEqual(b[1].inlines.map(r => r.t), ['rojo', ' ', 'neg', ' ', '2']);
  assert.equal(b[1].inlines[0].i, true); assert.equal(b[1].inlines[0].u, true); assert.equal(b[1].inlines[0].c, '#ff0000');
  assert.equal(b[1].inlines[2].b, true); assert.equal(b[1].inlines[4].sup, true);
});

test('pegado: correo/web contaminado — scripts, eventos, javascript:, imágenes, iframes y atributos peligrosos desaparecen', () => {
  const bad = `<div onclick="x()"><script>alert(1)</script><p style="background:url(javascript:x)" onmouseover="y()">Texto <a href="javascript:alert(1)" onclick="z()">malo</a> <a href="https://ok.es/p?a=1&amp;b=2" target="_blank">bueno</a><img src=x onerror=alert(1)><iframe src="//evil"></iframe></p><style>*{display:none}</style><svg><script>1</script></svg></div>`;
  const b = H.parseHtml(bad);
  assert.equal(b.length, 1);
  const j = JSON.stringify(b);
  for (const w of ['script', 'onclick', 'onerror', 'javascript', 'iframe', 'onmouseover', 'background']) { assert.equal(j.includes(w), false, w); }
  assert.deepEqual(b[0].inlines.map(r => [r.t, r.a || null]), [['Texto malo ', null], ['bueno', 'https://ok.es/p?a=1&b=2']]);
});

test('pegado: tablas HTML y texto con tabulaciones (Excel) → tabla; listas y citas', () => {
  const t = H.parseHtml('<table><tr><th>Plan</th><th>Precio</th></tr><tr><td>Base</td><td><b>5</b> €</td></tr></table>');
  assert.equal(t[0].type, 'table'); assert.equal(t[0].header_row, true);
  assert.deepEqual(t[0].rows[0], ['Plan', 'Precio']);
  assert.deepEqual(t[0].rows[1][1], { inlines: [{ t: '5', b: true }, { t: ' €' }] });
  const x = H.parseText('Nombre\tEdad\nAna\t30\nLuis\t41');
  assert.equal(x[0].type, 'table'); assert.equal(x[0].rows.length, 3);
  const l = H.parseHtml('<ul><li>uno</li><li>dos<ul><li>anidado</li></ul></li></ul><ol><li>a</li></ol><blockquote><p>cita</p></blockquote>');
  assert.deepEqual(l.map(b => b.type), ['list', 'list', 'quote']);
  assert.equal(l[0].items.length, 3); assert.equal(l[1].ordered, true);
});

test('pegado: texto plano de correo (viñetas, numeración, párrafos)', () => {
  const b = H.parseText('Hola,\n\n- uno\n- dos\n\n1. a\n2) b\n\nAdiós\nfirma');
  assert.deepEqual(b.map(x => x.type), ['paragraph', 'list', 'list', 'paragraph']);
  assert.deepEqual(b[1].items, ['uno', 'dos']); assert.equal(b[2].ordered, true);
  assert.equal(b[3].text, 'Adiós\nfirma');
});

test('campo editable: ida y vuelta sin pérdidas y saltos de bloque del navegador como salto de línea', () => {
  const rs = [{ t: 'a ', b: true }, { t: 'b', i: true, u: true, c: '#ff0000' }, { t: '\n' }, { t: 'x', sup: true, a: '/p/' }, { t: 'y', c: 'primary' }];
  assert.deepEqual(H.parseInline(H.runsToHtml(rs)), M.runs(rs));
  assert.deepEqual(H.parseInline('uno<div>dos</div><div><br></div>'), [{ t: 'uno\ndos' }]);
  assert.deepEqual(H.parseInline('<font color="#00ff00">verde</font><span style="font-weight:bold">n</span>'), [{ t: 'verde', c: '#00ff00' }, { t: 'n', b: true }]);
  assert.equal(H.runsToHtml([{ t: '<img src=x onerror=1>' }]), '&lt;img src=x onerror=1&gt;');
});
