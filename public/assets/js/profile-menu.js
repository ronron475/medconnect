/**
 * medConnect — Profile menu dropdown (all roles)
 * Trigger: [data-profile-menu-trigger]
 * Menu:    [data-profile-menu]
 */
(function () {
  'use strict';

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function clamp(v, min, max) { return Math.min(max, Math.max(min, v)); }

  function positionMenu(menu, trigger) {
    const r = trigger.getBoundingClientRect();
    menu.hidden = false;
    const mr = menu.getBoundingClientRect();
    const margin = 10;

    let left = r.right - mr.width;
    let top = r.bottom + 10;

    left = clamp(left, margin, window.innerWidth - mr.width - margin);
    top = clamp(top, margin, window.innerHeight - mr.height - margin);

    menu.style.left = left + 'px';
    menu.style.top = top + 'px';
  }

  function closeAll() {
    qsa('[data-profile-menu]').forEach((menu) => {
      menu.hidden = true;
    });
    qsa('[data-profile-menu-trigger]').forEach((t) => {
      t.setAttribute('aria-expanded', 'false');
    });
  }

  function initOne(trigger) {
    const id = trigger.getAttribute('data-profile-menu-trigger') || '';
    const menu = id ? qs('[data-profile-menu="' + CSS.escape(id) + '"]') : null;
    if (!menu) return;

    if (trigger.dataset.profMenuBound === '1') return;
    trigger.dataset.profMenuBound = '1';

    trigger.setAttribute('aria-haspopup', 'menu');
    trigger.setAttribute('aria-expanded', 'false');

    trigger.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      const isOpen = menu.hidden === false;
      closeAll();
      if (!isOpen) {
        positionMenu(menu, trigger);
        trigger.setAttribute('aria-expanded', 'true');
      }
    });

    // Menu action: sign out button can reuse existing logout trigger behavior
    qsa('[data-profmenu-logout]', menu).forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        closeAll();
        // Prefer existing modal/handler if present
        const anyLogout = qs('[data-logout-trigger]');
        if (anyLogout) {
          anyLogout.click();
        } else if (typeof window.showLogoutModal === 'function') {
          window.showLogoutModal();
        } else {
          window.location.href = (document.body.dataset.assetBase || '') + '/logout.php';
        }
      });
    });
  }

  function init() {
    qsa('[data-profile-menu-trigger]').forEach(initOne);

    document.addEventListener('click', (e) => {
      const t = e.target;
      const insideMenu = t && t.closest ? t.closest('[data-profile-menu]') : null;
      const isTrigger = t && t.closest ? t.closest('[data-profile-menu-trigger]') : null;
      if (!insideMenu && !isTrigger) closeAll();
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeAll();
    });

    window.addEventListener('resize', closeAll);
    window.addEventListener('scroll', closeAll, true);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

