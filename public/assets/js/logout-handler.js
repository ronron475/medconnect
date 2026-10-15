/**
 * Logout — all portal roles (admin, patient, provider, BHW).
 * Binds topbar/sidebar logout controls and shows confirm modal when available.
 */
(function (global) {
  'use strict';

  function assetBase() {
    return (global.ASSET_BASE || global.APP_BASE || document.body?.dataset?.assetBase || '').replace(/\/$/, '');
  }

  function getCsrfToken() {
    return global.CSRF_TOKEN || document.body?.dataset?.csrf || document.querySelector('meta[name="csrf-token"]')?.content || '';
  }

  function performLogout() {
    if (global.MedConnectLoginLoading && typeof global.MedConnectLoginLoading.performLogout === 'function') {
      global.MedConnectLoginLoading.performLogout();
      return;
    }
    if (global.MedConnectGlobalLoader && typeof global.MedConnectGlobalLoader.performLogout === 'function') {
      global.MedConnectGlobalLoader.performLogout();
      return;
    }
    var base = assetBase();
    var token = getCsrfToken();
    var headers = {
      'X-Requested-With': 'XMLHttpRequest',
      Accept: 'application/json',
      'X-MC-No-Loader': '1',
    };
    if (token) {
      headers['X-CSRF-TOKEN'] = token;
    }
    fetch(base + '/app/api/logout.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: new URLSearchParams({ csrf_token: token }),
    })
      .then(function (res) { return res.json().catch(function () { return null; }); })
      .then(function (data) {
        try {
          if (global.MedConnectAuthSync && typeof global.MedConnectAuthSync.notifyLogout === 'function') {
            global.MedConnectAuthSync.notifyLogout();
          }
        } catch (_) { /* ignore */ }
        var next = (data && data.redirect) ? data.redirect : (base + '/index.php');
        global.location.replace(next);
      })
      .catch(function () {
        try {
          if (global.MedConnectAuthSync && typeof global.MedConnectAuthSync.notifyLogout === 'function') {
            global.MedConnectAuthSync.notifyLogout();
          }
        } catch (_) { /* ignore */ }
        global.location.href = base + '/app/api/logout.php' + (token ? ('?csrf_token=' + encodeURIComponent(token)) : '');
      });
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
