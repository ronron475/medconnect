/**
 * Shared login lockout UI — countdown, form disable, sessionStorage resume.
 * Server enforces lockout in app/api/login.php; this module mirrors state for UX.
 */
(function (global) {
  'use strict';

  const STORAGE_KEY = 'medconnect_login_lockout';

  function lockoutMessage(seconds) {
    return `Too many failed attempts. Please try again in ${seconds} seconds.`;
  }

  function readStored() {
    try {
      const raw = sessionStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (_) {
      return null;
    }
  }

  function writeStored(email, untilMs) {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
        email: String(email || '').toLowerCase(),
        until: untilMs,
      }));
    } catch (_) { /* ignore */ }
  }

  function clearStored() {
    try {
      sessionStorage.removeItem(STORAGE_KEY);
    } catch (_) { /* ignore */ }
  }

  function createHandler(elements) {
    const {
      form,
      emailInput,
      pwdInput,
      submitBtn,
      alertEl,
      extras = [],
    } = elements;

    let timer = null;
    let lockedUntilMs = 0;

    function setDisabled(disabled) {
      if (emailInput) emailInput.disabled = disabled;
      if (pwdInput) pwdInput.disabled = disabled;
      if (submitBtn) submitBtn.disabled = disabled;
      extras.forEach((el) => {
        if (el) el.disabled = disabled;
      });
    }

    function showAlert(msg, type) {
      if (!alertEl) return;
      alertEl.textContent = msg;
      alertEl.className = `alert ${type || 'error'}`;
    }

    function clearAlert() {
      if (!alertEl) return;
      alertEl.className = 'alert';
      alertEl.textContent = '';
    }

    function clearLockout() {
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
      lockedUntilMs = 0;
      clearStored();
      setDisabled(false);
      clearAlert();
    }

    function tick() {
      const secs = Math.max(0, Math.ceil((lockedUntilMs - Date.now()) / 1000));
      if (secs <= 0) {
        clearLockout();
        return;
      }
      showAlert(lockoutMessage(secs), 'error');
    }

    function startLockout(seconds, email) {
      const secs = Math.max(1, Math.floor(Number(seconds) || 60));
      lockedUntilMs = Date.now() + secs * 1000;
      if (email) writeStored(email, lockedUntilMs);
      setDisabled(true);
      tick();
      if (timer) clearInterval(timer);
      timer = setInterval(tick, 1000);
    }

    function resumeFromStorage() {
      const stored = readStored();
      if (!stored || !stored.until) return false;
      if (stored.until <= Date.now()) {
        clearStored();
        return false;
      }

      const email = emailInput ? emailInput.value.trim().toLowerCase() : '';
      if (email && stored.email && stored.email !== email) return false;

      lockedUntilMs = stored.until;
      setDisabled(true);
      tick();
      if (timer) clearInterval(timer);
      timer = setInterval(tick, 1000);
      return true;
    }

    function handleLoginResponse(data, email) {
      if (data && data.code === 'locked') {
        const secs = data.retry_after_seconds
          || (data.locked_until
            ? Math.max(1, Math.ceil((new Date(data.locked_until).getTime() - Date.now()) / 1000))
            : 60);
        startLockout(secs, email);
        return true;
      }

      if (data && data.success) {
        clearLockout();
        return false;
      }

      if (data && typeof data.failed_attempts === 'number' && data.failed_attempts > 0) {
        const remaining = data.attempts_remaining;
        if (typeof remaining === 'number' && remaining > 0) {
          const base = data.message || 'Invalid email or password.';
          const suffix = remaining === 1 ? '1 attempt remaining.' : `${remaining} attempts remaining.`;
          showAlert(`${base} ${suffix}`, 'error');
          return true;
        }
      }

      return false;
    }

    if (emailInput) {
      emailInput.addEventListener('input', () => {
        const stored = readStored();
        const email = emailInput.value.trim().toLowerCase();
        if (stored && stored.email && email && stored.email !== stored.email && lockedUntilMs > Date.now()) {
          clearLockout();
        }
      });
    }

    if (form) {
      form.addEventListener('submit', (e) => {
        if (lockedUntilMs > Date.now()) {
          e.preventDefault();
          e.stopImmediatePropagation();
          tick();
        }
      }, true);
    }

    resumeFromStorage();

    return {
      startLockout,
      clearLockout,
      handleLoginResponse,
      isLocked: () => lockedUntilMs > Date.now(),
      resumeFromStorage,
    };
  }

  global.MedConnectLoginLockout = {
    createHandler,
    lockoutMessage,
    STORAGE_KEY,
  };
})(typeof window !== 'undefined' ? window : globalThis);
