(function () {
  'use strict';

  const minutes = parseInt(document.body.getAttribute('data-auto-logout') || '0', 10);
  if (!minutes || minutes <= 0) return;

  const expireUrl = document.body.getAttribute('data-expire-url') || '';
  if (!expireUrl) return;

  let timer;
  const ms = minutes * 60 * 1000;

  const expireSession = async () => {
    try {
      const res = await fetch(expireUrl, {
        method: 'POST',
        credentials: 'include',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: 'reason=inactivity',
      });
      const data = await res.json();
      window.location.href = data.redirect || '/index.php?session_expired=1';
    } catch (e) {
      window.location.href = '/index.php?session_expired=1';
    }
  };

  const reset = () => {
    clearTimeout(timer);
    timer = setTimeout(expireSession, ms);
  };

  ['mousemove', 'mousedown', 'click', 'keydown', 'touchstart', 'scroll'].forEach((evt) => {
    document.addEventListener(evt, reset, { passive: true });
  });

  reset();
})();
