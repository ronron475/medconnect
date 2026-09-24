/**
 * Shared password visibility toggles (eye / eye-off).
 * Hidden password → eye-off (slash). Visible password → open eye.
 * Bind buttons with data-mc-pw-toggle="<inputId>".
 */
(function () {
  'use strict';

  var EYE_OPEN =
    '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>';
  var EYE_OFF =
    '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-7-10-7a18.45 18.45 0 0 1 5.06-5.94"/>' +
    '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/>' +
    '<line x1="2" y1="2" x2="22" y2="22"/>';

  function resolveInput(btn) {
    var id = btn.getAttribute('data-mc-pw-toggle');
    if (id) {
      var byId = document.getElementById(id);
      if (byId) return byId;
    }
    var wrap = btn.closest('.mc-pw-field, .setup-pw-wrap, .reset-input-wrap, .mc-password-wrap, .ps-password-wrap, .pts-input-group, .input-wrap');
    return wrap ? wrap.querySelector('input') : null;
  }

  function applyIcon(btn, hidden) {
    btn.setAttribute('aria-pressed', hidden ? 'false' : 'true');
    btn.setAttribute('aria-label', hidden ? 'Show password' : 'Hide password');
    btn.classList.toggle('is-revealed', !hidden);

    var eyeOpen = btn.querySelector('.mc-eye-open, .pts-eye-open, .ps-eye-open');
    var eyeClosed = btn.querySelector('.mc-eye-closed, .pts-eye-closed, .ps-eye-closed');
    if (eyeOpen && eyeClosed) {
      eyeOpen.hidden = hidden;
      eyeClosed.hidden = !hidden;
      return;
    }
    var svg = btn.querySelector('svg');
    if (svg) svg.innerHTML = hidden ? EYE_OFF : EYE_OPEN;
  }

  function bind(btn) {
    if (!btn || btn.dataset.mcPwBound === '1') return;
    btn.dataset.mcPwBound = '1';
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var input = resolveInput(btn);
      if (!input) return;
      var reveal = input.type === 'password';
      input.type = reveal ? 'text' : 'password';
      applyIcon(btn, input.type === 'password');
    });
  }

  function init(root) {
    var scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll('[data-mc-pw-toggle]').forEach(bind);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { init(document); });
  } else {
    init(document);
  }

  window.MedConnectPasswordVisibility = { init: init, bind: bind, applyIcon: applyIcon };
})();
