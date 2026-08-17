/**
 * Landing download / PWA install for the official medConnect Android app.
 */
(function () {
  'use strict';

  const section = document.getElementById('download-app');
  if (!section) return;

  const apkBtn = document.getElementById('download-apk-btn');
  const pwaBtn = document.getElementById('install-pwa-btn');
  const doneEl = document.getElementById('download-app-done');
  const assetBase = (window.ASSET_BASE || window.APP_BASE || '').replace(/\/$/, '');
  let deferredPrompt = null;

  function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches
      || window.navigator.standalone === true;
  }

  function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    const swUrl = assetBase + '/sw.js';
    navigator.serviceWorker.register(swUrl, { scope: assetBase ? assetBase + '/' : './' }).catch(function () {});
  }

  function showUnavailable() {
    const message = 'App download is temporarily unavailable. Please try again later.';
    if (typeof McModal !== 'undefined' && typeof McModal.alert === 'function') {
      McModal.alert({
        title: 'Download unavailable',
        message: message,
      });
      return;
    }
    window.alert(message);
  }

  if (apkBtn) {
    apkBtn.addEventListener('click', function (event) {
      const ready = apkBtn.getAttribute('data-apk-ready') === '1'
        || section.getAttribute('data-apk-ready') === '1';
      if (!ready) {
        event.preventDefault();
        showUnavailable();
        return;
      }
      if (doneEl) doneEl.hidden = false;
    });
  }

  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    deferredPrompt = event;
    if (pwaBtn && !isStandalone()) {
      pwaBtn.hidden = false;
    }
  });

  function showManualInstallHelp() {
    if (typeof McModal !== 'undefined' && typeof McModal.alert === 'function') {
      McModal.alert({
        title: 'Install medConnect',
        message: 'On Android Chrome, open the menu and tap Install app or Add to Home screen. On iPhone, use Share → Add to Home Screen.',
      });
      return;
    }
    window.alert('On Android Chrome, open the menu and tap Install app. On iPhone, use Share → Add to Home Screen.');
  }

  if (pwaBtn) {
    pwaBtn.addEventListener('click', function () {
      if (!deferredPrompt) {
        showManualInstallHelp();
        return;
      }
      deferredPrompt.prompt();
      deferredPrompt.userChoice.finally(function () {
        deferredPrompt = null;
        pwaBtn.hidden = true;
      });
    });
  }

  if (isStandalone() && pwaBtn) {
    pwaBtn.hidden = true;
  }

  registerServiceWorker();

  const device = document.getElementById('download-app-device');
  const track = document.getElementById('download-app-track');
  const slides = track ? Array.from(track.querySelectorAll('.download-app__slide')) : [];
  const dots = Array.from(document.querySelectorAll('#download-app-dots [data-slide]'));
  const slideCount = slides.length;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let index = 0;
  let timer = 0;
  let leaveTimer = 0;
  let visible = false;
  let hovered = false;

  function goTo(next) {
    if (slideCount < 1) return;
    const prev = index;
    index = ((next % slideCount) + slideCount) % slideCount;
    const goingBack = index === prev - 1 || (prev === 0 && index === slideCount - 1);

    slides.forEach(function (slide, i) {
      slide.classList.toggle('is-back', goingBack && (i === prev || i === index));
      slide.classList.toggle('is-leaving', i === prev && i !== index);
      slide.classList.toggle('is-active', i === index);
    });

    dots.forEach(function (dot, i) {
      const on = i === index;
      dot.classList.toggle('is-active', on);
      dot.setAttribute('aria-selected', on ? 'true' : 'false');
    });

    if (leaveTimer) window.clearTimeout(leaveTimer);
    leaveTimer = window.setTimeout(function () {
      slides.forEach(function (slide) {
        if (!slide.classList.contains('is-active')) {
          slide.classList.remove('is-leaving', 'is-back');
        }
      });
    }, 760);
  }

  function stop() {
    if (timer) {
      window.clearInterval(timer);
      timer = 0;
    }
  }

  function start() {
    stop();
    if (reduceMotion || slideCount < 2 || !visible || hovered) return;
    timer = window.setInterval(function () {
      goTo(index + 1);
    }, 4500);
  }

  if (slideCount > 0) {
    goTo(0);

    dots.forEach(function (dot) {
      dot.addEventListener('click', function () {
        const next = parseInt(dot.getAttribute('data-slide') || '0', 10);
        goTo(next);
        start();
      });
    });

    if (device) {
      device.addEventListener('mouseenter', function () {
        hovered = true;
        stop();
      });
      device.addEventListener('mouseleave', function () {
        hovered = false;
        start();
      });
    }

    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver(function (entries) {
        visible = entries.some(function (entry) { return entry.isIntersecting; });
        if (visible) start();
        else stop();
      }, { threshold: 0.25 });
      observer.observe(section);
    } else {
      visible = true;
      start();
    }
  }
})();
