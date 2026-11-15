/* Prueba en Chrome sin cabeza del visor de galerías sobre el HTML que genera el servidor (mismas clases y atributos). */
(function () {
  var out = [], fails = 0, violations = [];
  document.addEventListener('securitypolicyviolation', function (e) { violations.push(e.violatedDirective + ' ' + e.blockedURI); });
  function ok(c, m) { out.push((c ? 'OK   ' : 'FALLO ') + m); if (!c) { fails++; } }
  window.addEventListener('load', function () { setTimeout(run, 300); });
  function run() {
    try {
      var vw = function () { return document.querySelector('.lux-vw'); };
      var links = document.querySelectorAll('#g1 a.lux-g-a');
      ok(!vw(), 'el visor no existe hasta el primer uso');
      var k = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ctrlKey: true });
      links[0].dispatchEvent(k);
      ok(!vw() || vw().hidden, 'Ctrl+clic no abre el visor (se respeta el navegador)');
      var c = new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
      links[1].dispatchEvent(c);
      ok(c.defaultPrevented && vw() && !vw().hidden, 'un clic abre el visor y cancela la navegación');
      ok(vw().getAttribute('role') === 'dialog' && vw().getAttribute('aria-modal') === 'true', 'es un diálogo modal accesible');
      ok(document.documentElement.classList.contains('lux-vw-lock'), 'se bloquea el desplazamiento de la página');
      ok(vw().classList.contains('lux-vw--slide'), 'aplica la transición elegida');
      var img = vw().querySelector('.lux-vw__img'), cnt = vw().querySelector('.lux-vw__count'), cap = vw().querySelector('.lux-vw__cap');
      ok(/rejilla\/preview\.webp$/.test(img.getAttribute('src')) && cnt.textContent === '2 de 3' && cap.textContent === 'Segunda', 'abre en la foto pulsada con contador y pie');
      ok(document.activeElement === vw().querySelector('.lux-vw__close'), 'el foco pasa al botón de cerrar');
      var kd = function (key, o) { var e = new KeyboardEvent('keydown', Object.assign({ key: key, bubbles: true, cancelable: true }, o || {})); document.activeElement.dispatchEvent(e); return e; };
      kd('ArrowRight'); ok(cnt.textContent === '3 de 3' && cap.hidden, 'flecha derecha avanza; sin pie no se muestra');
      kd('ArrowRight'); ok(cnt.textContent === '1 de 3', 'con bucle vuelve a la primera');
      kd('ArrowLeft'); ok(cnt.textContent === '3 de 3', 'y retrocede a la última');
      var f = []; for (var i = 0; i < 4; i++) { var e = kd('Tab'); f.push(document.activeElement.className); } ok(f.every(function (c) { return c.indexOf('lux-vw__btn') >= 0; }), 'Tab no saca el foco del visor (' + f.length + ' pulsaciones)');
      // gestos táctiles
      try {
        var t0 = new Touch({ identifier: 1, target: vw(), clientX: 300, clientY: 100 }), t1 = new Touch({ identifier: 1, target: vw(), clientX: 100, clientY: 100 });
        vw().dispatchEvent(new TouchEvent('touchstart', { touches: [t0], changedTouches: [t0], bubbles: true }));
        vw().dispatchEvent(new TouchEvent('touchend', { touches: [], changedTouches: [t1], bubbles: true }));
        ok(cnt.textContent === '1 de 3', 'deslizar a la izquierda avanza');
      } catch (er) { out.push('INFO gestos táctiles no probables aquí: ' + er.message); }
      var op = links[1];
      kd('Escape');
      ok(vw().hidden && !document.documentElement.classList.contains('lux-vw-lock'), 'Escape cierra y libera el desplazamiento');
      ok(document.activeElement === op, 'el foco vuelve al enlace que abrió el visor');
      // sin bucle: los extremos desactivan la flecha
      var l2 = document.querySelectorAll('#g2 a.lux-g-a');
      l2[0].dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
      ok(vw().classList.contains('lux-vw--fade') && vw().querySelector('.lux-vw__prev').disabled && !vw().querySelector('.lux-vw__next').disabled && vw().querySelector('.lux-vw__count').textContent === '', 'sin bucle ni contador: «Anterior» desactivado en la primera');
      vw().querySelector('.lux-vw__close').click();
      ok(vw().hidden, 'el botón cerrar funciona');
      // disuasión opcional
      var ctx2 = new MouseEvent('contextmenu', { bubbles: true, cancelable: true }); document.querySelector('#g2 img').dispatchEvent(ctx2);
      var ctx1 = new MouseEvent('contextmenu', { bubbles: true, cancelable: true }); document.querySelector('#g1 img').dispatchEvent(ctx1);
      ok(ctx2.defaultPrevented && !ctx1.defaultPrevented, 'el menú contextual solo se desactiva donde se pidió');
      var d2 = new Event('dragstart', { bubbles: true, cancelable: true }); document.querySelector('#g2 img').dispatchEvent(d2);
      var d1 = new Event('dragstart', { bubbles: true, cancelable: true }); document.querySelector('#g1 img').dispatchEvent(d1);
      ok(d2.defaultPrevented && !d1.defaultPrevented, 'el arrastre solo se impide donde se pidió');
      ok(violations.length === 0, 'ninguna violación de CSP: ' + violations.join(' | '));
    } catch (e) { fails++; out.push('FALLO excepción: ' + e.message + ' ' + (e.stack || '').split('\n')[1]); }
    document.getElementById('result').textContent = out.join('\n') + '\nRESULT ' + (fails ? 'FAIL' : 'PASS');
  }
})();
