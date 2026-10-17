(function () {
  'use strict';

  const assetBase = document.body.dataset.assetBase || '';
  const csrf = document.body.dataset.csrf || '';

  function bindAlertForm(formId, alertId, buttonId, url, onSuccess) {
    const form = document.getElementById(formId);
    if (!form) return;
    const alertEl = document.getElementById(alertId);
    const button = document.getElementById(buttonId);
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      const fd = new FormData(form);
      fd.append('csrf_token', csrf);
      if (button) button.disabled = true;
      try {
        const res = await fetch(assetBase + url, {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
        });
        const data = await res.json().catch(function () { return {}; });
        if (!res.ok || !data.success) {
          throw new Error(data.message || 'Could not save.');
        }
        if (alertEl) {
          alertEl.hidden = false;
          alertEl.textContent = data.message || 'Saved.';
          alertEl.classList.add('is-success');
          alertEl.classList.remove('is-error');
        }
        if (typeof onSuccess === 'function') onSuccess(data, form);
      } catch (err) {
        if (alertEl) {
          alertEl.hidden = false;
          alertEl.textContent = err.message || 'Could not save.';
          alertEl.classList.add('is-error');
          alertEl.classList.remove('is-success');
        }
      } finally {
        if (button) button.disabled = false;
      }
    });
  }

  bindAlertForm(
    'adminPasswordForm',
    'adminPasswordAlert',
    'adminPasswordSubmit',
    '/app/api/admin/settings/change_password.php',
    function (_data, form) { form.reset(); }
  );

  bindAlertForm(
    'adminGmailForm',
    'adminGmailAlert',
    'adminGmailSubmit',
    '/app/api/admin/settings/change_gmail.php',
    function (data, form) {
      const email = data.email || '';
      const current = document.getElementById('adminCurrentGmail');
      const account = document.getElementById('adminAccountEmail');
      if (current && email) current.value = email;
      if (account && email) account.textContent = email;
      form.reset();
      if (current && email) current.value = email;
    }
  );

  const btn = document.getElementById('adminLogoutAllBtn');
  if (!btn) return;

  const alertEl = document.getElementById('adminSessionsAlert');

  function showAlert(message, isError) {
    if (!alertEl) return;
    alertEl.textContent = message;
    alertEl.hidden = false;
    alertEl.classList.toggle('is-error', !!isError);
    alertEl.classList.toggle('is-success', !isError);
  }

  btn.addEventListener('click', async function () {
    if (!window.confirm('Sign out of all other devices? You will stay signed in on this browser.')) {
      return;
    }

    btn.disabled = true;
    const original = btn.textContent;
    btn.textContent = 'Signing out…';

    try {
      const fd = new FormData();
      fd.append('csrf_token', csrf);
      const res = await fetch(assetBase + '/app/api/admin/settings/logout_all_devices.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.success) {
        throw new Error(data.message || 'Could not sign out other devices.');
      }
      showAlert(data.message || 'All other devices have been signed out.', false);
    } catch (err) {
      showAlert(err.message || 'Could not sign out other devices.', true);
    } finally {
      btn.disabled = false;
      btn.textContent = original;
    }
  });
})();
