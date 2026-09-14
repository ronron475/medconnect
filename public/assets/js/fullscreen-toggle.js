/**
 * medConnect — navbar ⛶ control
 * Desktop: browser Fullscreen API
 * Mobile/tablet: in-page Floating View (not OS pop-up / not Fullscreen API)
 */
(function (global) {
  'use strict';

  var STORAGE_KEY = 'mc_floating_view';
  var MOBILE_MQ = '(max-width: 1024px)';

  var ICON_ENTER =
    '<path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>';
  var ICON_EXIT =
    '<path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3"/>';

  function buttons() {
    return document.querySelectorAll('[data-mc-fullscreen-toggle]');
  }

  function isMobileViewport() {
    return !!(global.matchMedia && global.matchMedia(MOBILE_MQ).matches);
  }

  function fullscreenElement() {
    return (
      document.fullscreenElement ||
      document.webkitFullscreenElement ||
      document.msFullscreenElement ||
      null
    );
  }

  function isFullscreenSupported() {
    var el = document.documentElement;
    return !!(
      el.requestFullscreen ||
      el.webkitRequestFullscreen ||
      el.msRequestFullscreen
    );
  }

  function requestFullscreen(el) {
    if (el.requestFullscreen) return el.requestFullscreen();
    if (el.webkitRequestFullscreen) return el.webkitRequestFullscreen();
    if (el.msRequestFullscreen) return el.msRequestFullscreen();
    return Promise.reject(new Error('Fullscreen API unavailable'));
  }

  function exitFullscreen() {
    if (!fullscreenElement()) return Promise.resolve();
    if (document.exitFullscreen) return document.exitFullscreen();
    if (document.webkitExitFullscreen) return document.webkitExitFullscreen();
    if (document.msExitFullscreen) return document.msExitFullscreen();
    return Promise.resolve();
  }

  function isVideoBusy() {
    var body = document.body;
    if (!body) return false;
    if (body.classList.contains('embedded-shell')) return true;
    if (body.classList.contains('mc-video-shell-fullscreen')) return true;
    if (body.classList.contains('mc-true-fullscreen')) return true;
    if (body.classList.contains('mc-video-shell-pip')) return false;
    return false;
  }

  function isFloatingActive() {
    return document.documentElement.classList.contains('mc-floating-view');
  }

  function readPersistedFloating() {
    try {
      return global.sessionStorage.getItem(STORAGE_KEY) === '1';
    } catch (_) {
      return false;
    }
  }

  function writePersistedFloating(on) {
    try {
      if (on) global.sessionStorage.setItem(STORAGE_KEY, '1');
      else global.sessionStorage.removeItem(STORAGE_KEY);
    } catch (_) { /* private mode */ }
  }

  function updateButton(btn, mode) {
    // mode: 'off' | 'fullscreen' | 'floating'
    var active = mode !== 'off';
    var label;
    if (mode === 'floating') {
      label = 'Exit floating view';
    } else if (mode === 'fullscreen') {
      label = 'Exit fullscreen';
    } else if (isMobileViewport()) {
      label = 'Enter floating view';
    } else {
      label = 'Enter fullscreen';
    }

    btn.hidden = false;
    btn.disabled = false;
    btn.removeAttribute('aria-hidden');
    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    btn.setAttribute('aria-label', label);
    btn.title = label;
    btn.classList.toggle('is-fullscreen', mode === 'fullscreen');
    btn.classList.toggle('is-floating', mode === 'floating');
    btn.dataset.mcViewMode = mode;

    var icon = btn.querySelector('[data-fs-icon]');
    if (icon) {
      icon.innerHTML = active ? ICON_EXIT : ICON_ENTER;
    }
  }

  function syncAll() {
    var mode = 'off';
    if (isMobileViewport()) {
      if (isFloatingActive()) mode = 'floating';
    } else if (fullscreenElement()) {
      mode = 'fullscreen';
    }
    Array.prototype.forEach.call(buttons(), function (btn) {
      updateButton(btn, mode);
    });
  }

  function setFloating(on) {
    if (on && isVideoBusy()) return;
    document.documentElement.classList.toggle('mc-floating-view', !!on);
    if (document.body) {
      document.body.classList.toggle('mc-floating-view', !!on);
    }
    writePersistedFloating(!!on);
    syncAll();
    try {
      global.dispatchEvent(new CustomEvent('medconnect:floating-view', { detail: { active: !!on } }));
    } catch (_) { /* ignore */ }
  }

  function toggleFloating() {
    setFloating(!isFloatingActive());
  }

  function toggleDesktopFullscreen() {
    try {
      if (fullscreenElement()) {
        Promise.resolve(exitFullscreen()).catch(function () {});
      } else {
        Promise.resolve(requestFullscreen(document.documentElement)).catch(function () {});
      }
    } catch (err) {
      /* Ignore unsupported / permission errors */
    }
  }

  function onToggleClick(ev) {
    if (ev) {
      ev.preventDefault();
      ev.stopPropagation();
    }
    if (isMobileViewport()) {
      // Never use Fullscreen API for the navbar control on mobile/tablet.
      if (fullscreenElement()) {
        Promise.resolve(exitFullscreen()).catch(function () {}).finally(function () {
          toggleFloating();
        });
        return;
      }
      toggleFloating();
      return;
    }
    // Desktop: leave floating mode if somehow active, then use Fullscreen API.
    if (isFloatingActive()) setFloating(false);
    if (!isFullscreenSupported()) return;
    toggleDesktopFullscreen();
  }

  function onViewportChange() {
    if (isMobileViewport()) {
      if (fullscreenElement()) {
        Promise.resolve(exitFullscreen()).catch(function () {});
      }
      if (readPersistedFloating() && !isVideoBusy()) {
        setFloating(true);
      } else if (!readPersistedFloating()) {
        setFloating(false);
      }
    } else {
      if (isFloatingActive()) setFloating(false);
    }
    syncAll();
  }

  function init() {
    var list = buttons();
    if (!list.length) return;

    // Mobile floating view does not require Fullscreen API support.
    if (!isMobileViewport() && !isFullscreenSupported()) {
      Array.prototype.forEach.call(list, function (btn) {
        btn.hidden = true;
        btn.setAttribute('aria-hidden', 'true');
        btn.disabled = true;
      });
      return;
    }

    Array.prototype.forEach.call(list, function (btn) {
      if (btn.dataset.mcFsBound === '1') return;
      btn.dataset.mcFsBound = '1';
      btn.addEventListener('click', onToggleClick);
    });

    document.addEventListener('fullscreenchange', syncAll);
    document.addEventListener('webkitfullscreenchange', syncAll);
    document.addEventListener('MSFullscreenChange', syncAll);

    if (global.matchMedia) {
      var mq = global.matchMedia(MOBILE_MQ);
      if (mq.addEventListener) mq.addEventListener('change', onViewportChange);
      else if (mq.addListener) mq.addListener(onViewportChange);
    }

    // Restore floating view after in-portal navigation on mobile.
    if (isMobileViewport() && readPersistedFloating() && !isVideoBusy()) {
      setFloating(true);
    } else {
      syncAll();
    }

    // Exit floating if a video consultation takes over the viewport.
    var observer = new MutationObserver(function () {
      if (isFloatingActive() && isVideoBusy()) {
        setFloating(false);
      }
    });
    if (document.body) {
      observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    }

    global.MedConnectFloatingView = {
      isActive: isFloatingActive,
      enter: function () { if (isMobileViewport()) setFloating(true); },
      exit: function () { setFloating(false); },
      toggle: function () { if (isMobileViewport()) toggleFloating(); },
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window);
