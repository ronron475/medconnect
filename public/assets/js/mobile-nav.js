/**
 * Mobile sidebar drawer — unified across patient, provider, admin, superadmin, and BHW.
 */
(function () {
  'use strict';

  const SIDEBAR_SELECTORS = '.sidebar, .sb-aqua, .adm-sidebar, #bhw-sidebar';
  const TOGGLE_SELECTORS = '#mcNavToggle, #pdHamburger, [data-sidebar-toggle]';
  const NAV_LINK_SELECTORS =
    'a.sb-item, a.sba-item, a.adm-nav-item, a.adm-profile, a.adm-logo, a.sb-logo, a.sba-logo, .sb-nav a, .sba-nav a, .adm-nav a';
  const MINI_KEY = 'mc_sidebar_mini';
  const TOGGLE_DEBOUNCE_MS = 400;
  const BURGER_ANIM_MS = 260;
  const GHOST_CLICK_GUARD_MS = 400;
  const OPEN_GUARD_MS = 450;

  const IS_TOUCH =
    ('ontouchstart' in window) || (navigator.maxTouchPoints && navigator.maxTouchPoints > 0);
  const TAP_EVENT = IS_TOUCH ? 'pointerup' : 'click';

  let closeGuardTimer = null;
  let openGuardTimer = null;
  let lastToggleAt = 0;

  function getSidebar() {
    return document.querySelector(SIDEBAR_SELECTORS);
  }

  function prefersMiniMode() {
    return window.matchMedia('(min-width: 768px)').matches;
  }

  function isMobileDrawer() {
    return window.matchMedia('(max-width: 1024px)').matches;
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

  function closeThemeMenus() {
    document.querySelectorAll('.mc-theme-toggle.is-open').forEach((wrap) => {
      wrap.classList.remove('is-open');
      const btn = wrap.querySelector('.mc-theme-toggle__btn');
      if (btn) btn.setAttribute('aria-expanded', 'false');
    });
  }

  function syncToggleAria(open) {
    document.querySelectorAll(TOGGLE_SELECTORS).forEach((btn) => {
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.setAttribute(
        'aria-label',
        open ? 'Close navigation menu' : 'Open navigation menu'
      );
    });
  }

  function setOpen(open) {
    const sidebar = getSidebar();
    const backdrop = getBackdrop();

    if (!sidebar) return;

    const wasOpen = sidebar.classList.contains('is-open');

    if (open) {
      closeThemeMenus();
    } else {
      window.clearTimeout(closeGuardTimer);
      window.clearTimeout(openGuardTimer);
      document.body.classList.remove('mc-nav-closing', 'mc-nav-opening');
    }

    sidebar.classList.toggle('is-open', open);
    backdrop.classList.toggle('is-visible', open);
    backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
    document.body.classList.toggle('mc-nav-open', open);
    syncToggleAria(open);

    if (open && !wasOpen && isMobileDrawer()) {
      armOpenGuard();
    }
  }

  /**
   * Dark mode removes sidebar transform transitions, so the drawer appears instantly
   * under the hamburger. The synthetic click then hits the dashboard logo link.
   */
  function armOpenGuard() {
    if (!IS_TOUCH) return;

    document.body.classList.add('mc-nav-opening');
    window.clearTimeout(openGuardTimer);
    openGuardTimer = window.setTimeout(() => {
      document.body.classList.remove('mc-nav-opening');
    }, OPEN_GUARD_MS);

    window.setTimeout(() => {
      document.addEventListener('click', blockOpeningGhostClick, { capture: true, once: true });
    }, 0);
  }

  function blockOpeningGhostClick(e) {
    if (!document.body.classList.contains('mc-nav-opening')) return;

    const sidebar = getSidebar();
    if (!sidebar || !sidebar.contains(e.target)) return;

    const link = e.target.closest ? e.target.closest('a[href]') : null;
    if (!link) return;

    e.preventDefault();
    e.stopImmediatePropagation();
  }

  function setMini(mini) {
    const sidebar = getSidebar();
    const backdrop = document.querySelector('.mc-nav-backdrop');

    if (sidebar) sidebar.classList.remove('is-open');
    if (backdrop) {
      backdrop.classList.remove('is-visible');
      backdrop.setAttribute('aria-hidden', 'true');
    }
    document.body.classList.remove('mc-nav-open', 'mc-nav-closing', 'mc-nav-opening');

    document.body.classList.toggle('mc-sidebar-mini', !!mini);
    try {
      localStorage.setItem(MINI_KEY, mini ? '1' : '0');
    } catch (_) { /* ignore */ }

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

  function open() {
    if (!isMobileDrawer()) return;
    setOpen(true);
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

  function isOpen() {
    const sidebar = getSidebar();
    return !!(sidebar && sidebar.classList.contains('is-open'));
  }

  /** Close drawer after nav tap without replaying ghost clicks on the page behind. */
  function closeAfterNav() {
    if (!isMobileDrawer()) return;
    document.body.classList.add('mc-nav-closing');
    window.clearTimeout(closeGuardTimer);
    close();
    closeGuardTimer = window.setTimeout(() => {
      document.body.classList.remove('mc-nav-closing');
    }, GHOST_CLICK_GUARD_MS);
  }

  function suppressGhostClick(el) {
    if (!IS_TOUCH || !el) return;
    el.addEventListener(
      'click',
      (e) => {
        e.preventDefault();
        e.stopPropagation();
      },
      { capture: true, once: true }
    );
  }

  function bindTap(el, handler) {
    el.addEventListener(TAP_EVENT, (e) => {
      handler(e);
    });
    suppressGhostClick(el);
  }

  function bindNavLink(link) {
    link.addEventListener('click', () => {
      if (!isMobileDrawer()) return;

      const href = link.getAttribute('href');
      if (!href || href.charAt(0) === '#') {
        close();
        return;
      }

      closeAfterNav();
    });
  }

  function bindToggle(btn) {
    bindTap(btn, (e) => {
      const now = Date.now();
      if (now - lastToggleAt < TOGGLE_DEBOUNCE_MS) return;
      lastToggleAt = now;
      e.preventDefault();
      e.stopPropagation();
      toggle();

      btn.classList.remove('mc-burger-animate');
      void btn.offsetWidth;
      btn.classList.add('mc-burger-animate');
      window.setTimeout(() => {
        btn.classList.remove('mc-burger-animate');
      }, BURGER_ANIM_MS);
    });
  }

  function bindBackdrop(backdrop) {
    bindTap(backdrop, (e) => {
      if (e.target !== backdrop) return;
      e.preventDefault();
      e.stopPropagation();
      close();
    });
  }

  function init() {
    const sidebar = getSidebar();
    if (!sidebar) return;

    if (!sidebar.id) {
      sidebar.id = 'app-sidebar';
    }

    close();

    document.querySelectorAll(TOGGLE_SELECTORS).forEach((btn) => {
      if (!btn.hasAttribute('aria-controls')) {
        btn.setAttribute('aria-controls', sidebar.id);
      }
      btn.style.pointerEvents = 'auto';
      bindToggle(btn);
    });

    const backdrop = getBackdrop();
    bindBackdrop(backdrop);

    sidebar.querySelectorAll(NAV_LINK_SELECTORS).forEach(bindNavLink);

    sidebar.querySelectorAll('[data-logout-trigger]').forEach((btn) => {
      bindTap(btn, () => {
        if (isMobileDrawer()) closeAfterNav();
      });
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && isOpen()) close();
    });

    window.addEventListener('resize', () => {
      if (prefersMiniMode()) close();
    });

    window.addEventListener('pageshow', (e) => {
      if (e.persisted) close();
    });

    restoreMini();
    initSidebarNavScroll(sidebar);
  }

  function getNavContainer(sidebar) {
    return sidebar.querySelector('.adm-nav, .sb-nav, .sba-nav');
  }

  function navScrollStorageKey(sidebar) {
    const nav = getNavContainer(sidebar);
    const portalNav = nav?.dataset?.portalNav;
    if (portalNav) {
      return 'mc_nav_scroll_' + portalNav;
    }
    const bodyPortal = document.body.dataset.portal;
    if (bodyPortal) {
      return 'mc_nav_scroll_' + bodyPortal;
    }
    if (sidebar.classList.contains('adm-sidebar--bhw')) {
      return 'mc_nav_scroll_bhw';
    }
    if (sidebar.classList.contains('sb-aqua')) {
      return 'mc_nav_scroll_provider';
    }
    return 'mc_nav_scroll_patient';
  }

  function isActiveNavItemVisible(nav, active) {
    const navRect = nav.getBoundingClientRect();
    const activeRect = active.getBoundingClientRect();
    return activeRect.top >= navRect.top - 2 && activeRect.bottom <= navRect.bottom + 2;
  }

  function initSidebarNavScroll(sidebar) {
    const nav = getNavContainer(sidebar);
    if (!nav) return;

    const storageKey = navScrollStorageKey(sidebar);

    const persistScroll = () => {
      try {
        sessionStorage.setItem(storageKey, String(nav.scrollTop));
      } catch (_) { /* ignore */ }
    };

    const restoreScroll = () => {
      try {
        const saved = sessionStorage.getItem(storageKey);
        if (saved !== null) {
          nav.scrollTop = parseInt(saved, 10) || 0;
          return true;
        }
      } catch (_) { /* ignore */ }
      return false;
    };

    restoreScroll();

    const active = nav.querySelector('.adm-nav-item.is-active, .sba-item.is-active, .sb-item.active');

    const finalizeScroll = () => {
      if (restoreScroll()) {
        if (active && !isActiveNavItemVisible(nav, active)) {
          active.scrollIntoView({ block: 'nearest', inline: 'nearest' });
          persistScroll();
        }
        return;
      }

      if (active) {
        active.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        persistScroll();
      }
    };

    requestAnimationFrame(() => {
      requestAnimationFrame(finalizeScroll);
    });

    let scrollPersistTimer;
    nav.addEventListener('scroll', () => {
      clearTimeout(scrollPersistTimer);
      scrollPersistTimer = window.setTimeout(persistScroll, 80);
    }, { passive: true });

    nav.querySelectorAll('a').forEach((link) => {
      link.addEventListener('pointerdown', persistScroll, { passive: true });
      link.addEventListener('click', persistScroll);
    });

    window.addEventListener('pagehide', persistScroll);
  }

  window.MedConnectMobileNav = {
    open,
    close,
    toggle,
    isOpen,
    closeAfterNav,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
