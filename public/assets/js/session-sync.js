/**
 * MedConnect cross-tab auth sync.
 * Notification only — PHP session is the authority.
 */
(function (global) {
  'use strict';

  var CHANNEL = 'medconnect-auth';
  var STORAGE_KEY = 'mc_auth_event';
  var bus = null;
  var handling = false;

  try {
    if (typeof global.BroadcastChannel !== 'undefined') {
      bus = new BroadcastChannel(CHANNEL);
    }
  } catch (_) {
    bus = null;
  }

  function assetBase() {
    return (global.ASSET_BASE || global.APP_BASE || document.body?.dataset?.assetBase || '')
      .toString()
      .replace(/\/$/, '');
  }

  function signInUrl() {
    return assetBase() + '/index.php?signin=1';
  }

  function statusUrl() {
    return assetBase() + '/app/api/auth/session_status.php';
  }

  function isPublicLanding() {
    var path = (global.location.pathname || '').toLowerCase();
    return /\/index\.php$/.test(path) || path.endsWith('/') || /\/medconnect\/?$/.test(path);
  }

  function broadcast(type) {
    var payload = { type: type, t: Date.now() };
    try {
      global.localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
      global.localStorage.removeItem(STORAGE_KEY);
    } catch (_) { /* private mode */ }
    if (bus) {
      try { bus.postMessage(payload); } catch (_) { /* ignore */ }
    }
  }

  function redirectToSignIn() {
    if (handling) return;
    handling = true;
    try {
      global.location.replace(signInUrl());
    } catch (_) {
      global.location.href = signInUrl();
    }
  }

  function verifyThenRedirectIfLoggedOut() {
    if (handling) return;
    fetch(statusUrl(), {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-MC-No-Loader': '1',
      },
    })
      .then(function (res) { return res.json().catch(function () { return null; }); })
      .then(function (data) {
        if (!data || data.authenticated !== true) {
          redirectToSignIn();
        }
      })
      .catch(function () {
        redirectToSignIn();
      });
  }

  function onLoginSignal() {
    if (!isPublicLanding()) return;
    fetch(statusUrl(), {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-MC-No-Loader': '1',
      },
    })
      .then(function (res) { return res.json().catch(function () { return null; }); })
      .then(function (data) {
        if (data && data.authenticated === true) {
          global.location.reload();
        }
      })
      .catch(function () { /* ignore */ });
  }

  function handleEvent(data) {
    if (!data || !data.type) return;
    if (data.type === 'logout') {
      verifyThenRedirectIfLoggedOut();
      return;
    }
    if (data.type === 'login') {
      onLoginSignal();
    }
  }

  if (bus) {
    bus.onmessage = function (ev) {
      handleEvent(ev && ev.data);
    };
  }

  global.addEventListener('storage', function (ev) {
    if (ev.key !== STORAGE_KEY || !ev.newValue) return;
    try {
      handleEvent(JSON.parse(ev.newValue));
    } catch (_) { /* ignore */ }
  });

  // Soft visibility check: if another tab logged out, catch it when user returns.
  global.addEventListener('visibilitychange', function () {
    if (document.visibilityState !== 'visible') return;
    if (isPublicLanding()) return;
    fetch(statusUrl(), {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-MC-No-Loader': '1',
      },
    })
      .then(function (res) { return res.json().catch(function () { return null; }); })
      .then(function (data) {
        if (data && data.authenticated === false) {
          redirectToSignIn();
        }
      })
      .catch(function () { /* ignore network blips */ });
  });

  global.MedConnectAuthSync = {
    notifyLogout: function () { broadcast('logout'); },
    notifyLogin: function () { broadcast('login'); },
    verify: verifyThenRedirectIfLoggedOut,
  };
})(window);
