(function () {
  'use strict';

  const root = document.getElementById('providerSettingsRoot');
  if (!root) return;

  const csrf = root.dataset.csrf || '';
  const assetBase = root.dataset.assetBase || '';
  const api = {
    profile: assetBase + '/app/api/provider/settings/save_profile.php',
    password: assetBase + '/app/api/provider/settings/change_password.php',
    notifications: assetBase + '/app/api/provider/settings/save_notifications.php',
    system: assetBase + '/app/api/provider/settings/save_system.php',
  };

  const alerts = {
    profile: document.getElementById('psAlertProfile'),
    security: document.getElementById('psAlertSecurity'),
    notifications: document.getElementById('psAlertNotifications'),
    system: document.getElementById('psAlertSystem'),
  };

  window.psShowToast = function showToast(message, type) {
    let toast = document.getElementById('psToast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'psToast';
      toast.className = 'ps-toast';
      toast.setAttribute('role', 'status');
      document.body.appendChild(toast);
    }
    toast.textContent = (type === 'success' ? '✓ ' : type === 'error' ? '✗ ' : '') + message;
    toast.className = 'ps-toast show ' + (type || 'success');
    clearTimeout(showToast._timer);
    showToast._timer = setTimeout(() => {
      toast.className = 'ps-toast';
      toast.textContent = '';
    }, 4200);
  };

  function showAlert(el, message, type) {
    if (!el) return;
    el.textContent = message;
    el.className = 'ps-alert show ' + type;
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    if (type === 'success') {
      setTimeout(() => {
        el.className = 'ps-alert';
        el.textContent = '';
      }, 5000);
    }
  }

  function clearAlert(el) {
    if (!el) return;
    el.className = 'ps-alert';
    el.textContent = '';
  }

  async function postForm(url, formData) {
    formData.append('csrf_token', csrf);
    const res = await fetch(url, {
      method: 'POST',
      body: formData,
      credentials: 'include',
      cache: 'no-store',
    });
    const data = await res.json();
    if (data.status === 'error' || data.success === false) {
      throw new Error(data.message || 'Request failed.');
    }
    if (!data.success && data.status !== 'success') {
      throw new Error(data.message || 'Request failed.');
    }
    return data;
  }

  function setLoading(btn, loading, label) {
    if (!btn) return;
    btn.disabled = loading;
    btn.textContent = loading ? 'Saving…' : label;
  }

  // Tab navigation
  const navItems = root.querySelectorAll('[data-settings-tab]');
  const panels = root.querySelectorAll('[data-settings-panel]');

  navItems.forEach((btn) => {
    btn.addEventListener('click', () => {
      const tab = btn.getAttribute('data-settings-tab');
      navItems.forEach((n) => {
        const active = n === btn;
        n.classList.toggle('is-active', active);
        n.classList.toggle('active', active);
        n.setAttribute('aria-selected', active ? 'true' : 'false');
      });
      panels.forEach((p) => p.classList.toggle('is-active', p.getAttribute('data-settings-panel') === tab));
    });
  });

  // Profile save
  const profileForm = document.getElementById('providerProfileForm');
  if (profileForm) {
    profileForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearAlert(alerts.profile);
      const btn = profileForm.querySelector('[type="submit"]');
      setLoading(btn, true, 'Save Changes');
      try {
        const fd = new FormData(profileForm);
        const data = await postForm(api.profile, fd);
        showAlert(alerts.profile, data.message, 'success');
      } catch (err) {
        showAlert(alerts.profile, err.message, 'error');
      } finally {
        setLoading(btn, false, 'Save Changes');
      }
    });
  }

  // Notifications save
  const notificationsForm = document.getElementById('providerNotificationsForm');
  if (notificationsForm) {
    notificationsForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearAlert(alerts.notifications);
      const btn = notificationsForm.querySelector('[type="submit"]');
      setLoading(btn, true, 'Save Preferences');
      try {
        const fd = new FormData(notificationsForm);
        ['new_messages', 'consultation_requests', 'triage_alerts', 'system_notifications', 'email_notifications', 'sms_notifications'].forEach((name) => {
          if (!fd.has(name)) fd.append(name, '0');
        });
        const data = await postForm(api.notifications, fd);
        showAlert(alerts.notifications, data.message, 'success');
      } catch (err) {
        showAlert(alerts.notifications, err.message, 'error');
      } finally {
        setLoading(btn, false, 'Save Preferences');
      }
    });
  }

  // System save
  const systemForm = document.getElementById('providerSystemForm');
  if (systemForm) {
    systemForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearAlert(alerts.system);
      const btn = systemForm.querySelector('[type="submit"]');
      const saveLabel = btn ? btn.dataset.i18nLabel || btn.textContent : 'Save Preferences';
      setLoading(btn, true, saveLabel);
      try {
        const fd = new FormData(systemForm);
        fd.append('theme_preference', fd.get('theme') || 'system');
        fd.append('auto_logout_duration', fd.get('auto_logout_minutes') || '30');
        const data = await postForm(api.system, fd);
        if (window.MedConnectProviderPrefs && data.data?.system) {
          window.MedConnectProviderPrefs.applySystemPrefs(data.data.system);
        }
        if (window.MedConnectTheme && data.data?.system) {
          const theme = data.data.system.theme || data.data.system.theme_preference || 'system';
          window.MedConnectTheme.applyTheme(theme);
          try { localStorage.setItem('medconnect_theme', theme); } catch (e) {}
        }
        showToast(data.message || 'Preferences updated successfully.', 'success');
        clearAlert(alerts.system);
      } catch (err) {
        showAlert(alerts.system, err.message, 'error');
        showToast(err.message, 'error');
      } finally {
        setLoading(btn, false, saveLabel);
      }
    });
  }
})();
