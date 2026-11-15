import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const History = require('../../public/admin/assets/editor/history.js');
const lint = require('../../public/admin/assets/editor/lint.js');
const S = require('../../public/admin/assets/editor/storage.js');

test('historial: deshacer, rehacer, corte de la rama rehacer y límite', () => {
  const h = new History('a', 3);
  assert.equal(h.push('b'), true); assert.equal(h.push('b'), false); h.push('c');
  assert.equal(h.undo(), 'b'); assert.equal(h.undo(), 'a'); assert.equal(h.undo(), null);
  assert.equal(h.redo(), 'b'); h.push('x'); assert.equal(h.canRedo(), false);
  h.push('y'); h.push('z'); assert.equal(h.stack.length, 3); assert.equal(h.current(), 'z');
});

test('avisos: H1, saltos de nivel, imágenes sin alt, tablas sin encabezado, botón sin enlace', () => {
  const w = lint([{ type: 'heading', level: 1, text: 'a' }, { type: 'heading', level: 4, text: 'b' }, { type: 'image', ref: 'img-1', alt: '', caption: '' },
    { type: 'table', caption: '', header_row: false, header_col: false, aligns: ['left'], rows: [['x']] }, { type: 'button', label: 'x', href: 'javascript:1', variant: 'primary' },
    { type: 'columns', layout: '1-1', gap: 'm', valign: 'top', cols: [[{ type: 'image', ref: 'img-2', alt: '', caption: '' }], []] }]);
  const m = w.map(x => x.msg).join('|');
  for (const k of ['nivel 1', 'Salto de título', 'sin texto alternativo', 'sin fila ni columna', 'enlace válido']) { assert.ok(m.includes(k), k); }
  assert.deepEqual(w.find(x => x.msg.includes('alternativo') && x.path.length === 3).path, [5, 0, 0]);
  assert.deepEqual(lint([{ type: 'heading', level: 2, text: 'ok' }, { type: 'paragraph', text: 'x' }]), []);
});

test('recuperación local: solo si parte del mismo contenido del servidor', () => {
  const mem = new Map(); const st = { getItem: k => mem.get(k) ?? null, setItem: (k, v) => mem.set(k, v), removeItem: k => mem.delete(k) };
  S.save('k', '[1]', '[2]', st);
  assert.equal(S.load('k', '[1]', st).json, '[2]');
  assert.equal(S.load('k', '[3]', st), null); assert.equal(mem.has('k'), false, 'obsoleto: se descarta');
  S.save('k', '[1]', '[1]', st); assert.equal(S.load('k', '[1]', st), null);
  S.save('k', '[1]', '[2]', st); S.clear('k', st); assert.equal(S.load('k', '[1]', st), null);
});
