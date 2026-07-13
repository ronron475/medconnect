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
        clearAlert('otp-alert');
        sentNote.textContent = `OTP sent to ${email}. Check your inbox (and spam folder).`;
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
        clearAlert('otp-alert');
        if (sentNote) sentNote.textContent = `OTP sent to ${email}. Check your inbox (and spam folder).`;
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
        stepDot0.removeAttribute('aria-current');
        stepDot0.classList.add('done');
        stepDot1.classList.add('active');
        stepDot1.setAttribute('aria-current', 'step');
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
    if (v.length < 12) return 'Password must be at least 12 characters.';
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
  'chief-complaint':   v => {
    const t = (v || '').trim();
    if (!t) return 'Please describe your current health concern (chief complaint).';
    if (t.length < 10) return 'Please provide a bit more detail (at least 10 characters).';
    if (t.length > 500) return 'Chief complaint must be 500 characters or fewer.';
    return '';
  },
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
  stepDot1.removeAttribute('aria-current');
  stepDot1.classList.add('done');
  stepDot2.classList.add('active');
  stepDot2.setAttribute('aria-current', 'step');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function goToStep1() {
  step2Panel.setAttribute('hidden', '');
  step1Panel.removeAttribute('hidden');
  stepDot2.classList.remove('active');
  stepDot2.removeAttribute('aria-current');
  stepDot1.classList.remove('done');
  stepDot1.classList.add('active');
  stepDot1.setAttribute('aria-current', 'step');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// Back button
document.getElementById('btn-back-step').addEventListener('click', goToStep1);

// Proceed button — enabled when ID extracted and Step 1 is complete (verify runs on click)
btnProceed.addEventListener('click', async () => {
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

  if (!valid) {
    showAlert('step1-alert', 'Please fill in all required fields correctly.');
    return;
  }

  const confirmEl = document.getElementById('ocr-info-confirm');
  if (confirmEl && !confirmEl.checked) {
    showAlert('step1-alert', 'Please confirm that the information matches your government-issued National ID before continuing.');
    confirmEl.focus();
    return;
  }

  if (typeof window.__ocrIsBagoCity === 'function' && !window.__ocrIsBagoCity()) {
    const cityErr = document.getElementById('city-error');
    if (cityErr) cityErr.textContent = 'Only residents of Bago City may register.';
    const citySel = document.getElementById('city');
    if (citySel) citySel.classList.add('invalid');
    if (typeof window.__ocrShowNonBagoReferral === 'function') window.__ocrShowNonBagoReferral();
    showAlert('step1-alert', 'Only Bago City residents can create a patient account on MedConnect. Please use the hospital referral to find care in your area.');
    return;
  }

  if (!window.__ocrIsVerified || !window.__ocrIsVerified()) {
    if (typeof window.__ocrRunVerify !== 'function') {
      showAlert('step1-alert', 'Please upload your National ID first.');
      return;
    }
    btnProceed.disabled = true;
    const verified = await window.__ocrRunVerify();
    if (typeof window.__ocrUpdateProceedState === 'function') window.__ocrUpdateProceedState();
    if (!verified) return;
  }

  clearAlert('step1-alert');
  goToStep2();
});

/* ===== CONSENT + CONDITIONAL MEDICAL FIELDS ===== */
const consentCheckbox = document.getElementById('consent-checkbox');
const submitBtnEl = document.getElementById('reg-submit');
const submitHintEl = document.getElementById('step2-submit-hint');
const allergyYes = document.getElementById('allergy-yes');
const allergyNo = document.getElementById('allergy-no');
const allergyDetails = document.getElementById('allergy-details');
const allergiesInput = document.getElementById('allergies');
const medsYes = document.getElementById('meds-yes');
const medsNo = document.getElementById('meds-no');
const medsDetails = document.getElementById('meds-details');
const medsInput = document.getElementById('current-medications');
const conditionsYes = document.getElementById('conditions-yes');
const conditionsNo = document.getElementById('conditions-no');
const conditionsDetails = document.getElementById('conditions-details');
const conditionsInput = document.getElementById('existing-conditions');
const chiefComplaintInput = document.getElementById('chief-complaint');
const chiefComplaintCount = document.getElementById('chief-complaint-count');
const NO_KNOWN_ALLERGIES = 'No Known Allergies';
const NO_MAINTENANCE_MEDS = 'None';
const NO_MEDICAL_CONDITIONS = 'None';

function syncConditionalDetails(yesEl, noEl, detailsEl, inputEl, noValue) {
  if (!detailsEl || !inputEl) return;
  const show = !!(yesEl && yesEl.checked);
  if (show) {
    detailsEl.hidden = false;
    requestAnimationFrame(() => detailsEl.classList.add('is-open'));
    if (inputEl.value.trim() === noValue) inputEl.value = '';
  } else {
    detailsEl.classList.remove('is-open');
    window.setTimeout(() => {
      if (noEl && noEl.checked) detailsEl.hidden = true;
    }, 260);
    inputEl.value = noValue;
    const errEl = document.getElementById(inputEl.id + '-error');
    if (errEl) errEl.textContent = '';
    inputEl.classList.remove('invalid');
  }
}

function syncAllergyUi() {
  syncConditionalDetails(allergyYes, allergyNo, allergyDetails, allergiesInput, NO_KNOWN_ALLERGIES);
}
function syncMedsUi() {
  syncConditionalDetails(medsYes, medsNo, medsDetails, medsInput, NO_MAINTENANCE_MEDS);
}
function syncConditionsUi() {
  syncConditionalDetails(conditionsYes, conditionsNo, conditionsDetails, conditionsInput, NO_MEDICAL_CONDITIONS);
}

function updateChiefComplaintCount() {
  if (!chiefComplaintInput || !chiefComplaintCount) return;
  const len = (chiefComplaintInput.value || '').length;
  chiefComplaintCount.textContent = `${len}/500`;
  chiefComplaintCount.classList.toggle('is-near-limit', len >= 450);
}

function updateSubmitEnabled() {
  // Prefer NLP module gate (consent + analysis) when present
  if (window.MedConnectRegisterNlp && typeof window.MedConnectRegisterNlp.updateSubmitGate === 'function') {
    window.MedConnectRegisterNlp.updateSubmitGate();
    return;
  }
  if (!submitBtnEl) return;
  const ok = !!(consentCheckbox && consentCheckbox.checked);
  if (submitBtnEl.dataset.loading === '1') {
    submitBtnEl.disabled = true;
    return;
  }
  submitBtnEl.disabled = !ok;
  if (submitHintEl) {
    if (ok) {
      submitHintEl.textContent = 'Consent accepted. You can submit your registration.';
      submitHintEl.classList.add('is-ready');
    } else {
      submitHintEl.textContent = 'Please accept the privacy consent to enable submission.';
      submitHintEl.classList.remove('is-ready');
    }
  }
}

function requireDetailsIfYes(yesEl, inputEl, label) {
  if (yesEl && yesEl.checked && inputEl && !inputEl.value.trim()) {
    const errEl = document.getElementById(inputEl.id + '-error');
    if (errEl) errEl.textContent = `Please specify your ${label}, or select No.`;
    inputEl.classList.add('invalid');
    showAlert('step2-alert', `Please specify your ${label}.`);
    inputEl.focus();
    return false;
  }
  return true;
}

if (allergyYes) allergyYes.addEventListener('change', syncAllergyUi);
if (allergyNo) allergyNo.addEventListener('change', syncAllergyUi);
if (medsYes) medsYes.addEventListener('change', syncMedsUi);
if (medsNo) medsNo.addEventListener('change', syncMedsUi);
if (conditionsYes) conditionsYes.addEventListener('change', syncConditionsUi);
if (conditionsNo) conditionsNo.addEventListener('change', syncConditionsUi);
if (chiefComplaintInput) {
  chiefComplaintInput.addEventListener('input', updateChiefComplaintCount);
  updateChiefComplaintCount();
}
syncAllergyUi();
syncMedsUi();
syncConditionsUi();

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
    updateSubmitEnabled();
  });
  updateSubmitEnabled();
}

/* ===== STEP 2 FORM SUBMIT ===== */
const step2Form = document.getElementById('step2-form');
const submitBtn = document.getElementById('reg-submit');
const btnText   = document.getElementById('reg-btn-text');
const spinner   = document.getElementById('reg-spinner');
const setLoading = on => {
  if (submitBtn) {
    submitBtn.dataset.loading = on ? '1' : '0';
    submitBtn.disabled = on || !(consentCheckbox && consentCheckbox.checked);
  }
  if (btnText) btnText.hidden = on;
  if (spinner) spinner.hidden = !on;
  if (window.MedConnectRegisterNlp && typeof window.MedConnectRegisterNlp.updateSubmitGate === 'function') {
    window.MedConnectRegisterNlp.updateSubmitGate();
  }
};

function signInPath(fallback) {
  return fallback || ((typeof window.APP_BASE !== 'undefined' ? window.APP_BASE : '') + '/index.php');
}

function hideOutcomeModals() {
  ['reg-outcome-success', 'reg-outcome-urgent', 'reg-outcome-emergency'].forEach(function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.hidden = true;
    el.setAttribute('aria-hidden', 'true');
  });
  document.body.classList.remove('reg-outcome-open');
}

function showOutcomeModal(id) {
  hideOutcomeModals();
  const el = document.getElementById(id);
  if (!el) return;
  el.hidden = false;
  el.setAttribute('aria-hidden', 'false');
  document.body.classList.add('reg-outcome-open');
}

function persistPostRegIntent(urgency, nlpResult, redirectUrl) {
  try {
    if (chiefComplaintInput && chiefComplaintInput.value.trim()) {
      sessionStorage.setItem('medconnect_pending_chief_complaint', chiefComplaintInput.value.trim());
    }
    if (nlpResult) {
      sessionStorage.setItem('medconnect_pending_nlp_result', JSON.stringify(nlpResult));
    }
    sessionStorage.setItem('medconnect_post_reg_urgency', urgency || 'NON-URGENT');
    if (urgency === 'URGENT') {
      sessionStorage.setItem('medconnect_prefer_earliest_slot', '1');
    } else {
      sessionStorage.removeItem('medconnect_prefer_earliest_slot');
    }
    if (urgency === 'EMERGENCY') {
      sessionStorage.setItem('medconnect_block_telemedicine', '1');
    } else {
      sessionStorage.removeItem('medconnect_block_telemedicine');
    }
  } catch (_) { /* ignore */ }
}

function wireOutcomeActions(redirectUrl) {
  const goSignIn = () => {
    window.location.href = signInPath(redirectUrl);
  };

  const successGo = document.getElementById('reg-outcome-success-go');
  if (successGo) {
    successGo.onclick = goSignIn;
  }

  const urgentView = document.getElementById('reg-outcome-urgent-view');
  const urgentContinue = document.getElementById('reg-outcome-urgent-continue');
  if (urgentView) {
    urgentView.onclick = () => {
      try {
        sessionStorage.setItem('medconnect_prefer_earliest_slot', '1');
        sessionStorage.setItem('medconnect_post_reg_next', 'triage_earliest');
      } catch (_) { /* ignore */ }
      goSignIn();
    };
  }
  if (urgentContinue) {
    urgentContinue.onclick = goSignIn;
  }

  const emergHospitals = document.getElementById('reg-outcome-emergency-hospitals');
  const emergAck = document.getElementById('reg-outcome-emergency-ack');
  if (emergHospitals) {
    emergHospitals.onclick = () => {
      if (window.BagoReferral && typeof window.BagoReferral.show === 'function') {
        window.BagoReferral.show();
      } else {
        showAlert('step2-alert', 'Opening hospital finder…', 'success');
      }
    };
  }
  if (emergAck) {
    emergAck.onclick = goSignIn;
  }
}

function presentPostRegistrationOutcome(urgency, redirectUrl) {
  const nlp = window.MedConnectRegisterNlp;
  if (nlp && typeof nlp.hideOverlay === 'function') nlp.hideOverlay();
  setLoading(false);
  wireOutcomeActions(redirectUrl);

  const u = String(urgency || 'NON-URGENT').toUpperCase();
  if (u === 'EMERGENCY') {
    showOutcomeModal('reg-outcome-emergency');
    return;
  }
  if (u === 'URGENT') {
    showOutcomeModal('reg-outcome-urgent');
    return;
  }
  showOutcomeModal('reg-outcome-success');
}

step2Form.addEventListener('submit', async e => {
  e.preventDefault();
  clearAlert('step2-alert');

  // Sync conditional medical values before validation/submit
  if (allergyNo && allergyNo.checked && allergiesInput) allergiesInput.value = NO_KNOWN_ALLERGIES;
  if (medsNo && medsNo.checked && medsInput) medsInput.value = NO_MAINTENANCE_MEDS;
  if (conditionsNo && conditionsNo.checked && conditionsInput) conditionsInput.value = NO_MEDICAL_CONDITIONS;

  if (!requireDetailsIfYes(allergyYes, allergiesInput, 'allergies')) return;
  if (!requireDetailsIfYes(medsYes, medsInput, 'maintenance medications')) return;
  if (!requireDetailsIfYes(conditionsYes, conditionsInput, 'medical conditions')) return;

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

  const nlp = window.MedConnectRegisterNlp;
  let urgency = 'NON-URGENT';
  let nlpResult = null;

  setLoading(true);
  if (nlp && typeof nlp.showOverlay === 'function') {
    nlp.showOverlay(true);
  }

  // Silent NLP — patient never sees technical analysis; only loading overlay
  if (nlp && typeof nlp.runAnalysis === 'function') {
    try {
      const analysis = await nlp.runAnalysis({
        manual: true,
        force: true,
        showOverlay: false,
        keepOverlay: true,
      });
      if (analysis && analysis.ok) {
        urgency = analysis.urgency || 'NON-URGENT';
        nlpResult = analysis.result || nlp.getLastResult?.() || null;
      } else if (analysis && analysis.error === 'aborted') {
        if (nlp.hideOverlay) nlp.hideOverlay();
        setLoading(false);
        return;
      } else {
        // Soft-fail: allow registration as non-urgent when NLP is unavailable
        if (typeof nlp.allowContinueWithoutNlp === 'function') {
          nlpResult = nlp.allowContinueWithoutNlp();
        }
        urgency = 'NON-URGENT';
      }
    } catch (_) {
      if (typeof nlp.allowContinueWithoutNlp === 'function') {
        nlpResult = nlp.allowContinueWithoutNlp();
      }
      urgency = 'NON-URGENT';
    }
  }

  persistPostRegIntent(urgency, nlpResult);

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

    // Attach urgency for server logging / future hooks (registration API ignores unknown fields)
    fd.append('triage_urgency', urgency);
    if (nlpResult) {
      try {
        fd.append('nlp_result_json', JSON.stringify(nlpResult));
      } catch (_) { /* ignore */ }
    }

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
      if (nlp && nlp.hideOverlay) nlp.hideOverlay();
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
      if (nlp && nlp.hideOverlay) nlp.hideOverlay();
      showAlert('step2-alert', 'Server returned an unexpected response. Check the console for details.');
      setLoading(false);
      return;
    }

    if (data.success) {
      presentPostRegistrationOutcome(urgency, data.redirect);
    } else {
      // Show the exact backend message (e.g. "Email already exists", "OCR not verified")
      if (nlp && nlp.hideOverlay) nlp.hideOverlay();
      showAlert('step2-alert', data.message || 'Registration failed. Please try again.');
      if (data.error_details) console.error('Backend error:', data.error_details, 'at', data.error_file, 'line', data.error_line);
      setLoading(false);
    }

  } catch (err) {
    // Only reaches here on true network failure (offline, DNS, CORS block)
    console.error('Fetch failed:', err);
    if (nlp && nlp.hideOverlay) nlp.hideOverlay();
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
  const uploadSuccess = document.getElementById('ocr-upload-success');
  const uploadSuccessFile = document.getElementById('ocr-upload-success-file');
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
  const statusPanel  = document.getElementById('ocr-status-panel');
  const statusPanelTitle = document.getElementById('ocr-status-panel-title');
  const statusSpinner = document.getElementById('ocr-status-spinner');
  const reviewNotice = document.getElementById('ocr-review-notice');
  const summaryCard  = document.getElementById('ocr-summary-card');
  const summaryStats = document.getElementById('ocr-summary-stats');
  const summaryToggle = document.getElementById('ocr-summary-toggle');
  const summaryBody  = document.getElementById('ocr-summary-body');
  const errorCard    = document.getElementById('ocr-error-card');
  const errorMessage = document.getElementById('ocr-error-message');
  const btnErrorReupload = document.getElementById('btn-ocr-error-reupload');
  const btnManualEntry = document.getElementById('btn-ocr-manual-entry');
  const confirmCheck = document.getElementById('ocr-info-confirm');
  const resultBox    = document.getElementById('ocr-result-box');
  const resultHeader = document.getElementById('ocr-result-header');
  const checkList    = document.getElementById('ocr-check-list');
  const badge        = document.getElementById('ocr-badge');
  const btnProceed   = document.getElementById('btn-proceed');
  const proceedHint  = document.getElementById('step1-cta-hint');
  const browseBtn    = document.getElementById('btn-ocr-browse');

  if (!fileInput || !scanBtn) return;

  let summaryHideTimer = null;
  const OCR_STATUS_ORDER = ['uploaded', 'reading', 'extracting', 'validating', 'done'];

  const ocrApi = window.NationalIdOcr;
  if (ocrApi) {
    ocrApi.clearAllClientCache();
    ocrApi.lockOcrFields();
    [0, 150, 400, 900].forEach((ms) => {
      setTimeout(() => ocrApi.guardAgainstBrowserAutofill(), ms);
    });
  }
  fetch((typeof window.APP_BASE !== 'undefined' ? window.APP_BASE : '') + '/app/controllers/patient/process_id_ocr.php?clear_session=1', { method: 'POST' }).catch(() => {});

  function clearStep1AddressFields() {
    ['region-text', 'province-text', 'city-text', 'barangay-text'].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    ['region', 'province', 'city', 'barangay'].forEach((id) => {
      const sel = document.getElementById(id);
      if (!sel) return;
      const label = id === 'region' ? 'Choose Region'
        : id === 'province' ? 'Choose Province'
        : id === 'city' ? 'Choose City / Municipality'
        : 'Choose Barangay';
      sel.innerHTML = `<option value="" disabled selected>${label}</option>`;
      sel.value = '';
      sel.disabled = id !== 'region';
    });
  }

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

  function isStep1FormComplete() {
    for (const id of Object.keys(step1Rules)) {
      const el = document.getElementById(id);
      if (!el || step1Rules[id](el.value)) return false;
    }
    return ['region', 'province', 'city', 'barangay'].every((id) => {
      const sel = document.getElementById(id);
      return sel && sel.value;
    });
  }

  function isBagoCitySelected() {
    const cityText = (document.getElementById('city-text') || {}).value || '';
    const citySel = document.getElementById('city');
    let label = cityText;
    if (citySel && citySel.selectedIndex >= 0 && citySel.value) {
      label = citySel.options[citySel.selectedIndex].text || cityText;
    }
    const n = label.toLowerCase().replace(/[^a-z\s]/g, ' ').replace(/\s+/g, ' ').trim();
    return n === 'bago city' || n === 'city of bago' || /\bbago\s+city\b/.test(n);
  }

  let nonBagoReferralShown = false;

  function showNonBagoReferral() {
    disableProceed('Registration is only for Bago City residents.');
    const notice = document.getElementById('non-bago-notice');
    if (notice) notice.hidden = false;
    showAlert(
      'step1-alert',
      'Only Bago City residents can create a patient account on MedConnect. If you live outside Bago City, please use the hospital referral below.'
    );
    const cityErr = document.getElementById('city-error');
    if (cityErr) cityErr.textContent = 'Only residents of Bago City may register.';
    const citySel = document.getElementById('city');
    if (citySel) citySel.classList.add('invalid');
    if (!nonBagoReferralShown && window.BagoReferral) {
      nonBagoReferralShown = true;
      setTimeout(() => window.BagoReferral.show(), 500);
    }
  }

  function clearNonBagoReferral() {
    nonBagoReferralShown = false;
    const notice = document.getElementById('non-bago-notice');
    if (notice) notice.hidden = true;
    const citySel = document.getElementById('city');
    if (citySel) citySel.classList.remove('invalid');
    const cityErr = document.getElementById('city-error');
    if (cityErr) cityErr.textContent = '';
  }

  function isInfoConfirmed() {
    return !!(confirmCheck && confirmCheck.checked);
  }

  function enableProceed(hint) {
    if (!isInfoConfirmed()) {
      btnProceed.disabled = true;
      btnProceed.classList.remove('btn-proceed-ready');
      if (proceedHint) {
        proceedHint.textContent = 'Please confirm that the information matches your National ID before continuing.';
        proceedHint.className = 'step1-cta-hint';
      }
      return;
    }
    btnProceed.disabled = false;
    btnProceed.classList.add('btn-proceed-ready');
    if (proceedHint) {
      proceedHint.textContent = hint || 'All required details complete. Click Create Patient Account to continue.';
      proceedHint.className = 'step1-cta-hint step1-cta-hint--ready';
    }
  }
  function disableProceed(hint) {
    btnProceed.disabled = true;
    btnProceed.classList.remove('btn-proceed-ready');
    if (proceedHint) {
      proceedHint.textContent = hint || 'Upload your National ID to auto-fill the form, complete any missing fields, confirm the details, then create your account.';
      proceedHint.className = 'step1-cta-hint';
    }
  }

  function updateProceedState() {
    const citySel = document.getElementById('city');
    const hasCity = !!(citySel && citySel.value);

    if (hasCity && !isBagoCitySelected()) {
      showNonBagoReferral();
      return;
    }
    clearNonBagoReferral();

    if (ocrVerified) {
      if (!isBagoCitySelected()) {
        showNonBagoReferral();
        return;
      }
      enableProceed('Bago City residency confirmed. You may now proceed.');
      return;
    }
    if (!extractComplete || !selectedFile) {
      disableProceed();
      return;
    }
    if (!isStep1FormComplete()) {
      disableProceed('Complete all required fields, then create your account.');
      return;
    }
    if (!isBagoCitySelected()) {
      showNonBagoReferral();
      return;
    }
    enableProceed();
  }

  function resetOcrStatusPanel() {
    if (!statusPanel) return;
    statusPanel.hidden = true;
    statusPanel.classList.remove('is-complete', 'is-error', 'is-processing');
    if (statusSpinner) statusSpinner.hidden = true;
    if (statusPanelTitle) statusPanelTitle.textContent = 'Processing your National ID';
    statusPanel.querySelectorAll('.ocr-status-step').forEach((li) => {
      li.classList.remove('is-active', 'is-done', 'is-pending');
      li.classList.add('is-pending');
    });
  }

  function showOcrStatusPanel() {
    if (!statusPanel) return;
    statusPanel.hidden = false;
    statusPanel.classList.add('is-processing');
    statusPanel.classList.remove('is-complete', 'is-error');
    if (statusSpinner) statusSpinner.hidden = false;
  }

  function setOcrStatusStep(activeKey) {
    if (!statusPanel) return;
    showOcrStatusPanel();
    const activeIdx = OCR_STATUS_ORDER.indexOf(activeKey);
    statusPanel.querySelectorAll('.ocr-status-step').forEach((li) => {
      const key = li.getAttribute('data-step');
      const idx = OCR_STATUS_ORDER.indexOf(key);
      li.classList.remove('is-active', 'is-done', 'is-pending');
      if (activeIdx < 0) {
        li.classList.add('is-pending');
      } else if (idx < activeIdx) {
        li.classList.add('is-done');
      } else if (idx === activeIdx) {
        li.classList.add(activeKey === 'done' ? 'is-done' : 'is-active');
      } else {
        li.classList.add('is-pending');
      }
    });
    if (activeKey === 'done') {
      statusPanel.classList.remove('is-processing');
      statusPanel.classList.add('is-complete');
      if (statusSpinner) statusSpinner.hidden = true;
      if (statusPanelTitle) statusPanelTitle.textContent = 'OCR completed successfully';
    } else if (statusPanelTitle) {
      const labels = {
        uploaded: 'National ID uploaded',
        reading: 'Reading your National ID',
        extracting: 'Extracting personal information',
        validating: 'Validating residency',
      };
      statusPanelTitle.textContent = labels[activeKey] || 'Processing your National ID';
    }
  }

  function mapProgressMessageToStep(msg) {
    const m = String(msg || '').toLowerCase();
    if (m.includes('validat') || m.includes('residenc') || m.includes('almost')) return 'validating';
    if (m.includes('extract')) return 'extracting';
    return 'reading';
  }

  function hideReviewAndSummary() {
    if (reviewNotice) reviewNotice.hidden = true;
    if (summaryCard) {
      summaryCard.hidden = true;
      summaryCard.classList.remove('is-collapsed');
    }
    if (summaryHideTimer) {
      clearTimeout(summaryHideTimer);
      summaryHideTimer = null;
    }
  }

  function showReviewNotice() {
    if (reviewNotice) reviewNotice.hidden = false;
  }

  function showSummaryCard(filled, reviewCount, missingCount) {
    if (!summaryCard || !summaryStats) return;
    const review = (reviewCount || 0) + (missingCount || 0);
    let html = `<span class="ocr-summary-ok">✓ ${filled} field${filled === 1 ? '' : 's'} successfully extracted</span>`;
    if (review > 0) {
      html += `<span class="ocr-summary-warn">⚠ ${review} field${review === 1 ? '' : 's'} require${review === 1 ? 's' : ''} manual review</span>`;
    }
    summaryStats.innerHTML = html;
    summaryCard.hidden = false;
    summaryCard.classList.remove('is-collapsed');
    if (summaryToggle) summaryToggle.setAttribute('aria-expanded', 'true');
    if (summaryHideTimer) clearTimeout(summaryHideTimer);
    summaryHideTimer = setTimeout(() => {
      summaryCard.classList.add('is-collapsed');
      if (summaryToggle) summaryToggle.setAttribute('aria-expanded', 'false');
    }, 8000);
  }

  function hideErrorCard() {
    if (errorCard) errorCard.hidden = true;
  }

  function showErrorCard(msg) {
    if (!errorCard) return;
    hideReviewAndSummary();
    if (errorMessage) {
      errorMessage.textContent = msg || 'Please upload a clearer image or manually complete the missing fields.';
    }
    errorCard.hidden = false;
    if (statusPanel) {
      statusPanel.classList.add('is-error');
      statusPanel.classList.remove('is-processing', 'is-complete');
      if (statusSpinner) statusSpinner.hidden = true;
      if (statusPanelTitle) statusPanelTitle.textContent = 'OCR needs your attention';
    }
  }

  function resetConfirmCheck() {
    if (confirmCheck) confirmCheck.checked = false;
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
    if (on) {
      showOcrStatusPanel();
      if (progressFill) {
        progressFill.style.width = '0%';
        requestAnimationFrame(() => { progressFill.style.width = '85%'; });
      }
    }
    if (!on && progressFill) progressFill.style.width = '0%';
  }

  function updateProgress(msg) {
    if (progressText) progressText.textContent = msg;
    setOcrStatusStep(mapProgressMessageToStep(msg));
    showStatus(msg, 'scanning');
  }

  async function runExtract(file, force) {
    if (!ocrApi || extractRunning) return;
    extractRunning = true;
    setUploadLocked(true);
    hideErrorCard();
    hideReviewAndSummary();
    showProgress(true);
    resetVerification();
    resetResultPanel();
    resetConfirmCheck();
    if (ocrApi) ocrApi.resetExtractionPreview();
    extractComplete = false;
    setOcrStatusStep('uploaded');
    setTimeout(() => setOcrStatusStep('reading'), 300);

    ocrApi.startProgress(updateProgress);

    try {
      const data = await ocrApi.extractFromImage(file, { force: !!force });

      ocrApi.stopProgress();
      showProgress(false);

      if (!data.success) {
        showStatus(data.message || "We couldn't accurately read your National ID. Please upload a clearer photo taken in good lighting.", 'error');
        showErrorCard(data.message || 'Please upload a clearer image or manually complete the missing fields.');
        if (retryBtn) retryBtn.hidden = false;
        if (ocrApi) ocrApi.unlockOcrFields();
        return;
      }

      if (data.low_confidence || !data.confidence_ok) {
        showStatus(data.message || 'We could not read your National ID with enough confidence. Please upload a clearer photo.', 'error');
        showErrorCard(data.message || 'Please upload a clearer image or manually complete the missing fields.');
        if (retryBtn) retryBtn.hidden = false;
        if (ocrApi) ocrApi.unlockOcrFields();
        return;
      }

      setOcrStatusStep('extracting');
      const { filled, reviewCount, missingCount, isBagoResident } = await ocrApi.applyAutofill(data);
      setOcrStatusStep('validating');
      await new Promise((r) => setTimeout(r, 350));
      setOcrStatusStep('done');

      extractComplete = true;
      if (retryBtn) retryBtn.hidden = false;
      showReviewNotice();
      showSummaryCard(filled, reviewCount || 0, missingCount || 0);
      hideErrorCard();

      const cacheNote = data.cached || data.client_cached ? ' (cached)' : '';
      if (isBagoResident === false) {
        const street = document.getElementById('street-address')?.value || data.extracted?.address?.value || '';
        const streetNorm = street.toLowerCase();
        if (/\b(bago|bgo)\b/.test(streetNorm) && /\bnegros\b/.test(streetNorm) && window.PhAddressAutofill) {
          const retry = await window.PhAddressAutofill.fillFromText(street);
          if (retry.isBagoResident) {
            showStatus(
              `Successfully auto-filled ${retry.filled || filled} field(s). Bago City address detected.${cacheNote}`,
              'success'
            );
            updateProceedState();
            return;
          }
        }
        showStatus('Your National ID shows an address outside Bago City. Please use hospital referral for registration in your area.', 'error');
      } else {
        showStatus(
          `Successfully auto-filled ${filled} field(s). Please review every field, confirm accuracy, then create your account.${cacheNote}`,
          'success'
        );
      }
      updateProceedState();
    } catch (err) {
      ocrApi.stopProgress();
      showProgress(false);
      console.error('OCR extract error:', err);
      showStatus("We couldn't accurately read your National ID. Please upload a clearer photo taken in good lighting.", 'error');
      showErrorCard("We couldn't accurately read your National ID. Please upload a clearer image or manually complete the missing fields.");
      if (retryBtn) retryBtn.hidden = false;
      if (ocrApi) ocrApi.unlockOcrFields();
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
  uploadArea.addEventListener('click', () => {
    if (extractRunning || fileInput.disabled) return;
    if (!selectedFile) fileInput.click();
  });
  uploadArea.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      if (!extractRunning && !fileInput.disabled && !selectedFile) fileInput.click();
    }
  });

  function handleFile(file) {
    resetVerification(); hideStatus(); hideErrorCard(); hideReviewAndSummary(); resetConfirmCheck();
    filenameText.textContent = file.name;
    filenameEl.classList.add('visible');
    if (uploadSuccessFile) uploadSuccessFile.textContent = file.name;
    if (uploadSuccess) uploadSuccess.hidden = false;
    previewWrap.removeAttribute('hidden');
    placeholder.setAttribute('hidden', '');
    uploadArea.classList.add('has-file');
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
    setOcrStatusStep('uploaded');
  }

  clearBtn.addEventListener('click', () => {
    if (extractRunning) return;
    if (selectedFile && ocrApi) ocrApi.clearCache(selectedFile);
    if (ocrApi) ocrApi.clearAllClientCache();
    fileInput.value = ''; selectedFile = null; extractComplete = false;
    previewImg.src = ''; previewImg.setAttribute('hidden', '');
    pdfIndicator.setAttribute('hidden', ''); previewWrap.setAttribute('hidden', '');
    placeholder.removeAttribute('hidden');
    if (uploadSuccess) uploadSuccess.hidden = true;
    if (uploadSuccessFile) uploadSuccessFile.textContent = '';
    uploadArea.classList.remove('has-file');
    filenameEl.classList.remove('visible'); filenameText.textContent = '';
    actionsRow.setAttribute('hidden', '');
    if (retryBtn) retryBtn.hidden = true;
    if (ocrApi) {
      ocrApi.resetExtractionPreview();
      ocrApi.resetOcrFieldLock();
    }
    clearStep1AddressFields();
    showProgress(false);
    resetOcrStatusPanel();
    hideReviewAndSummary();
    hideErrorCard();
    resetConfirmCheck();
    resetVerification(); hideStatus();
    fetch((typeof window.APP_BASE !== 'undefined' ? window.APP_BASE : '') + '/app/controllers/patient/process_id_ocr.php?clear_session=1', { method: 'POST' }).catch(() => {});
  });

  if (retryBtn) {
    retryBtn.addEventListener('click', () => {
      const file = (fileInput.files && fileInput.files[0]) || selectedFile;
      if (!file || extractRunning) return;
      if (ocrApi) ocrApi.clearCache(file);
      runExtract(file, true);
    });
  }

  async function runVerify() {
    const file = (fileInput.files && fileInput.files[0]) || selectedFile;
    if (!file) {
      showStatus('No file selected. Please upload your National ID first.', 'error');
      return false;
    }

    const firstName  = document.getElementById('first-name').value.trim();
    const middleName = document.getElementById('middle-name')?.value?.trim() || '';
    const lastName   = document.getElementById('last-name').value.trim();
    const dob        = document.getElementById('dob').value.trim();
    const nationalId = document.getElementById('national-id').value.trim();
    const barangay   = document.getElementById('barangay-text')?.value || '';

    if (!firstName || !lastName || !dob || !nationalId) {
      showStatus('Please ensure First Name, Last Name, Date of Birth, and National ID Number are filled (upload your ID to auto-fill).', 'error');
      return false;
    }

    setScanLoading(true);
    showStatus('Verifying your National ID and Bago City residency…', 'scanning');
    scanRanOnce = false;
    resetVerification({ keepExtract: true });
    resetResultPanel();

    const fd = new FormData();
    fd.append('national_id_image', file, file.name);
    fd.append('first_name', firstName);
    fd.append('middle_name', middleName);
    fd.append('last_name', lastName);
    fd.append('date_of_birth', dob);
    fd.append('national_id', nationalId);
    fd.append('barangay', barangay);

    try {
      const base = (typeof window.APP_BASE !== 'undefined') ? window.APP_BASE : '';
      const res  = await fetch(base + '/app/controllers/patient/process_id_ocr.php', { method: 'POST', body: fd });

      if (!res.ok) {
        const raw = await res.text().catch(() => '');
        const msg = raw.length < 300 ? raw : `Server error (${res.status})`;
        showStatus('Server error: ' + msg.replace(/<[^>]+>/g, '').trim(), 'error');
        return false;
      }

      let data;
      try {
        data = await res.json();
      } catch (jsonErr) {
        const raw = await res.clone().text().catch(() => '(unreadable)');
        console.error('OCR JSON parse failed. Raw response:', raw);
        showStatus('Server returned invalid data. Open F12 → Console to see the raw response.', 'error');
        return false;
      }

      if (!data.success) {
        showStatus(data.message || 'Verification failed. Please try again.', 'error');
        ocrVerified = false;
        scanRanOnce = true;
        unlockAddress();
        return false;
      }

      ocrVerified      = data.verified === true && data.bago_city === true;
      ocrBagoConfirmed = data.bago_city === true;
      scanRanOnce = true;

      if (data.bago_city === true) { lockAddress(); } else { unlockAddress(); }

      if (ocrVerified) {
        showStatus('National ID verified. Bago City residency confirmed.', 'success');
      } else {
        const errMsg = (data.errors && data.errors.length)
          ? data.errors[0]
          : 'National ID verification failed. Please make sure the entered details exactly match your uploaded National ID.';
        showStatus(errMsg, 'error');
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
      return ocrVerified;
    } catch (err) {
      console.error('OCR verify error:', err);
      showStatus("We couldn't accurately read your National ID. Please upload a clearer photo taken in good lighting.", 'error');
      ocrVerified = false;
      ocrBagoConfirmed = false;
      scanRanOnce = true;
      unlockAddress();
      return false;
    } finally {
      setScanLoading(false);
      updateProceedState();
    }
  }

  scanBtn.addEventListener('click', () => { runVerify(); });

  ['first-name', 'middle-name', 'last-name', 'dob', 'national-id', 'street-address'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input',  () => {
      if (ocrApi && ocrApi.markFieldEdited) ocrApi.markFieldEdited(id);
      invalidateIfVerified(id);
      updateProceedState();
    });
    el.addEventListener('change', () => {
      if (ocrApi && ocrApi.markFieldEdited) ocrApi.markFieldEdited(id);
      invalidateIfVerified(id);
      updateProceedState();
    });
  });

  ['gender', 'civil-status'].forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('input', updateProceedState);
    el.addEventListener('change', updateProceedState);
  });

  ['region', 'province', 'city', 'barangay'].forEach((id) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('change', () => {
      setTimeout(() => { invalidateIfVerified(id); updateProceedState(); }, 120);
    });
  });

  if (confirmCheck) {
    confirmCheck.addEventListener('change', updateProceedState);
  }

  if (summaryToggle && summaryCard) {
    summaryToggle.addEventListener('click', () => {
      const collapsed = summaryCard.classList.toggle('is-collapsed');
      summaryToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });
  }

  if (btnErrorReupload) {
    btnErrorReupload.addEventListener('click', () => {
      hideErrorCard();
      fileInput.click();
    });
  }

  if (btnManualEntry) {
    btnManualEntry.addEventListener('click', () => {
      hideErrorCard();
      if (ocrApi) ocrApi.unlockOcrFields();
      ['first-name', 'middle-name', 'last-name', 'dob', 'national-id', 'street-address'].forEach((id) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.readOnly = false;
        el.removeAttribute('readonly');
        el.classList.remove('ocr-gated');
        el.dataset.ocrUnlocked = '1';
        if (!el.value && el.type !== 'date') {
          el.placeholder = 'Unable to extract. Please enter manually.';
        }
        if (ocrApi && ocrApi.setFieldBadge && !el.value) ocrApi.setFieldBadge(id, 'empty');
      });
      extractComplete = true;
      showReviewNotice();
      showStatus('You can complete the form manually. Please enter all details exactly as shown on your National ID.', 'scanning');
      updateProceedState();
      const first = document.getElementById('first-name');
      if (first) first.focus();
    });
  }

  function invalidateIfVerified() {
    if (window.__ocrAutofillActive) return;
    if (!ocrVerified && !scanRanOnce) {
      updateProceedState();
      return;
    }
    const wasVerified = ocrVerified;
    resetVerification({ keepExtract: true });
    scanRanOnce = false;
    if (wasVerified) showStatus('Details changed — please create your account again to re-verify.', 'error');
    else hideStatus();
    fetch((typeof window.APP_BASE !== 'undefined' ? window.APP_BASE : '') + '/app/controllers/patient/process_id_ocr.php?clear_session=1', { method: 'POST' }).catch(() => {});
    updateProceedState();
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
  function resetVerification(opts) {
    const keepExtract = opts && opts.keepExtract;
    ocrVerified = false;
    ocrBagoConfirmed = false;
    if (!keepExtract) scanRanOnce = false;
    badge.hidden = true;
    badge.className = 'ocr-badge';
    unlockAddress();
    resetResultPanel();
    if (!keepExtract) extractComplete = false;
    updateProceedState();
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

  document.getElementById('btn-open-referral')?.addEventListener('click', () => {
    if (window.BagoReferral) window.BagoReferral.show();
  });

  window.__ocrIsVerified = () => ocrVerified;
  window.__ocrIsBago     = () => ocrBagoConfirmed;
  window.__ocrIsBagoCity = () => isBagoCitySelected();
  window.__ocrShowNonBagoReferral = showNonBagoReferral;
  window.__ocrInvalidate = () => invalidateIfVerified('address');
  window.__ocrRunVerify = runVerify;
  window.__ocrUpdateProceedState = updateProceedState;
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
      len:     v.length >= 12,
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
      len: v.length >= 12,
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
