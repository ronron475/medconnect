/**
 * Post-authentication and post-logout transition overlay.
 */
(function (global) {
  'use strict';

  const MIN_DISPLAY_MS = 2400;
  const EXIT_FADE_MS = 380;
  let isActive = false;

  const COPY = {
    login: {
      status: 'Loading…',
      hint: 'Please wait while we prepare your dashboard.',
      sr: 'Signing you in. Please wait.',
    },
    logout: {
      status: 'Signing out…',
      hint: 'Please wait while we secure your session.',
      sr: 'Signing you out. Please wait.',
    },
  };

  function assetBase() {
    const root = document.getElementById('medconnectThemeRoot');
    const fromDom = document.body.getAttribute('data-asset-base')
      || (root && root.getAttribute('data-asset-base'))
      || '';
    return (fromDom || global.ASSET_BASE || global.APP_BASE || '').replace(/\/$/, '');
  }

  function resolveTheme() {
    const resolved = document.documentElement.getAttribute('data-theme-resolved');
    if (resolved === 'dark' || resolved === 'light') {
      return resolved;
    }
    return global.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function dismissSignInUi() {
    const modal = document.getElementById('signin-modal');
    if (modal) {
      modal.hidden = true;
      modal.style.display = 'none';
    }
    const alertBox = document.getElementById('alert');
    if (alertBox) {
      alertBox.className = 'alert';
      alertBox.textContent = '';
    }
  }

  function hideLogoutModal() {
    if (typeof global.hideLogoutModal === 'function') {
      global.hideLogoutModal();
    }
  }

  /**
   * @param {string} redirectUrl
   * @param {{ mode?: 'login'|'logout', beforeRedirect?: Promise<string|void>, prefetch?: boolean }} [options]
   */
  function show(redirectUrl, options) {
    options = options || {};
    if (isActive || !redirectUrl || typeof redirectUrl !== 'string') {
      return;
    }
    isActive = true;

    const mode = options.mode === 'logout' ? 'logout' : 'login';
    const copy = COPY[mode];

    if (mode === 'login') {
      dismissSignInUi();
    } else {
      hideLogoutModal();
    }

    const base = assetBase();
    const logoSrc = base + '/assets/img/medcon_logo.png';

    const overlay = document.createElement('div');
    overlay.id = 'mc-login-loading';
    overlay.className = 'mc-login-loading';
    overlay.dataset.theme = resolveTheme();
    overlay.dataset.mode = mode;
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'mc-login-loading-title');
    overlay.setAttribute('aria-describedby', 'mc-login-loading-desc');
    overlay.setAttribute('aria-busy', 'true');
    overlay.setAttribute('tabindex', '-1');

    overlay.innerHTML =
      '<div class="mc-login-loading__panel">' +
        '<div class="mc-login-loading__logo-wrap">' +
          '<img class="mc-login-loading__logo" src="' + logoSrc + '" alt="" width="72" height="72" decoding="async" />' +
        '</div>' +
        '<h1 class="mc-login-loading__brand" id="mc-login-loading-title">' +
          'med<span class="mc-login-loading__brand-accent">Connect</span>' +
        '</h1>' +
        '<p class="mc-login-loading__tagline">Telemedicine Consultation System</p>' +
        '<div class="mc-login-loading__spinner" role="status" aria-label="Loading"></div>' +
        '<p class="mc-login-loading__status" aria-live="polite">' + copy.status + '</p>' +
        '<p class="mc-login-loading__hint" id="mc-login-loading-desc">' + copy.hint + '</p>' +
        '<span class="mc-login-loading__sr-only">' + copy.sr + '</span>' +
      '</div>';

    document.body.appendChild(overlay);
    document.body.classList.add('mc-login-loading-active');

    const shouldPrefetch = options.prefetch !== false && mode === 'login';
    if (shouldPrefetch) {
      try {
        const prefetch = document.createElement('link');
        prefetch.rel = 'prefetch';
        prefetch.href = redirectUrl;
        prefetch.as = 'document';
        document.head.appendChild(prefetch);
      } catch (_) { /* optional */ }
    }

    requestAnimationFrame(function () {
      overlay.classList.add('mc-login-loading--visible');
      overlay.focus({ preventScroll: true });
    });

    const started = Date.now();
    let redirectTarget = redirectUrl;

    function finishRedirect() {
      overlay.classList.remove('mc-login-loading--visible');
      overlay.classList.add('mc-login-loading--exit');
      overlay.setAttribute('aria-busy', 'false');
      setTimeout(function () {
        global.location.replace(redirectTarget);
      }, EXIT_FADE_MS);
    }

    const minWait = new Promise(function (resolve) {
      const elapsed = Date.now() - started;
      setTimeout(resolve, Math.max(0, MIN_DISPLAY_MS - elapsed));
    });

    const work = options.beforeRedirect
      ? options.beforeRedirect.then(function (nextUrl) {
          if (nextUrl && typeof nextUrl === 'string') {
            redirectTarget = nextUrl;
          }
        }).catch(function () { /* non-fatal */ })
      : Promise.resolve();

    Promise.all([minWait, work]).then(finishRedirect);
  }

  function performLogout() {
    if (isActive) {
      return;
    }

    const base = assetBase();
    const logoutUrl = base + '/app/api/logout.php';
    const fallbackRedirect = base + '/index.php';

    show(fallbackRedirect, {
      mode: 'logout',
      prefetch: false,
      beforeRedirect: fetch(logoutUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
        },
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data && data.success && data.redirect) {
            return data.redirect;
          }
          return fallbackRedirect;
        }),
    });
  }

  global.MedConnectLoginLoading = {
    show: show,
    performLogout: performLogout,
  };
})(window);
