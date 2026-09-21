/**
 * MedConnect cross-tab auth sync + centralized session-expiration handling.
 * Notification only for login/logout signals — PHP session is the authority.
 */
(function (global) {
  'use strict';

  var CHANNEL = 'medconnect-auth';
  var STORAGE_KEY = 'mc_auth_event';
  var FORM_DRAFT_PREFIX = 'mc_safe_form_draft:';
  var MESSAGE = 'Your session has expired. Please log in again.';
  var DEACTIVATED_MESSAGE = 'Your account has been deactivated. Please contact the administrator.';
  var SESSION_CODES = {
    session_expired: true,
    session_invalid: true,
    unauthorized: true,
    SESSION_EXPIRED: true,
    account_deactivated: true,
    ACCOUNT_DEACTIVATED: true,
  };
  var NON_SESSION_CODES = {
    csrf_invalid: true,
    forbidden: true,
    account_setup_required: true,
    demo_api_key_invalid: true,
    demo_session_invalid: true,
  };

  var bus = null;
  var handling = false;
  var fetchPatched = false;

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

  function sessionExpiredUrl() {
    return assetBase() + '/index.php?session_expired=1';
  }

  function accountDeactivatedUrl() {
    return assetBase() + '/index.php?account_deactivated=1';
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

  function isSensitiveName(name) {
    return /password|passwd|csrf|token|secret|otp|pin|ssn|national.?id|auth|file|document|upload|license|prc/i.test(String(name || ''));
  }

  function preserveSafeForms() {
    try {
      var forms = document.querySelectorAll('form');
      forms.forEach(function (form, idx) {
        if (!form || form.dataset.mcSkipDraft === '1') return;
        var draft = {};
        var hasValue = false;
        Array.prototype.forEach.call(form.elements || [], function (el) {
          if (!el || !el.name) return;
          if (el.type === 'password' || el.type === 'file' || el.type === 'hidden') return;
          if (isSensitiveName(el.name) || isSensitiveName(el.id)) return;
          if (el.disabled) return;
          if (el.type === 'checkbox' || el.type === 'radio') {
            if (el.checked) {
              draft[el.name] = el.value;
              hasValue = true;
            }
            return;
          }
          if (el.tagName === 'SELECT' || el.tagName === 'TEXTAREA' || el.tagName === 'INPUT') {
            var val = el.value;
            if (val != null && String(val) !== '') {
              draft[el.name] = String(val);
              hasValue = true;
            }
          }
        });
        if (!hasValue) return;
        var key = FORM_DRAFT_PREFIX + (form.id || form.getAttribute('name') || ('form' + idx)) + ':' + (global.location.pathname || '');
        global.sessionStorage.setItem(key, JSON.stringify({
          path: global.location.pathname || '',
          savedAt: Date.now(),
          values: draft,
        }));
      });
    } catch (_) { /* storage blocked */ }
  }

  function restoreSafeForms() {
    try {
      var path = global.location.pathname || '';
      Object.keys(global.sessionStorage || {}).forEach(function (key) {
        if (key.indexOf(FORM_DRAFT_PREFIX) !== 0) return;
        var raw = global.sessionStorage.getItem(key);
        if (!raw) return;
        var parsed = null;
        try { parsed = JSON.parse(raw); } catch (_) { parsed = null; }
        if (!parsed || !parsed.values || parsed.path !== path) return;
        // Drop drafts older than 2 hours.
        if (parsed.savedAt && (Date.now() - parsed.savedAt) > 2 * 60 * 60 * 1000) {
          global.sessionStorage.removeItem(key);
          return;
        }
        var formKey = key.slice(FORM_DRAFT_PREFIX.length).split(':')[0];
        var form = document.getElementById(formKey) || document.querySelector('form[name="' + formKey + '"]');
        if (!form) return;
        Object.keys(parsed.values).forEach(function (name) {
          if (isSensitiveName(name)) return;
          var els = form.elements[name];
          if (!els) return;
          var list = els.length !== undefined && els.tagName !== 'SELECT' ? els : [els];
          Array.prototype.forEach.call(list, function (el) {
            if (!el || el.type === 'password' || el.type === 'file' || el.type === 'hidden') return;
            if (el.type === 'checkbox' || el.type === 'radio') {
              el.checked = String(el.value) === String(parsed.values[name]);
              return;
            }
            el.value = parsed.values[name];
          });
        });
        global.sessionStorage.removeItem(key);
      });
    } catch (_) { /* ignore */ }
  }

  function lockUi(message) {
    global.__mcSessionExpired = true;
    try {
      document.documentElement.classList.add('mc-session-expired');
      document.body && document.body.classList.add('mc-session-expired');
    } catch (_) { /* ignore */ }

    var nodes = document.querySelectorAll('form, button, input, select, textarea, a.mc-btn, [type="submit"]');
    Array.prototype.forEach.call(nodes, function (el) {
      if (!el) return;
      if (el.tagName === 'A') {
        el.setAttribute('aria-disabled', 'true');
        el.style.pointerEvents = 'none';
        return;
      }
      try {
        el.disabled = true;
        el.setAttribute('aria-disabled', 'true');
      } catch (_) { /* ignore */ }
    });

    var alertEls = document.querySelectorAll('.mc-form-alert, #alert, [data-session-expired-msg]');
    Array.prototype.forEach.call(alertEls, function (el) {
      el.textContent = message || MESSAGE;
      el.classList.add('is-visible');
      if (el.className.indexOf('mc-form-alert') !== -1 && el.className.indexOf('mc-form-alert--') === -1) {
        el.className = 'mc-form-alert mc-form-alert--error is-visible';
      }
    });

    if (!document.querySelector('.mc-session-expired-banner')) {
      var banner = document.createElement('div');
      banner.className = 'mc-session-expired-banner';
      banner.setAttribute('role', 'alert');
      banner.textContent = message || MESSAGE;
      banner.style.cssText = 'position:fixed;z-index:100000;left:0;right:0;top:0;padding:12px 16px;background:#92400e;color:#fff;text-align:center;font-weight:600;box-shadow:0 2px 8px rgba(0,0,0,.2);';
      (document.body || document.documentElement).appendChild(banner);
    }
  }

  function redirectTo(url) {
    var target = url || sessionExpiredUrl();
    try {
      global.location.replace(target);
    } catch (_) {
      global.location.href = target;
    }
  }

  function handleSessionExpired(data) {
    if (handling) return true;
    handling = true;
    global.__mcSessionExpired = true;

    var code = data && (data.code || data.error) ? String(data.code || data.error) : '';
    var isDeactivated = code === 'account_deactivated' || code === 'ACCOUNT_DEACTIVATED';
    var message = (data && data.message)
      ? String(data.message)
      : (isDeactivated ? DEACTIVATED_MESSAGE : MESSAGE);
    var redirect = (data && data.redirect)
      ? String(data.redirect)
      : (isDeactivated ? accountDeactivatedUrl() : sessionExpiredUrl());

    preserveSafeForms();
    lockUi(message);
    broadcast('logout');

    global.setTimeout(function () {
      redirectTo(redirect);
    }, 700);

    return true;
  }

  function isSessionExpiredPayload(data, status) {
    if (status && status !== 401) return false;
    if (!data || typeof data !== 'object') {
      // Bare 401 from authenticated API endpoints is treated as session loss.
      return status === 401;
    }
    if (data.authenticated === true) return false;
    var code = String(data.code || data.error || '');
    if (NON_SESSION_CODES[code]) return false;
    if (SESSION_CODES[code]) return true;
    if (data.error === 'SESSION_EXPIRED') return true;
    if (data.authenticated === false && (status === 401 || data.success === false)) return true;
    if (status === 401 && data.success === false) return true;
    return false;
  }

  function inspectResponseForExpiry(res, input) {
    if (!res || res.status !== 401) return Promise.resolve(res);
    // Soft probes may return non-JSON; avoid locking on unrelated 401s from public pages.
    if (isPublicLanding()) return Promise.resolve(res);

    var url = '';
    try {
      if (typeof input === 'string') url = input;
      else if (input && typeof input.url === 'string') url = input.url;
      else if (input && input.href) url = String(input.href);
    } catch (_) { /* ignore */ }
    // session_status is a soft probe (HTTP 200 when logged out); never treat it as action failure.
    if (/\/app\/api\/auth\/session_status\.php/i.test(url)) {
      return Promise.resolve(res);
    }

    return res.clone().json().then(function (data) {
      if (isSessionExpiredPayload(data, res.status)) {
        handleSessionExpired(data);
      }
      return res;
    }).catch(function () {
      handleSessionExpired({ message: MESSAGE, redirect: sessionExpiredUrl() });
      return res;
    });
  }

  function patchFetch() {
    if (fetchPatched || typeof global.fetch !== 'function') return;
    fetchPatched = true;
    var originalFetch = global.fetch.bind(global);
    global.fetch = function (input, init) {
      if (global.__mcSessionExpired) {
        return Promise.reject(new Error('SESSION_EXPIRED'));
      }
      return originalFetch(input, init).then(function (res) {
        return inspectResponseForExpiry(res, input);
      });
    };
  }

  function redirectToSignIn() {
    handleSessionExpired({ message: MESSAGE, redirect: sessionExpiredUrl() });
  }

  function verifyThenRedirectIfLoggedOut() {
    if (handling || global.__mcSessionExpired) return;
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
          handleSessionExpired(data || { message: MESSAGE, redirect: sessionExpiredUrl() });
        }
      })
      .catch(function () {
        handleSessionExpired({ message: MESSAGE, redirect: sessionExpiredUrl() });
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
    if (handling || global.__mcSessionExpired) return;
    probeAccountStatus();
  });

  function probeAccountStatus() {
    if (handling || global.__mcSessionExpired || isPublicLanding()) return;
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
          handleSessionExpired(data);
        }
      })
      .catch(function () { /* ignore network blips */ });
  }

  // Poll while the tab is visible so Super Admin deactivation ends access quickly.
  var statusTimer = null;
  function startStatusPoll() {
    if (statusTimer || isPublicLanding()) return;
    statusTimer = global.setInterval(function () {
      if (document.hidden) return;
      probeAccountStatus();
    }, 12000);
  }
  function stopStatusPoll() {
    if (!statusTimer) return;
    global.clearInterval(statusTimer);
    statusTimer = null;
  }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) stopStatusPoll();
    else startStatusPoll();
  });
  startStatusPoll();

  // Block native form submits once expired (covers non-fetch posts).
  document.addEventListener('submit', function (ev) {
    if (!global.__mcSessionExpired) return;
    ev.preventDefault();
    ev.stopPropagation();
    handleSessionExpired({ message: MESSAGE, redirect: sessionExpiredUrl() });
  }, true);

  patchFetch();

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restoreSafeForms);
  } else {
    restoreSafeForms();
  }

  global.MedConnectAuthSync = {
    notifyLogout: function () { broadcast('logout'); },
    notifyLogin: function () { broadcast('login'); },
    verify: verifyThenRedirectIfLoggedOut,
    handleExpired: handleSessionExpired,
    isExpiredPayload: function (data, status) { return isSessionExpiredPayload(data, status || 401); },
    lockUi: lockUi,
    preserveSafeForms: preserveSafeForms,
  };

  global.MedConnectSession = global.MedConnectAuthSync;
})(window);
