/**
 * Landing page — theme toggle reveal + single-click light/dark background swap.
 */
(function () {
  'use strict';

  if (!document.body.classList.contains('landing-page')) return;

  const wrap = document.getElementById('landing-theme-fab');
  const btn = document.getElementById('landing-theme-toggle');
  const signinBtn = document.getElementById('signin-theme-toggle');
  if (!btn && !signinBtn) return;

  const services = document.getElementById('services-section');
  const sentinel = document.getElementById('hero-theme-sentinel');
  const target = services || sentinel;

  let visible = false;

  function getResolved() {
    return document.documentElement.getAttribute('data-theme-resolved') || 'light';
  }

  function syncLandingBgClass() {
    const dark = getResolved() === 'dark';
    document.body.classList.toggle('landing-bg--dark', dark);
    document.body.classList.toggle('landing-bg--light', !dark);
    const label = dark ? 'Switch to light mode' : 'Switch to dark mode';
    if (btn) {
      btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
      btn.setAttribute('aria-label', label);
      btn.setAttribute('title', label);
    }
    if (signinBtn) {
      signinBtn.setAttribute('aria-pressed', dark ? 'true' : 'false');
      signinBtn.setAttribute('aria-label', label);
      signinBtn.setAttribute('title', label);
    }
  }

  function applyLandingTheme(preference) {
    if (window.MedConnectTheme && typeof window.MedConnectTheme.applyTheme === 'function') {
      window.MedConnectTheme.applyTheme(preference);
    } else {
      const resolved = preference === 'dark' ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme-preference', preference);
      document.documentElement.setAttribute('data-theme-resolved', resolved);
      document.body.setAttribute('data-theme-preference', preference);
      document.body.setAttribute('data-theme-resolved', resolved);
      try {
        localStorage.setItem('medconnect_theme', preference);
      } catch (e) { /* ignore */ }
    }
    syncLandingBgClass();
  }

  function setVisible(next) {
    if (!wrap || !btn) return;
    if (next === visible) return;
    visible = next;
    wrap.classList.toggle('is-visible', visible);
    wrap.setAttribute('aria-hidden', visible ? 'false' : 'true');
    if (!visible) btn.blur();
  }

  function onToggle(e) {
    e.preventDefault();
    e.stopPropagation();
    const next = getResolved() === 'dark' ? 'light' : 'dark';
    if (window.MedConnectTheme && typeof window.MedConnectTheme.saveTheme === 'function') {
      window.MedConnectTheme.saveTheme(next);
    } else {
      applyLandingTheme(next);
    }
    syncLandingBgClass();
  }

  if (btn) btn.addEventListener('click', onToggle);
  if (signinBtn) signinBtn.addEventListener('click', onToggle);

  syncLandingBgClass();

  const themeObserver = new MutationObserver(syncLandingBgClass);
  themeObserver.observe(document.documentElement, {
    attributes: true,
    attributeFilter: ['data-theme-resolved'],
  });

  if (wrap && target) {
    const scrollObserver = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (services) {
            setVisible(entry.isIntersecting);
          } else {
            setVisible(!entry.isIntersecting);
          }
        });
      },
      { threshold: 0, rootMargin: '0px' }
    );

    scrollObserver.observe(target);

    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') {
        scrollObserver.unobserve(target);
        scrollObserver.observe(target);
      }
    });
  } else if (wrap) {
    setVisible(true);
  }
})();
