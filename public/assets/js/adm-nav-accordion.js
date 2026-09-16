/**
 * Admin sidebar section accordion — one open section at a time.
 */
(function () {
  'use strict';

  function panels(nav) {
    return Array.prototype.slice.call(nav.querySelectorAll('[data-adm-nav-group]'));
  }

  function setOpen(group, open) {
    const toggle = group.querySelector('[data-adm-nav-toggle]');
    const panel = group.querySelector('[data-adm-nav-panel]');
    group.classList.toggle('is-open', open);
    if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (panel) {
      panel.setAttribute('aria-hidden', open ? 'false' : 'true');
      if (open) panel.removeAttribute('inert');
      else panel.setAttribute('inert', '');
    }
  }

  function closeOthers(nav, except) {
    panels(nav).forEach(function (group) {
      if (group !== except && group.classList.contains('is-open')) {
        setOpen(group, false);
      }
    });
  }

  function initNav(nav) {
    if (nav.dataset.admNavAccordionReady === '1') return;
    nav.dataset.admNavAccordionReady = '1';

    let activeGroup = nav.querySelector('.adm-nav-group.is-open');
    const activeItem = nav.querySelector('.adm-nav-item.is-active');
    if (activeItem) {
      activeGroup = activeItem.closest('[data-adm-nav-group]') || activeGroup;
    }

    panels(nav).forEach(function (group) {
      setOpen(group, group === activeGroup);
    });

    nav.addEventListener('click', function (e) {
      const toggle = e.target.closest('[data-adm-nav-toggle]');
      if (!toggle || !nav.contains(toggle)) return;
      e.preventDefault();
      const group = toggle.closest('[data-adm-nav-group]');
      if (!group) return;
      const willOpen = !group.classList.contains('is-open');
      if (willOpen) closeOthers(nav, group);
      setOpen(group, willOpen);
    });
  }

  function boot() {
    document.querySelectorAll('.adm-nav[data-adm-nav-accordion]').forEach(initNav);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
