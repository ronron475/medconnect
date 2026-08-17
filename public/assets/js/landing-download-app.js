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

  const phoneCol = section.querySelector('.download-app__phone-col');
  const device = document.getElementById('download-app-device');
  const viewport = document.getElementById('download-app-viewport');
  const track = document.getElementById('download-app-track');
  const slides = track ? Array.from(track.querySelectorAll('.download-app__slide')) : [];
  const dots = Array.from(document.querySelectorAll('#download-app-dots [data-slide]'));
  const liveTimerEl = section.querySelector('.dap-vc__timer');
  const slideCount = slides.length;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const intervalMs = Math.max(
    2800,
    parseInt((phoneCol && phoneCol.getAttribute('data-interval')) || '4200', 10) || 4200
  );
  let index = 0;
  let timer = 0;
  let leaveTimer = 0;
  let liveClock = 0;
  let liveSeconds = 0;
  let visible = true;
  let paused = false;
  let touchStartX = 0;
  let touchDeltaX = 0;

  function formatClock(total) {
    const m = String(Math.floor(total / 60)).padStart(2, '0');
    const s = String(total % 60).padStart(2, '0');
    return m + ':' + s;
  }

  function stopLiveClock() {
    if (liveClock) {
      window.clearInterval(liveClock);
      liveClock = 0;
    }
  }

  function startLiveClock(reset) {
    stopLiveClock();
    const slide = slides[index];
    if (!liveTimerEl || !slide || slide.getAttribute('data-preview') !== 'video') {
      if (liveTimerEl && reset) liveTimerEl.textContent = '00:00';
      return;
    }
    if (reset) liveSeconds = 0;
    liveTimerEl.textContent = formatClock(liveSeconds);
    liveClock = window.setInterval(function () {
      liveSeconds += 1;
      liveTimerEl.textContent = formatClock(liveSeconds);
    }, 1000);
  }

  function goTo(next) {
    if (slideCount < 1) return;
    const prev = index;
    index = ((next % slideCount) + slideCount) % slideCount;
    const goingBack = index === prev - 1 || (prev === 0 && index === slideCount - 1);

    slides.forEach(function (slide, i) {
      slide.classList.toggle('is-back', goingBack && (i === prev || i === index));
      slide.classList.toggle('is-leaving', i === prev && i !== index);
      slide.classList.toggle('is-active', i === index);
      slide.setAttribute('aria-hidden', i === index ? 'false' : 'true');
    });

    dots.forEach(function (dot, i) {
      const on = i === index;
      dot.classList.toggle('is-active', on);
      dot.classList.remove('is-playing');
      dot.setAttribute('aria-selected', on ? 'true' : 'false');
      if (on) {
        void dot.offsetWidth;
        dot.classList.add('is-playing');
      }
    });

    startLiveClock(true);

    if (leaveTimer) window.clearTimeout(leaveTimer);
    leaveTimer = window.setTimeout(function () {
      slides.forEach(function (slide) {
        if (!slide.classList.contains('is-active')) {
          slide.classList.remove('is-leaving', 'is-back');
        }
      });
    }, reduceMotion ? 40 : 760);
  }

  function stop() {
    if (timer) {
      window.clearInterval(timer);
      timer = 0;
    }
  }

  function start() {
    stop();
    if (slideCount < 2 || !visible || paused || document.hidden) return;
    timer = window.setInterval(function () {
      goTo(index + 1);
    }, reduceMotion ? Math.max(intervalMs, 7000) : intervalMs);
  }

  function pause() {
    paused = true;
    stop();
    stopLiveClock();
    if (phoneCol) phoneCol.classList.add('is-paused');
  }

  function resume() {
    paused = false;
    if (phoneCol) phoneCol.classList.remove('is-paused');
    startLiveClock(false);
    start();
  }

  if (slideCount > 0) {
    if (phoneCol) {
      phoneCol.setAttribute('aria-roledescription', 'carousel');
      phoneCol.setAttribute('aria-label', 'medConnect app preview');
      phoneCol.style.setProperty('--dap-carousel-ms', intervalMs + 'ms');
    }

    goTo(0);
    start();

    dots.forEach(function (dot) {
      dot.addEventListener('click', function () {
        const next = parseInt(dot.getAttribute('data-slide') || '0', 10);
        goTo(next);
        resume();
      });
    });

    if (phoneCol) {
      phoneCol.addEventListener('focusin', pause);
      phoneCol.addEventListener('focusout', function () {
        if (!phoneCol.contains(document.activeElement)) resume();
      });
    }

    window.setTimeout(function () {
      if (timer || paused || document.hidden || slideCount < 2) return;
      const root = phoneCol || device || section;
      const rect = root.getBoundingClientRect();
      if (rect.bottom > 60 && rect.top < (window.innerHeight || 0)) {
        visible = true;
        start();
      }
    }, 250);

    const swipeRoot = viewport || device;
    if (swipeRoot) {
      swipeRoot.addEventListener('touchstart', function (e) {
        touchStartX = e.changedTouches[0].clientX;
        touchDeltaX = 0;
        pause();
      }, { passive: true });
      swipeRoot.addEventListener('touchmove', function (e) {
        touchDeltaX = e.changedTouches[0].clientX - touchStartX;
      }, { passive: true });
      swipeRoot.addEventListener('touchend', function () {
        if (Math.abs(touchDeltaX) > 40) {
          goTo(index + (touchDeltaX < 0 ? 1 : -1));
        }
        resume();
      });
      swipeRoot.addEventListener('touchcancel', resume);
    }

    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stop();
      else start();
    });

    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver(function (entries) {
        visible = entries.some(function (entry) {
          return entry.isIntersecting && entry.intersectionRatio > 0;
        });
        if (visible) start();
        else stop();
      }, { threshold: [0, 0.08, 0.2] });
      observer.observe(phoneCol || device || section);
    }
  }
})();
