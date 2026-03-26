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

function validateEmail(value) {
  if (!value) return 'Email is required.';
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return 'Enter a valid email address.';
  return '';
}

function validatePassword(value) {
  if (!value) return 'Password is required.';
  if (value.length < 6) return 'Password must be at least 6 characters.';
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
  submitBtn.disabled = loading;
  btnText.hidden = loading;
  btnSpinner.hidden = !loading;
}

form.addEventListener('submit', async (e) => {
  e.preventDefault();
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
 /   // Simulate API call — replace with your real auth endpoint
    await fakeAuthRequest(emailVal, pwdVal);
    showAlert('Signed in successfully! Redirecting…', 'success');
    setTimeout(() => { window.location.href = 'index.php'; }, 1000);
  } catch (err) {
    showAlert(err.message || 'Invalid email or password. Please try again.');
    setLoading(false);
  }
});

// Replace this with a real fetch() call to your backend
function fakeAuthRequest(email, password) {
  return new Promise((resolve, reject) => {
    setTimeout(() => {
      // Demo: accept any well-formed credentials
      if (email && password.length >= 6) {
        resolve();
      } else {
        reject(new Error('Invalid email or password.'));
      }
    }, 1200);
  });
}
