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

  function openModal() {
    modal.hidden = false;
    modal.classList.add('is-open');
    modal.style.display = 'flex';
  }

  function closeModal() {
    modal.classList.remove('is-open');
    modal.hidden = true;
    modal.style.display = 'none';
  }

  function showAlert(msg, type = 'e') {
    alertEl.textContent = msg;
    alertEl.classList.add('is-visible');
    alertEl.classList.toggle('is-success', type === 's');
    alertEl.classList.toggle('is-error', type !== 's');
  }

  function clearAlert() {
    alertEl.textContent = '';
    alertEl.classList.remove('is-visible', 'is-success', 'is-error');
  }

  function setLoading(btn, textEl, spinEl, on, idleLabel, busyLabel) {
    btn.disabled = on;
    if (textEl) textEl.textContent = on ? busyLabel : idleLabel;
    if (spinEl) spinEl.hidden = !on;
  }

  function goStep(n) {
    ['fp-s1', 'fp-s2', 'fp-s3', 'fp-done'].forEach((id, i) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.hidden = i + 1 !== n && !(n === 4 && i === 3);
    });
    clearAlert();

    [1, 2, 3].forEach((i) => {
      const step = document.getElementById('fp-step-' + i);
      if (!step) return;
      const done = n === 4 || i < n;
      const active = n < 4 && i === n;
      step.classList.toggle('is-done', done);
      step.classList.toggle('is-active', active);
    });

    const line1 = document.getElementById('fln1');
    const line2 = document.getElementById('fln2');
    if (line1) line1.classList.toggle('is-done', n > 1);
    if (line2) line2.classList.toggle('is-done', n > 2);
  }

  function startCountdown(seconds) {
    const btn = document.getElementById('fp-resend');
    const cd = document.getElementById('fp-cd');
    if (!btn || !cd) return;
    btn.disabled = true;
    let remaining = seconds;
    cd.textContent = ` (${remaining}s)`;
    clearInterval(timer);
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

  function waitForGrecaptcha(timeoutMs) {
    const limit = typeof timeoutMs === 'number' ? timeoutMs : 6000;
    return new Promise((resolve) => {
      const started = Date.now();
      const tick = () => {
        if (window.grecaptcha && typeof window.grecaptcha.ready === 'function') {
          window.grecaptcha.ready(() => resolve(true));
          return;
        }
        if (Date.now() - started >= limit) {
          resolve(false);
          return;
        }
        setTimeout(tick, 50);
      };
      tick();
    });
  }

  async function getRecaptchaToken(action) {
    const key = window.RECAPTCHA_SITE_KEY;
    const version = (window.RECAPTCHA_VERSION || 'v3').toLowerCase();
    if (!key) return '';

    if (version === 'v3') {
      const ready = await waitForGrecaptcha();
      if (!ready || !window.grecaptcha || typeof window.grecaptcha.execute !== 'function') {
        return '';
      }
      try {
        return await window.grecaptcha.execute(key, { action: action || 'forgot_password' });
      } catch (_) {
        return '';
      }
    }

    // v2 checkbox token (forgot modal first, then sign-in widget)
    const scoped = document.querySelector('#forgot-modal textarea[name="g-recaptcha-response"]');
    const any = document.querySelector('textarea[name="g-recaptcha-response"]');
    return ((scoped && scoped.value) || (any && any.value) || '').trim();
  }

  async function sendOtp(addr) {
    const fd = new FormData();
    fd.append('email', addr);
    if (window.RECAPTCHA_SITE_KEY) {
      const token = await getRecaptchaToken('forgot_password');
      if (!token) {
        return {
          success: false,
          message: 'Security check could not load. Refresh the page (or disable blockers) and try again.',
        };
      }
      fd.append('recaptcha_token', token);
    }
    return (await fetch(api('request_password_reset.php'), { method: 'POST', body: fd })).json();
  }

  document.getElementById('forgot-link')?.addEventListener('click', (ev) => {
    ev.preventDefault();
    openModal();
    goStep(1);
    document.getElementById('fp-email')?.focus();
  });

  document.getElementById('forgot-close')?.addEventListener('click', closeModal);

  modal.addEventListener('click', (e) => {
    if (e.target === modal) closeModal();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
  });

  document.getElementById('fp-send')?.addEventListener('click', async () => {
    const addr = document.getElementById('fp-email').value.trim();
    if (!addr || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(addr)) {
      showAlert('Please enter a valid email address.');
      return;
    }
    const btn = document.getElementById('fp-send');
    setLoading(btn, document.getElementById('fp-send-t'), document.getElementById('fp-send-s'), true, 'Send OTP', 'Sending…');
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
    setLoading(btn, document.getElementById('fp-send-t'), document.getElementById('fp-send-s'), false, 'Send OTP', 'Sending…');
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
    setLoading(btn, document.getElementById('fp-verify-t'), document.getElementById('fp-verify-s'), true, 'Verify OTP', 'Verifying…');
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
    setLoading(btn, document.getElementById('fp-verify-t'), document.getElementById('fp-verify-s'), false, 'Verify OTP', 'Verifying…');
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
    setLoading(btn, document.getElementById('fp-reset-t'), document.getElementById('fp-reset-s'), true, 'Reset Password', 'Saving…');
    try {
      const fd = new FormData();
      fd.append('email', email);
      fd.append('password', pw);
      fd.append('confirm_password', cpw);
      if (window.RECAPTCHA_SITE_KEY) {
        const token = await getRecaptchaToken('reset_password');
        if (!token) {
          showAlert('Security check could not load. Refresh the page (or disable blockers) and try again.');
          setLoading(btn, document.getElementById('fp-reset-t'), document.getElementById('fp-reset-s'), false, 'Reset Password', 'Saving…');
          return;
        }
        fd.append('recaptcha_token', token);
      }
      const data = await (await fetch(api('reset_password_otp.php'), { method: 'POST', body: fd })).json();
      if (data.success) goStep(4);
      else showAlert(data.message);
    } catch {
      showAlert('Could not reset password.');
    }
    setLoading(btn, document.getElementById('fp-reset-t'), document.getElementById('fp-reset-s'), false, 'Reset Password', 'Saving…');
  });

  document.getElementById('fp-signin')?.addEventListener('click', () => {
    closeModal();
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
