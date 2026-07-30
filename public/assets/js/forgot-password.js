/**
 * Forgot-password OTP flow (landing page modal).
 * Requires window.APP_BASE (set in landing layout).
 * Security: IP throttle + email OTP.
 */
(function () {
  const modal = document.getElementById('forgot-modal');
  const alertEl = document.getElementById('fp-alert');
  if (!modal || !alertEl) return;

  const base = (typeof window.APP_BASE !== 'undefined') ? window.APP_BASE : '';
  const api = (path) => base + '/app/api/' + path;

  let email = '';
  let timer = null;

  function validateNewPassword(pw) {
    if (!pw) return 'Password is required.';
    if (pw.length < 12) return 'Password must be at least 12 characters.';
    if (!/[A-Z]/.test(pw)) return 'Password must contain at least one uppercase letter.';
    if (!/[a-z]/.test(pw)) return 'Password must contain at least one lowercase letter.';
    if (!/[0-9]/.test(pw)) return 'Password must contain at least one number.';
    if (!/[^A-Za-z0-9]/.test(pw)) return 'Password must contain at least one special character (!@#$%^&*).';
    return '';
  }

  async function postForm(path, fd) {
    const res = await fetch(api(path), { method: 'POST', body: fd, credentials: 'same-origin' });
    const text = await res.text();
    try {
      return text ? JSON.parse(text) : { success: false, message: 'Empty server response.' };
    } catch (_) {
      console.error('Password reset API non-JSON:', text.slice(0, 400));
      return { success: false, message: 'Server error. Please try again.' };
    }
  }

  const FP_EYE_OPEN =
    '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>';
  const FP_EYE_CLOSED =
    '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-10-7-10-7a18.45 18.45 0 0 1 5.06-5.94"/>' +
    '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19"/>' +
    '<line x1="2" y1="2" x2="22" y2="22"/>';

  function initFpPasswordToggles() {
    modal.querySelectorAll('.fp-toggle-pwd').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-target');
        const input = id ? document.getElementById(id) : null;
        const svg = btn.querySelector('svg');
        if (!input || !svg) return;
        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        svg.innerHTML = reveal ? FP_EYE_CLOSED : FP_EYE_OPEN;
        btn.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
        btn.setAttribute('aria-pressed', reveal ? 'true' : 'false');
      });
    });
  }

  initFpPasswordToggles();

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

  async function sendOtp(addr) {
    const fd = new FormData();
    fd.append('email', addr);
    return postForm('request_password_reset.php', fd);
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
        email = addr.toLowerCase();
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
      const data = await postForm('verify_reset_otp.php', fd);
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
    const pwErr = validateNewPassword(pw);
    if (pwErr) {
      showAlert(pwErr);
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
      const data = await postForm('reset_password_otp.php', fd);
      if (data.success) goStep(4);
      else showAlert(data.message || 'Could not reset password.');
    } catch {
      showAlert('Could not reset password. Check your connection and try again.');
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
