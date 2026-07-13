/**
 * Mobile sidebar drawer — works across patient, provider, admin, and BHW layouts.
 */
(function () {
  'use strict';

  const SIDEBAR_SELECTORS = '.sidebar, .sb-aqua, .adm-sidebar, .bhw-sidebar';
  const TOGGLE_SELECTORS = '#mcNavToggle, #pdHamburger, [data-sidebar-toggle]';
  const MINI_KEY = 'mc_sidebar_mini';
  const TOGGLE_EVENTS = ['click', 'pointerup'];
  const TOGGLE_DEBOUNCE_MS = 450;
  const BURGER_ANIM_MS = 260;

  function getSidebar() {
    return document.querySelector(SIDEBAR_SELECTORS);
  }

  function prefersMiniMode() {
    // Match the reference behavior: collapse-to-icons for tablet+desktop,
    // and keep drawer only on small phones.
    return window.matchMedia('(min-width: 768px)').matches;
  }

  function getBackdrop() {
    let el = document.querySelector('.mc-nav-backdrop');
    if (!el) {
      el = document.createElement('div');
      el.className = 'mc-nav-backdrop';
      el.setAttribute('aria-hidden', 'true');
      document.body.appendChild(el);
    }
    return el;
  }

  function setOpen(open) {
    const sidebar = getSidebar();
    const backdrop = getBackdrop();
    const toggles = document.querySelectorAll(TOGGLE_SELECTORS);

    if (!sidebar) return;

    sidebar.classList.toggle('is-open', open);
    backdrop.classList.toggle('is-visible', open);
    document.body.classList.toggle('mc-nav-open', open);

    toggles.forEach((btn) => {
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  function setMini(mini) {
    const sidebar = getSidebar();
    const backdrop = document.querySelector('.mc-nav-backdrop');

    // Ensure drawer state is cleared when switching to mini.
    if (sidebar) sidebar.classList.remove('is-open');
    if (backdrop) backdrop.classList.remove('is-visible');
    document.body.classList.remove('mc-nav-open');

    document.body.classList.toggle('mc-sidebar-mini', !!mini);
    try {
      localStorage.setItem(MINI_KEY, mini ? '1' : '0');
    } catch (_) { /* ignore */ }

    // Keep aria state meaningful on desktop: expanded=false means "collapsed".
    document.querySelectorAll(TOGGLE_SELECTORS).forEach((btn) => {
      btn.setAttribute('aria-expanded', mini ? 'false' : 'true');
    });
  }

  function restoreMini() {
    if (!prefersMiniMode()) return;
    try {
      const raw = localStorage.getItem(MINI_KEY);
      if (raw === '1') setMini(true);
    } catch (_) { /* ignore */ }
  }

  function close() {
    setOpen(false);
  }

  function toggle() {
    const sidebar = getSidebar();
    if (!sidebar) return;
    if (prefersMiniMode()) {
      setMini(!document.body.classList.contains('mc-sidebar-mini'));
      return;
    }
    setOpen(!sidebar.classList.contains('is-open'));
  }

  function init() {
    const sidebar = getSidebar();
    if (!sidebar) return;

    if (!sidebar.id) {
      sidebar.id = 'app-sidebar';
    }

    let lastToggleAt = 0;
    function bindToggle(btn) {
      // Use both click + pointerup for maximum compatibility:
      // some Windows touch-capable devices expose ontouchstart,
      // but users still click with mouse (no pointerup fired).
      TOGGLE_EVENTS.forEach((evt) => {
        btn.addEventListener(evt, (e) => {
          const now = Date.now();
          if (now - lastToggleAt < TOGGLE_DEBOUNCE_MS) return;
          lastToggleAt = now;
          e.preventDefault();
          toggle();

          // Micro animation on every toggle (all roles)
          btn.classList.remove('mc-burger-animate');
          // Force reflow so animation restarts reliably
          void btn.offsetWidth;
          btn.classList.add('mc-burger-animate');
          window.setTimeout(() => {
            btn.classList.remove('mc-burger-animate');
          }, BURGER_ANIM_MS);
        });
      });
    }

    document.querySelectorAll(TOGGLE_SELECTORS).forEach((btn) => {
      if (!btn.hasAttribute('aria-controls')) {
        btn.setAttribute('aria-controls', sidebar.id);
      }
      // Use pointer events on mobile so taps always register.
      btn.style.pointerEvents = 'auto';
      bindToggle(btn);
    });

    getBackdrop().addEventListener('click', close);

    sidebar.querySelectorAll('a.sb-item, a.sba-item, a.adm-nav-item, a.bhw-sb-item, a.bhw-sb-subitem, .sb-nav a').forEach((link) => {
      link.addEventListener('click', () => {
        if (window.matchMedia('(max-width: 1024px)').matches) {
          close();
        }
      });
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') close();
    });

    window.addEventListener('resize', () => {
      // If we crossed into mini-capable sizes, ensure drawer is closed.
      if (prefersMiniMode()) close();
    });

    restoreMini();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
