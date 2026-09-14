/**
 * medConnect — shared navbar fullscreen toggle (Fullscreen API)
 * Works with any [data-mc-fullscreen-toggle] control in the top bar.
 */
(function () {
  'use strict';

  var ICON_ENTER =
    '<path d="M8 3H5a2 2 0 0 0-2 2v3m18 0V5a2 2 0 0 0-2-2h-3m0 18h3a2 2 0 0 0 2-2v-3M3 16v3a2 2 0 0 0 2 2h3"/>';
  var ICON_EXIT =
    '<path d="M8 3v3a2 2 0 0 1-2 2H3m18 0h-3a2 2 0 0 1-2-2V3m0 18v-3a2 2 0 0 1 2-2h3M3 16h3a2 2 0 0 1 2 2v3"/>';

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

  function updateButton(btn, active) {
    var label = active ? 'Exit fullscreen' : 'Enter fullscreen';
    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    btn.setAttribute('aria-label', label);
    btn.title = label;
    btn.classList.toggle('is-fullscreen', !!active);

    var icon = btn.querySelector('[data-fs-icon]');
    if (icon) {
      icon.innerHTML = active ? ICON_EXIT : ICON_ENTER;
    }
  }

  function init() {
    var buttons = document.querySelectorAll('[data-mc-fullscreen-toggle]');
    if (!buttons.length) return;

    if (!isFullscreenSupported()) {
      Array.prototype.forEach.call(buttons, function (btn) {
        btn.hidden = true;
        btn.setAttribute('aria-hidden', 'true');
        btn.disabled = true;
      });
      return;
    }

    function syncAll() {
      var active = !!fullscreenElement();
      Array.prototype.forEach.call(buttons, function (btn) {
        updateButton(btn, active);
      });
    }

    Array.prototype.forEach.call(buttons, function (btn) {
      btn.addEventListener('click', function () {
        try {
          if (fullscreenElement()) {
            Promise.resolve(exitFullscreen()).catch(function () {});
          } else {
            Promise.resolve(requestFullscreen(document.documentElement)).catch(function () {});
          }
        } catch (err) {
          /* Ignore unsupported / permission errors */
        }
      });
    });

    document.addEventListener('fullscreenchange', syncAll);
    document.addEventListener('webkitfullscreenchange', syncAll);
    document.addEventListener('MSFullscreenChange', syncAll);
    syncAll();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
