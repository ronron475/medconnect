/**
 * Logout — all portal roles (admin, patient, provider, BHW).
 * Binds topbar/sidebar logout controls and shows confirm modal when available.
 */
(function (global) {
  'use strict';

  function assetBase() {
    return (global.ASSET_BASE || global.APP_BASE || document.body?.dataset?.assetBase || '').replace(/\/$/, '');
  }

  function performLogout() {
    if (global.MedConnectLoginLoading && typeof global.MedConnectLoginLoading.performLogout === 'function') {
      global.MedConnectLoginLoading.performLogout();
      return;
    }
    global.location.href = assetBase() + '/app/api/logout.php';
  }

  function isLogoutHref(href) {
    if (!href) return false;
    return /\/app\/api\/logout\.php|\/logout\.php(?:\?|#|$)/i.test(href);
  }

  function showModalOrLogout() {
    if (typeof global.showLogoutModal === 'function') {
      global.showLogoutModal();
      return;
    }
    performLogout();
  }

  function bindTrigger(el) {
    if (!el || el.dataset.logoutBound === '1') return;
    el.dataset.logoutBound = '1';

    el.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      showModalOrLogout();
    });
  }

  function bindConfirm(el) {
    if (!el || el.dataset.logoutConfirmBound === '1') return;
    el.dataset.logoutConfirmBound = '1';

    el.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (typeof global.hideLogoutModal === 'function') {
        global.hideLogoutModal();
      }
      performLogout();
    });
  }

  function init() {
    document.querySelectorAll('[data-logout-trigger]').forEach(bindTrigger);
    document.querySelectorAll('#logout-modal-yes, [data-logout-confirm]').forEach(bindConfirm);

    document.querySelectorAll('a.adm-logout, a.topbar-logout').forEach(function (el) {
      if (!isLogoutHref(el.getAttribute('href'))) return;
      bindTrigger(el);
    });

    document.querySelectorAll('a[href*="logout.php"]').forEach(function (el) {
      if (el.dataset.logoutBound === '1') return;
      if (!isLogoutHref(el.getAttribute('href'))) return;
      bindTrigger(el);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  global.MedConnectLogout = {
    perform: performLogout,
    confirm: showModalOrLogout,
    init: init,
  };
})(window);
