/**
 * Forgot-password OTP flow (landing page modal).
 * Requires window.APP_BASE (set in landing layout).
 */
(function () {
  const modal = document.getElementById('forgot-modal');
  const alertEl = document.getElementById('fp-alert');
  if (!modal || !alertEl) return;

  const base = (typeof window.APP_BASE !== 'undefined') ? window.APP_BASE : '';
  const api = (path) => base + '/app/api/' + path;

  let email = '';
  let timer = null;

  const activeDot = 'background:linear-gradient(135deg,#1a6db5,#3b82f6);color:#fff;border:none;box-shadow:0 4px 14px rgba(26,109,181,.3);';
  const doneDot = 'background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff;border:none;';
  const idleDot = 'background:rgba(255,255,255,0.07);color:rgba(255,255,255,0.35);border:2px solid rgba(255,255,255,0.12);';
  const dotBase = 'width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;';

  function showAlert(msg, type = 'e') {
    alertEl.textContent = msg;
    alertEl.style.display = 'block';
    alertEl.style.background = type === 's' ? '#f0fdf4' : '#fef2f2';
    alertEl.style.color = type === 's' ? '#16a34a' : '#dc2626';
    alertEl.style.border = type === 's' ? '1px solid #86efac' : '1px solid #fca5a5';
  }

  function clearAlert() {
    alertEl.style.display = 'none';
  }

  function setLoading(btn, textEl, spinEl, on) {
    btn.disabled = on;
    textEl.hidden = on;
    spinEl.hidden = !on;
  }

  function goStep(n) {
    ['fp-s1', 'fp-s2', 'fp-s3', 'fp-done'].forEach((id, i) => {
      document.getElementById(id).hidden = i + 1 !== n && !(n === 4 && i === 3);
    });
    clearAlert();
    const dots = ['fd1', 'fd2', 'fd3'].map((id) => document.getElementById(id));
    const labels = ['fl1', 'fl2', 'fl3'].map((id) => document.getElementById(id));
    const lines = ['fln1', 'fln2'].map((id) => document.getElementById(id));
    dots.forEach((d, i) => {
      if (i + 1 < n) {
        d.style.cssText = dotBase + doneDot;
        labels[i].style.color = '#16a34a';
        if (lines[i]) lines[i].style.background = '#86efac';
      } else if (i + 1 === n) {
        d.style.cssText = dotBase + activeDot;
        labels[i].style.color = '#1a6db5';
      } else {
        d.style.cssText = dotBase + idleDot;
        labels[i].style.color = '#94a3b8';
      }
    });
  }

  function startCountdown(seconds) {
    const btn = document.getElementById('fp-resend');
    const cd = document.getElementById('fp-cd');
    btn.disabled = true;
    let remaining = seconds;
    cd.textContent = ` (${remaining}s)`;
    timer = setInterval(() => {
      remaining -= 1;
      if (remaining <= 0) {
        clearInterval(timer);
        btn.disabled = false;
        cd.textContent = '';
      } else {
        cd.textContent = ` (${remaining}s)`;
      }
    }, 1000);
  }

  async function sendOtp(addr) {
    const fd = new FormData();
    fd.append('email', addr);
    const token = await getRecaptchaToken('forgot_password');
    if (token) fd.append('recaptcha_token', token);
    return (await fetch(api('request_password_reset.php'), { method: 'POST', body: fd })).json();
  }

  async function getRecaptchaToken(action) {
    const key = window.RECAPTCHA_SITE_KEY;
    const version = (window.RECAPTCHA_VERSION || 'v3').toLowerCase();
    if (!key) return '';
    if (version === 'v3') {
      if (!window.grecaptcha || !window.grecaptcha.execute) return '';
      try {
        return await window.grecaptcha.execute(key, { action: action || 'forgot_password' });
      } catch (_) {
        return '';
      }
    }
    return (document.querySelector('textarea[name="g-recaptcha-response"]')?.value || '').trim();
  }

  document.getElementById('forgot-link')?.addEventListener('click', (ev) => {
    ev.preventDefault();
    modal.style.display = 'flex';
    goStep(1);
    document.getElementById('fp-email')?.focus();
  });

  document.getElementById('forgot-close')?.addEventListener('click', () => {
    modal.style.display = 'none';
  });

  modal.addEventListener('click', (e) => {
    if (e.target === modal) modal.style.display = 'none';
  });

  document.getElementById('fp-send')?.addEventListener('click', async () => {
    const addr = document.getElementById('fp-email').value.trim();
    if (!addr || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(addr)) {
      showAlert('Please enter a valid email address.');
      return;
    }
    const btn = document.getElementById('fp-send');
    setLoading(btn, document.getElementById('fp-send-t'), document.getElementById('fp-send-s'), true);
    try {
      const data = await sendOtp(addr);
      if (data.success) {
        email = addr;
        document.getElementById('fp-otp-note').textContent = `OTP sent to ${addr}`;
        document.getElementById('fp-otp').value = '';
        goStep(2);
        startCountdown(60);
        document.getElementById('fp-otp').focus();
      } else {
        showAlert(data.message);
      }
    } catch {
      showAlert('Could not send OTP. Please try again.');
    }
    setLoading(btn, document.getElementById('fp-send-t'), document.getElementById('fp-send-s'), false);
  });

  document.getElementById('fp-resend')?.addEventListener('click', async () => {
    clearInterval(timer);
    try {
      const data = await sendOtp(email);
      if (data.success) {
        showAlert('New OTP sent.', 's');
        startCountdown(60);
        document.getElementById('fp-otp').value = '';
      } else {
        showAlert(data.message);
      }
    } catch {
      showAlert('Could not resend OTP.');
    }
  });

  document.getElementById('fp-verify')?.addEventListener('click', async () => {
    const otp = document.getElementById('fp-otp').value.trim();
    if (!otp || !/^\d{6}$/.test(otp)) {
      showAlert('Please enter the 6-digit OTP.');
      return;
    }
    const btn = document.getElementById('fp-verify');
    setLoading(btn, document.getElementById('fp-verify-t'), document.getElementById('fp-verify-s'), true);
    try {
      const fd = new FormData();
      fd.append('email', email);
      fd.append('otp', otp);
      const data = await (await fetch(api('verify_reset_otp.php'), { method: 'POST', body: fd })).json();
      if (data.success) {
        goStep(3);
        document.getElementById('fp-pw').focus();
      } else {
        showAlert(data.message);
      }
    } catch {
      showAlert('Could not verify OTP.');
    }
    setLoading(btn, document.getElementById('fp-verify-t'), document.getElementById('fp-verify-s'), false);
  });

  document.getElementById('fp-reset')?.addEventListener('click', async () => {
    const pw = document.getElementById('fp-pw').value;
    const cpw = document.getElementById('fp-cpw').value;
    if (pw.length < 6) {
      showAlert('Password must be at least 6 characters.');
      return;
    }
    if (pw !== cpw) {
      showAlert('Passwords do not match.');
      return;
    }
    const btn = document.getElementById('fp-reset');
    setLoading(btn, document.getElementById('fp-reset-t'), document.getElementById('fp-reset-s'), true);
    try {
      const fd = new FormData();
      fd.append('email', email);
      fd.append('password', pw);
      fd.append('confirm_password', cpw);
      const token = await getRecaptchaToken('reset_password');
      if (token) fd.append('recaptcha_token', token);
      const data = await (await fetch(api('reset_password_otp.php'), { method: 'POST', body: fd })).json();
      if (data.success) goStep(4);
      else showAlert(data.message);
    } catch {
      showAlert('Could not reset password.');
    }
    setLoading(btn, document.getElementById('fp-reset-t'), document.getElementById('fp-reset-s'), false);
  });

  document.getElementById('fp-signin')?.addEventListener('click', () => {
    modal.style.display = 'none';
    if (typeof window.openSignInModal === 'function') {
      window.openSignInModal();
      return;
    }
    const signin = document.getElementById('signin-modal');
    if (signin) {
      signin.removeAttribute('hidden');
      requestAnimationFrame(() => requestAnimationFrame(() => signin.classList.add('is-open')));
      document.body.classList.add('signin-active');
      const hero = document.getElementById('hero-section');
      if (hero && signin.classList.contains('hero-signin-panel')) {
        hero.classList.add('is-signin-open');
      }
    }
  });

  document.getElementById('fp-email')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') document.getElementById('fp-send').click();
  });
  document.getElementById('fp-otp')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') document.getElementById('fp-verify').click();
  });
})();
