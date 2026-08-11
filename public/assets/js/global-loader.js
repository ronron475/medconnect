/**
 * medConnect — Auth-only loading screen (login / logout).
 * Full-screen loader is intentionally NOT used for navigation, fetch, or other UI actions.
 */
(function (global) {
  'use strict';

  const FADE_MS = 280;
  const MIN_DISPLAY_MS = 400;
  const BOOT_MAX_MS = 6000;

  let authActive = false;
  let booting = false;
  let isVisible = false;
  let hideTimer = null;

  function assetBase() {
    const root = document.getElementById('medconnectThemeRoot');
    const fromDom = document.body && document.body.getAttribute('data-asset-base')
      || (root && root.getAttribute('data-asset-base'))
      || '';
    return (fromDom || global.ASSET_BASE || global.APP_BASE || '').replace(/\/$/, '');
  }

  function logoSrc() {
    return assetBase() + '/assets/img/medcon_logo.png';
  }

  function buildLoaderExtras() {
    return (
      '<div class="mc-loader__dots" aria-hidden="true">' +
        '<span class="mc-loader__dot"></span>' +
        '<span class="mc-loader__dot"></span>' +
        '<span class="mc-loader__dot"></span>' +
      '</div>'
    );
  }

  function buildMarkup(srText, statusText) {
    return (
      '<div class="mc-global-loader__stage" aria-hidden="true">' +
        '<div class="mc-global-loader__glow"></div>' +
        '<div class="mc-global-loader__logo-wrap">' +
          '<img class="mc-global-loader__logo" src="' + logoSrc() + '" alt="" width="200" height="200" decoding="async" />' +
        '</div>' +
      '</div>' +
      '<p class="mc-loader__status">' + (statusText || 'Loading medConnect...') + '</p>' +
      buildLoaderExtras() +
      '<span class="mc-global-loader__sr-only">' + (srText || 'Loading. Please wait.') + '</span>'
    );
  }

  function getOverlay() {
    const boot = document.getElementById('mc-loader-boot');
    const legacy = document.getElementById('mc-global-loader');

    if (boot && legacy && legacy !== boot) {
      legacy.remove();
    }

    let el = boot || legacy;

    if (!el) {
      el = document.createElement('div');
      el.id = 'mc-global-loader';
      document.body.appendChild(el);
    }

    el.classList.add('mc-global-loader', 'mc-loader');
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');

    if (!el.querySelector('.mc-global-loader__stage')) {
      el.innerHTML = '<div class="mc-loader__panel">' + buildMarkup() + '</div>';
    }

    return el;
  }

  function hideLoaderElement(el, animate) {
    if (!el) return;
    if (hideTimer) {
      clearTimeout(hideTimer);
      hideTimer = null;
    }

    el.setAttribute('aria-busy', 'false');
    el.classList.remove('mc-global-loader--visible', 'mc-loader--visible', 'mc-loader-panel--visible');

    if (animate === false) {
      el.classList.remove('mc-global-loader--exit', 'mc-loader--exit', 'mc-global-loader--modal');
      el.setAttribute('aria-hidden', 'true');
      el.setAttribute('hidden', '');
      return;
    }

    el.classList.add('mc-global-loader--exit', 'mc-loader--exit');
    hideTimer = setTimeout(function () {
      el.classList.remove('mc-global-loader--exit', 'mc-loader--exit', 'mc-global-loader--modal');
      el.setAttribute('aria-hidden', 'true');
      el.setAttribute('hidden', '');
      hideTimer = null;
    }, FADE_MS);
  }

  function removeDuplicateLoaders() {
    const boot = document.getElementById('mc-loader-boot');
    const legacy = document.getElementById('mc-global-loader');
    const primary = boot || legacy;

    if (boot && legacy && legacy !== boot) {
      legacy.remove();
    }

    ['mc-login-loading', 'mc-global-loader'].forEach(function (id) {
      const dup = document.getElementById(id);
      if (!dup || dup === primary) return;
      if (boot && id === 'mc-global-loader') {
        dup.remove();
      } else if (dup !== primary) {
        dup.remove();
      }
    });
  }

  function setBodyActive(on) {
    document.body.classList.toggle('mc-global-loader-active', on);
    document.body.classList.toggle('mc-loader-active', on);
    document.body.classList.toggle('mc-login-loading-active', on);
    document.body.classList.toggle('mc-global-loader--boot-active', on && booting);
    document.body.classList.toggle('mc-global-loader--modal-active', on);
  }

  function setAuthModal(el, status, sr) {
    el.classList.add('mc-global-loader--modal');
    const panel = el.querySelector('.mc-loader__panel');
    if (panel) {
      panel.innerHTML = buildMarkup(sr || status || 'Loading. Please wait.', status || 'Loading medConnect...');
    }
  }

  function applyVisible(on, animate) {
    const el = getOverlay();
    if (hideTimer) {
      clearTimeout(hideTimer);
      hideTimer = null;
    }

    if (on) {
      isVisible = true;
      el.removeAttribute('hidden');
      el.setAttribute('aria-busy', 'true');
      el.setAttribute('aria-hidden', 'false');
      el.classList.remove('mc-global-loader--exit', 'mc-loader--exit');
      if (animate === false) {
        el.classList.add('mc-global-loader--visible', 'mc-loader--visible');
      } else {
        requestAnimationFrame(function () {
          el.classList.add('mc-global-loader--visible', 'mc-loader--visible');
        });
      }
      setBodyActive(true);
      return;
    }

    isVisible = false;
    el.setAttribute('aria-busy', 'false');
    el.classList.remove('mc-global-loader--visible', 'mc-loader--visible', 'mc-loader-panel--visible');

    if (animate === false) {
      el.classList.remove('mc-global-loader--exit', 'mc-loader--exit', 'mc-global-loader--modal');
      el.setAttribute('aria-hidden', 'true');
      el.setAttribute('hidden', '');
      setBodyActive(false);
      return;
    }

    el.classList.add('mc-global-loader--exit', 'mc-loader--exit');
    hideTimer = setTimeout(function () {
      el.classList.remove('mc-global-loader--exit', 'mc-loader--exit', 'mc-global-loader--modal');
      el.setAttribute('aria-hidden', 'true');
      el.setAttribute('hidden', '');
      setBodyActive(false);
      hideTimer = null;
    }, FADE_MS);
  }

  function showAuth(options) {
    options = options || {};
    const mode = options.mode === 'logout' ? 'logout' : 'login';
    const status = options.status
      || (mode === 'logout' ? 'Signing Out...' : 'Signing In...');
    const sr = options.sr
      || (mode === 'logout' ? 'Signing out.' : 'Signing in.');

    authActive = true;
    booting = false;

    removeDuplicateLoaders();
    const el = getOverlay();
    setAuthModal(el, status, sr);

    // Instant cover so UI dismiss (sign-in panel / logout modal) never flashes through.
    isVisible = true;
    el.removeAttribute('hidden');
    el.setAttribute('aria-busy', 'true');
    el.setAttribute('aria-hidden', 'false');
    el.classList.remove('mc-global-loader--exit', 'mc-loader--exit');
    el.classList.add('mc-global-loader--visible', 'mc-loader--visible');
    setBodyActive(true);
    return el;
  }

  function hideAuth(animate) {
    authActive = false;
    booting = false;
    applyVisible(false, animate !== false);
  }

  function dismissSignInUi() {
    if (typeof global.closeSignInModalInstant === 'function') {
      global.closeSignInModalInstant();
    } else if (typeof global.closeSignInModal === 'function') {
      global.closeSignInModal();
    } else {
      const modal = document.getElementById('signin-modal');
      if (modal) {
        modal.hidden = true;
        modal.classList.remove('is-open', 'is-closing', 'is-viewport-pinned');
        modal.setAttribute('aria-hidden', 'true');
      }
      document.body.classList.remove('signin-active');
      const hero = document.getElementById('hero-section');
      if (hero) hero.classList.remove('is-signin-open');
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
   * Primary auth transition: show loader immediately, complete auth work, then redirect.
   */
  function showTransition(redirectUrl, options) {
    options = options || {};
    if (!redirectUrl || typeof redirectUrl !== 'string') return;

    const mode = options.mode === 'logout' ? 'logout' : 'login';

    showAuth({
      mode: mode,
      status: mode === 'logout' ? 'Signing Out...' : 'Signing In...',
      sr: mode === 'logout' ? 'Signing out.' : 'Signing in.',
    });

    if (mode === 'login') dismissSignInUi();
    else hideLogoutModal();

    let redirectTarget = redirectUrl;

    function finishRedirect() {
      try {
        // Login only: continue spinner on the destination portal.
        // Logout already showed the loader on the source page — do not repeat on landing.
        if (mode === 'login') {
          sessionStorage.setItem('mc_auth_handoff', mode);
        } else {
          sessionStorage.removeItem('mc_auth_handoff');
        }
      } catch (_) { /* ignore */ }
      global.location.replace(redirectTarget);
    }

    const minWait = new Promise(function (resolve) {
      setTimeout(resolve, MIN_DISPLAY_MS);
    });

    const work = options.beforeRedirect
      ? options.beforeRedirect.then(function (nextUrl) {
          if (nextUrl && typeof nextUrl === 'string') redirectTarget = nextUrl;
        }).catch(function () {})
      : Promise.resolve();

    const shouldWarmup = options.prefetch !== false && mode === 'login';
    const dashboardWarmup = shouldWarmup
      ? fetch(redirectTarget, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: { 'X-MC-No-Loader': '1' },
        }).catch(function () {})
      : Promise.resolve();

    Promise.all([minWait, work, dashboardWarmup]).then(finishRedirect);
  }

  function performLogout() {
    const base = assetBase();
    showTransition(base + '/index.php', {
      mode: 'logout',
      prefetch: false,
      beforeRedirect: fetch(base + '/app/api/logout.php', {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          Accept: 'application/json',
          'X-MC-No-Loader': '1',
        },
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data && data.success && data.redirect) return data.redirect;
          return base + '/index.php';
        }),
    });
  }

  /** Continue loader only after login redirect handoff. */
  function initPageBoot() {
    const boot = document.getElementById('mc-loader-boot');
    if (!boot) return;

    let authHandoff = '';
    try {
      authHandoff = sessionStorage.getItem('mc_auth_handoff') || '';
      if (authHandoff) sessionStorage.removeItem('mc_auth_handoff');
    } catch (_) { /* ignore */ }

    // Logout spinner is shown only on the source portal — never repeat on landing.
    if (authHandoff !== 'login') {
      hideLoaderElement(boot, false);
      hideLoaderElement(document.getElementById('mc-global-loader'), false);
      authActive = false;
      booting = false;
      isVisible = false;
      setBodyActive(false);
      return;
    }

    const statusEl = boot.querySelector('.mc-loader__status');
    if (authHandoff === 'login') {
      if (statusEl) statusEl.textContent = 'Signing In...';
    }

    boot.classList.add('mc-global-loader--modal');
    authActive = true;
    booting = true;
    boot.removeAttribute('hidden');
    boot.setAttribute('aria-busy', 'true');
    boot.setAttribute('aria-hidden', 'false');
    boot.classList.add('mc-global-loader--visible', 'mc-loader--visible');
    setBodyActive(true);
    isVisible = true;

    let ended = false;
    function endBoot() {
      if (ended) return;
      ended = true;
      booting = false;
      authActive = false;
      applyVisible(false, true);
    }

    if (document.readyState === 'complete') {
      setTimeout(endBoot, 160);
    } else {
      global.addEventListener('load', function () {
        setTimeout(endBoot, 160);
      }, { once: true });
    }
    setTimeout(endBoot, BOOT_MAX_MS);
  }

  let bootInitialized = false;

  function init() {
    if (bootInitialized) return;
    bootInitialized = true;
    removeDuplicateLoaders();
    try { sessionStorage.removeItem('mc_nav_handoff'); } catch (_) { /* ignore */ }
    initPageBoot();
  }

  // ── Compatibility no-ops (non-auth callers must not show the loader) ──
  // Return false so callers can fall back to local/inline loading UI.
  function noopIgnored() {
    return false;
  }

  function forceHide() {
    authActive = false;
    booting = false;
    isVisible = false;
    hideLoaderElement(document.getElementById('mc-loader-boot'), false);
    hideLoaderElement(document.getElementById('mc-global-loader'), false);
    setBodyActive(false);
  }

  function maybeShowAuth(options) {
    options = options || {};
    if (options.mode === 'login' || options.mode === 'logout'
      || options.preset === 'login' || options.preset === 'logout') {
      return showAuth({
        mode: options.mode || options.preset,
        status: options.status,
        sr: options.sr,
      });
    }
    return false;
  }

  function inlineLoadingHtml(message, options) {
    options = options || {};
    const tag = options.tag || 'div';
    const extraClass = options.className ? ' ' + options.className : '';
    const logo = logoSrc();
    return (
      '<' + tag + ' class="mc-inline-loading staff-apps-loading' + extraClass + '" role="status">' +
        '<div class="mc-global-loader__stage" aria-hidden="true">' +
          '<div class="mc-global-loader__glow"></div>' +
          '<div class="mc-global-loader__logo-wrap">' +
            '<img class="mc-global-loader__logo" src="' + logo + '" alt="" width="36" height="36" decoding="async" />' +
          '</div>' +
        '</div>' +
        '<span>' + (message || 'Loading…') + '</span>' +
      '</' + tag + '>'
    );
  }

  function inlineLoadingRow(colspan, message, cellClass) {
    const tdClass = cellClass ? ' class="' + cellClass + '"' : '';
    return '<tr><td colspan="' + colspan + '"' + tdClass + '>' + inlineLoadingHtml(message) + '</td></tr>';
  }

  const api = {
    // Auth-only entry points
    showTransition: showTransition,
    performLogout: performLogout,
    showAuth: showAuth,
    hideAuth: hideAuth,

    // Kept for API compatibility — non-auth calls return false (ignored)
    show: maybeShowAuth,
    hide: function () {
      if (authActive || booting || isVisible) hideAuth(true);
    },
    forceHide: forceHide,
    showFormal: maybeShowAuth,
    hideFormal: function () {
      if (authActive || booting || isVisible) hideAuth(true);
    },
    update: function () {},
    showPanel: noopIgnored,
    hidePanel: function () {},
    showPersistent: noopIgnored,
    hidePersistent: function () {},
    showModal: maybeShowAuth,
    hideModal: forceHide,
    inlineHtml: inlineLoadingHtml,
    inlineRow: inlineLoadingRow,
    paintSteps: function () {},
    startStepAnimation: function () {},
    clearStepTimer: function () {},
  };

  global.MedConnectGlobalLoader = api;
  global.MedConnectLoader = api;
  global.MedConnectLoginLoading = {
    show: showTransition,
    performLogout: performLogout,
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window);
