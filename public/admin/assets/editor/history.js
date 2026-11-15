/* LuxEditor · deshacer/rehacer por instantáneas del modelo (cadenas JSON), con fusión de cambios seguidos al teclear. */
(function (root) {
  'use strict';
  var LE = root.LuxEditor = root.LuxEditor || {};
  function History(initial, limit) { this.limit = limit || 200; this.stack = [initial]; this.i = 0; }
  History.prototype.push = function (s) {
    if (s === this.stack[this.i]) { return false; }
    this.stack = this.stack.slice(0, this.i + 1); this.stack.push(s);
    if (this.stack.length > this.limit) { this.stack.shift(); }
    this.i = this.stack.length - 1; return true;
  };
  History.prototype.canUndo = function () { return this.i > 0; };
  History.prototype.canRedo = function () { return this.i < this.stack.length - 1; };
  History.prototype.undo = function () { return this.canUndo() ? this.stack[--this.i] : null; };
  History.prototype.redo = function () { return this.canRedo() ? this.stack[++this.i] : null; };
  History.prototype.current = function () { return this.stack[this.i]; };
  LE.History = History;
  if (typeof module !== 'undefined' && module.exports) { module.exports = History; }
})(typeof window !== 'undefined' ? window : globalThis);
