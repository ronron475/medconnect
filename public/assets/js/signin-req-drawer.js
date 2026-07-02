/**
 * Sign-In page — left FAB + registration requirements drawer.
 * Independent from landing-fab.js (bottom-right landing navigation).
 */
(function () {
  'use strict';

  if (!document.body.classList.contains('landing-page')) return;

  const root = document.getElementById('signin-req-drawer-root');
  const fab = document.getElementById('signin-req-fab');
  const fabWrap = fab ? fab.closest('.signin-req-fab-wrap') : null;
  const drawer = document.getElementById('signin-req-drawer');
  const panel = document.getElementById('signin-req-drawer-panel');
  const overlay = document.getElementById('signin-req-drawer-overlay');
  const pwdInput = document.getElementById('signin-req-demo-password');
  const pwdRules = {
    len: document.getElementById('signin-req-pc-len'),
    upper: document.getElementById('signin-req-pc-upper'),
    lower: document.getElementById('signin-req-pc-lower'),
    num: document.getElementById('signin-req-pc-num'),
    special: document.getElementById('signin-req-pc-special'),
  };

  if (!root || !fab || !fabWrap || !drawer || !panel) return;

  const CLOSE_MS = 280;
  const FAB_POS_KEY = 'medconnect_signin_req_fab_pos';
  let isOpen = false;
  let closeTimer = null;
  let focusTrapHandler = null;

  const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

  function isSignInActive() {
    return document.body.classList.contains('signin-active');
  }

  function getFocusableElements() {
    return Array.from(panel.querySelectorAll(FOCUSABLE)).filter((el) => {
      return el.offsetParent !== null && !el.hasAttribute('disabled');
    });
  }

  function trapFocus(e) {
    if (e.key !== 'Tab' || !isOpen) return;

    const focusables = getFocusableElements();
    if (!focusables.length) return;

    const first = focusables[0];
    const last = focusables[focusables.length - 1];

    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus({ preventScroll: true });
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus({ preventScroll: true });
    }
  }

  function enableFocusTrap() {
    focusTrapHandler = trapFocus;
    document.addEventListener('keydown', focusTrapHandler);
  }

  function disableFocusTrap() {
    if (focusTrapHandler) {
      document.removeEventListener('keydown', focusTrapHandler);
      focusTrapHandler = null;
    }
  }

  function setDrawerOpen(open) {
    isOpen = open;
    fab.setAttribute('aria-expanded', open ? 'true' : 'false');
    fab.setAttribute('aria-label', open
      ? 'Close patient registration requirements'
      : 'Open patient registration requirements');

    if (open) {
      drawer.hidden = false;
      panel.setAttribute('aria-hidden', 'false');
      root.setAttribute('aria-hidden', 'false');
      document.body.classList.add('signin-req-drawer-open');

      requestAnimationFrame(() => {
        drawer.classList.add('is-open');
        enableFocusTrap();
        const closeBtn = panel.querySelector('.signin-req-drawer__close');
        if (closeBtn) closeBtn.focus({ preventScroll: true });
      });
      return;
    }

    drawer.classList.remove('is-open');
    panel.setAttribute('aria-hidden', 'true');
    disableFocusTrap();
    document.body.classList.remove('signin-req-drawer-open');

    window.clearTimeout(closeTimer);
    closeTimer = window.setTimeout(() => {
      drawer.hidden = true;
      if (!isSignInActive()) {
        root.setAttribute('aria-hidden', 'true');
      }
      fab.focus({ preventScroll: true });
    }, CLOSE_MS);
  }

  function openDrawer() {
    if (!isSignInActive()) return;
    setDrawerOpen(true);
  }

  function closeDrawer() {
    if (!isOpen) return;
    setDrawerOpen(false);
  }

  function toggleDrawer() {
    if (isOpen) closeDrawer();
    else openDrawer();
  }

  function updatePasswordChecklist() {
    if (!pwdInput) return;
    const value = pwdInput.value || '';
    const checks = {
      len: value.length >= 8,
      upper: /[A-Z]/.test(value),
      lower: /[a-z]/.test(value),
      num: /\d/.test(value),
      special: /[!@#$%^&*]/.test(value),
    };

    Object.keys(checks).forEach((key) => {
      const el = pwdRules[key];
      if (el) el.classList.toggle('pass', checks[key]);
    });
  }

  function onSignInChange() {
    closeDrawer();
    if (!isSignInActive()) {
      root.setAttribute('aria-hidden', 'true');
    } else {
      root.setAttribute('aria-hidden', 'false');
    }
  }

  /* ── Draggable FAB — all sign-in roles (patient, provider, admin, BHW, superadmin) ── */
  if (window.MedConnectDraggableFab) {
    window.MedConnectDraggableFab.init({
      handle: fab,
      wrap: fabWrap,
      storageKey: FAB_POS_KEY,
      enabled: isSignInActive,
      onTap: toggleDrawer,
      dragBodyClass: 'signin-req-fab-dragging',
    });
  }

  drawer.querySelectorAll('[data-signin-drawer-close]').forEach((el) => {
    el.addEventListener('click', closeDrawer);
  });

  if (overlay) {
    overlay.addEventListener('click', closeDrawer);
  }

  if (pwdInput) {
    pwdInput.addEventListener('input', updatePasswordChecklist);
  }

  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape' || !isOpen) return;
    e.preventDefault();
    e.stopPropagation();
    closeDrawer();
  });

  document.addEventListener('medconnect:signin', onSignInChange);

  window.MedConnectSignInReqDrawer = {
    open: openDrawer,
    close: closeDrawer,
    isOpen: () => isOpen,
  };
})();
