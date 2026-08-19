/**
 * MedConnect shared debounce utility.
 * Usage: McDebounce.debounce(fn, ms)
 *        McDebounce.attachInput(selector, handler, ms)
 */
(function (global) {
  'use strict';

  function debounce(fn, delay) {
    var timer = null;
    return function () {
      var ctx = this, args = arguments;
      if (timer) clearTimeout(timer);
      timer = setTimeout(function () {
        timer = null;
        fn.apply(ctx, args);
      }, delay || 300);
    };
  }

  function attachInput(selector, handler, delay) {
    var els = document.querySelectorAll(selector);
    if (!els.length) return;
    var debounced = debounce(handler, delay || 300);
    els.forEach(function (el) {
      el.addEventListener('input', debounced);
    });
  }

  global.McDebounce = { debounce: debounce, attachInput: attachInput };
})(window);
