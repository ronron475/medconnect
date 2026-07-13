(function () {
  'use strict';

  const STORAGE_KEY = 'medconnect_theme';
  const root = document.documentElement;
  const body = document.body;

  function getMetaPreference() {
    const meta = document.querySelector('meta[name="medconnect-theme"]');
    return meta ? meta.getAttribute('content') || 'system' : null;
  }

  function getCsrf() {
    const el = document.querySelector('body[data-csrf], #medconnectThemeRoot[data-csrf], [data-csrf]');
    return el ? el.getAttribute('data-csrf') || '' : '';
  }

  function getAssetBase() {
    const el = document.querySelector('body[data-asset-base], #medconnectThemeRoot[data-asset-base], [data-asset-base]');
    if (el) return el.getAttribute('data-asset-base') || '';
    if (window.APP_BASE) return window.APP_BASE;
    return '';
  }

  function resolveTheme(preference) {
    if (preference === 'dark') return 'dark';
    if (preference === 'light') return 'light';
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function applyTheme(preference, persistLocal) {
    const pref = preference || 'system';
    const resolved = resolveTheme(pref);

    root.setAttribute('data-theme-preference', pref);
    root.setAttribute('data-theme-resolved', resolved);
    if (body) {
      body.setAttribute('data-theme-preference', pref);
      body.setAttribute('data-theme-resolved', resolved);
      body.setAttribute('data-provider-theme', pref);
      body.setAttribute('data-provider-theme-resolved', resolved);
    }

    if (persistLocal !== false) {
      try {
        localStorage.setItem(STORAGE_KEY, pref);
      } catch (e) { /* ignore */ }
    }

    document.querySelectorAll('.mc-theme-toggle__option').forEach((btn) => {
      const val = btn.getAttribute('data-theme-value');
      btn.classList.toggle('is-active', val === pref);
      btn.setAttribute('aria-checked', val === pref ? 'true' : 'false');
    });

    document.querySelectorAll('.mc-theme-toggle__icon').forEach((icon) => {
      icon.textContent = resolved === 'dark' ? '🌙' : '☀';
    });
  }

  async function saveTheme(preference) {
    applyTheme(preference);
    const assetBase = getAssetBase();
    const csrf = getCsrf();
    if (!assetBase || !csrf) {
      return { success: true, localOnly: true };
    }

    try {
      const fd = new FormData();
      fd.append('theme_preference', preference);
      fd.append('csrf_token', csrf);
      const res = await fetch(assetBase + '/app/api/theme/save.php', {
        method: 'POST',
        body: fd,
        credentials: 'include',
        cache: 'no-store',
      });
      const data = await res.json();
      if (!data.success && data.status !== 'success') {
        throw new Error(data.message || 'Could not save theme.');
      }
      return data;
    } catch (err) {
      console.warn('Theme save failed:', err);
      return { success: false, message: err.message };
    }
  }

  function bindToggle() {
    const IS_TOUCH = ('ontouchstart' in window) || (navigator.maxTouchPoints && navigator.maxTouchPoints > 0);
    // Use a single, consistent event family to avoid "ghost click" closing immediately on mobile.
    const OPEN_EVENT = IS_TOUCH ? 'pointerdown' : 'click';
    document.querySelectorAll('.mc-theme-toggle').forEach((wrap) => {
      const btn = wrap.querySelector('.mc-theme-toggle__btn');
      const menu = wrap.querySelector('.mc-theme-toggle__menu');
      if (!btn || !menu) return;

      // Mobile: use pointer events so taps always register.
      btn.style.pointerEvents = 'auto';
      btn.addEventListener(OPEN_EVENT, (e) => {
        // Prevent the global outside-click handler from immediately closing it.
        e.preventDefault();
        e.stopPropagation();
        const open = wrap.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });

      // Safety: stop bubbling for the "click" that some browsers still emit after pointer events.
      btn.addEventListener('click', (e) => e.stopPropagation());

      menu.querySelectorAll('.mc-theme-toggle__option').forEach((option) => {
        option.addEventListener(OPEN_EVENT, async (e) => {
          e.preventDefault();
          e.stopPropagation();
          const value = option.getAttribute('data-theme-value') || 'system';
          wrap.classList.remove('is-open');
          btn.setAttribute('aria-expanded', 'false');
          await saveTheme(value);

          const select = document.getElementById('theme');
          if (select) select.value = value;
          const patientSelect = document.getElementById('patientTheme');
          if (patientSelect) patientSelect.value = value;

          if (window.MedConnectProviderPrefs) {
            window.MedConnectProviderPrefs.applyTheme(value);
          }
        });
        option.addEventListener('click', (e) => e.stopPropagation());
      });
    });

    // Close only when clicking/tapping outside the toggle.
    const CLOSE_EVENT = IS_TOUCH ? 'pointerdown' : 'click';
    document.addEventListener(
      CLOSE_EVENT,
      (e) => {
        const target = e.target;
        if (target && target.closest && target.closest('.mc-theme-toggle')) {
          return;
        }
        document.querySelectorAll('.mc-theme-toggle.is-open').forEach((wrap) => {
          wrap.classList.remove('is-open');
          const btn = wrap.querySelector('.mc-theme-toggle__btn');
          if (btn) btn.setAttribute('aria-expanded', 'false');
        });
      },
      true // capture: close before other handlers, but we ignore taps inside the toggle
    );

    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      document.querySelectorAll('.mc-theme-toggle.is-open').forEach((wrap) => {
        wrap.classList.remove('is-open');
        const btn = wrap.querySelector('.mc-theme-toggle__btn');
        if (btn) btn.setAttribute('aria-expanded', 'false');
      });
    });
  }

  function bindPatientAppearanceForm() {
    const form = document.getElementById('patientAppearanceForm');
    if (!form) return;
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const select = document.getElementById('patientTheme');
      const alert = document.getElementById('patientAppearanceAlert');
      const btn = form.querySelector('[type="submit"]');
      const value = select ? select.value : 'system';
      if (btn) {
        btn.disabled = true;
        btn.textContent = 'Saving…';
      }
      const result = await saveTheme(value);
      if (alert) {
        alert.textContent = result.success !== false
          ? 'Appearance preferences saved.'
          : (result.message || 'Could not save preferences.');
        alert.className = 'mc-alert ' + (result.success !== false ? 'mc-alert--success' : 'mc-alert--error');
        alert.style.display = 'block';
      }
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Save Appearance';
      }
    });
  }

  window.MedConnectTheme = {
    STORAGE_KEY,
    resolveTheme,
    applyTheme,
    saveTheme,
    getPreference() {
      return root.getAttribute('data-theme-preference') || getMetaPreference() || 'system';
    },
  };

  // Prefer localStorage over server meta so the UI doesn't "revert" on reload
  // if the API save fails (e.g., CSRF/session mismatch). Server meta still
  // applies when no local choice exists.
  const initial = (function () {
    try {
      return localStorage.getItem(STORAGE_KEY);
    } catch (e) {
      return null;
    }
  })() || getMetaPreference() || 'system';

  applyTheme(initial, false);
  try {
    localStorage.setItem(STORAGE_KEY, initial);
  } catch (e) { /* ignore */ }

  bindToggle();
  bindPatientAppearanceForm();

  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (window.MedConnectTheme.getPreference() === 'system') {
      applyTheme('system', false);
    }
  });
})();
