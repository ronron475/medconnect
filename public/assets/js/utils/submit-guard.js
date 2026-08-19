/**
 * MedConnect Submit Guard — prevents double-click submissions.
 * Auto-initializes on forms with [data-submit-guard] or can be called manually.
 *
 * Usage:
 *   <form data-submit-guard>
 *     <button type="submit" data-loading-text="Saving...">Save</button>
 *   </form>
 *
 * Or: McSubmitGuard.guard(buttonEl)  /  McSubmitGuard.release(buttonEl)
 */
(function (global) {
  'use strict';

  function guard(btn) {
    if (!btn || btn.disabled) return false;
    btn.disabled = true;
    btn.dataset.originalText = btn.textContent;
    btn.textContent = btn.dataset.loadingText || 'Processing...';
    btn.classList.add('is-loading');
    return true;
  }

  function release(btn) {
    if (!btn) return;
    btn.disabled = false;
    if (btn.dataset.originalText) {
      btn.textContent = btn.dataset.originalText;
      delete btn.dataset.originalText;
    }
    btn.classList.remove('is-loading');
  }

  function init() {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form || !form.matches || !form.matches('[data-submit-guard]')) return;
      var btn = form.querySelector('[type="submit"]') || form.querySelector('button:not([type="button"])');
      if (!btn) return;
      if (!guard(btn)) {
        e.preventDefault();
        return;
      }
      setTimeout(function () { release(btn); }, 10000);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  global.McSubmitGuard = { guard: guard, release: release };
})(window);
