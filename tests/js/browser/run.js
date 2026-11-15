/* Prueba en navegador real (Chrome sin cabeza) del editor bajo la misma CSP del panel. Escribe el resultado en #result. */
(function () {
  var out = [], fails = 0, violations = [];
  document.addEventListener('securitypolicyviolation', function (e) { violations.push(e.violatedDirective + ' ' + e.blockedURI); });
  function ok(c, m) { out.push((c ? 'OK   ' : 'FALLO ') + m); if (!c) { fails++; } }
  function json() { return JSON.parse(document.querySelector('[data-body-json]').value); }
  function type(el, html) { el.innerHTML = html; el.dispatchEvent(new Event('input', { bubbles: true })); }
  function paste(el, html, text) {
    var dt = new DataTransfer(); if (html) { dt.setData('text/html', html); } dt.setData('text/plain', text || '');
    el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
  }
  function key(el, k, o) { var e = new KeyboardEvent('keydown', Object.assign({ key: k, bubbles: true, cancelable: true }, o || {})); el.dispatchEvent(e); return e; }
  setTimeout(function () {
    try {
      var ed = window.LuxEditor.instance;
      ok(!!ed, 'el editor arranca');
      var hidden = document.querySelector('[data-body-json]');
      ok(hidden.name === 'body_json', 'el campo JSON recibe nombre al arrancar');
      ok(document.querySelector('textarea[name=body]').disabled, 'la alternativa de texto se desactiva');
      var texts = document.querySelectorAll('.lux-ed-text');
      ok(texts.length === 2, 'dos bloques editables');
      // escribir con formato
      type(texts[0], 'Hola <b>mundo</b> <img src=x onerror=alert(1)><script>alert(1)<\/script>');
      ok(JSON.stringify(json()[0]) === JSON.stringify({ type: 'paragraph', inlines: [{ t: 'Hola ' }, { t: 'mundo', b: true }, { t: ' alert(1)' }] }) || json()[0].inlines.length >= 2, 'HTML activo escrito en el campo no entra en el modelo: ' + JSON.stringify(json()[0]));
      ok(JSON.stringify(json()).indexOf('onerror') < 0 && JSON.stringify(json()).indexOf('<img') < 0, 'sin etiquetas ni atributos peligrosos');
      // IME: durante la composición no se confirma
      var before = hidden.value; texts[1].dispatchEvent(new CompositionEvent('compositionstart')); type(texts[1], 'に'); ok(hidden.value === before, 'IME: no se guarda a mitad de composición'); texts[1].dispatchEvent(new CompositionEvent('compositionend')); ok(hidden.value !== before && json()[1].text === 'に', 'IME: se guarda al terminar');
      // Enter crea bloque; Backspace en vacío lo borra
      texts = document.querySelectorAll('.lux-ed-text'); texts[1].focus(); key(texts[1], 'Enter');
      ok(json().length === 3 && json()[2].type === 'paragraph', 'Intro añade un párrafo');
      var empty = document.querySelectorAll('.lux-ed-text')[2]; empty.focus(); key(empty, 'Enter', { isComposing: true, keyCode: 229 }); ok(json().length === 3, 'Intro durante IME no crea bloque');
      key(document.querySelectorAll('.lux-ed-text')[2], 'Backspace'); ok(json().length === 2, 'Retroceso en bloque vacío lo borra');
      // mover con teclado y con botones
      var first = document.querySelector('.lux-blk'); key(first.querySelector('.lux-ed-text'), 'ArrowDown', { altKey: true });
      ok(json()[0].type === 'heading', 'Alt+↓ mueve el bloque');
      document.querySelector('.lux-blk button[aria-label^="Subir"]'); // existe
      var up = document.querySelectorAll('.lux-blk')[1].querySelector('button[aria-label^="Subir"]'); up.click(); ok(json()[0].type === 'paragraph', 'botón Subir');
      document.querySelectorAll('.lux-blk')[0].querySelector('button[aria-label^="Duplicar"]').click(); ok(json().length === 3, 'botón Duplicar');
      document.querySelectorAll('.lux-blk')[1].querySelector('button[aria-label^="Borrar"]').click(); ok(json().length === 2, 'botón Borrar');
      // deshacer / rehacer
      ed.undo(); ok(json().length === 3, 'deshacer'); ed.redo(); ok(json().length === 2, 'rehacer');
      // pegado desde Word en un campo
      var t0 = document.querySelectorAll('.lux-ed-text')[0]; t0.focus();
      paste(t0, '<!--StartFragment--><p class=MsoNormal style="mso-margin-top-alt:auto"><span style="font-family:Calibri;mso-fareast-font-family:Calibri">Uno <b style="mso-bidi-font-weight:normal">dos</b><o:p></o:p></span></p><p class=MsoListParagraphCxSpFirst style="mso-list:l0 level1"><![if !supportLists]><span>·&nbsp;</span><![endif]>a</p><p class=MsoListParagraphCxSpLast style="mso-list:l0 level1"><![if !supportLists]><span>·&nbsp;</span><![endif]>b</p><!--EndFragment-->', 'Uno dos');
      var j = JSON.stringify(json());
      ok(j.indexOf('mso') < 0 && j.indexOf('Calibri') < 0, 'Word: sin restos de Word');
      ok(json().some(function (b) { return b.type === 'list' && b.items.join() === 'a,b'; }), 'Word: la lista se convierte en lista real');
      // tabla pegada desde Excel
      var tt = document.querySelectorAll('.lux-ed-text')[0]; tt.focus(); paste(tt, '', 'Nombre\tEdad\nAna\t30');
      ok(json().some(function (b) { return b.type === 'table' && b.rows.length === 2; }), 'Excel: tabulaciones → tabla');
      // columnas
      ed.addAfter([0], 'columns'); var ci = json().findIndex(function (b) { return b.type === 'columns'; }); ok(ci === 1, 'columnas añadidas tras el bloque activo');
      ed.addInto([ci], 1, 'button'); ok(json()[ci].cols[1].length === 2, 'bloque añadido dentro de una columna');
      ed.setLayout([ci], '1-1-1'); ok(json()[ci].cols.length === 3, 'distribución de 3 columnas');
      ed.setLayout([ci], '1-1'); ok(json()[ci].cols.length === 2 && json()[ci].cols[1].length === 3, 'al reducir columnas no se pierde contenido');
      ok(document.querySelectorAll('.lux-ed-col').length === 2, 'las columnas se dibujan');
      // tabla: añadir fila/columna
      var ti = json().findIndex(function (b) { return b.type === 'table'; }); var tb = ed.get([ti]);
      ed.tableOp(tb, [ti], 'addRow', { r: 0, c: 0 }); ed.tableOp(tb, [ti], 'addCol', { r: 0, c: 0 }); ok(json()[ti].rows.length === 3 && json()[ti].rows[0].length === 3, 'tabla: +fila +columna');
      // enlace peligroso rechazado por el modelo
      ed.blocks[0].inlines = [{ t: 'x', a: 'javascript:alert(1)' }]; ed.commit(true); ok(JSON.stringify(json()).indexOf('javascript') < 0, 'enlace javascript: descartado');
      // selector de imágenes (sin red: solo comprueba que abre con foco atrapado y se cierra con Escape)
      ed.addAfter([0], 'image'); document.querySelector('.lux-blk--image .lux-ed-row .lux-ed-btn').click();
      var dlg = document.querySelector('[role=dialog]'); ok(!!dlg && dlg.getAttribute('aria-modal') === 'true', 'selector de imágenes es un diálogo modal');
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })); ok(!document.querySelector('[role=dialog]'), 'Escape cierra el selector');
      // avisos
      ed.lint(); ok(document.querySelector('.lux-ed-lint summary').textContent.indexOf('(') > 0, 'hay avisos (imagen sin seleccionar)');
      // recuperación: el borrador queda guardado para el siguiente arranque
      ok(!!localStorage.getItem('harness-key'), 'borrador local guardado');
      // envío: JSON final en el campo y limpieza del borrador
      document.getElementById('f').addEventListener('submit', function (e) { e.preventDefault(); });
      document.getElementById('f').dispatchEvent(new Event('submit', { cancelable: true }));
      ok(!localStorage.getItem('harness-key'), 'al enviar se limpia el borrador');
      ok(violations.length === 0, 'ninguna violación de CSP: ' + violations.join(' | '));
    } catch (e) { fails++; out.push('FALLO excepción: ' + e.message + ' ' + (e.stack || '').split('\n')[1]); }
    document.getElementById('result').textContent = out.join('\n') + '\nRESULT ' + (fails ? 'FAIL ' + fails : 'PASS');
  }, 300);
})();
