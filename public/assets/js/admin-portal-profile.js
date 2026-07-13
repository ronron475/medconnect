(function () {
  'use strict';

  const btn = document.getElementById('adminLogoutAllBtn');
  if (!btn) return;

  const alertEl = document.getElementById('adminSessionsAlert');
  const assetBase = document.body.dataset.assetBase || '';
  const csrf = document.body.dataset.csrf || '';

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
