/**
 * Mobile sidebar drawer — works across patient, provider, admin, and BHW layouts.
 */
(function () {
  'use strict';

  const SIDEBAR_SELECTORS = '.sidebar, .sb-aqua, .adm-sidebar, .bhw-sidebar';
  const TOGGLE_SELECTORS = '#mcNavToggle, #pdHamburger, [data-sidebar-toggle]';
  const CLICK_EVENT = ('ontouchstart' in window) ? 'pointerup' : 'click';

  function getSidebar() {
    return document.querySelector(SIDEBAR_SELECTORS);
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

  function close() {
    setOpen(false);
  }

  function toggle() {
    const sidebar = getSidebar();
    if (!sidebar) return;
    setOpen(!sidebar.classList.contains('is-open'));
  }

  function init() {
    const sidebar = getSidebar();
    if (!sidebar) return;

    if (!sidebar.id) {
      sidebar.id = 'app-sidebar';
    }

    document.querySelectorAll(TOGGLE_SELECTORS).forEach((btn) => {
      if (!btn.hasAttribute('aria-controls')) {
        btn.setAttribute('aria-controls', sidebar.id);
      }
      // Use pointer events on mobile so taps always register.
      btn.style.pointerEvents = 'auto';
      btn.addEventListener(CLICK_EVENT, (e) => {
        e.preventDefault();
        toggle();
      });
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
      if (window.matchMedia('(min-width: 1025px)').matches) {
        close();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
