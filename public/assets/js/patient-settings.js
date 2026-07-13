/**
 * Patient Settings — tabs, forms, API actions
 */
(function () {
  'use strict';

  const root = document.getElementById('patientSettingsRoot');
  if (!root) return;

  const page = root.querySelector('.pts-page');
  const apiBase = root.dataset.api || '';
  const csrf = root.dataset.csrf || '';
  let confirmCallback = null;
  let toastTimer = null;

  const confirmModal = document.getElementById('ptsConfirmModal');
  const confirmMsg = document.getElementById('ptsConfirmMessage');
  const confirmOk = document.getElementById('ptsConfirmOk');
  const toastEl = document.getElementById('ptsToast');

  function showToast(msg, type) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.className = 'pts-toast' + (type === 'error' ? ' pts-toast--error' : '');
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.hidden = true; }, 4500);
  }

  function showPanelAlert(id, msg, type) {
    const el = document.getElementById(id);
    if (el) {
      el.textContent = msg;
      el.className = 'pts-alert pts-alert--' + (type || 'info');
      el.hidden = false;
      el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    showToast(msg, type);
  }

  function setLoading(btn, loading) {
    if (!btn) return;
    btn.disabled = loading;
    const spinner = btn.querySelector('.pts-btn__spinner');
    const text = btn.querySelector('.pts-btn__text');
    if (spinner) spinner.hidden = !loading;
    if (text) text.style.opacity = loading ? '0.7' : '1';
  }

  function confirmAction(title, message, onConfirm) {
    if (window.McModal && typeof window.McModal.confirm === 'function') {
      window.McModal.confirm({
        title: title,
        message: message,
        confirmLabel: 'Confirm',
        cancelLabel: 'Cancel',
        showLogo: false,
        icon: 'confirm',
      }).then(function (ok) {
        if (ok && typeof onConfirm === 'function') onConfirm();
      });
      return;
    }
    if (!confirmModal) {
      if (window.confirm(message)) onConfirm();
      return;
    }
    document.getElementById('ptsConfirmTitle').textContent = title;
    confirmMsg.textContent = message;
    confirmCallback = onConfirm;
    confirmModal.hidden = false;
  }

  confirmOk?.addEventListener('click', function () {
    confirmModal.hidden = true;
    if (typeof confirmCallback === 'function') confirmCallback();
    confirmCallback = null;
  });
  confirmModal?.querySelectorAll('[data-pts-close]').forEach(function (el) {
    el.addEventListener('click', function () {
      confirmModal.hidden = true;
      confirmCallback = null;
    });
  });

  function activateTab(id) {
    document.querySelectorAll('[data-pts-tab]').forEach(function (t) {
      const active = t.dataset.ptsTab === id;
      t.classList.toggle('is-active', active);
      t.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('[data-pts-panel]').forEach(function (panel) {
      const active = panel.dataset.ptsPanel === id;
      panel.classList.toggle('is-active', active);
      panel.hidden = !active;
    });
    try {
      const url = new URL(window.location.href);
      url.searchParams.set('tab', id);
      window.history.replaceState({}, '', url.toString());
    } catch (_) { /* ignore */ }
  }

  document.querySelectorAll('[data-pts-tab]').forEach(function (tab) {
    tab.addEventListener('click', function () {
      activateTab(tab.dataset.ptsTab);
    });
  });

  const initialTab = page?.dataset.initialTab || 'security';
  if (initialTab !== 'security') {
    activateTab(initialTab);
  }

  // Password visibility — swap type + icon + label
  document.querySelectorAll('.pts-toggle-pw').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      const input = document.getElementById(btn.dataset.target);
      if (!input) return;
      const reveal = input.type === 'password';
      input.type = reveal ? 'text' : 'password';
      btn.setAttribute('aria-pressed', reveal ? 'true' : 'false');
      btn.classList.toggle('is-revealed', reveal);
      const label = btn.querySelector('.pts-toggle-label');
      if (label) label.textContent = reveal ? 'Hide' : 'Show';
      const eyeOpen = btn.querySelector('.pts-eye-open');
      const eyeClosed = btn.querySelector('.pts-eye-closed');
      if (eyeOpen) eyeOpen.hidden = reveal;
      if (eyeClosed) eyeClosed.hidden = !reveal;
      const fieldLabel = input.id === 'ptsCurrentPassword' ? 'current password'
        : input.id === 'ptsNewPassword' ? 'new password' : 'confirm password';
      btn.setAttribute('aria-label', (reveal ? 'Hide ' : 'Show ') + fieldLabel);
      input.focus();
    });
  });

  const newPw = document.getElementById('ptsNewPassword');
  const confirmPw = document.getElementById('ptsConfirmPassword');
  const strengthFill = document.getElementById('ptsStrengthFill');
  const strengthLabel = document.getElementById('ptsStrengthLabel');
  const matchHint = document.getElementById('ptsMatchHint');
  const reqList = document.getElementById('ptsReqList');

  function checkRequirements(pw) {
    const checks = {
      len: pw.length >= 12,
      upper: /[A-Z]/.test(pw),
      lower: /[a-z]/.test(pw),
      digit: /\d/.test(pw),
      special: /[^A-Za-z0-9]/.test(pw),
    };
    if (reqList) {
      reqList.querySelectorAll('[data-req]').forEach(function (li) {
        li.classList.toggle('is-met', !!checks[li.dataset.req]);
      });
    }
    let met = 0;
    Object.values(checks).forEach(function (v) { if (v) met++; });

    // Map 0..5 checks -> 0..4 levels with required labels.
    const level = met >= 5 ? 4 : met >= 4 ? 3 : met >= 3 ? 2 : met >= 2 ? 1 : 0;
    const labels = ['Weak', 'Fair', 'Good', 'Strong', 'Very Strong'];
    return { met, level, label: pw ? labels[level] : 'Weak', checks: checks };
  }

  function updatePasswordUI() {
    const pw = newPw?.value || '';
    const r = checkRequirements(pw);
    if (strengthFill) {
      const pct = pw ? Math.round((r.met / 5) * 100) : 0;
      strengthFill.style.width = pct + '%';
      const cls = r.level === 4 ? 'pts-validation__fill--vstrong'
        : r.level === 3 ? 'pts-validation__fill--strong'
        : r.level === 2 ? 'pts-validation__fill--good'
        : r.level === 1 ? 'pts-validation__fill--fair'
        : 'pts-validation__fill--weak';
      strengthFill.className = 'pts-validation__fill ' + cls;
    }
    if (strengthLabel) strengthLabel.textContent = r.label;

    if (confirmPw && matchHint) {
      if (!confirmPw.value) {
        matchHint.hidden = true;
      } else if (confirmPw.value === pw) {
        matchHint.hidden = false;
        matchHint.textContent = 'Passwords match';
        matchHint.className = 'pts-match-hint is-ok';
      } else {
        matchHint.hidden = false;
        matchHint.textContent = 'Passwords do not match';
        matchHint.className = 'pts-match-hint is-bad';
      }
    }
  }

  newPw?.addEventListener('input', updatePasswordUI);
  confirmPw?.addEventListener('input', updatePasswordUI);

  async function postForm(url, fd, btn) {
    setLoading(btn, true);
    try {
      const res = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
      let data;
      try { data = await res.json(); } catch (_) {
        throw new Error('Unexpected server response.');
      }
      if (!data.success) throw new Error(data.message || 'Request failed.');
      return data;
    } finally {
      setLoading(btn, false);
    }
  }

  document.getElementById('ptsPasswordForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const pw = newPw?.value || '';
    const confirm = confirmPw?.value || '';
    const r = checkRequirements(pw);

    if (!Object.values(r.checks).every(Boolean)) {
      showPanelAlert('ptsPasswordAlert', 'Please meet all password requirements.', 'error');
      return;
    }
    if (pw !== confirm) {
      showPanelAlert('ptsPasswordAlert', 'New password and confirmation do not match.', 'error');
      return;
    }

    const fd = new FormData(e.target);
    fd.append('csrf_token', csrf);
    const btn = document.getElementById('ptsPasswordSubmit');
    try {
      const data = await postForm(apiBase + '/change_password.php', fd, btn);
      showPanelAlert('ptsPasswordAlert', data.message, 'success');
      e.target.reset();
      document.querySelectorAll('.pts-toggle-pw').forEach(function (btn) {
        btn.classList.remove('is-revealed');
        btn.setAttribute('aria-pressed', 'false');
        const label = btn.querySelector('.pts-toggle-label');
        if (label) label.textContent = 'Show';
        const eyeOpen = btn.querySelector('.pts-eye-open');
        const eyeClosed = btn.querySelector('.pts-eye-closed');
        if (eyeOpen) eyeOpen.hidden = false;
        if (eyeClosed) eyeClosed.hidden = true;
      });
      updatePasswordUI();
    } catch (err) {
      showPanelAlert('ptsPasswordAlert', err.message || 'Could not update password.', 'error');
    }
  });

  document.getElementById('ptsPrivacyForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('csrf_token', csrf);
    const btn = e.target.querySelector('button[type="submit"]');
    try {
      const data = await postForm(apiBase + '/save_privacy.php', fd, btn);
      showPanelAlert('ptsPrivacyAlert', data.message, 'success');
    } catch (err) {
      showPanelAlert('ptsPrivacyAlert', err.message || 'Could not save.', 'error');
    }
  });

  document.getElementById('ptsNotifForm')?.addEventListener('submit', async function (e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    fd.append('csrf_token', csrf);
    const btn = e.target.querySelector('button[type="submit"]');
    try {
      const data = await postForm(apiBase + '/save_notifications.php', fd, btn);
      showPanelAlert('ptsNotifAlert', data.message, 'success');
    } catch (err) {
      showPanelAlert('ptsNotifAlert', err.message || 'Could not save.', 'error');
    }
  });

  document.getElementById('ptsLogoutAllBtn')?.addEventListener('click', function () {
    confirmAction(
      'Logout all devices',
      'This will sign you out of all other active sessions and revoke remembered devices. Your current session stays active.',
      async function () {
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        const btn = document.getElementById('ptsLogoutAllBtn');
        setLoading(btn, true);
        try {
          const res = await fetch(apiBase + '/logout_all_devices.php', { method: 'POST', body: fd, credentials: 'same-origin' });
          const data = await res.json();
          if (!data.success) throw new Error(data.message || 'Failed');
          showPanelAlert('ptsSessionsAlert', data.message, 'success');
          document.querySelectorAll('.pts-session-end').forEach(function (b) {
            b.closest('.pts-session-item')?.remove();
          });
        } catch (err) {
          showPanelAlert('ptsSessionsAlert', err.message || 'Action failed.', 'error');
        } finally {
          setLoading(btn, false);
        }
      }
    );
  });

  document.querySelectorAll('.pts-session-end').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const sid = btn.dataset.sessionId;
      confirmAction('End session', 'Sign out this device? The user on that device will need to log in again.', async function () {
        const fd = new FormData();
        fd.append('csrf_token', csrf);
        fd.append('session_id', sid);
        setLoading(btn, true);
        try {
          const res = await fetch(apiBase + '/terminate_session.php', { method: 'POST', body: fd, credentials: 'same-origin' });
          const data = await res.json();
          if (!data.success) throw new Error(data.message || 'Failed');
          btn.closest('.pts-session-item')?.remove();
          showPanelAlert('ptsSessionsAlert', data.message, 'success');
        } catch (err) {
          showPanelAlert('ptsSessionsAlert', err.message || 'Could not end session.', 'error');
        } finally {
          setLoading(btn, false);
        }
      });
    });
  });
})();
