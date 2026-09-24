// Password visibility is handled by password-visibility.js (eye / eye-off).

// Form validation & submission
const form = document.getElementById('login-form');
const emailInput = document.getElementById('email');
const pwdInput = document.getElementById('password');
const emailError = document.getElementById('email-error');
const passwordError = document.getElementById('password-error');
const rememberMe = document.getElementById('remember-me');
const toggleBtn = document.getElementById('toggle-pwd');
const alert = document.getElementById('alert');
const submitBtn = document.getElementById('submit-btn');
const btnText = document.getElementById('btn-text');
const btnSpinner = document.getElementById('btn-spinner');

function showAlert(message, type = 'error') {
  alert.textContent = message;
  alert.className = `alert ${type}`;
  document.querySelectorAll('.signin-context-alert').forEach((el) => {
    el.hidden = true;
  });
}

function clearAlert() {
  alert.className = 'alert';
  alert.textContent = '';
}

function validateEmail(value) {
  if (window.MCContactValidation) {
    return window.MCContactValidation.validateEmail(value, true);
  }
  if (!value) return 'Email address is required.';
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return 'Please enter a valid email address.';
  return '';
}

function validatePassword(value) {
  if (!value) return 'Password is required.';
  return '';
}

// Inline validation on blur
emailInput.addEventListener('blur', () => {
  const err = validateEmail(emailInput.value.trim());
  emailError.textContent = err;
  emailInput.classList.toggle('invalid', !!err);
});

pwdInput.addEventListener('blur', () => {
  const err = validatePassword(pwdInput.value);
  passwordError.textContent = err;
  pwdInput.classList.toggle('invalid', !!err);
});

function setLoading(loading) {
  if (!loading && lockout && lockout.isLocked()) {
    btnText.hidden = false;
    btnSpinner.hidden = true;
    return;
  }
  submitBtn.disabled = loading;
  btnText.hidden = loading;
  btnSpinner.hidden = !loading;
}

const lockout = window.MedConnectLoginLockout
  ? window.MedConnectLoginLockout.createHandler({
      form,
      emailInput,
      pwdInput,
      submitBtn,
      alertEl: alert,
      extras: [rememberMe, toggleBtn].filter(Boolean),
    })
  : null;

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (lockout && lockout.isLocked()) return;
  clearAlert();

  const emailVal = emailInput.value.trim();
  const pwdVal = pwdInput.value;

  const eErr = validateEmail(emailVal);
  const pErr = validatePassword(pwdVal);

  emailError.textContent = eErr;
  passwordError.textContent = pErr;
  emailInput.classList.toggle('invalid', !!eErr);
  pwdInput.classList.toggle('invalid', !!pErr);

  if (eErr || pErr) return;

  setLoading(true);

  try {
    const fd = new FormData();
    fd.append('email', emailVal);
    fd.append('password', pwdVal);
    fd.append('remember_me', rememberMe && rememberMe.checked ? '1' : '0');

    const apiBase = window.ASSET_BASE || '';
    const res  = await fetch(apiBase + '/app/api/login.php', {
      method: 'POST',
      body: fd,
      headers: { 'X-MC-No-Loader': '1' },
    });

    if (!res.ok) {
      let msg = `Server error (${res.status}).`;
      try { const d = await res.json(); if (d.message) msg = d.message; } catch(_) {}
      showAlert(msg);
      setLoading(false);
      return;
    }

    const data = await res.json();
    if (data.success) {
      setLoading(false);
      try {
        if (window.MedConnectAuthSync && typeof window.MedConnectAuthSync.notifyLogin === 'function') {
          window.MedConnectAuthSync.notifyLogin();
        }
      } catch (_) { /* ignore */ }
      if (window.MedConnectLoginLoading && typeof MedConnectLoginLoading.show === 'function') {
        MedConnectLoginLoading.show(data.redirect);
      } else {
        window.location.replace(data.redirect);
      }
    } else {
      const emailVal = emailInput.value.trim();
      if (lockout && lockout.handleLoginResponse(data, emailVal)) {
        setLoading(false);
        return;
      }
      showAlert(data.message || 'Invalid email or password.');
      setLoading(false);
    }
  } catch (err) {
    console.error('Login fetch failed:', err);
    showAlert(!navigator.onLine ? 'You appear to be offline.' : 'Could not reach the server. Please try again.');
    setLoading(false);
  }
});
