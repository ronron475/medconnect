// Password visibility toggle
const toggleBtn = document.getElementById('toggle-pwd');
const pwdInput = document.getElementById('password');
const eyeIcon = document.getElementById('eye-icon');

const eyeOpen = `<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>`;
const eyeClosed = `<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-7-10-7a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/><line x1="2" y1="2" x2="22" y2="22"/>`;

toggleBtn.addEventListener('click', () => {
  const isPassword = pwdInput.type === 'password';
  pwdInput.type = isPassword ? 'text' : 'password';
  eyeIcon.innerHTML = isPassword ? eyeClosed : eyeOpen;
  toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
});

// Form validation & submission
const form = document.getElementById('login-form');
const emailInput = document.getElementById('email');
const emailError = document.getElementById('email-error');
const passwordError = document.getElementById('password-error');
const rememberMe = document.getElementById('remember-me');
const alert = document.getElementById('alert');
const submitBtn = document.getElementById('submit-btn');
const btnText = document.getElementById('btn-text');
const btnSpinner = document.getElementById('btn-spinner');

function showAlert(message, type = 'error') {
  alert.textContent = message;
  alert.className = `alert ${type}`;
  alert.style.display = 'block';
  document.querySelectorAll('.signin-context-alert').forEach((el) => {
    el.hidden = true;
  });
}

function clearAlert() {
  alert.className = 'alert';
  alert.textContent = '';
  alert.style.display = '';
}

function validateEmail(value) {
  if (!value) return 'Email is required.';
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return 'Enter a valid email address.';
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
