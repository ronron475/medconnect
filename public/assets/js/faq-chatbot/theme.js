/**
 * medConnect FAQ Chatbot — Theme engine (Light / Dark)
 */
(function (global) {
  'use strict';

  const STORAGE_KEY = 'mc_fcb_theme';
  const THEMES = Object.freeze({ LIGHT: 'light', DARK: 'dark' });

  /** @type {HTMLElement|null} */
  let root = null;
  /** @type {HTMLButtonElement|null} */
  let toggleBtn = null;

  function getSystemTheme() {
    if (global.matchMedia && global.matchMedia('(prefers-color-scheme: dark)').matches) {
      return THEMES.DARK;
    }
    return THEMES.LIGHT;
  }

  function getStoredTheme() {
    try {
      const v = localStorage.getItem(STORAGE_KEY);
      if (v === THEMES.DARK || v === THEMES.LIGHT) return v;
    } catch (_) { /* ignore */ }
    return null;
  }

  function getTheme() {
    return getStoredTheme() || getSystemTheme();
  }

  function updateToggleUI(theme) {
    if (!toggleBtn) return;
    const isDark = theme === THEMES.DARK;
    toggleBtn.setAttribute('aria-pressed', isDark ? 'true' : 'false');
    toggleBtn.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
    toggleBtn.title = isDark ? 'Light mode' : 'Dark mode';
    toggleBtn.dataset.theme = theme;
  }

  function applyTheme(theme, options = {}) {
    const t = theme === THEMES.DARK ? THEMES.DARK : THEMES.LIGHT;
    if (!root) root = document.getElementById('faq-chatbot');
    if (!root) return t;

    root.setAttribute('data-theme', t);
    root.classList.add('fcb--theme-transition');

    if (options.persist !== false) {
      try { localStorage.setItem(STORAGE_KEY, t); } catch (_) { /* ignore */ }
    }

    updateToggleUI(t);

    if (!options.silent) {
      window.clearTimeout(applyTheme._timer);
      applyTheme._timer = window.setTimeout(() => {
        root.classList.remove('fcb--theme-transition');
      }, 420);
    } else {
      root.classList.remove('fcb--theme-transition');
    }

    return t;
  }

  function toggle() {
    const next = getTheme() === THEMES.DARK ? THEMES.LIGHT : THEMES.DARK;
    return applyTheme(next);
  }

  function init() {
    root = document.getElementById('faq-chatbot');
    toggleBtn = document.getElementById('fcb-theme-toggle');
    if (!root) return;

    const theme = getTheme();
    applyTheme(theme, { silent: true });

    if (toggleBtn) {
      toggleBtn.addEventListener('click', (e) => {
        const UI = global.McFaqUI;
        if (UI && UI.ripple) UI.ripple(e, toggleBtn);
        toggle();
      });
      if (global.McFaqUI && global.McFaqUI.bindRipple) {
        global.McFaqUI.bindRipple(toggleBtn);
      }
    }

    if (global.matchMedia) {
      const mq = global.matchMedia('(prefers-color-scheme: dark)');
      const onChange = () => {
        if (!getStoredTheme()) applyTheme(getSystemTheme(), { persist: false, silent: true });
      };
      if (mq.addEventListener) mq.addEventListener('change', onChange);
      else if (mq.addListener) mq.addListener(onChange);
    }
  }

  /** Boot before paint — call from inline script */
  function boot() {
    const rootEl = document.getElementById('faq-chatbot');
    if (!rootEl) return;
    const theme = getTheme();
    rootEl.setAttribute('data-theme', theme);
  }

  global.McFaqTheme = {
    THEMES,
    STORAGE_KEY,
    boot,
    init,
    getTheme,
    applyTheme,
    toggle,
  };
})(window);
