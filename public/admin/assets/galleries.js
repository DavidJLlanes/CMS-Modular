/* Panel de galerías: ordenar con ↑ ↓ y elegir el foco pulsando sobre la miniatura. Sin él, todo funciona con los campos numéricos. */
(function () {
  'use strict';
  var list = document.querySelector('[data-rows]');
  if (!list) { return; }

  function rows() { return Array.prototype.slice.call(list.querySelectorAll('[data-row]')); }
  function renumber() { rows().forEach(function (r, i) { var o = r.querySelector('[data-order]'); if (o) { o.value = i + 1; } }); }

  rows().forEach(function (row) {
    var holder = row.querySelector('[data-move]');
    if (holder) {
      [['↑', 'Subir', -1], ['↓', 'Bajar', 1]].forEach(function (d) {
        var b = document.createElement('button');
        b.type = 'button'; b.textContent = d[0]; b.setAttribute('aria-label', d[1]);
        b.addEventListener('click', function () {
          var all = rows(), i = all.indexOf(row), j = i + d[2];
          if (j < 0 || j >= all.length) { return; }
          if (d[2] < 0) { list.insertBefore(row, all[j]); } else { list.insertBefore(all[j], row); }
          renumber();
          b.focus();
        });
        holder.appendChild(b);
      });
    }
    var th = row.querySelector('[data-focus]'), fx = row.querySelector('[data-fx]'), fy = row.querySelector('[data-fy]'), mark = row.querySelector('[data-mark]');
    function place() {
      if (!mark) { return; }
      var x = fx && fx.value !== '' ? +fx.value : (fx ? +fx.placeholder : 50), y = fy && fy.value !== '' ? +fy.value : (fy ? +fy.placeholder : 50);
      mark.style.left = (isNaN(x) ? 50 : x) + '%';
      mark.style.top = (isNaN(y) ? 50 : y) + '%';
    }
    if (th && fx && fy) {
      th.addEventListener('click', function (e) {
        var b = th.getBoundingClientRect();
        if (!b.width || !b.height) { return; }
        fx.value = Math.max(0, Math.min(100, Math.round((e.clientX - b.left) / b.width * 100)));
        fy.value = Math.max(0, Math.min(100, Math.round((e.clientY - b.top) / b.height * 100)));
        place();
      });
      fx.addEventListener('input', place); fy.addEventListener('input', place);
      place();
    }
  });
})();
