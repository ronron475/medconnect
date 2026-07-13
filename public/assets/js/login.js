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
const captchaWrap = document.getElementById('captcha-wrap');
const captchaInput = document.getElementById('captcha_answer');
const captchaError = document.getElementById('captcha-error');
const rememberMe = document.getElementById('remember-me');
const alert = document.getElementById('alert');
const submitBtn = document.getElementById('submit-btn');
const btnText = document.getElementById('btn-text');
const btnSpinner = document.getElementById('btn-spinner');

function showAlert(message, type = 'error') {
  alert.textContent = message;
  alert.className = `alert ${type}`;
}

function clearAlert() {
  alert.className = 'alert';
  alert.textContent = '';
}

function showCaptcha(question) {
  if (!captchaWrap || !captchaInput) return;
  captchaWrap.hidden = false;
  captchaInput.placeholder = question || 'Answer the challenge';
  captchaError.textContent = '';
}

function hideCaptcha() {
  if (!captchaWrap || !captchaInput) return;
  captchaWrap.hidden = true;
  captchaInput.value = '';
  captchaInput.placeholder = '';
  captchaError.textContent = '';
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

async function getRecaptchaToken(action) {
  const key = window.RECAPTCHA_SITE_KEY;
  const version = (window.RECAPTCHA_VERSION || 'v3').toLowerCase();
  if (!key) return '';

  if (version === 'v3') {
    if (!window.grecaptcha || !window.grecaptcha.execute) return '';
    try {
      return await window.grecaptcha.execute(key, { action: action || 'login' });
    } catch (_) {
      return '';
    }
  }

  // v2 checkbox: token is in g-recaptcha-response (widget renders UI)
  const v2Token = (document.querySelector('textarea[name="g-recaptcha-response"]')?.value || '').trim();
  return v2Token;
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
  submitBtn.disabled = loading;
  btnText.hidden = loading;
  btnSpinner.hidden = !loading;
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  clearAlert();
  if (captchaError) captchaError.textContent = '';

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
    // If CAPTCHA is required, obtain token first (v3 invisible; v2 reads widget token).
    if (window.__MC_CAPTCHA_REQUIRED) {
      const token = await getRecaptchaToken('login');
      if (!token) {
        showAlert('Please verify that you are not a robot.');
        setLoading(false);
        return;
      }
    }

    const fd = new FormData();
    fd.append('email', emailVal);
    fd.append('password', pwdVal);
    fd.append('remember_me', rememberMe && rememberMe.checked ? '1' : '0');
    if (window.__MC_CAPTCHA_REQUIRED) {
      const token = await getRecaptchaToken('login');
      if (token) fd.append('recaptcha_token', token);
    }

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
      if (data && data.captcha_required) {
        window.__MC_CAPTCHA_REQUIRED = true;
        if (window.RECAPTCHA_VERSION && window.RECAPTCHA_VERSION.toLowerCase() === 'v2') {
          const el = document.getElementById('mc-recaptcha-v2');
          if (el) el.hidden = false;
        }
        showAlert(data.message || 'Please complete the verification to continue.');
        setLoading(false);
        return;
      }
      if (data && data.code === 'locked') {
        showAlert(data.message || 'Account temporarily locked.');
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
