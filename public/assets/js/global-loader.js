/**
 * medConnect — Global transparent logo loader
 */
(function (global) {
  'use strict';

  const FADE_MS = 280;
  const EXIT_MS = 120;
  const MIN_DISPLAY_MS = 400;
  const BOOT_MAX_MS = 6000;

  let activeRequests = 0;
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

  function buildMarkup(srText, modalOpts) {
    const modal = modalOpts || {};
    const textBlock = modal.enabled ? (
      '<p class="mc-loader__title">' + (modal.brand || 'medConnect') + '</p>' +
      '<p class="mc-loader__status">' + (modal.status || 'Connecting...') + '</p>' +
      '<p class="mc-loader__hint">' + (modal.substatus || 'Securing your session...') + '</p>' +
      '<p class="mc-loader__hint">' + (modal.hint || 'Please wait...') + '</p>'
    ) : '';
    return (
      '<div class="mc-global-loader__stage" aria-hidden="true">' +
        '<div class="mc-global-loader__ring"></div>' +
        '<div class="mc-global-loader__glow"></div>' +
        '<div class="mc-global-loader__logo-wrap">' +
          '<img class="mc-global-loader__logo" src="' + logoSrc() + '" alt="" width="64" height="64" decoding="async" />' +
        '</div>' +
      '</div>' +
      textBlock +
      '<span class="mc-global-loader__sr-only">' + (srText || 'Loading. Please wait.') + '</span>'
    );
  }

  /** Single overlay element — always reuse #mc-loader-boot when present. */
  function getOverlay() {
    let el = document.getElementById('mc-loader-boot')
      || document.getElementById('mc-global-loader');

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

  function removeDuplicateLoaders() {
    const primary = getOverlay();
    ['mc-login-loading'].forEach(function (id) {
      const dup = document.getElementById(id);
      if (dup && dup !== primary) dup.remove();
    });
  }

  function setBodyActive(on) {
    document.body.classList.toggle('mc-global-loader-active', on);
    document.body.classList.toggle('mc-loader-active', on);
    document.body.classList.toggle('mc-login-loading-active', on);
    document.body.classList.toggle('mc-global-loader--boot-active', on && booting);
  }

  function setModalMode(el, on, options) {
    options = options || {};
    el.classList.toggle('mc-global-loader--modal', on);
    document.body.classList.toggle('mc-global-loader--modal-active', on);
    if (on) {
      const panel = el.querySelector('.mc-loader__panel');
      if (panel) {
        panel.innerHTML = buildMarkup(options.sr || 'Loading. Please wait.', {
          enabled: true,
          brand: options.brand,
          status: options.status,
          substatus: options.substatus,
          hint: options.hint,
        });
      }
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
      el.classList.remove('mc-global-loader--exit', 'mc-loader--exit');
      el.setAttribute('aria-hidden', 'true');
      el.setAttribute('hidden', '');
      setBodyActive(false);
      setModalMode(el, false);
      return;
    }

    el.classList.add('mc-global-loader--exit', 'mc-loader--exit');
    hideTimer = setTimeout(function () {
      el.classList.remove('mc-global-loader--exit', 'mc-loader--exit');
      el.setAttribute('aria-hidden', 'true');
      el.setAttribute('hidden', '');
      setBodyActive(false);
      setModalMode(el, false);
      hideTimer = null;
    }, FADE_MS);
  }

  function syncOverlay() {
    const wantVisible = booting || activeRequests > 0;
    if (wantVisible === isVisible) return;
    applyVisible(wantVisible, true);
  }

  const FORMAL_PRESETS = {
    default: {
      brand: 'medConnect',
      status: 'Loading…',
      substatus: 'Securing your session…',
      hint: 'Please wait…',
      sr: 'Loading. Please wait.',
    },
    login: {
      status: 'Connecting…',
      substatus: 'Securing your session…',
      hint: 'Please wait…',
      sr: 'Signing in.',
    },
    logout: {
      status: 'Signing out…',
      substatus: 'Securing your session…',
      hint: 'Please wait…',
      sr: 'Signing out.',
    },
    ai: {
      status: 'Verifying with AI…',
      substatus: 'Running medical NLP pipeline…',
      hint: 'Please wait…',
      sr: 'Analyzing health information.',
    },
    booking: {
      status: 'Booking your appointment…',
      substatus: 'Confirming provider availability…',
      hint: 'Please wait…',
      sr: 'Booking appointment.',
    },
    submit: {
      status: 'Submitting…',
      substatus: 'Saving your information…',
      hint: 'Please wait…',
      sr: 'Submitting form.',
    },
    assessment: {
      status: 'Running AI assessment…',
      substatus: 'Analyzing symptoms and classifying urgency…',
      hint: 'Please wait…',
      sr: 'Running medical assessment.',
    },
  };

  function resolveFormalOptions(options) {
    options = options || {};
    const presetKey = options.preset || options.mode || 'default';
    const preset = FORMAL_PRESETS[presetKey] || FORMAL_PRESETS.default;
    return {
      modal: true,
      keepModal: true,
      instant: options.instant === true,
      brand: options.brand || preset.brand || FORMAL_PRESETS.default.brand,
      status: options.status || preset.status || FORMAL_PRESETS.default.status,
      substatus: options.substatus || options.message || preset.substatus || FORMAL_PRESETS.default.substatus,
      hint: options.hint || preset.hint || FORMAL_PRESETS.default.hint,
      sr: options.sr || options.status || preset.sr || FORMAL_PRESETS.default.sr,
    };
  }

  function showFormal(options) {
    return show(resolveFormalOptions(options));
  }

  function hideFormal() {
    hide();
  }

  function show(options) {
    options = options || {};
    const el = getOverlay();
    const useModal = options.modal === true || options.mode === 'login' || options.mode === 'logout' || !!options.preset;
    if (useModal) {
      setModalMode(el, true, {
        sr: options.sr,
        brand: options.brand || 'medConnect',
        status: options.status || (options.mode === 'logout' ? 'Signing out...' : 'Connecting...'),
        substatus: options.substatus || 'Securing your session...',
        hint: options.hint || 'Please wait...',
      });
    } else if (!options.keepModal) {
      const modalActive = el.classList.contains('mc-global-loader--modal')
        || document.body.classList.contains('mc-global-loader--modal-active');
      if (!(modalActive && activeRequests > 0)) {
        setModalMode(el, false);
      }
    }
    if (options.sr && !useModal) {
      const sr = el.querySelector('.mc-global-loader__sr-only');
      if (sr) sr.textContent = options.sr;
    }
    activeRequests += 1;
    const instant = options.instant === true;
    if (instant) {
      isVisible = true;
      el.removeAttribute('hidden');
      el.setAttribute('aria-busy', 'true');
      el.setAttribute('aria-hidden', 'false');
      el.classList.remove('mc-global-loader--exit', 'mc-loader--exit');
      el.classList.add('mc-global-loader--visible', 'mc-loader--visible');
      setBodyActive(true);
    } else {
      syncOverlay();
    }
    return el;
  }

  function hide() {
    activeRequests = Math.max(0, activeRequests - 1);
    syncOverlay();
  }

  function forceHide() {
    activeRequests = 0;
    booting = false;
    syncOverlay();
  }

  function showPersistent(id, options) {
    options = options || {};
    if (!options.preset && !options.mode) {
      if (id && id.indexOf('nlp') !== -1) options.preset = 'ai';
      else if (id && id.indexOf('booking') !== -1) options.preset = 'booking';
    }
    return showFormal(options);
  }

  function hidePersistent() {
    document.body.classList.remove('reg-nlp-overlay-open', 'patient-booking-overlay-open');
    hideFormal();
  }

  function showPanel(options) {
    return show(options);
  }

  function hidePanel() {
    hide();
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

  function showTransition(redirectUrl, options) {
    options = options || {};
    if (!redirectUrl || typeof redirectUrl !== 'string') return;

    const mode = options.mode === 'logout' ? 'logout' : 'login';

    booting = false;
    activeRequests = 0;

    // Cover the page immediately so sign-in panel close never flashes through.
    showFormal({
      preset: mode,
      instant: true,
      sr: mode === 'logout' ? 'Signing out.' : 'Signing in.',
    });

    if (mode === 'login') dismissSignInUi();
    else hideLogoutModal();

    let redirectTarget = redirectUrl;

    function finishRedirect() {
      try {
        sessionStorage.setItem('mc_auth_handoff', mode);
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

  function initPageBoot() {
    const boot = document.getElementById('mc-loader-boot');
    if (!boot) return;

    let authHandoff = '';
    try {
      authHandoff = sessionStorage.getItem('mc_auth_handoff') || '';
      if (authHandoff) sessionStorage.removeItem('mc_auth_handoff');
    } catch (_) { /* ignore */ }

    if (authHandoff === 'login') {
      const statusEl = boot.querySelector('.mc-loader__status');
      const hintEl = boot.querySelector('.mc-loader__hint');
      if (statusEl) statusEl.textContent = 'Loading your dashboard…';
      if (hintEl) hintEl.textContent = 'Almost there…';
    } else if (authHandoff === 'logout') {
      const statusEl = boot.querySelector('.mc-loader__status');
      if (statusEl) statusEl.textContent = 'Signed out';
    }

    booting = true;
    const bootAlreadyVisible = boot.classList.contains('mc-global-loader--visible')
      && !boot.hasAttribute('hidden');

    if (bootAlreadyVisible) {
      isVisible = true;
      boot.removeAttribute('hidden');
      boot.setAttribute('aria-busy', 'true');
      boot.setAttribute('aria-hidden', 'false');
      setBodyActive(true);
    } else {
      syncOverlay();
    }

    let ended = false;
    function endBoot() {
      if (ended) return;
      ended = true;
      booting = false;
      syncOverlay();
    }

    if (document.readyState === 'complete') {
      setTimeout(endBoot, 120);
    } else {
      global.addEventListener('load', function () {
        setTimeout(endBoot, 120);
      }, { once: true });
    }
    setTimeout(endBoot, BOOT_MAX_MS);
  }

  function bindNavigationLoader() {
    document.addEventListener('click', function (e) {
      const link = e.target && e.target.closest ? e.target.closest('a[href]') : null;
      if (!link || e.defaultPrevented) return;
      if (link.target === '_blank' || link.hasAttribute('download')) return;
      if (link.dataset.mcNoLoader === '1' || link.classList.contains('mc-no-loader')) return;

      const href = link.getAttribute('href');
      if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href)) return;
      if (link.origin && link.origin !== global.location.origin) return;
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

      show({ mode: 'navigation', sr: 'Loading page.' });
    }, false);
  }

  function hasNoLoaderHeader(opts) {
    const h = opts && opts.headers;
    if (!h) return false;
    if (typeof Headers !== 'undefined' && h instanceof Headers) {
      return h.get('X-MC-No-Loader') === '1';
    }
    return h['X-MC-No-Loader'] === '1' || h['x-mc-no-loader'] === '1';
  }

  function shouldAutoLoad(url, options) {
    const opts = options || {};
    if (hasNoLoaderHeader(opts)) return false;
    if (opts.mcNoLoader === true) return false;

    const method = String(opts.method || 'GET').toUpperCase();
    if (method === 'GET' || method === 'HEAD') return false;

    let urlStr = typeof url === 'string' ? url : (url && url.url ? url.url : '');
    if (!urlStr) return false;
    if (/^data:|^blob:/i.test(urlStr)) return false;

    try {
      const parsed = new URL(urlStr, global.location.href);
      if (parsed.origin !== global.location.origin) return false;
      urlStr = parsed.pathname + parsed.search;
    } catch (err) {
      return false;
    }

    if (/\.(css|js|png|jpe?g|gif|svg|webp|woff2?|ttf|ico|map)(\?|$)/i.test(urlStr)) return false;
    if (/\/assets\//i.test(urlStr)) return false;
    if (/\/app\/api\/login\.php$/i.test(urlStr)) return false;
    if (/\/app\/api\/logout\.php$/i.test(urlStr)) return false;
    if (/\/app\/api\/consultations\/session_timer\.php/i.test(urlStr)) return false;
    if (/\/app\/api\/consultations\/session_keepalive\.php/i.test(urlStr)) return false;
    if (/\/app\/api\/patient\/approved_recommendations\.php/i.test(urlStr)) return false;
    if (/\/app\/api\/patient\/acknowledge_recommendation\.php/i.test(urlStr)) return false;

    return /\/app\/(api|controllers)\//i.test(urlStr);
  }

  function patchFetch() {
    if (!global.fetch || global.fetch.__mcGlobalLoaderPatched) return;
    const nativeFetch = global.fetch.bind(global);

    global.fetch = function (input, init) {
      const opts = init || {};
      if (!shouldAutoLoad(input, opts)) {
        return nativeFetch(input, init);
      }
      show({ mode: 'fetch', sr: 'Loading data.' });
      return nativeFetch(input, init).finally(function () {
        hide();
      });
    };
    global.fetch.__mcGlobalLoaderPatched = true;
  }

  function patchXHR() {
    if (!global.XMLHttpRequest || global.XMLHttpRequest.__mcGlobalLoaderPatched) return;
    const XHR = global.XMLHttpRequest;
    const open = XHR.prototype.open;
    const send = XHR.prototype.send;

    XHR.prototype.open = function (method, url) {
      this.__mcLoaderMethod = method;
      this.__mcLoaderUrl = url;
      return open.apply(this, arguments);
    };

    XHR.prototype.send = function () {
      const self = this;
      const opts = { method: self.__mcLoaderMethod, headers: {} };
      if (self.__mcLoaderUrl && shouldAutoLoad(self.__mcLoaderUrl, opts)) {
        show({ mode: 'xhr', sr: 'Loading data.' });
        const done = function () {
          self.removeEventListener('loadend', done);
          hide();
        };
        self.addEventListener('loadend', done);
      }
      return send.apply(this, arguments);
    };

    global.XMLHttpRequest.__mcGlobalLoaderPatched = true;
  }

  function init() {
    removeDuplicateLoaders();
    initPageBoot();
    bindNavigationLoader();
    patchFetch();
    patchXHR();
  }

  function showModal(options) {
    options = options || {};
    return show(Object.assign({ modal: true }, options));
  }

  function hideModal() {
    const el = getOverlay();
    setModalMode(el, false);
    activeRequests = 0;
    booting = false;
    applyVisible(false, false);
  }

  function inlineLoadingHtml(message, options) {
    options = options || {};
    const tag = options.tag || 'div';
    const extraClass = options.className ? ' ' + options.className : '';
    const logo = logoSrc();
    return (
      '<' + tag + ' class="mc-inline-loading staff-apps-loading' + extraClass + '" role="status">' +
        '<div class="mc-global-loader__stage" aria-hidden="true">' +
          '<div class="mc-global-loader__ring"></div>' +
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
    show: show,
    hide: hide,
    forceHide: forceHide,
    showFormal: showFormal,
    hideFormal: hideFormal,
    update: function () {},
    showPanel: showPanel,
    hidePanel: hidePanel,
    showPersistent: showPersistent,
    hidePersistent: hidePersistent,
    showTransition: showTransition,
    showModal: showModal,
    hideModal: hideModal,
    performLogout: performLogout,
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
