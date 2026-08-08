/**
 * Mobile sidebar drawer — unified across patient, provider, admin, superadmin, and BHW.
 * Drawer closes on navigation so destination pages render in the main content area.
 */
(function () {
  'use strict';

  const SIDEBAR_SELECTORS = '.sidebar, .sb-aqua, .adm-sidebar, #bhw-sidebar';
  const TOGGLE_SELECTORS = '#mcNavToggle, #pdHamburger, [data-sidebar-toggle]';
  const NAV_LINK_SELECTORS =
    'a.sb-item, a.sba-item, a.adm-nav-item, a.adm-profile, a.adm-logo, a.sb-logo, a.sba-logo, .sb-nav a, .sba-nav a, .adm-nav a';
  const MINI_KEY = 'mc_sidebar_mini';
  const DRAWER_OPEN_KEY = 'mc_sidebar_drawer_open';
  const TOGGLE_DEBOUNCE_MS = 400;
  const BURGER_ANIM_MS = 260;
  const OPEN_GUARD_MS = 500;

  const IS_TOUCH =
    ('ontouchstart' in window) || (navigator.maxTouchPoints && navigator.maxTouchPoints > 0);

  let openGuardTimer = null;
  let openingClickBlocker = null;
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

  function isOpeningGuarded() {
    return document.body.classList.contains('mc-nav-opening');
  }

  function persistDrawerOpen(open) {
    try {
      if (open && isMobileDrawer()) {
        sessionStorage.setItem(DRAWER_OPEN_KEY, '1');
      } else {
        sessionStorage.removeItem(DRAWER_OPEN_KEY);
      }
    } catch (_) { /* ignore */ }
  }

  function isSidebarLogoLink(link) {
    return (
      link.classList.contains('adm-logo') ||
      link.classList.contains('sb-logo') ||
      link.classList.contains('sba-logo')
    );
  }

  function normalizeNavPath(href) {
    try {
      const url = new URL(href, window.location.href);
      const path = url.pathname.replace(/\/+$/, '') || '/';
      return path + url.search;
    } catch (_) {
      return href;
    }
  }

  function closeDrawerForNav() {
    persistDrawerOpen(false);
    disarmOpenGuard();
    document.body.classList.add('mc-nav-closing');
    setOpen(false, { persist: false, skipGuard: true });
    window.setTimeout(() => {
      document.body.classList.remove('mc-nav-closing');
    }, 300);
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

  function disarmOpenGuard() {
    window.clearTimeout(openGuardTimer);
    document.body.classList.remove('mc-nav-opening');
    if (openingClickBlocker) {
      document.removeEventListener('click', openingClickBlocker, true);
      openingClickBlocker = null;
    }
  }

  /**
   * Dark mode CSS removes sidebar transform transitions, so the drawer can appear
   * instantly under the hamburger. The synthetic click then activates the dashboard logo.
   */
  function armOpenGuard() {
    if (!IS_TOUCH || !isMobileDrawer()) return;

    disarmOpenGuard();
    document.body.classList.add('mc-nav-opening');

    openingClickBlocker = function blockOpeningGhostClick(e) {
      if (!isOpeningGuarded()) return;

      const link = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!link || !isSidebarLogoLink(link)) return;

      e.preventDefault();
      e.stopImmediatePropagation();
    };

    document.addEventListener('click', openingClickBlocker, true);

    openGuardTimer = window.setTimeout(disarmOpenGuard, OPEN_GUARD_MS);
  }

  function setOpen(open, opts) {
    const sidebar = getSidebar();
    const backdrop = getBackdrop();
    const persist = !opts || opts.persist !== false;
    const skipGuard = !!(opts && opts.skipGuard);

    if (!sidebar) return;

    const wasOpen = sidebar.classList.contains('is-open');

    if (open) {
      closeThemeMenus();
    } else {
      disarmOpenGuard();
      document.body.classList.remove('mc-nav-closing');
    }

    sidebar.classList.toggle('is-open', open);
    backdrop.classList.toggle('is-visible', open);
    backdrop.setAttribute('aria-hidden', open ? 'false' : 'true');
    document.body.classList.toggle('mc-nav-open', open);
    syncToggleAria(open);

    if (persist) {
      persistDrawerOpen(open);
    }

    // Ghost-click guard only for user-opened drawers (not restored after navigation).
    if (open && !wasOpen && !skipGuard) {
      armOpenGuard();
    }
  }

  function setMini(mini) {
    const sidebar = getSidebar();
    const backdrop = document.querySelector('.mc-nav-backdrop');

    if (sidebar) sidebar.classList.remove('is-open');
    if (backdrop) {
      backdrop.classList.remove('is-visible');
      backdrop.setAttribute('aria-hidden', 'true');
    }
    document.body.classList.remove('mc-nav-open', 'mc-nav-closing');
    disarmOpenGuard();
    persistDrawerOpen(false);

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

  /**
   * Close the mobile drawer after in-portal navigation.
   */
  function closeAfterNav() {
    closeDrawerForNav();
  }

  function onToggleClick(e) {
    const now = Date.now();
    if (now - lastToggleAt < TOGGLE_DEBOUNCE_MS) {
      e.preventDefault();
      e.stopPropagation();
      return;
    }
    lastToggleAt = now;
    e.preventDefault();
    e.stopPropagation();
    toggle();

    const btn = e.currentTarget;
    btn.classList.remove('mc-burger-animate');
    void btn.offsetWidth;
    btn.classList.add('mc-burger-animate');
    window.setTimeout(() => {
      btn.classList.remove('mc-burger-animate');
    }, BURGER_ANIM_MS);
  }

  function onBackdropClick(e) {
    if (e.target !== e.currentTarget) return;
    if (isOpeningGuarded()) {
      e.preventDefault();
      e.stopPropagation();
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    close();
  }

  function bindNavLink(link) {
    link.addEventListener('click', (e) => {
      if (!isMobileDrawer()) return;

      const href = link.getAttribute('href');
      if (!href || href === '#' || href.startsWith('javascript:')) return;

      if (isOpeningGuarded() && isSidebarLogoLink(link)) {
        e.preventDefault();
        e.stopPropagation();
        return;
      }

      const targetPath = normalizeNavPath(href);
      const currentPath = normalizeNavPath(window.location.href);
      const isSamePage = targetPath === currentPath;

      closeDrawerForNav();

      if (isSamePage) {
        e.preventDefault();
      }
    });
  }

  function restoreDrawerState() {
    // Never reopen the drawer after navigation or bfcache restore.
    persistDrawerOpen(false);

    if (!isMobileDrawer()) {
      setOpen(false, { persist: false, skipGuard: true });
      return;
    }

    setOpen(false, { persist: false, skipGuard: true });
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
      btn.style.pointerEvents = 'auto';
      btn.style.touchAction = 'manipulation';
      btn.addEventListener('click', onToggleClick);
    });

    const backdrop = getBackdrop();
    backdrop.addEventListener('click', onBackdropClick);

    sidebar.querySelectorAll(NAV_LINK_SELECTORS).forEach(bindNavLink);

    // Logout still closes the drawer and clears persisted open state.
    sidebar.querySelectorAll('[data-logout-trigger]').forEach((btn) => {
      btn.addEventListener('click', () => {
        persistDrawerOpen(false);
        if (!isMobileDrawer() || isOpeningGuarded()) return;
        setOpen(false, { persist: false });
      });
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && isOpen()) close();
    });

    window.addEventListener('resize', () => {
      if (prefersMiniMode()) {
        // Desktop/tablet mini mode — drawer off-canvas state does not apply.
        setOpen(false, { persist: false, skipGuard: true });
      } else if (isMobileDrawer()) {
        restoreDrawerState();
      }
    });

    window.addEventListener('pageshow', () => {
      restoreDrawerState();
    });

    restoreMini();
    restoreDrawerState();
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
