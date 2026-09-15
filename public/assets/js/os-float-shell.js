/**
 * medConnect — Detect Android Floating Window / Pop-up / multi-window
 *
 * Adds html/body.mc-os-float when the browser viewport is clearly smaller than
 * the device screen (OS floating / freeform / split). Does NOT create windows,
 * call window.open(), or block Android multi-window.
 *
 * Normal full-screen mobile and desktop stay unchanged.
 */
(function (global) {
  'use strict';

  var CLASS = 'mc-os-float';
  var MIN_W = 320;
  var MAX_W = 600;
  var resizeTimer = null;

  function isVideoBusy() {
    var body = document.body;
    if (!body) return false;
    if (body.classList.contains('embedded-shell')) return true;
    if (body.classList.contains('mc-video-shell-fullscreen')) return true;
    if (body.classList.contains('mc-true-fullscreen')) return true;
    return false;
  }

  function isManualFloating() {
    return document.documentElement.classList.contains('mc-floating-view');
  }

  /**
   * True when the browser window is a reduced OS multi-window / pop-up,
   * not a normal full-screen phone browser tab.
   */
  function isLikelyOsFloating() {
    if (!global.matchMedia) return false;
    if (global.matchMedia('(min-width: 1025px)').matches) return false;

    // Resized desktop/laptop windows must not get the OS-float shell.
    // Android phones use coarse pointer; keep the shell for those.
    if (
      global.matchMedia('(hover: hover) and (pointer: fine)').matches &&
      !global.matchMedia('(pointer: coarse)').matches
    ) {
      return false;
    }

    var iw = global.innerWidth || 0;
    var ih = global.innerHeight || 0;
    if (iw < MIN_W || iw > MAX_W) return false;

    var sw = global.screen && (global.screen.availWidth || global.screen.width);
    var sh = global.screen && (global.screen.availHeight || global.screen.height);
    if (!sw || !sh) return false;

    var ow = global.outerWidth || iw;
    var oh = global.outerHeight || ih;

    // Clamp odd UA values
    ow = Math.min(Math.max(ow, iw), sw);
    oh = Math.min(Math.max(oh, ih), sh);

    var wRatio = ow / sw;
    var hRatio = oh / sh;
    var areaRatio = (ow * oh) / (sw * sh);

    // Floating / pop-up: clearly smaller than the device display
    if (areaRatio <= 0.68) return true;
    if (wRatio <= 0.88 && hRatio <= 0.90) return true;
    if (hRatio <= 0.72 && iw <= MAX_W) return true;

    // Very short narrow viewport (CSS fallback companion)
    if (iw <= MAX_W && ih <= 540) return true;

    return false;
  }

  function apply(on) {
    var root = document.documentElement;
    var body = document.body;
    var active = !!on && !isVideoBusy();

    // Manual ⛶ floating view already owns the same shell — avoid double chrome.
    if (isManualFloating()) active = false;

    root.classList.toggle(CLASS, active);
    if (body) body.classList.toggle(CLASS, active);

    try {
      if (active) {
        root.style.setProperty('--mc-header-offset', '0px');
      } else if (!isManualFloating()) {
        root.style.removeProperty('--mc-header-offset');
        global.dispatchEvent(new Event('resize'));
      }
    } catch (_) { /* ignore */ }

    try {
      global.dispatchEvent(new CustomEvent('medconnect:os-float', { detail: { active: active } }));
    } catch (_) { /* ignore */ }
  }

  function sync() {
    apply(isLikelyOsFloating());
  }

  function onResize() {
    if (resizeTimer) global.clearTimeout(resizeTimer);
    resizeTimer = global.setTimeout(sync, 120);
  }

  function init() {
    sync();

    global.addEventListener('resize', onResize, { passive: true });
    global.addEventListener('orientationchange', onResize, { passive: true });
    if (global.visualViewport) {
      global.visualViewport.addEventListener('resize', onResize, { passive: true });
    }

    if (global.matchMedia) {
      var mq = global.matchMedia('(max-width: 600px)');
      if (mq.addEventListener) mq.addEventListener('change', sync);
      else if (mq.addListener) mq.addListener(sync);
    }

    // Exit OS-float shell if a video consultation takes over.
    if (document.body) {
      var observer = new MutationObserver(function () {
        if (document.documentElement.classList.contains(CLASS) && isVideoBusy()) {
          apply(false);
        } else if (!isVideoBusy()) {
          sync();
        }
      });
      observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    }

    // Re-check after manual floating view toggles.
    global.addEventListener('medconnect:floating-view', function () {
      sync();
    });

    global.MedConnectOsFloat = {
      isActive: function () {
        return document.documentElement.classList.contains(CLASS);
      },
      sync: sync,
    };
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})(window);
