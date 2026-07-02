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
  const strengthEl = document.getElementById('passwordStrength');
  const strengthBar = document.getElementById('passwordStrengthBar');
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

  function validatePasswordRules(password) {
    if (password.length < 8) return { valid: false, level: 'weak', score: 0 };
    let score = 0;
    if (password.length >= 8) score++;
    if (password.length >= 12) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[a-z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;

    const hasUpper = /[A-Z]/.test(password);
    const hasLower = /[a-z]/.test(password);
    const hasNum = /[0-9]/.test(password);
    const hasSpecial = /[^A-Za-z0-9]/.test(password);
    const valid = password.length >= 8 && hasUpper && hasLower && hasNum && hasSpecial;

    let level = 'weak';
    if (valid && score >= 6) level = 'strong';
    else if (valid && score >= 4) level = 'medium';
    else if (!valid) level = 'weak';

    return { valid, level, score };
  }

  function updateStrengthMeter() {
    const v = newPw ? newPw.value : '';
    if (!strengthEl) return;

    if (!v) {
      strengthEl.textContent = '';
      strengthEl.className = 'ps-strength';
      if (strengthBar) {
        strengthBar.className = 'ps-strength-bar';
        strengthBar.style.width = '0%';
      }
      if (newPasswordError) {
        newPasswordError.textContent = '';
        newPasswordError.className = 'ps-field-error';
      }
      return;
    }

    const result = validatePasswordRules(v);
    const label = result.level.charAt(0).toUpperCase() + result.level.slice(1);
    strengthEl.textContent = 'Strength: ' + label;
    strengthEl.className = 'ps-strength ' + result.level;

    if (strengthBar) {
      strengthBar.className = 'ps-strength-bar ' + result.level;
      const width = result.level === 'strong' ? '100%' : result.level === 'medium' ? '66%' : '33%';
      strengthBar.style.width = width;
    }

    if (newPasswordError) {
      if (!result.valid) {
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
    const rules = validatePasswordRules(newPw.value);
    const confirmOk = updateConfirmMatch();

    if (!currentPw.value || !newPw.value || !confirmPw.value) {
      showAlert('All password fields are required.', 'error');
      return false;
    }
    if (!rules.valid) {
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
