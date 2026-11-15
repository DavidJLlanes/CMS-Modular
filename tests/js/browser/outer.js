/* Prueba en navegador real (Chrome sin cabeza) del editor visual: el editor vive en un iframe (como en el panel) y escribe en el formulario de la página exterior. */
(function () {
  var out = [], fails = 0, violations = [];
  document.addEventListener('securitypolicyviolation', function (e) { violations.push(e.violatedDirective + ' ' + e.blockedURI); });
  function ok(c, m) { out.push((c ? 'OK   ' : 'FALLO ') + m); if (!c) { fails++; } }
  function json() { return JSON.parse(document.querySelector('[data-body-json]').value); }
  window.addEventListener('load', function () { setTimeout(run, 400); });
  function run() {
    try {
      var W = document.getElementById('fr').contentWindow, D = W.document, ed = W.LuxEditor && W.LuxEditor.instance;
      function q(s) { return D.querySelector(s); } function qa(s) { return D.querySelectorAll(s); }
      function type(el, html) { el.innerHTML = html; el.dispatchEvent(new W.Event('input', { bubbles: true })); }
      function paste(el, html, text) { var dt = new W.DataTransfer(); if (html) { dt.setData('text/html', html); } dt.setData('text/plain', text || ''); el.dispatchEvent(new W.ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true })); }
      function key(el, k, o) { var e = new W.KeyboardEvent('keydown', Object.assign({ key: k, bubbles: true, cancelable: true }, o || {})); el.dispatchEvent(e); return e; }
      W.addEventListener('securitypolicyviolation', function (e) { violations.push('frame ' + e.violatedDirective + ' ' + e.blockedURI); });
      ok(!!ed, 'el editor arranca dentro de la plantilla');
      var hidden = document.querySelector('[data-body-json]');
      ok(hidden.name === 'body_json', 'el campo JSON del formulario recibe nombre');
      ok(document.querySelector('textarea[name=body]').disabled, 'la alternativa de texto se desactiva');
      ok(document.querySelector('.lux-editor-root').classList.contains('is-ready'), 'la raíz se marca como lista');
      // WYSIWYG: los bloques son las etiquetas reales
      ok(!!q('main p.lux-ed-text') && !!q('main h2.lux-ed-text'), 'párrafo y título son <p> y <h2> reales');
      var texts = qa('.lux-ed-text');
      type(texts[0], 'Hola <b>mundo</b> <img src=x onerror=alert(1)><script>alert(1)<\/script>');
      ok(JSON.stringify(json()).indexOf('onerror') < 0 && JSON.stringify(json()).indexOf('<img') < 0 && json()[0].inlines.some(function (r) { return r.b; }), 'HTML activo escrito no entra en el modelo');
      var before = hidden.value; texts[1].dispatchEvent(new W.CompositionEvent('compositionstart')); type(texts[1], 'に'); ok(hidden.value === before, 'IME: no se guarda a mitad de composición'); texts[1].dispatchEvent(new W.CompositionEvent('compositionend')); ok(json()[1].text === 'に', 'IME: se guarda al terminar');
      texts = qa('.lux-ed-text'); texts[1].focus(); key(texts[1], 'Enter'); ok(json().length === 3 && json()[2].type === 'paragraph', 'Intro añade un párrafo');
      var empty = qa('.lux-ed-text')[2]; empty.focus(); key(empty, 'Enter', { isComposing: true, keyCode: 229 }); ok(json().length === 3, 'Intro durante IME no crea bloque');
      key(qa('.lux-ed-text')[2], 'Backspace'); ok(json().length === 2, 'Retroceso en bloque vacío lo borra');
      key(q('.lux-blk .lux-ed-text'), 'ArrowDown', { altKey: true }); ok(json()[0].type === 'heading', 'Alt+↓ mueve el bloque');
      qa('.lux-blk')[1].querySelector('button[aria-label^="Subir"]').click(); ok(json()[0].type === 'paragraph', 'botón Subir');
      qa('.lux-blk')[0].querySelector('button[aria-label^="Duplicar"]').click(); ok(json().length === 3, 'botón Duplicar');
      qa('.lux-blk')[1].querySelector('button[aria-label^="Borrar"]').click(); ok(json().length === 2, 'botón Borrar');
      ed.undo(); ok(json().length === 3, 'deshacer'); ed.redo(); ok(json().length === 2, 'rehacer');
      // controles flotantes: ocultos sin selección ni ratón
      ed.select(null); ok(W.getComputedStyle(q('.lux-ed-head')).display === 'none', 'los controles del bloque no estorban cuando no está seleccionado');
      ed.focusPath([0]); ok(W.getComputedStyle(q('.lux-blk.is-sel > .lux-ed-head')).display !== 'none', 'al seleccionar aparecen los controles');
      // barra fija y ventana de estilo
      ok(W.getComputedStyle(q('.lux-ed-toolbar')).position === 'fixed', 'barra de herramientas fija');
      var t0 = qa('.lux-ed-text')[0]; t0.focus();
      q('.lux-ed-toolbar button[aria-label^="Estilo"]').click(); ok(!q('.lux-ed-pop').hidden && q('.lux-ed-pop select'), 'el panel de estilo se abre');
      var al = q('.lux-ed-pop button[aria-label="Alinear: Centro"]'); al.click(); ok(json()[0].align === 'center' && q('main p.lux-a-center') !== null, 'alinear al centro se ve en la página con la clase del tema');
      q('.lux-ed-pop .lux-ed-popclose').click();
      // pegado desde Word
      t0 = qa('.lux-ed-text')[0]; t0.focus();
      paste(t0, '<!--StartFragment--><p class=MsoNormal style="mso-margin-top-alt:auto"><span style="font-family:Calibri;mso-fareast-font-family:Calibri">Uno <b style="mso-bidi-font-weight:normal">dos</b><o:p></o:p></span></p><p class=MsoListParagraphCxSpFirst style="mso-list:l0 level1"><![if !supportLists]><span>·&nbsp;</span><![endif]>a</p><p class=MsoListParagraphCxSpLast style="mso-list:l0 level1"><![if !supportLists]><span>·&nbsp;</span><![endif]>b</p><!--EndFragment-->', 'Uno dos');
      var j = JSON.stringify(json());
      ok(j.indexOf('mso') < 0 && j.indexOf('Calibri') < 0, 'Word: sin restos de Word');
      ok(json().some(function (b) { return b.type === 'list' && b.items.join() === 'a,b'; }), 'Word: la lista se convierte en lista real');
      ok(!!q('main ul li.lux-ed-text'), 'la lista se dibuja como <ul><li> real');
      var tt = qa('.lux-ed-text')[0]; tt.focus(); paste(tt, '', 'Nombre\tEdad\nAna\t30');
      ok(json().some(function (b) { return b.type === 'table' && b.rows.length === 2; }), 'Excel: tabulaciones → tabla');
      ok(!!q('.lux-table-wrap table.lux-table'), 'la tabla usa las clases del sitio');
      ed.addAfter([0], 'columns'); var ci = json().findIndex(function (b) { return b.type === 'columns'; }); ok(ci === 1, 'columnas tras el bloque activo');
      ed.addInto([ci], 1, 'button'); ok(json()[ci].cols[1].length === 2, 'bloque dentro de una columna');
      ok(!!q('.lux-cols.lux-cols--1-1 .lux-col .lux-btn'), 'columnas y botón con las clases del sitio');
      ed.setLayout([ci], '1-1-1'); ok(json()[ci].cols.length === 3, '3 columnas');
      ed.setLayout([ci], '1-1'); ok(json()[ci].cols.length === 2 && json()[ci].cols[1].length === 3, 'al reducir columnas no se pierde contenido');
      var ti = json().findIndex(function (b) { return b.type === 'table'; }); var tb = ed.get([ti]);
      ed.tableOp(tb, [ti], 'addRow', { r: 0, c: 0 }); ed.tableOp(tb, [ti], 'addCol', { r: 0, c: 0 }); ok(json()[ti].rows.length === 3 && json()[ti].rows[0].length === 3, 'tabla: +fila +columna');
      ed.blocks[0].inlines = [{ t: 'x', a: 'javascript:alert(1)' }]; ed.commit(true); ok(JSON.stringify(json()).indexOf('javascript') < 0, 'enlace javascript: descartado');
      // los enlaces de la plantilla no sacan al editor
      var link = q('header a'); var ev = new W.MouseEvent('click', { bubbles: true, cancelable: true }); link.dispatchEvent(ev); ok(ev.defaultPrevented, 'los enlaces de la plantilla no navegan');
      // selector de imágenes
      ed.addAfter([0], 'image'); q('.lux-blk--image .lux-ed-extra .lux-ed-btn').click();
      ok(!!q('[role=dialog]') && q('[role=dialog]').getAttribute('aria-modal') === 'true', 'selector de imágenes modal');
      D.dispatchEvent(new W.KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true })); ok(!q('[role=dialog]'), 'Escape cierra el selector');
      ed.lint(); ok(q('.lux-ed-toolbar').textContent.indexOf('SEO (') >= 0, 'avisos de SEO en la barra');
      ok(!!localStorage.getItem('harness-key'), 'borrador local guardado');
      document.getElementById('f').addEventListener('submit', function (e) { e.preventDefault(); });
      document.getElementById('f').dispatchEvent(new Event('submit', { cancelable: true }));
      ok(!localStorage.getItem('harness-key'), 'al enviar se limpia el borrador');
      ok(violations.length === 0, 'ninguna violación de CSP: ' + violations.join(' | '));
    } catch (e) { fails++; out.push('FALLO excepción: ' + e.message + ' ' + (e.stack || '').split('\n').slice(0, 3).join(' / ')); }
    document.getElementById('result').textContent = out.join('\n') + '\nRESULT ' + (fails ? 'FAIL ' + fails : 'PASS');
  }
})();
