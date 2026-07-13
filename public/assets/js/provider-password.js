(function () {
  'use strict';

  const form = document.getElementById('providerPasswordForm');
  if (!form) return;

  const root = document.getElementById('providerSettingsRoot');
  const csrf = root ? root.dataset.csrf || '' : '';
  const assetBase = root ? root.dataset.assetBase || '' : '';
  const apiUrl = assetBase + '/app/api/provider/settings/change_password.php';

  const currentPw = document.getElementById('currentPassword');
  const newPw = document.getElementById('newPassword');
  const confirmPw = document.getElementById('confirmPassword');
  const strengthLabel = document.getElementById('psPwStrengthLabel');
  const strengthFill = document.getElementById('psPwStrengthFill');
  const reqList = document.getElementById('psPwReqList');
  const confirmError = document.getElementById('confirmPasswordError');
  const newPasswordError = document.getElementById('newPasswordError');
  const alertEl = document.getElementById('psAlertSecurity');
  const submitBtn = form.querySelector('[type="submit"]');

  function showToast(message, type) {
    if (typeof window.psShowToast === 'function') {
      window.psShowToast(message, type);
      return;
    }
    let toast = document.getElementById('psToast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'psToast';
      toast.className = 'ps-toast';
      document.body.appendChild(toast);
    }
    toast.textContent = (type === 'success' ? '✓ ' : '✗ ') + message;
    toast.className = 'ps-toast show ' + (type || 'success');
    clearTimeout(showToast._timer);
    showToast._timer = setTimeout(() => {
      toast.className = 'ps-toast';
      toast.textContent = '';
    }, 4200);
  }

  function showAlert(message, type) {
    if (!alertEl) return;
    alertEl.textContent = message;
    alertEl.className = 'ps-alert show ' + type;
  }

  function clearAlert() {
    if (!alertEl) return;
    alertEl.className = 'ps-alert';
    alertEl.textContent = '';
  }

  function checkRequirements(pw) {
    const p = String(pw || '');
    const checks = {
      len: p.length >= 12,
      upper: /[A-Z]/.test(p),
      lower: /[a-z]/.test(p),
      digit: /\d/.test(p),
      special: /[^A-Za-z0-9]/.test(p),
    };
    if (reqList) {
      reqList.querySelectorAll('[data-req]').forEach((li) => {
        li.classList.toggle('is-met', !!checks[li.dataset.req]);
      });
    }
    let met = 0;
    Object.values(checks).forEach((v) => { if (v) met++; });
    const level = met >= 5 ? 4 : met >= 4 ? 3 : met >= 3 ? 2 : met >= 2 ? 1 : 0;
    const labels = ['Weak', 'Fair', 'Good', 'Strong', 'Very Strong'];
    return { checks, met, level, label: p ? labels[level] : 'Weak' };
  }

  function updateStrengthMeter() {
    const v = newPw ? newPw.value : '';
    if (!v) {
      if (strengthLabel) strengthLabel.textContent = 'Weak';
      if (strengthFill) {
        strengthFill.style.width = '0%';
        strengthFill.className = 'ps-validation__fill ps-validation__fill--weak';
      }
      if (newPasswordError) {
        newPasswordError.textContent = '';
        newPasswordError.className = 'ps-field-error';
      }
      return;
    }

    const r = checkRequirements(v);
    if (strengthLabel) strengthLabel.textContent = r.label;
    if (strengthFill) {
      const pct = Math.round((r.met / 5) * 100);
      strengthFill.style.width = pct + '%';
      const cls = r.level === 4 ? 'ps-validation__fill--vstrong'
        : r.level === 3 ? 'ps-validation__fill--strong'
        : r.level === 2 ? 'ps-validation__fill--good'
        : r.level === 1 ? 'ps-validation__fill--fair'
        : 'ps-validation__fill--weak';
      strengthFill.className = 'ps-validation__fill ' + cls;
    }

    if (newPasswordError) {
      const allMet = Object.values(r.checks).every(Boolean);
      if (!allMet) {
        newPasswordError.textContent = 'Password does not meet security requirements.';
        newPasswordError.className = 'ps-field-error show';
        newPw.classList.add('is-invalid');
      } else {
        newPasswordError.textContent = '';
        newPasswordError.className = 'ps-field-error';
        newPw.classList.remove('is-invalid');
      }
    }
  }

  function updateConfirmMatch() {
    if (!confirmPw || !newPw || !confirmError) return;
    if (!confirmPw.value) {
      confirmError.textContent = '';
      confirmError.className = 'ps-field-error';
      confirmPw.classList.remove('is-invalid');
      return;
    }
    if (newPw.value !== confirmPw.value) {
      confirmError.textContent = 'Passwords do not match.';
      confirmError.className = 'ps-field-error show';
      confirmPw.classList.add('is-invalid');
      return false;
    }
    confirmError.textContent = '';
    confirmError.className = 'ps-field-error';
    confirmPw.classList.remove('is-invalid');
    return true;
  }

  function validateClientForm() {
    updateStrengthMeter();
    const rules = checkRequirements(newPw.value);
    const confirmOk = updateConfirmMatch();

    if (!currentPw.value || !newPw.value || !confirmPw.value) {
      showAlert('All password fields are required.', 'error');
      return false;
    }
    if (!Object.values(rules.checks).every(Boolean)) {
      showAlert('Password does not meet security requirements.', 'error');
      return false;
    }
    if (confirmOk === false || newPw.value !== confirmPw.value) {
      showAlert('Passwords do not match.', 'error');
      return false;
    }
    if (currentPw.value === newPw.value) {
      showAlert('New password must be different from the current password.', 'error');
      return false;
    }
    return true;
  }

  document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const targetId = btn.getAttribute('data-toggle-password');
      const input = document.getElementById(targetId);
      if (!input) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.textContent = show ? 'Hide' : 'Show';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });

  if (newPw) {
    newPw.addEventListener('input', () => {
      updateStrengthMeter();
      updateConfirmMatch();
    });
  }
  if (confirmPw) {
    confirmPw.addEventListener('input', updateConfirmMatch);
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearAlert();
    if (!validateClientForm()) {
      return;
    }

    const originalLabel = submitBtn ? submitBtn.textContent : 'Update Password';
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Updating…';
    }

    try {
      const fd = new FormData(form);
      fd.append('csrf_token', csrf);
      const res = await fetch(apiUrl, {
        method: 'POST',
        body: fd,
        credentials: 'include',
        cache: 'no-store',
      });
      const data = await res.json();

      if (data.status === 'success' || data.success) {
        form.reset();
        updateStrengthMeter();
        if (confirmError) {
          confirmError.textContent = '';
          confirmError.className = 'ps-field-error';
        }
        document.querySelectorAll('.ps-toggle-pw').forEach((btn) => {
          btn.textContent = 'Show';
        });
        showToast(data.message || 'Password updated successfully.', 'success');
        clearAlert();
      } else {
        const msg = data.message || 'Could not update password.';
        showAlert(msg, 'error');
        showToast(msg, 'error');
      }
    } catch (err) {
      showAlert('Network error. Please try again.', 'error');
      showToast('Network error. Please try again.', 'error');
    } finally {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = originalLabel;
      }
    }
  });
})();
