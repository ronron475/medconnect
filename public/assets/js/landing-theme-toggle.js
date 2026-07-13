/**
 * Landing page — single-click light/dark background toggle (BSIS-style).
 */
(function () {
  'use strict';

  function init() {
    if (!document.body.classList.contains('landing-page')) return;

    const btn = document.getElementById('landing-theme-toggle');
    if (!btn) return;

    function getResolved() {
      return document.documentElement.getAttribute('data-theme-resolved') || 'light';
    }

    function syncButton() {
      const dark = getResolved() === 'dark';
      btn.setAttribute('aria-pressed', dark ? 'true' : 'false');
      btn.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
      btn.setAttribute('title', dark ? 'Switch to light mode' : 'Switch to dark mode');
    }

    function applyLandingTheme(preference) {
      if (window.MedConnectTheme && typeof window.MedConnectTheme.saveTheme === 'function') {
        window.MedConnectTheme.saveTheme(preference);
        return;
      }
      if (window.MedConnectTheme && typeof window.MedConnectTheme.applyTheme === 'function') {
        window.MedConnectTheme.applyTheme(preference);
        return;
      }
      const resolved = preference === 'dark' ? 'dark' : 'light';
      document.documentElement.setAttribute('data-theme-preference', preference);
      document.documentElement.setAttribute('data-theme-resolved', resolved);
      document.body.setAttribute('data-theme-preference', preference);
      document.body.setAttribute('data-theme-resolved', resolved);
      try {
        localStorage.setItem('medconnect_theme', preference);
      } catch (e) { /* ignore */ }
    }

    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      const next = getResolved() === 'dark' ? 'light' : 'dark';
      applyLandingTheme(next);
      syncButton();
    });

    syncButton();

    const observer = new MutationObserver(syncButton);
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['data-theme-resolved'],
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
