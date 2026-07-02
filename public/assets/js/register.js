/* ===== NAVBAR SCROLL ===== */
const navbar = document.getElementById('navbar');
if (navbar) {
  window.addEventListener('scroll', () => {
    navbar.classList.toggle('scrolled', window.scrollY > 20);
  }, { passive: true });
}

/* ===== BUBBLE CANVAS ===== */
(function () {
  const canvas = document.getElementById('bubble-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  let W, H, bubbles = [];
  function resize() { W = canvas.width = window.innerWidth; H = canvas.height = window.innerHeight; }
  window.addEventListener('resize', resize, { passive: true });
  resize();
  const COLORS = ['rgba(13,148,136,','rgba(45,212,191,','rgba(14,165,233,','rgba(34,211,238,'];
  function makeBubble() {
    const r = Math.random() * 60 + 20;
    return { x: Math.random() * W, y: H + r + Math.random() * 200, r,
      color: COLORS[Math.floor(Math.random() * COLORS.length)],
      alpha: Math.random() * 0.07 + 0.02, speed: Math.random() * 0.25 + 0.08,
      drift: (Math.random() - 0.5) * 0.18, wobble: Math.random() * Math.PI * 2,
      wobbleSpeed: Math.random() * 0.008 + 0.003 };
  }
  for (let i = 0; i < 22; i++) { const b = makeBubble(); b.y = Math.random() * H; bubbles.push(b); }
  function draw() {
    ctx.clearRect(0, 0, W, H);
    bubbles.forEach(b => {
      b.y -= b.speed; b.wobble += b.wobbleSpeed; b.x += Math.sin(b.wobble) * b.drift;
      const g = ctx.createRadialGradient(b.x, b.y, 0, b.x, b.y, b.r);
      g.addColorStop(0, b.color + (b.alpha * 1.4) + ')');
      g.addColorStop(0.5, b.color + b.alpha + ')');
      g.addColorStop(1, b.color + '0)');
      ctx.beginPath(); ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2);
      ctx.fillStyle = g; ctx.fill();
      if (b.y + b.r < -20) Object.assign(b, makeBubble());
    });
    requestAnimationFrame(draw);
  }
  draw();
})();

/* ===== OTP EMAIL VERIFICATION ===== */
(function () {
  const emailPanel    = document.getElementById('otp-email-panel');
  const codePanel     = document.getElementById('otp-code-panel');
  const emailInput    = document.getElementById('otp-email');
  const otpInput      = document.getElementById('otp-input');
  const btnSend       = document.getElementById('btn-send-otp');
  const btnVerify     = document.getElementById('btn-verify-otp');
  const btnResend     = document.getElementById('btn-resend-otp');
  const btnChange     = document.getElementById('btn-change-email');
  const sentNote      = document.getElementById('otp-sent-note');
  const countdown     = document.getElementById('resend-countdown');
  const step0Panel    = document.getElementById('step0');
  const step1Panel    = document.getElementById('step1');
  const stepDot0      = document.getElementById('step-dot-0');
  const stepDot1      = document.getElementById('step-dot-1');

  let resendTimer = null;
  let emailCheckTimer = null;
  let lastCheckedEmail = '';
  let lastAvailability = null; // null | { available: boolean, message: string }

  const emailErrEl = document.getElementById('otp-email-error');
  const GMAIL_REGEX = /^[A-Za-z0-9._%+-]+@gmail\.com$/i;
  const UX_GMAIL_ERROR =
    'Please enter a valid Gmail address (example@gmail.com). Only Gmail accounts are accepted for registration.';
  const UX_EXISTS_ERROR =
    'This Gmail address is already registered. Please try another email address.';

  function setLoading(btn, textEl, spinnerEl, on) {
    btn.disabled = on; textEl.hidden = on; spinnerEl.hidden = !on;
  }

  function validateGmailEmail(raw) {
    const email = (raw || '').trim();
    if (!email) return { ok: false, email, message: 'Email is required.' };
    if (/\s/.test(email)) return { ok: false, email, message: UX_GMAIL_ERROR };
    // Basic email sanity, then strict Gmail allowlist
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return { ok: false, email, message: UX_GMAIL_ERROR };
    if (!GMAIL_REGEX.test(email)) return { ok: false, email, message: UX_GMAIL_ERROR };
    return { ok: true, email, message: '✓ Valid Gmail address.' };
  }

  function setEmailUiState(state) {
    if (!emailInput || !btnSend) return;
    const wrap = emailInput.closest('.input-wrap');
    const icon = wrap ? wrap.querySelector('.input-icon') : null;
    if (wrap) {
      wrap.classList.toggle('is-valid', !!state.ok);
      wrap.classList.toggle('is-invalid', !state.ok && !!state.email);
    }
    if (icon) {
      icon.classList.toggle('is-valid', !!state.ok);
      icon.classList.toggle('is-invalid', !state.ok && !!state.email);
    }
    if (emailErrEl) {
      emailErrEl.textContent = state.message || '';
      emailErrEl.classList.toggle('success', !!state.ok);
      emailErrEl.classList.toggle('has-msg', !!(state.message || '').trim());
    }
    emailInput.classList.toggle('invalid', !state.ok && !!state.email);
    emailInput.classList.toggle('valid', !!state.ok);
    btnSend.disabled = !state.ok;
  }

  function refreshEmailValidation() {
    const state = validateGmailEmail(emailInput?.value || '');
    setEmailUiState(state);
    return state;
  }

  function shakeEmailOnce() {
    const wrap = emailInput?.closest('.input-wrap');
    if (!wrap) return;
    wrap.classList.remove('shake');
    // Force reflow so animation re-triggers
    void wrap.offsetWidth;
    wrap.classList.add('shake');
    window.setTimeout(() => wrap.classList.remove('shake'), 420);
  }

  async function checkEmailAvailability(email) {
    const fd = new FormData();
    fd.append('email', email);
    fd.append('csrf_token', (window.CSRF_TOKEN || ''));
    const base = (typeof window.APP_BASE !== 'undefined') ? window.APP_BASE : '';
    const res = await fetch(base + '/app/api/check_email.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch {
      console.error('check_email non-JSON response:', text);
      return { success: false, status: res.status || 0, message: 'Server error while checking email.' };
    }
    return { ...data, status: res.status || 200 };
  }

  function scheduleAvailabilityCheck() {
    if (!emailInput) return;
    const state = validateGmailEmail(emailInput.value);
    lastAvailability = null;

    // If format invalid: stop any pending checks and keep button disabled
    if (!state.ok) {
      if (emailCheckTimer) window.clearTimeout(emailCheckTimer);
      lastCheckedEmail = '';
      btnSend.disabled = true;
      return;
    }

    // While checking: disable button
    btnSend.disabled = true;

    if (emailErrEl) {
      emailErrEl.textContent = 'Checking email availability…';
      emailErrEl.classList.remove('success');
      emailErrEl.classList.add('has-msg');
    }

    if (emailCheckTimer) window.clearTimeout(emailCheckTimer);
    const email = state.email;
    emailCheckTimer = window.setTimeout(async () => {
      lastCheckedEmail = email;
      const res = await checkEmailAvailability(email);

      // Input changed while request was in-flight; ignore stale result
      const now = validateGmailEmail(emailInput.value);
      if (!now.ok || now.email !== email) return;

      if (res.success) {
        lastAvailability = { available: true, message: '✅ Email is available.' };
        setEmailUiState({ ok: true, email, message: lastAvailability.message });
        btnSend.disabled = false;
        return;
      }

      if (res.status === 409) {
        lastAvailability = { available: false, message: UX_EXISTS_ERROR };
        setEmailUiState({ ok: false, email, message: lastAvailability.message });
        btnSend.disabled = true;
        emailInput.focus();
        shakeEmailOnce();
        return;
      }

      // 422 or other: keep disabled, show backend message if present
      const msg = res.message || UX_GMAIL_ERROR;
      setEmailUiState({ ok: false, email, message: msg });
      btnSend.disabled = true;
    }, 420);
  }

  function startResendCountdown(seconds) {
    btnResend.disabled = true;
    let remaining = seconds;
    countdown.textContent = ` (${remaining}s)`;
    resendTimer = setInterval(() => {
      remaining--;
      if (remaining <= 0) {
        clearInterval(resendTimer);
        btnResend.disabled = false;
        countdown.textContent = '';
      } else {
        countdown.textContent = ` (${remaining}s)`;
      }
    }, 1000);
  }

  async function sendOtp(email) {
    const fd = new FormData();
    fd.append('email', email);
    fd.append('csrf_token', (window.CSRF_TOKEN || ''));
    const base = (typeof window.APP_BASE !== 'undefined') ? window.APP_BASE : '';
    const res  = await fetch(base + '/app/api/send_otp.php', { method: 'POST', body: fd });
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch {
      console.error('send_otp non-JSON response:', text);
      return { success: false, message: 'Server error while sending OTP. Please try again.' };
    }
  }

  // Initial state: disable Send OTP until email is valid
  if (btnSend) btnSend.disabled = true;
  if (emailInput) {
    emailInput.addEventListener('input', () => { refreshEmailValidation(); scheduleAvailabilityCheck(); });
    emailInput.addEventListener('blur',  () => { refreshEmailValidation(); scheduleAvailabilityCheck(); });
  }

  btnSend.addEventListener('click', async () => {
    const state = refreshEmailValidation();
    if (!state.ok) return;

    // Ensure availability check is up-to-date before sending OTP
    const email = state.email;
    if (lastCheckedEmail !== email || !lastAvailability) {
      if (emailCheckTimer) window.clearTimeout(emailCheckTimer);
      const res = await checkEmailAvailability(email);
      if (!res.success) {
        const msg = res.message || 'Server error while checking email. Please try again.';
        setEmailUiState({ ok: false, email, message: msg });
        btnSend.disabled = true;
        return;
      }
      lastAvailability = { available: true, message: '✅ Email is available.' };
    }
    if (lastAvailability?.available === false) {
      setEmailUiState({ ok: false, email, message: UX_EXISTS_ERROR });
      btnSend.disabled = true;
      emailInput.focus();
      shakeEmailOnce();
      return;
    }

    setLoading(btnSend, document.getElementById('send-otp-btn-text'), document.getElementById('send-otp-spinner'), true);
    try {
      const data = await sendOtp(email);
      if (data.success) {
        emailPanel.hidden = true;
        codePanel.hidden  = false;
        sentNote.textContent = `OTP sent to ${email}. Check your inbox (and spam folder).`;
        showAlert('otp-alert', data.message, 'success');
        startResendCountdown(60);
        otpInput.focus();
      } else {
        // If backend says already registered, keep user on email step and show inline error
        if ((data.message || '').toLowerCase().includes('already registered')) {
          setEmailUiState({ ok: false, email, message: UX_EXISTS_ERROR });
          btnSend.disabled = true;
          emailInput.focus();
          shakeEmailOnce();
        }
        showAlert('otp-alert', data.message);
      }
    } catch {
      showAlert('otp-alert', 'Could not send OTP. Please try again.');
    }
    setLoading(btnSend, document.getElementById('send-otp-btn-text'), document.getElementById('send-otp-spinner'), false);
  });

  btnResend.addEventListener('click', async () => {
    const state = validateGmailEmail(emailInput.value);
    if (!state.ok) {
      setEmailUiState(state);
      return;
    }
    const email = state.email;
    clearInterval(resendTimer);
    showAlert('otp-alert', '');
    try {
      const data = await sendOtp(email);
      if (data.success) {
        showAlert('otp-alert', 'New OTP sent. Please check your inbox.', 'success');
        startResendCountdown(60);
        otpInput.value = '';
        otpInput.focus();
      } else {
        showAlert('otp-alert', data.message);
      }
    } catch {
      showAlert('otp-alert', 'Could not resend OTP. Please try again.');
    }
  });

  btnChange.addEventListener('click', () => {
    codePanel.hidden  = true;
    emailPanel.hidden = false;
    otpInput.value    = '';
    clearAlert('otp-alert');
    clearInterval(resendTimer);
    countdown.textContent = '';
    refreshEmailValidation();
  });

  btnVerify.addEventListener('click', async () => {
    const otp   = otpInput.value.trim();
    const email = emailInput.value.trim();
    const errEl = document.getElementById('otp-input-error');
    if (!otp || otp.length !== 6 || !/^\d{6}$/.test(otp)) {
      errEl.textContent = 'Please enter the 6-digit OTP.';
      return;
    }
    errEl.textContent = '';
    setLoading(btnVerify, document.getElementById('verify-otp-btn-text'), document.getElementById('verify-otp-spinner'), true);
    try {
      const fd = new FormData();
      fd.append('otp', otp); fd.append('email', email);
      const base = (typeof window.APP_BASE !== 'undefined') ? window.APP_BASE : '';
      const res  = await fetch(base + '/app/api/verify_otp.php', { method: 'POST', body: fd });
      const text = await res.text();
      let data;
      try {
        data = JSON.parse(text);
      } catch {
        console.error('verify_otp non-JSON response:', text);
        showAlert('otp-alert', 'Server error while verifying OTP. Please try again.');
        setLoading(btnVerify, document.getElementById('verify-otp-btn-text'), document.getElementById('verify-otp-spinner'), false);
        return;
      }
      if (data.success) {
        // Store verified email in hidden field for Step 2
        const hEmail = document.getElementById('h-email');
        if (hEmail) hEmail.value = email;
        const emailDisplay = document.getElementById('email-display');
        if (emailDisplay) emailDisplay.value = email;

        // Advance to Step 1
        step0Panel.setAttribute('hidden', '');
        step1Panel.removeAttribute('hidden');
        stepDot0.classList.remove('active');
        stepDot0.classList.add('done');
        stepDot1.classList.add('active');
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } else {
        showAlert('otp-alert', data.message);
        errEl.textContent = data.message;
      }
    } catch {
      showAlert('otp-alert', 'Could not verify OTP. Please try again.');
    }
    setLoading(btnVerify, document.getElementById('verify-otp-btn-text'), document.getElementById('verify-otp-spinner'), false);
  });

  // Allow Enter key on OTP input
  otpInput.addEventListener('keydown', e => { if (e.key === 'Enter') btnVerify.click(); });
  emailInput.addEventListener('keydown', e => { if (e.key === 'Enter') btnSend.click(); });
})();

/* ===== AGE AUTO-COMPUTE ===== */
document.getElementById('dob').addEventListener('change', function () {
  const dob = new Date(this.value);
  const ageField = document.getElementById('age');
  if (isNaN(dob.getTime())) { ageField.value = ''; return; }
  const today = new Date();
  let age = today.getFullYear() - dob.getFullYear();
  const m = today.getMonth() - dob.getMonth();
  if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
  ageField.value = age >= 0 ? age : '';
});

/* ===== STEP 1 VALIDATION RULES ===== */
const step1Rules = {
  'first-name':   v => !v.trim() ? 'First name is required.' : '',
  'last-name':    v => !v.trim() ? 'Last name is required.' : '',
  'dob':          v => {
    if (!v) return 'Date of birth is required.';
    const d = new Date(v); const now = new Date();
    let age = now.getFullYear() - d.getFullYear();
    const mo = now.getMonth() - d.getMonth();
    if (mo < 0 || (mo === 0 && now.getDate() < d.getDate())) age--;
    return d >= now ? 'Date of birth cannot be in the future.' : age > 120 ? 'Please enter a valid date of birth.' : '';
  },
  'gender':       v => !v ? 'Please select a gender.' : '',
  'civil-status': v => !v ? 'Please select a civil status.' : '',
  'national-id': v => !v.trim() ? 'National ID number is required.'
                    : !/^[\d\-]{12,20}$/.test(v.trim().replace(/\s/g,'')) ? 'Enter a valid National ID number.' : '',
};

/* ===== STEP 2 VALIDATION RULES ===== */
const step2Rules = {
  'reg-password': v => {
    if (!v) return 'Password is required.';
    if (v.length < 8) return 'Password must be at least 8 characters.';
    if (!/[A-Z]/.test(v)) return 'Password must contain at least one uppercase letter.';
    if (!/[a-z]/.test(v)) return 'Password must contain at least one lowercase letter.';
    if (!/[0-9]/.test(v)) return 'Password must contain at least one number.';
    if (!/[^A-Za-z0-9]/.test(v)) return 'Password must contain at least one special character (!@#$%^&*).';
    const weak = ['password','12345678','qwerty123','admin123','iloveyou','welcome1','letmein1','abc12345'];
    if (weak.includes(v.toLowerCase())) return 'This password is too common. Please choose a stronger one.';
    // Check against personal info
    const first = (document.getElementById('first-name')?.value || '').toLowerCase();
    const last  = (document.getElementById('last-name')?.value  || '').toLowerCase();
    const email = (document.getElementById('otp-email')?.value  || '').toLowerCase().split('@')[0];
    const vl    = v.toLowerCase();
    if (first.length > 2 && vl.includes(first)) return 'Password must not contain your first name.';
    if (last.length  > 2 && vl.includes(last))  return 'Password must not contain your last name.';
    if (email.length > 2 && vl.includes(email)) return 'Password must not contain your email address.';
    return '';
  },
  'reg-confirm-password': v => !v ? 'Confirm password is required.' : v !== document.getElementById('reg-password').value ? 'Passwords do not match.' : '',
  'employment-status': v => '',
  'income-bracket':    v => '',
  'philhealth-status': v => '',
  'contact-number':    v => !v.trim() ? 'Contact number is required.'
                          : !/^(09|\+639)\d{9}$/.test(v.trim().replace(/\s/g,'')) ? 'Enter a valid PH mobile number (e.g. 09171234567).' : '',
  'philhealth-status': v => !v ? 'Please select a PhilHealth status.' : '',
  'blood-type':        v => !v ? 'Please select a blood type.' : '',
};

/* ===== BLUR VALIDATION ===== */
Object.keys(step1Rules).forEach(id => {
  const el = document.getElementById(id);
  if (!el) return;
  el.addEventListener('blur', () => {
    const errEl = document.getElementById(id + '-error');
    if (!errEl) return;
    const err = step1Rules[id](el.value);
    errEl.textContent = err;
    el.classList.toggle('invalid', !!err);
  });
});
Object.keys(step2Rules).forEach(id => {
  const el = document.getElementById(id);
  if (!el) return;
  el.addEventListener('blur', () => {
    const errEl = document.getElementById(id + '-error');
    if (!errEl) return;
    const err = step2Rules[id](el.value);
    errEl.textContent = err;
    el.classList.toggle('invalid', !!err);
  });
});

/* ===== ADDRESS VALIDATION ===== */
function validateAddress() {
  const fields = [
    { id: 'region',   errId: 'region-error',   msg: 'Please select a region.'            },
    { id: 'province', errId: 'province-error',  msg: 'Please select a province.'          },
    { id: 'city',     errId: 'city-error',      msg: 'Please select a city/municipality.' },
    { id: 'barangay', errId: 'barangay-error',  msg: 'Please select a barangay.'          },
  ];
  let valid = true;
  fields.forEach(f => {
    const sel = document.getElementById(f.id);
    const err = document.getElementById(f.errId);
    const val = sel ? sel.value : '';
    if (!val) {
      if (err) err.textContent = f.msg;
      if (sel) sel.classList.add('invalid');
      valid = false;
    } else {
      if (err) err.textContent = '';
      if (sel) sel.classList.remove('invalid');
    }
  });
  return valid;
}

/* ===== ALERT HELPERS ===== */
function showAlert(boxId, msg, type = 'error') {
  const box = document.getElementById(boxId);
  if (!box) return;
  box.textContent = msg;
  box.className = `alert ${type}`;
  box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function clearAlert(boxId) {
  const box = document.getElementById(boxId);
  if (box) { box.className = 'alert'; box.textContent = ''; }
}

/* ===== STEP NAVIGATION ===== */
const step1Panel  = document.getElementById('step1');
const step2Panel  = document.getElementById('step2');
const stepDot1    = document.getElementById('step-dot-1');
const stepDot2    = document.getElementById('step-dot-2');
const btnProceed  = document.getElementById('btn-proceed');
const proceedHint = document.getElementById('step1-cta-hint');

function goToStep2() {
  // Copy Step 1 values into hidden fields for Step 2 form submission
  document.getElementById('h-first-name').value   = document.getElementById('first-name').value;
  document.getElementById('h-middle-name').value  = document.getElementById('middle-name').value;
  document.getElementById('h-last-name').value    = document.getElementById('last-name').value;
  document.getElementById('h-dob').value         = document.getElementById('dob').value;
  document.getElementById('h-age').value         = document.getElementById('age').value;
  document.getElementById('h-gender').value       = document.getElementById('gender').value;
  document.getElementById('h-civil-status').value = document.getElementById('civil-status').value;
  document.getElementById('h-region').value       = document.getElementById('region-text').value;
  document.getElementById('h-province').value    = document.getElementById('province-text').value;
  document.getElementById('h-city').value         = document.getElementById('city-text').value;
  document.getElementById('h-barangay').value     = document.getElementById('barangay-text').value;
  document.getElementById('h-street-address').value = document.getElementById('street-address')?.value || '';
  document.getElementById('h-national-id').value  = document.getElementById('national-id').value;
  
  // Get the file input element and check if there's a file selected
  const fileInput = document.getElementById('national-id-image');
  if (fileInput.files && fileInput.files[0]) {
    document.getElementById('h-national-id-image').value = fileInput.files[0].name;
  }

  step1Panel.setAttribute('hidden', '');
  step2Panel.removeAttribute('hidden');
  stepDot1.classList.remove('active');
  stepDot1.classList.add('done');
  stepDot2.classList.add('active');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goToStep1() {
  step2Panel.setAttribute('hidden', '');
  step1Panel.removeAttribute('hidden');
  stepDot2.classList.remove('active');
  stepDot1.classList.remove('done');
  stepDot1.classList.add('active');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Back button
document.getElementById('btn-back-step').addEventListener('click', goToStep1);

// Proceed button — only enabled after Bago confirmed
btnProceed.addEventListener('click', () => {
  if (!window.__ocrIsVerified()) return;

  // Validate Step 1 fields before proceeding
  let valid = true;
  Object.keys(step1Rules).forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    const errEl = document.getElementById(id + '-error');
    const err = step1Rules[id](el.value);
    if (errEl) errEl.textContent = err;
    el.classList.toggle('invalid', !!err);
    if (err) valid = false;
  });
  if (!validateAddress()) valid = false;

  const cityText = (document.getElementById('city-text') || {}).value || '';
  if (cityText && !cityText.toLowerCase().includes('bago')) {
    const cityErr = document.getElementById('city-error');
    if (cityErr) cityErr.textContent = 'Only residents of Bago City may register.';
    const citySel = document.getElementById('city');
    if (citySel) citySel.classList.add('invalid');
    valid = false;
  }

  if (!valid) {
    showAlert('step1-alert', 'Please fill in all required fields correctly.');
    return;
  }

  clearAlert('step1-alert');
  goToStep2();
});

/* ===== CONSENT CHECKBOX ===== */
const consentCheckbox = document.getElementById('consent-checkbox');
if (consentCheckbox) {
  consentCheckbox.addEventListener('change', () => {
    const errEl = document.getElementById('consent-error');
    const label = consentCheckbox.closest('.consent-label');
    if (!consentCheckbox.checked) {
      if (errEl) errEl.textContent = 'You must agree to the data privacy consent to proceed.';
      if (label) label.classList.add('invalid');
    } else {
      if (errEl) errEl.textContent = '';
      if (label) label.classList.remove('invalid');
    }
  });
}

/* ===== STEP 2 FORM SUBMIT ===== */
const step2Form = document.getElementById('step2-form');
const submitBtn = document.getElementById('reg-submit');
const btnText   = document.getElementById('reg-btn-text');
const spinner   = document.getElementById('reg-spinner');
const setLoading = on => { submitBtn.disabled = on; btnText.hidden = on; spinner.hidden = !on; };

step2Form.addEventListener('submit', async e => {
  e.preventDefault();
  clearAlert('step2-alert');

  let valid = true;
  Object.keys(step2Rules).forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    const errEl = document.getElementById(id + '-error');
    const err = step2Rules[id](el.value);
    if (errEl) errEl.textContent = err;
    el.classList.toggle('invalid', !!err);
    if (err) valid = false;
  });

  // Consent
  if (!consentCheckbox || !consentCheckbox.checked) {
    const errEl = document.getElementById('consent-error');
    if (errEl) errEl.textContent = 'You must agree to the data privacy consent to proceed.';
    if (consentCheckbox) consentCheckbox.closest('.consent-label').classList.add('invalid');
    valid = false;
  }

  if (!valid) {
    showAlert('step2-alert', 'Please fill in all required fields correctly.');
    return;
  }

  // Final OCR gate — backend also checks, but guard here too
  if (!window.__ocrIsVerified()) {
    showAlert('step2-alert', 'Session expired. Please go back and re-verify your National ID.');
    return;
  }

  setLoading(true);

  // Build path to register API using APP_BASE for subfolder compatibility
  const base = (typeof window.APP_BASE !== 'undefined') ? window.APP_BASE : '';
  const registerUrl = base + '/app/api/register.php';

  try {
    const fd = new FormData(step2Form);
    try {
      const key = window.RECAPTCHA_SITE_KEY;
      const version = (window.RECAPTCHA_VERSION || 'v3').toLowerCase();
      if (key && version === 'v3' && window.grecaptcha?.execute) {
        const token = await window.grecaptcha.execute(key, { action: 'register' });
        if (token) fd.append('recaptcha_token', token);
      } else if (key && version === 'v2') {
        const token = (document.querySelector('textarea[name="g-recaptcha-response"]')?.value || '').trim();
        if (token) fd.append('recaptcha_token', token);
      }
    } catch (_) { /* non-fatal */ }

    const res = await fetch(registerUrl, { method: 'POST', body: fd });

    // Server responded but with an error status (4xx / 5xx)
    if (!res.ok) {
      let errMsg = `Server error (${res.status} ${res.statusText}).`;
      try {
        const errData = await res.json();
        if (errData.message) errMsg = errData.message;
        if (errData.error_details) console.error('Server detail:', errData.error_details, 'at', errData.error_file, 'line', errData.error_line);
      } catch (_) {
        // response wasn't JSON (e.g. PHP fatal error HTML) — log raw text
        const raw = await res.text().catch(() => '');
        console.error('Non-JSON server response:', raw);
      }
      showAlert('step2-alert', errMsg);
      setLoading(false);
      return;
    }

    // Parse JSON — if this throws, the server returned non-JSON despite 200
    let data;
    try {
      data = await res.json();
    } catch (_) {
      const raw = await res.clone().text().catch(() => '(unreadable)');
      console.error('JSON parse failed. Raw response:', raw);
      showAlert('step2-alert', 'Server returned an unexpected response. Check the console for details.');
      setLoading(false);
      return;
    }

    if (data.success) {
      showAlert('step2-alert', 'Account created! Please check your email to verify your account.', 'success');
      setTimeout(() => { window.location.href = data.redirect || '/index.php'; }, 1800);
    } else {
      // Show the exact backend message (e.g. "Email already exists", "OCR not verified")
      showAlert('step2-alert', data.message || 'Registration failed. Please try again.');
      if (data.error_details) console.error('Backend error:', data.error_details, 'at', data.error_file, 'line', data.error_line);
      setLoading(false);
    }

  } catch (err) {
    // Only reaches here on true network failure (offline, DNS, CORS block)
    console.error('Fetch failed:', err);
    if (!navigator.onLine) {
      showAlert('step2-alert', 'You appear to be offline. Please check your internet connection.');
    } else {
      showAlert('step2-alert', 'Could not reach the server. Please try again in a moment.');
    }
    setLoading(false);
  }
});

/* ===== PHILIPPINE ADDRESS SELECTOR ===== */
(function () {
  const base      = (typeof window.APP_BASE !== 'undefined') ? window.APP_BASE : '';
  const JSON_BASE = base + '/philippine-address-selector-main/ph-json/';
  const selRegion   = document.getElementById('region');
  const selProvince = document.getElementById('province');
  const selCity     = document.getElementById('city');
  const selBarangay = document.getElementById('barangay');
  const inpRegion   = document.getElementById('region-text');
  const inpProvince = document.getElementById('province-text');
  const inpCity     = document.getElementById('city-text');
  const inpBarangay = document.getElementById('barangay-text');

  function clearSelect(sel, placeholder, disabled = true) {
    sel.innerHTML = `<option value="" disabled selected>${placeholder}</option>`;
    sel.disabled = disabled;
  }
  function clearError(id) {
    const el = document.getElementById(id + '-error');
    if (el) el.textContent = '';
    const sel = document.getElementById(id);
    if (sel) sel.classList.remove('invalid');
  }

  fetch(JSON_BASE + 'region.json').then(r => r.json()).then(data => {
    data.forEach(r => selRegion.append(new Option(r.region_name, r.region_code)));
    selRegion.disabled = false;
  });

  selRegion.addEventListener('change', function () {
    const regionCode = this.value;
    inpRegion.value = this.options[this.selectedIndex].text;
    inpProvince.value = ''; inpCity.value = ''; inpBarangay.value = '';
    clearSelect(selProvince, 'Loading…', true);
    clearSelect(selCity, 'Choose City / Municipality');
    clearSelect(selBarangay, 'Choose Barangay');
    clearError('region');
    if (window.__ocrAutofillActive) { /* skip */ }
    else if (window.__ocrInvalidate) window.__ocrInvalidate();
    fetch(JSON_BASE + 'province.json')
      .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(data => {
        const filtered = data.filter(p => p.region_code === regionCode)
          .sort((a, b) => a.province_name.localeCompare(b.province_name));
        clearSelect(selProvince, filtered.length ? 'Choose Province' : 'No provinces found');
        filtered.forEach(p => selProvince.append(new Option(p.province_name, p.province_code)));
        selProvince.disabled = filtered.length === 0;
      })
      .catch(() => {
        clearSelect(selProvince, 'Failed to load — retry');
        selProvince.disabled = false;
      });
  });

  selProvince.addEventListener('change', function () {
    const provCode = this.value;
    inpProvince.value = this.options[this.selectedIndex].text;
    inpCity.value = ''; inpBarangay.value = '';
    clearSelect(selCity, 'Loading…', true);
    clearSelect(selBarangay, 'Choose Barangay');
    clearError('province');
    if (window.__ocrAutofillActive) { /* skip */ }
    else if (window.__ocrInvalidate) window.__ocrInvalidate();
    fetch(JSON_BASE + 'city.json')
      .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(data => {
        const filtered = data.filter(c => c.province_code === provCode)
          .sort((a, b) => a.city_name.localeCompare(b.city_name));
        clearSelect(selCity, filtered.length ? 'Choose City / Municipality' : 'No cities found');
        filtered.forEach(c => selCity.append(new Option(c.city_name, c.city_code)));
        selCity.disabled = filtered.length === 0;
      })
      .catch(() => {
        clearSelect(selCity, 'Failed to load — retry');
        selCity.disabled = false;
      });
  });

  selCity.addEventListener('change', function () {
    const cityCode = this.value;
    inpCity.value = this.options[this.selectedIndex].text;
    inpBarangay.value = '';
    clearSelect(selBarangay, 'Loading…', true);
    clearError('city');
    if (window.__ocrAutofillActive) { /* skip */ }
    else if (window.__ocrInvalidate) window.__ocrInvalidate();
    fetch(JSON_BASE + 'barangay.json')
      .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(data => {
        const filtered = data.filter(b => b.city_code === cityCode)
          .sort((a, b) => a.brgy_name.localeCompare(b.brgy_name));
        clearSelect(selBarangay, filtered.length ? 'Choose Barangay' : 'No barangays found');
        filtered.forEach(b => selBarangay.append(new Option(b.brgy_name, b.brgy_code)));
        selBarangay.disabled = filtered.length === 0;
      })
      .catch(() => {
        clearSelect(selBarangay, 'Failed to load — retry');
        selBarangay.disabled = false;
      });
  });

  selBarangay.addEventListener('change', function () {
    inpBarangay.value = this.options[this.selectedIndex].text;
    clearError('barangay');
    if (window.__ocrAutofillActive) { /* skip */ }
    else if (window.__ocrInvalidate) window.__ocrInvalidate();
  });
})();

/* ===== OCR — IDENTITY VERIFICATION & AUTO-FILL ===== */
(function () {
  const fileInput    = document.getElementById('national-id-image');
  const uploadArea   = document.getElementById('ocr-upload-area');
  const placeholder  = document.getElementById('ocr-placeholder');
  const previewWrap  = document.getElementById('ocr-preview-wrap');
  const previewImg   = document.getElementById('ocr-preview');
  const pdfIndicator = document.getElementById('ocr-pdf-indicator');
  const pdfName      = document.getElementById('ocr-pdf-name');
  const filenameEl   = document.getElementById('ocr-filename');
  const filenameText = document.getElementById('ocr-filename-text');
  const actionsRow   = document.getElementById('ocr-actions');
  const scanBtn      = document.getElementById('btn-ocr-scan');
  const retryBtn     = document.getElementById('btn-ocr-retry');
  const clearBtn     = document.getElementById('btn-ocr-clear');
  const ocrBtnText   = document.getElementById('ocr-btn-text');
  const ocrSpinner   = document.getElementById('ocr-spinner');
  const statusEl     = document.getElementById('ocr-status');
  const progressEl   = document.getElementById('ocr-progress');
  const progressText = document.getElementById('ocr-progress-text');
  const progressFill = document.getElementById('ocr-progress-fill');
  const resultBox    = document.getElementById('ocr-result-box');
  const resultHeader = document.getElementById('ocr-result-header');
  const checkList    = document.getElementById('ocr-check-list');
  const badge        = document.getElementById('ocr-badge');
  const btnProceed   = document.getElementById('btn-proceed');
  const proceedHint  = document.getElementById('step1-cta-hint');
  const browseBtn    = document.getElementById('btn-ocr-browse');

  if (!fileInput || !scanBtn) return;

  const ocrApi = window.NationalIdOcr;
  let selectedFile     = null;
  let ocrVerified      = false;
  let ocrBagoConfirmed = false;
  let scanRanOnce      = false;
  let extractComplete  = false;
  let extractRunning   = false;

  const addressGrid    = document.querySelector('.address-grid');
  const lockNotice     = document.getElementById('address-lock-notice');
  const addressSelects = ['region', 'province', 'city', 'barangay'].map(id => document.getElementById(id));

  function lockAddress() {
    if (addressGrid) addressGrid.classList.add('ocr-locked');
    if (lockNotice)  lockNotice.removeAttribute('hidden');
    addressSelects.forEach(sel => { if (sel) sel.disabled = true; });
  }
  function unlockAddress() {
    if (addressGrid) addressGrid.classList.remove('ocr-locked');
    if (lockNotice)  lockNotice.setAttribute('hidden', '');
    const region = document.getElementById('region');
    if (region) region.disabled = false;
    ['province', 'city', 'barangay'].forEach(id => {
      const sel = document.getElementById(id);
      if (sel && sel.options.length > 1) sel.disabled = false;
    });
  }

  function enableProceed() {
    btnProceed.disabled = false;
    btnProceed.classList.add('btn-proceed-ready');
    if (proceedHint) {
      proceedHint.textContent = 'Bago City residency confirmed. You may now proceed.';
      proceedHint.className = 'step1-cta-hint step1-cta-hint--ready';
    }
  }
  function disableProceed() {
    btnProceed.disabled = true;
    btnProceed.classList.remove('btn-proceed-ready');
    if (proceedHint) {
      proceedHint.textContent = 'Upload your National ID, review auto-filled details, then verify residency to enable this button.';
      proceedHint.className = 'step1-cta-hint';
    }
  }

  function setUploadLocked(on) {
    fileInput.disabled = on;
    if (browseBtn) browseBtn.classList.toggle('disabled', on);
    if (uploadArea) uploadArea.classList.toggle('ocr-processing', on);
    if (clearBtn) clearBtn.disabled = on;
    if (retryBtn) retryBtn.disabled = on;
  }

  function showProgress(on) {
    if (!progressEl) return;
    progressEl.hidden = !on;
    if (on && progressFill) {
      progressFill.style.width = '0%';
      requestAnimationFrame(() => { progressFill.style.width = '85%'; });
    }
    if (!on && progressFill) progressFill.style.width = '0%';
  }

  function updateProgress(msg) {
    if (progressText) progressText.textContent = msg;
    showStatus(msg, 'scanning');
  }

  async function runExtract(file, force) {
    if (!ocrApi || extractRunning) return;
    extractRunning = true;
    setUploadLocked(true);
    showProgress(true);
    resetVerification();
    resetResultPanel();
    if (ocrApi) ocrApi.resetExtractionPreview();
    extractComplete = false;

    ocrApi.startProgress(updateProgress);

    try {
      const data = await ocrApi.extractFromImage(file, { force: !!force });

      ocrApi.stopProgress();
      showProgress(false);

      if (!data.success) {
        showStatus(data.message || "We couldn't accurately read your National ID. Please upload a clearer photo taken in good lighting.", 'error');
        if (retryBtn) retryBtn.hidden = false;
        return;
      }

      if (data.low_confidence || !data.confidence_ok) {
        showStatus(data.message || 'We could not read your National ID with enough confidence. Please upload a clearer photo.', 'error');
        if (retryBtn) retryBtn.hidden = false;
        return;
      }

      const { filled } = await ocrApi.applyAutofill(data);
      extractComplete = true;
      if (retryBtn) retryBtn.hidden = false;

      const cacheNote = data.cached || data.client_cached ? ' (cached)' : '';
      showStatus(
        `Successfully extracted and filled ${filled} field(s). Please review your details, then click Verify Residency.${cacheNote}`,
        'success'
      );
    } catch (err) {
      ocrApi.stopProgress();
      showProgress(false);
      console.error('OCR extract error:', err);
      showStatus("We couldn't accurately read your National ID. Please upload a clearer photo taken in good lighting.", 'error');
      if (retryBtn) retryBtn.hidden = false;
    } finally {
      extractRunning = false;
      setUploadLocked(false);
    }
  }

  fileInput.addEventListener('change', function () {
    if (extractRunning) return;
    if (this.files && this.files[0]) {
      selectedFile = this.files[0];
      handleFile(selectedFile);
      runExtract(selectedFile, false);
    }
  });

  uploadArea.addEventListener('dragover', e => { e.preventDefault(); if (!extractRunning) uploadArea.classList.add('drag-over'); });
  uploadArea.addEventListener('dragleave', () => uploadArea.classList.remove('drag-over'));
  uploadArea.addEventListener('drop', e => {
    e.preventDefault(); uploadArea.classList.remove('drag-over');
    if (extractRunning) return;
    const dropped = e.dataTransfer.files[0]; if (!dropped) return;
    try { const dt = new DataTransfer(); dt.items.add(dropped); fileInput.files = dt.files; } catch (_) {}
    selectedFile = dropped; handleFile(dropped);
    runExtract(dropped, false);
  });

  function handleFile(file) {
    resetVerification(); hideStatus();
    filenameText.textContent = file.name;
    filenameEl.classList.add('visible');
    previewWrap.removeAttribute('hidden');
    placeholder.setAttribute('hidden', '');
    if (file.type.startsWith('image/')) {
      pdfIndicator.setAttribute('hidden', '');
      previewImg.src = ''; previewImg.removeAttribute('hidden');
      const reader = new FileReader();
      reader.onload = ev => { previewImg.src = ev.target.result; };
      reader.readAsDataURL(file);
    } else {
      previewImg.setAttribute('hidden', ''); previewImg.src = '';
      pdfName.textContent = file.name; pdfIndicator.removeAttribute('hidden');
    }
    actionsRow.removeAttribute('hidden');
    if (retryBtn) retryBtn.hidden = true;
  }

  clearBtn.addEventListener('click', () => {
    if (extractRunning) return;
    if (selectedFile && ocrApi) ocrApi.clearCache(selectedFile);
    fileInput.value = ''; selectedFile = null; extractComplete = false;
    previewImg.src = ''; previewImg.setAttribute('hidden', '');
    pdfIndicator.setAttribute('hidden', ''); previewWrap.setAttribute('hidden', '');
    placeholder.removeAttribute('hidden');
    filenameEl.classList.remove('visible'); filenameText.textContent = '';
    actionsRow.setAttribute('hidden', '');
    if (retryBtn) retryBtn.hidden = true;
    if (ocrApi) ocrApi.resetExtractionPreview();
    showProgress(false);
    resetVerification(); hideStatus();
  });

  if (retryBtn) {
    retryBtn.addEventListener('click', () => {
      const file = (fileInput.files && fileInput.files[0]) || selectedFile;
      if (!file || extractRunning) return;
      if (ocrApi) ocrApi.clearCache(file);
      runExtract(file, true);
    });
  }

  scanBtn.addEventListener('click', async () => {
    const file = (fileInput.files && fileInput.files[0]) || selectedFile;
    if (!file) { showStatus('No file selected. Please upload your National ID first.', 'error'); return; }

    const firstName  = document.getElementById('first-name').value.trim();
    const middleName = document.getElementById('middle-name')?.value?.trim() || '';
    const lastName   = document.getElementById('last-name').value.trim();
    const dob        = document.getElementById('dob').value.trim();
    const nationalId = document.getElementById('national-id').value.trim();
    const barangay   = document.getElementById('barangay-text')?.value || '';

    if (!firstName || !lastName || !dob || !nationalId) {
      showStatus('Please ensure First Name, Last Name, Date of Birth, and National ID Number are filled (upload your ID to auto-fill).', 'error');
      return;
    }

    setScanLoading(true);
    showStatus('Verifying your National ID and Bago City residency…', 'scanning');
    scanRanOnce = false; resetVerification(); resetResultPanel();

    const fd = new FormData();
    fd.append('national_id_image', file, file.name);
    fd.append('first_name', firstName); fd.append('middle_name', middleName);
    fd.append('last_name', lastName);   fd.append('date_of_birth', dob);
    fd.append('national_id', nationalId); fd.append('barangay', barangay);

    try {
      const base = (typeof window.APP_BASE !== 'undefined') ? window.APP_BASE : '';
      const res  = await fetch(base + '/app/controllers/patient/process_id_ocr.php', { method: 'POST', body: fd });

      if (!res.ok) {
        const raw = await res.text().catch(() => '');
        const msg = raw.length < 300 ? raw : `Server error (${res.status})`;
        showStatus('Server error: ' + msg.replace(/<[^>]+>/g, '').trim(), 'error');
        setScanLoading(false); return;
      }

      let data;
      try {
        data = await res.json();
      } catch (jsonErr) {
        const raw = await res.clone().text().catch(() => '(unreadable)');
        console.error('OCR JSON parse failed. Raw response:', raw);
        showStatus('Server returned invalid data. Open F12 → Console to see the raw response.', 'error');
        setScanLoading(false); return;
      }

      if (!data.success) {
        showStatus(data.message || 'Verification failed. Please try again.', 'error');
        ocrVerified = false; scanRanOnce = true; unlockAddress(); disableProceed(); return;
      }

      ocrVerified      = data.verified === true && data.bago_city === true;
      ocrBagoConfirmed = data.bago_city === true;
      scanRanOnce = true;

      if (data.bago_city === true) { lockAddress(); } else { unlockAddress(); }

      if (ocrVerified) {
        showStatus('National ID verified. Bago City residency confirmed. You may now proceed.', 'success');
        enableProceed();
      } else {
        const errMsg = (data.errors && data.errors.length)
          ? data.errors[0]
          : 'National ID verification failed. Please make sure the entered details exactly match your uploaded National ID.';
        showStatus(errMsg, 'error');
        disableProceed();
        unlockAddress();

        const residencyFailed = (data.bago_city === false)
          || (data.bago_state && data.bago_state !== 'direct')
          || (data.errors && data.errors.some(function(e) {
               return e.toLowerCase().includes('bago') || e.toLowerCase().includes('residency');
             }));

        if (residencyFailed && window.BagoReferral) {
          setTimeout(function () { window.BagoReferral.show(); }, 700);
        }
      }

      renderResult(data);
    } catch (err) {
      console.error('OCR verify error:', err);
      showStatus("We couldn't accurately read your National ID. Please upload a clearer photo taken in good lighting.", 'error');
      ocrVerified = false; ocrBagoConfirmed = false; scanRanOnce = true;
      unlockAddress(); disableProceed();
    } finally {
      setScanLoading(false);
    }
  });

  ['first-name', 'middle-name', 'last-name', 'dob', 'national-id'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input',  () => invalidateIfVerified(id));
    el.addEventListener('change', () => invalidateIfVerified(id));
  });

  function invalidateIfVerified() {
    if (window.__ocrAutofillActive) return;
    if (!ocrVerified && !scanRanOnce) return;
    resetVerification(); scanRanOnce = false;
    if (ocrVerified) showStatus('Details changed — please verify your ID again.', 'error');
    else hideStatus();
    fetch((typeof window.APP_BASE !== 'undefined' ? window.APP_BASE : '') + '/app/controllers/patient/process_id_ocr.php?clear_session=1', { method: 'POST' }).catch(() => {});
  }

  function renderResult(data) {
    showResultPanel();
    const finalState = data.final_state || (data.verified ? 'verified' : 'failed');
    if (finalState === 'verified') {
      resultHeader.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Identity Verified — all details match your National ID`;
      resultHeader.className = 'ocr-result-header ocr-result-header--pass';
      setBadge('verified');
    } else {
      resultHeader.innerHTML = `<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg> Verification Failed — please review the issues below`;
      resultHeader.className = 'ocr-result-header ocr-result-header--fail';
      setBadge('failed');
    }
    checkList.innerHTML = '';
    const idState   = data.id_state   || (data.errors?.some(e => e.includes('National ID')) ? 'fail' : 'exact');
    const bagoState = data.bago_state || (data.bago_city ? 'direct' : 'fail');
    const checks = [
      { label: 'First name matches',    state: data.errors?.some(e => e.includes('First name'))    ? 'fail' : 'pass' },
      { label: 'Last name matches',     state: data.errors?.some(e => e.includes('Last name'))     ? 'fail' : 'pass' },
      { label: 'Date of birth matches', state: data.errors?.some(e => e.includes('Date of birth')) ? 'fail' : 'pass' },
      {
        label: idState === 'exact' ? 'National ID number matches'
             : 'National ID number does not match — please check the number you entered',
        state: idState === 'exact' ? 'pass' : 'fail'
      },
      {
        label: bagoState === 'fail' ? 'Bago City residency not confirmed'
             : 'Bago City residency confirmed',
        state: bagoState === 'fail' ? 'fail' : 'pass'
      },
    ];
    const svgPass = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`;
    const svgFail = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" x2="6" y1="6" y2="18"/><line x1="6" x2="18" y1="6" y2="18"/></svg>`;
    const svgWarn = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>`;
    checks.forEach(c => {
      const li = document.createElement('li');
      li.className = `ocr-check-item ${c.state}`;
      li.innerHTML = (c.state === 'pass' ? svgPass : c.state === 'warn' ? svgWarn : svgFail) + c.label;
      checkList.appendChild(li);
    });
    const mnStatus = data.middle_name_status || 'skipped';
    const mnLi = document.createElement('li');
    if (mnStatus === 'pass') {
      mnLi.className = 'ocr-check-item pass';
      mnLi.innerHTML = svgPass + 'Middle name matches';
    } else if (mnStatus === 'mismatch') {
      mnLi.className = 'ocr-check-item fail';
      mnLi.innerHTML = svgFail + 'Middle name mismatch';
    } else if (mnStatus === 'not_detected') {
      mnLi.className = 'ocr-check-item warn';
      mnLi.innerHTML = svgWarn + 'Middle name could not be confidently detected — please retry';
    } else {
      mnLi.className = 'ocr-check-item skip';
      mnLi.innerHTML = `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" x2="19" y1="12" y2="12"/></svg>Middle name not provided (optional)`;
    }
    const items = checkList.querySelectorAll('li');
    if (items.length > 1) checkList.insertBefore(mnLi, items[1].nextSibling);
    else checkList.appendChild(mnLi);

    // ── Hospital Referral button — shown whenever residency check failed ──
    // Covers: bago_city===false, bago_state==='fallback', or any residency error.
    const residencyCheckFailed = (data.bago_city === false)
      || (data.bago_state && data.bago_state !== 'direct')
      || (data.errors && data.errors.some(function(e) {
           return e.toLowerCase().includes('bago') || e.toLowerCase().includes('residency');
         }));

    // Remove any previously injected referral button before re-rendering
    const existing = resultBox.querySelector('.referral-trigger-wrap');
    if (existing) existing.remove();

    if (residencyCheckFailed && window.BagoReferral) {
      const referralWrap = document.createElement('div');
      referralWrap.className = 'referral-trigger-wrap';
      referralWrap.style.cssText = 'margin-top:14px;padding:14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;';
      referralWrap.innerHTML = `
        <div style="font-size:12.5px;color:#1e40af;font-weight:700;margin-bottom:4px;">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-1px;margin-right:4px;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
          Not a Bago City resident?
        </div>
        <div style="font-size:12px;color:#374151;margin-bottom:10px;line-height:1.5;">
          Find the nearest hospital in your area that can assist you with registration and healthcare services.
        </div>
        <button type="button" onclick="window.BagoReferral.show()"
          style="width:100%;padding:10px 16px;background:#2563eb;color:#fff;border:none;
                 border-radius:8px;font-size:13px;font-weight:700;cursor:pointer;
                 display:flex;align-items:center;justify-content:center;gap:8px;
                 transition:background .15s;min-height:42px;"
          onmouseover="this.style.background='#1d4ed8'"
          onmouseout="this.style.background='#2563eb'">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
          Find Nearest Hospital
        </button>
      `;
      resultBox.appendChild(referralWrap);
    }
  } // end renderResult

  function setBadge(state) {
    badge.hidden = false;
    if (state === 'verified') { badge.textContent = '✓ Verified'; badge.className = 'ocr-badge ocr-badge--pass'; }
    else { badge.textContent = '✗ Not Verified'; badge.className = 'ocr-badge ocr-badge--fail'; }
  }
  function resetVerification() {
    ocrVerified = false; ocrBagoConfirmed = false; scanRanOnce = false;
    badge.hidden = true; badge.className = 'ocr-badge';
    unlockAddress(); resetResultPanel(); disableProceed();
  }
  function resetResultPanel() {
    resultBox.hidden = true; resultBox.style.display = 'none';
    resultBox.querySelectorAll('.ocr-review-note').forEach(el => el.remove());
    checkList.innerHTML = ''; resultHeader.innerHTML = '';
    resultHeader.className = 'ocr-result-header';
  }
  function showResultPanel() { resultBox.hidden = false; resultBox.style.display = ''; }
  function setScanLoading(on) {
    scanBtn.disabled = on;
    ocrBtnText.hidden = on;
    ocrSpinner.hidden = !on;
    if (on) setUploadLocked(true);
    else if (!extractRunning) setUploadLocked(false);
  }
  function showStatus(msg, type) { statusEl.textContent = msg; statusEl.className = `ocr-status ${type}`; statusEl.hidden = false; }
  function hideStatus() { statusEl.hidden = true; statusEl.textContent = ''; }

  window.__ocrIsVerified = () => ocrVerified;
  window.__ocrIsBago     = () => ocrBagoConfirmed;
  window.__ocrInvalidate = () => invalidateIfVerified('address');
})();

/* ===== REGISTER PAGE PASSWORD TOGGLES + STRENGTH ===== */
(function () {
  [
    { btn: 'toggle-reg-pwd',         input: 'reg-password',         icon: 'reg-eye-icon' },
    { btn: 'toggle-reg-confirm-pwd', input: 'reg-confirm-password', icon: 'reg-confirm-eye-icon' },
  ].forEach(({ btn, input, icon }) => {
    const b = document.getElementById(btn);
    const i = document.getElementById(input);
    const ic = document.getElementById(icon);
    if (!b || !i) return;
    b.addEventListener('click', () => {
      const show = i.type === 'password';
      i.type = show ? 'text' : 'password';
      ic.innerHTML = show
        ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>'
        : '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>';
    });
  });

  // ── Live strength indicator ──────────────────────────────
  const pwdInput     = document.getElementById('reg-password');
  const confInput    = document.getElementById('reg-confirm-password');
  const strengthWrap = document.getElementById('pwd-strength-wrap');
  const strengthFill = document.getElementById('pwd-strength-fill');
  const strengthLbl  = document.getElementById('pwd-strength-label');
  const checklist    = document.getElementById('pwd-checklist');
  const matchHint    = document.getElementById('pwd-match-hint');

  const checks = {
    len:     document.getElementById('pc-len'),
    upper:   document.getElementById('pc-upper'),
    lower:   document.getElementById('pc-lower'),
    num:     document.getElementById('pc-num'),
    special: document.getElementById('pc-special'),
  };

  function updateStrength() {
    const v = pwdInput?.value || '';
    if (!v) {
      if (strengthWrap) strengthWrap.hidden = true;
      if (checklist)    checklist.hidden    = true;
      return;
    }
    if (strengthWrap) strengthWrap.hidden = false;
    if (checklist)    checklist.hidden    = false;

    const rules = {
      len:     v.length >= 8,
      upper:   /[A-Z]/.test(v),
      lower:   /[a-z]/.test(v),
      num:     /[0-9]/.test(v),
      special: /[^A-Za-z0-9]/.test(v),
    };

    Object.entries(rules).forEach(([k, pass]) => {
      if (checks[k]) checks[k].classList.toggle('pass', pass);
    });

    const score = Object.values(rules).filter(Boolean).length;
    let level = 'weak';
    if (score >= 5 && v.length >= 12) level = 'strong';
    else if (score >= 4) level = 'medium';

    strengthFill.className = 'pwd-strength-fill ' + level;
    strengthLbl.className  = 'pwd-strength-label ' + level;
    strengthLbl.textContent = level.charAt(0).toUpperCase() + level.slice(1);
  }

  function updateMatch() {
    if (!matchHint || !confInput) return;
    const conf = confInput.value;
    if (!conf) { matchHint.hidden = true; return; }
    matchHint.hidden = false;
    if (conf === pwdInput?.value) {
      matchHint.textContent = '✓ Passwords match';
      matchHint.className   = 'pwd-match-hint match';
    } else {
      matchHint.textContent = '✗ Passwords do not match';
      matchHint.className   = 'pwd-match-hint mismatch';
    }
  }

  if (pwdInput)  pwdInput.addEventListener('input',  () => { updateStrength(); updateMatch(); });
  if (confInput) confInput.addEventListener('input',  updateMatch);
})();

/* ===== REGISTRATION REQUIREMENTS MODAL ===== */
(function () {
  const modal = document.getElementById('regRequirementsModal');
  if (!modal) return;

  const understandBtn = document.getElementById('reg-req-understand');
  const reopenBtn = document.getElementById('btn-reg-requirements');
  const demoPwd = document.getElementById('reg-req-demo-password');
  const strengthWrap = document.getElementById('reg-req-pwd-strength-wrap');
  const strengthFill = document.getElementById('reg-req-pwd-strength-fill');
  const strengthLbl = document.getElementById('reg-req-pwd-strength-label');
  const checklist = document.getElementById('reg-req-pwd-checklist');
  const STORAGE_KEY = 'medconnect_reg_req_understood';
  const CLOSE_MS = 320;
  let closeTimer = null;

  const checks = {
    len: document.getElementById('reg-req-pc-len'),
    upper: document.getElementById('reg-req-pc-upper'),
    lower: document.getElementById('reg-req-pc-lower'),
    num: document.getElementById('reg-req-pc-num'),
    special: document.getElementById('reg-req-pc-special'),
  };

  function updateDemoStrength() {
    const v = demoPwd?.value || '';
    if (!v) {
      if (strengthWrap) strengthWrap.hidden = true;
      if (checklist) checklist.hidden = true;
      return;
    }
    if (strengthWrap) strengthWrap.hidden = false;
    if (checklist) checklist.hidden = false;

    const rules = {
      len: v.length >= 8,
      upper: /[A-Z]/.test(v),
      lower: /[a-z]/.test(v),
      num: /[0-9]/.test(v),
      special: /[^A-Za-z0-9]/.test(v),
    };

    Object.entries(rules).forEach(([k, pass]) => {
      if (checks[k]) checks[k].classList.toggle('pass', pass);
    });

    const score = Object.values(rules).filter(Boolean).length;
    let level = 'weak';
    if (score >= 5 && v.length >= 12) level = 'strong';
    else if (score >= 4) level = 'medium';

    if (strengthFill) strengthFill.className = 'pwd-strength-fill ' + level;
    if (strengthLbl) {
      strengthLbl.className = 'pwd-strength-label ' + level;
      strengthLbl.textContent = level.charAt(0).toUpperCase() + level.slice(1);
    }
  }

  function openModal() {
    window.clearTimeout(closeTimer);
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    modal.classList.remove('is-closing');
    document.body.classList.add('reg-req-modal-open');
    requestAnimationFrame(() => modal.classList.add('is-open'));
    if (understandBtn) understandBtn.focus({ preventScroll: true });
  }

  function closeModal(persist) {
    if (!modal.classList.contains('is-open') && modal.hidden) return;

    if (persist) {
      try { sessionStorage.setItem(STORAGE_KEY, '1'); } catch (e) { /* ignore */ }
    }

    modal.classList.add('is-closing');
    modal.classList.remove('is-open');
    document.body.classList.remove('reg-req-modal-open');

    closeTimer = window.setTimeout(() => {
      modal.classList.remove('is-closing');
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      if (demoPwd) demoPwd.value = '';
      updateDemoStrength();
    }, CLOSE_MS);
  }

  modal.querySelectorAll('[data-reg-req-close]').forEach((el) => {
    el.addEventListener('click', () => closeModal(false));
  });

  if (understandBtn) {
    understandBtn.addEventListener('click', () => closeModal(true));
  }

  if (reopenBtn) {
    reopenBtn.addEventListener('click', openModal);
  }

  if (demoPwd) {
    demoPwd.addEventListener('input', updateDemoStrength);
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) {
      e.preventDefault();
      closeModal(false);
    }
  });

  let shouldAutoOpen = true;
  try {
    shouldAutoOpen = !sessionStorage.getItem(STORAGE_KEY);
  } catch (e) { /* ignore */ }

  if (shouldAutoOpen) {
    window.setTimeout(openModal, 400);
  }
})();

/* ===== REGISTER PAGE — ANIMATED BACKGROUND ===== */
(function () {
  const canvas = document.getElementById('bubble-canvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  let W, H;

  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
  }
  window.addEventListener('resize', resize, { passive: true });
  resize();

  // Soft floating blobs
  const blobs = Array.from({ length: 7 }, (_, i) => ({
    x:     Math.random() * W,
    y:     Math.random() * H,
    r:     180 + Math.random() * 220,
    vx:    (Math.random() - 0.5) * 0.35,
    vy:    (Math.random() - 0.5) * 0.28,
    hue:   195 + Math.random() * 30,   // cyan-blue range
    alpha: 0.18 + Math.random() * 0.14,
  }));

  function drawBackground() {
    // Base gradient — sky blue top to near-white bottom
    const bg = ctx.createLinearGradient(0, 0, 0, H);
    bg.addColorStop(0,   '#5bc8f5');
    bg.addColorStop(0.4, '#90d9f8');
    bg.addColorStop(0.75,'#d6f0fc');
    bg.addColorStop(1,   '#f0f8ff');
    ctx.fillStyle = bg;
    ctx.fillRect(0, 0, W, H);
  }

  function drawBlobs() {
    blobs.forEach(b => {
      // Move
      b.x += b.vx;
      b.y += b.vy;
      if (b.x < -b.r)  b.x = W + b.r;
      if (b.x > W + b.r) b.x = -b.r;
      if (b.y < -b.r)  b.y = H + b.r;
      if (b.y > H + b.r) b.y = -b.r;

      // Draw soft radial blob
      const g = ctx.createRadialGradient(b.x, b.y, 0, b.x, b.y, b.r);
      g.addColorStop(0,   `hsla(${b.hue}, 85%, 92%, ${b.alpha})`);
      g.addColorStop(0.5, `hsla(${b.hue}, 80%, 88%, ${b.alpha * 0.6})`);
      g.addColorStop(1,   `hsla(${b.hue}, 75%, 85%, 0)`);
      ctx.beginPath();
      ctx.arc(b.x, b.y, b.r, 0, Math.PI * 2);
      ctx.fillStyle = g;
      ctx.fill();
    });
  }

  // Subtle wave lines at bottom
  let waveOffset = 0;
  function drawWaves() {
    waveOffset += 0.008;
    const waves = [
      { y: H * 0.82, amp: 18, freq: 0.008, color: 'rgba(255,255,255,0.18)', width: 2.5 },
      { y: H * 0.88, amp: 14, freq: 0.010, color: 'rgba(255,255,255,0.24)', width: 2 },
      { y: H * 0.94, amp: 10, freq: 0.013, color: 'rgba(255,255,255,0.30)', width: 1.5 },
    ];
    waves.forEach((w, wi) => {
      ctx.beginPath();
      ctx.moveTo(0, w.y);
      for (let x = 0; x <= W; x += 4) {
        const y = w.y + Math.sin(x * w.freq + waveOffset + wi * 1.2) * w.amp;
        ctx.lineTo(x, y);
      }
      ctx.strokeStyle = w.color;
      ctx.lineWidth   = w.width;
      ctx.stroke();
    });
  }

  function draw() {
    ctx.clearRect(0, 0, W, H);
    drawBackground();
    drawBlobs();
    drawWaves();
    requestAnimationFrame(draw);
  }
  draw();
})();
