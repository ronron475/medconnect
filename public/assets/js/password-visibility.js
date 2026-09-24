/**
 * Shared password visibility toggles — single source of truth.
 * Hidden password → eye-off (slash). Visible password → open eye (no slash).
 * Icon-only: no "Show" text, no green/teal highlight backgrounds.
 */
(function () {
  'use strict';

  if (window.MedConnectPasswordVisibility && window.MedConnectPasswordVisibility.__canonical) {
    return;
  }

  var TOGGLE_SEL =
    '.toggle-pwd, .fp-toggle-pwd, .ps-toggle-pw, .pts-toggle-pw, .mc-password-toggle, [data-mc-pw-toggle], [data-toggle-password]';

  var EYE_OPEN =
    '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/>' +
    '<circle cx="12" cy="12" r="3"/>';
  var EYE_OFF =
    '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-7-10-7a18.45 18.45 0 0 1 5.06-5.94"/>' +
    '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/>' +
    '<line x1="2" y1="2" x2="22" y2="22"/>';

  function injectCss() {
    if (document.getElementById('mc-pw-vis-css')) return;
    var style = document.createElement('style');
    style.id = 'mc-pw-vis-css';
    style.textContent = [
      '.toggle-pwd, .fp-toggle-pwd, .ps-toggle-pw, .pts-toggle-pw, .pts-input-action.pts-toggle-pw, .mc-password-toggle {',
      '  font-size: 0 !important;',
      '  line-height: 0 !important;',
      '  background: transparent !important;',
      '  box-shadow: none !important;',
      '}',
      '.toggle-pwd:hover, .toggle-pwd:active, .toggle-pwd:focus, .toggle-pwd[aria-pressed="true"], .toggle-pwd.is-revealed,',
      '.fp-toggle-pwd:hover, .fp-toggle-pwd:active, .fp-toggle-pwd:focus, .fp-toggle-pwd[aria-pressed="true"], .fp-toggle-pwd.is-revealed,',
      '.ps-toggle-pw:hover, .ps-toggle-pw:active, .ps-toggle-pw:focus, .ps-toggle-pw[aria-pressed="true"], .ps-toggle-pw.is-revealed,',
      '.pts-toggle-pw:hover, .pts-toggle-pw:active, .pts-toggle-pw:focus, .pts-toggle-pw[aria-pressed="true"], .pts-toggle-pw.is-revealed,',
      '.pts-input-action.pts-toggle-pw:hover, .pts-input-action.pts-toggle-pw:active, .pts-input-action.pts-toggle-pw:focus,',
      '.pts-input-action.pts-toggle-pw[aria-pressed="true"], .pts-input-action.pts-toggle-pw.is-revealed,',
      '.mc-password-toggle:hover, .mc-password-toggle:active, .mc-password-toggle:focus, .mc-password-toggle[aria-pressed="true"], .mc-password-toggle.is-revealed {',
      '  background: transparent !important;',
      '  box-shadow: none !important;',
      '}',
      '.toggle-pwd svg, .fp-toggle-pwd svg, .ps-toggle-pw svg, .pts-toggle-pw svg, .mc-password-toggle svg {',
      '  display: block !important;',
      '  flex-shrink: 0;',
      '  pointer-events: none;',
      '}',
    ].join('\n');
    (document.head || document.documentElement).appendChild(style);
  }

  function resolveInput(btn) {
    if (!btn) return null;
    var id =
      btn.getAttribute('data-mc-pw-toggle') ||
      btn.getAttribute('data-toggle-password') ||
      btn.getAttribute('data-target') ||
      '';
    if (id) {
      var byId = document.getElementById(id);
      if (byId) return byId;
    }
    var wrap = btn.closest(
      '.mc-pw-field, .setup-pw-wrap, .reset-input-wrap, .mc-password-wrap, .ps-password-wrap, .pts-input-group, .input-wrap, .fp-field, .form-group'
    );
    if (!wrap) return null;
    return (
      wrap.querySelector('input[type="password"]') ||
      wrap.querySelector('input[name*="password"], input[name*="Password"], input[id*="password"], input[id*="Password"]') ||
      wrap.querySelector('input')
    );
  }

  function renderEye(btn, passwordHidden) {
    // Always rebuild a single SVG so the slash cannot linger from a previous icon.
    Array.from(btn.childNodes).forEach(function (node) {
      if (node.nodeType === 1 || node.nodeType === 3) {
        btn.removeChild(node);
      }
    });

    var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('width', '16');
    svg.setAttribute('height', '16');
    svg.setAttribute('viewBox', '0 0 24 24');
    svg.setAttribute('fill', 'none');
    svg.setAttribute('stroke', 'currentColor');
    svg.setAttribute('stroke-width', '2');
    svg.setAttribute('stroke-linecap', 'round');
    svg.setAttribute('stroke-linejoin', 'round');
    svg.setAttribute('aria-hidden', 'true');
    svg.innerHTML = passwordHidden ? EYE_OFF : EYE_OPEN;
    btn.appendChild(svg);

    btn.setAttribute('aria-pressed', passwordHidden ? 'false' : 'true');
    btn.classList.toggle('is-revealed', !passwordHidden);

    var labelBase = 'password';
    var input = resolveInput(btn);
    if (input && input.id) {
      if (/confirm/i.test(input.id)) labelBase = 'confirm password';
      else if (/current/i.test(input.id)) labelBase = 'current password';
      else if (/new/i.test(input.id)) labelBase = 'new password';
    }
    btn.setAttribute('aria-label', (passwordHidden ? 'Show ' : 'Hide ') + labelBase);
  }

  function applyIcon(btn, passwordHidden) {
    if (!btn) return;
    renderEye(btn, !!passwordHidden);
  }

  function syncButton(btn) {
    var input = resolveInput(btn);
    if (!input) {
      applyIcon(btn, true);
      return;
    }
    applyIcon(btn, input.type === 'password');
  }

  function toggle(btn) {
    var input = resolveInput(btn);
    if (!input) return;
    var reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    applyIcon(btn, input.type === 'password');
  }

  function onClick(e) {
    var btn = e.target && e.target.closest ? e.target.closest(TOGGLE_SEL) : null;
    if (!btn || btn.disabled) return;
    // Ignore if click is inside a non-password control accidentally matching.
    e.preventDefault();
    e.stopPropagation();
    if (typeof e.stopImmediatePropagation === 'function') {
      e.stopImmediatePropagation();
    }
    toggle(btn);
  }

  function init(root) {
    injectCss();
    var scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll(TOGGLE_SEL).forEach(syncButton);
  }

  if (!window.__mcPwVisDelegation) {
    window.__mcPwVisDelegation = true;
    document.addEventListener('click', onClick, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      init(document);
    });
  } else {
    init(document);
  }

  window.MedConnectPasswordVisibility = {
    __canonical: true,
    init: init,
    applyIcon: applyIcon,
    syncButton: syncButton,
    toggle: toggle,
    resolveInput: resolveInput,
  };
})();
