/* LuxEditor · recuperación tras recarga o cierre accidental: copia local del borrador, ligada al contenido del servidor del que parte. */
(function (root) {
  'use strict';
  var LE = root.LuxEditor = root.LuxEditor || {};
  function hash(s) { var h = 5381; for (var i = 0; i < s.length; i++) { h = ((h << 5) + h + s.charCodeAt(i)) | 0; } return (h >>> 0).toString(36) + ':' + s.length; }
  function store(st) { return st || (function () { try { return root.localStorage; } catch (e) { return null; } })(); }
  var S = {
    hash: hash,
    save: function (key, base, json, st) { try { var s = store(st); if (s) { s.setItem(key, JSON.stringify({ base: hash(base), json: json, at: Date.now() })); } } catch (e) { /* cuota o modo privado: la recuperación es opcional */ } },
    /** Devuelve el borrador local si parte del mismo contenido del servidor y es distinto de él. */
    load: function (key, base, st) {
      try {
        var s = store(st), raw = s && s.getItem(key); if (!raw) { return null; }
        var d = JSON.parse(raw);
        if (!d || d.base !== hash(base)) { s.removeItem(key); return null; }
        return d.json === base ? null : d;
      } catch (e) { return null; }
    },
    clear: function (key, st) { try { var s = store(st); if (s) { s.removeItem(key); } } catch (e) { /* nada */ } }
  };
  LE.storage = S;
  if (typeof module !== 'undefined' && module.exports) { module.exports = S; }
})(typeof window !== 'undefined' ? window : globalThis);
