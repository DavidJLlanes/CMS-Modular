(function () {
  'use strict';
  var form = document.querySelector('form[data-autosave]');
  if (!form || !window.fetch || !window.FormData) { return; }
  var url = form.getAttribute('data-autosave');
  var status = document.getElementById('autosave-status');
  var last = snapshot();
  var busy = false;
  function snapshot() {
    var parts = [];
    new FormData(form).forEach(function (v, k) { if (k !== 'csrf') { parts.push(k + '=' + v); } });
    return parts.join('&');
  }
  function say(t) { if (status) { status.textContent = t; } }
  function tick() {
    var now = snapshot();
    if (busy || now === last) { return; }
    busy = true;
    fetch(url, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); })
      .then(function (res) {
        if (res.j && res.j.ok) { last = now; say('Autoguardado a las ' + res.j.at); }
        else { say((res.j && res.j.error) || 'No se pudo autoguardar.'); if (res.s === 409) { last = now; } }
      })
      .catch(function () { say('Sin conexión: el autoguardado se reintentará.'); })
      .then(function () { busy = false; });
  }
  setInterval(tick, 20000);
  form.addEventListener('submit', function () { last = snapshot(); });
})();
