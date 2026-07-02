(function () {
  'use strict';

  const body = document.body;
  if (!body || !body.classList.contains('provider-body')) return;

  const i18n = {
    en: {
      settings: 'Settings',
      profile_information: 'Profile Information',
      security_password: 'Security & Password',
      notification_preferences: 'Notification Preferences',
      system_preferences: 'System Preferences',
      theme_preference: 'Theme Preference',
      language: 'Language',
      time_format: 'Time Format',
      date_format: 'Date Format',
      auto_logout: 'Auto Logout Duration',
      save_preferences: 'Save Preferences',
    },
    fil: {
      settings: 'Mga Setting',
      profile_information: 'Impormasyon ng Profile',
      security_password: 'Seguridad at Password',
      notification_preferences: 'Mga Kagustuhan sa Notification',
      system_preferences: 'Mga Kagustuhan sa Sistema',
      theme_preference: 'Tema',
      language: 'Wika',
      time_format: 'Format ng Oras',
      date_format: 'Format ng Petsa',
      auto_logout: 'Tagal bago Mag-logout',
      save_preferences: 'I-save ang mga Kagustuhan',
    },
  };

  function resolveTheme(preference) {
    if (preference === 'dark') return 'dark';
    if (preference === 'light') return 'light';
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function applyTheme(preference) {
    if (window.MedConnectTheme) {
      window.MedConnectTheme.applyTheme(preference || body.getAttribute('data-provider-theme') || 'system');
      return;
    }
    const resolved = resolveTheme(preference || body.getAttribute('data-provider-theme') || 'system');
    body.setAttribute('data-provider-theme', preference || 'system');
    body.setAttribute('data-provider-theme-resolved', resolved);
    document.documentElement.setAttribute('data-theme-preference', preference || 'system');
    document.documentElement.setAttribute('data-theme-resolved', resolved);
  }

  function applyLanguage(lang) {
    const strings = i18n[lang] || i18n.en;
    document.documentElement.lang = lang === 'fil' ? 'fil' : 'en';
    document.querySelectorAll('[data-i18n]').forEach((el) => {
      const key = el.getAttribute('data-i18n');
      if (strings[key]) {
        el.textContent = strings[key];
      }
    });
  }

  function formatClock(date) {
    const timeFormat = body.getAttribute('data-time-format') || '12h';
    if (timeFormat === '24h') {
      return date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false });
    }
    return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
  }

  function formatHeaderDate(date) {
    const dateFormat = body.getAttribute('data-date-format') || 'M j, Y';
    const map = {
      'M j, Y': { month: 'short', day: 'numeric', year: 'numeric' },
      'j M Y': { day: 'numeric', month: 'short', year: 'numeric' },
      'Y-m-d': undefined,
    };
    if (dateFormat === 'Y-m-d') {
      const y = date.getFullYear();
      const m = String(date.getMonth() + 1).padStart(2, '0');
      const d = String(date.getDate()).padStart(2, '0');
      return `${y}-${m}-${d}`;
    }
    const opts = map[dateFormat] || map['M j, Y'];
    return date.toLocaleDateString('en-US', { weekday: 'long', ...opts });
  }

  function tickClock() {
    const clock = document.getElementById('pdClock');
    const headerDate = document.querySelector('.pd-header-date');
    const now = new Date();
    if (clock) clock.textContent = formatClock(now);
    if (headerDate) headerDate.textContent = formatHeaderDate(now);
  }

  window.MedConnectProviderPrefs = {
    applyTheme,
    applyLanguage,
    formatClock,
    formatHeaderDate,
    updateAutoLogout(minutes) {
      body.setAttribute('data-auto-logout', String(minutes || 30));
    },
    applySystemPrefs(prefs) {
      if (!prefs) return;
      if (prefs.theme || prefs.theme_preference) {
        applyTheme(prefs.theme || prefs.theme_preference);
      }
      if (prefs.language) {
        body.setAttribute('data-language', prefs.language);
        applyLanguage(prefs.language);
      }
      if (prefs.time_format) {
        body.setAttribute('data-time-format', prefs.time_format);
      }
      if (prefs.date_format) {
        body.setAttribute('data-date-format', prefs.date_format);
      }
      const logout = prefs.auto_logout_duration ?? prefs.auto_logout_minutes;
      if (logout !== undefined) {
        this.updateAutoLogout(logout);
      }
      tickClock();
    },
  };

  if (!window.MedConnectTheme) {
    applyTheme(body.getAttribute('data-provider-theme') || 'system');
  }
  applyLanguage(body.getAttribute('data-language') || 'en');
  tickClock();
  setInterval(tickClock, 1000);

  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if ((body.getAttribute('data-provider-theme') || 'system') === 'system') {
      applyTheme('system');
    }
  });
})();
