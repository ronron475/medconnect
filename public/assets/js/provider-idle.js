(function () {
  'use strict';

  if (document.body.getAttribute('data-remember-extended') === '1') {
    return;
  }

  const minutes = parseInt(document.body.getAttribute('data-auto-logout') || '0', 10);
  if (!minutes || minutes <= 0) return;

  const expireUrl = document.body.getAttribute('data-expire-url') || '';
  if (!expireUrl) return;

  let timer;
  const ms = minutes * 60 * 1000;
  const channelName = 'medconnect-session-keepalive';

  const expireSession = async () => {
    const base = (typeof window.ASSET_BASE !== 'undefined' && window.ASSET_BASE)
      ? String(window.ASSET_BASE).replace(/\/$/, '')
      : (document.body.getAttribute('data-asset-base') || '').replace(/\/$/, '');
    const fallback = (base || '') + '/index.php?session_expired=1';

    try {
      const csrf = document.body.getAttribute('data-csrf') || '';
      const res = await fetch(expireUrl, {
        method: 'POST',
        credentials: 'include',
        cache: 'no-store',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest',
          'X-MC-No-Loader': '1',
        },
        body: 'reason=inactivity&csrf_token=' + encodeURIComponent(csrf),
      });
      const data = await res.json();
      window.location.href = data.redirect || fallback;
    } catch (e) {
      window.location.href = fallback;
    }
  };

  const reset = () => {
    clearTimeout(timer);
    timer = setTimeout(expireSession, ms);
  };

  ['mousemove', 'mousedown', 'click', 'keydown', 'touchstart', 'scroll'].forEach((evt) => {
    document.addEventListener(evt, reset, { passive: true });
  });

  // Video consultation tabs (same browser profile) ping this channel so an open
  // provider dashboard tab does not idle-logout the shared PHP session mid-call.
  try {
    if (typeof BroadcastChannel !== 'undefined') {
      const bus = new BroadcastChannel(channelName);
      bus.onmessage = function (ev) {
        const data = ev && ev.data ? ev.data : null;
        if (!data || data.type !== 'activity') return;
        reset();
      };
    }
  } catch (e) { /* ignore */ }

  reset();
})();
