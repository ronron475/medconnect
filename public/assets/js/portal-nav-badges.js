/**
 * Portal sidebar badge sync — polls role-specific nav count APIs and updates badges.
 */
(function (global) {
  'use strict';

  if (global.MedConnectPortalNavBadges) return;

  const POLL_MS = 8000;

  const API_BY_PORTAL = {
    patient: '/app/api/patient/nav_counts.php',
    provider: '/app/api/provider/nav_counts.php',
    admin: '/app/api/admin/nav_counts.php',
    superadmin: '/app/api/admin/nav_counts.php',
    bhw: '/app/api/bhw/nav_counts.php',
  };

  const KEY_ALIASES = {
    messages: ['messages', 'unread_count'],
    queue: ['queue'],
    triage: ['triage'],
    consultations: ['consultations'],
    bhw_triage: ['bhw_triage'],
    bhw_consultations: ['bhw_consultations'],
    bhw_referrals: ['bhw_referrals'],
    bhw_records: ['bhw_records'],
    pending_doctor_apps: ['pending_doctor_apps'],
    pending_bhw_apps: ['pending_bhw_apps'],
    active_consultations: ['active_consultations'],
    queue_pending: ['queue_pending'],
    notifications: ['notifications'],
  };

  function getAssetBase() {
    const body = document.body;
    if (body && body.dataset.assetBase) return body.dataset.assetBase;
    const themeRoot = document.getElementById('medconnectThemeRoot');
    if (themeRoot && themeRoot.dataset.assetBase) return themeRoot.dataset.assetBase;
    if (typeof global.APP_BASE === 'string' && global.APP_BASE) return global.APP_BASE;
    return '';
  }

  function getPortal() {
    const body = document.body;
    if (!body) return '';
    if (body.dataset.portal) return body.dataset.portal;
    if (body.classList.contains('provider-body')) return 'provider';
    if (body.classList.contains('patient-portal')) return 'patient';
    if (body.classList.contains('bhw-body')) return 'bhw';
    if (body.classList.contains('superadmin-body')) return 'superadmin';
    if (body.classList.contains('admin-body')) return 'admin';
    return '';
  }

  function clamp(v) {
    return Math.max(0, parseInt(v, 10) || 0);
  }

  function formatBadge(n) {
    if (n <= 0) return '';
    return n > 99 ? '99+' : String(n);
  }

  function resolveCount(data, key) {
    if (!data || !key) return 0;
    const aliases = KEY_ALIASES[key] || [key];
    for (let i = 0; i < aliases.length; i++) {
      const k = aliases[i];
      if (data[k] != null) return clamp(data[k]);
    }
    return 0;
  }

  function setBadgeEl(badge, count) {
    const n = clamp(count);
    const text = formatBadge(n);
    badge.textContent = text;
    badge.hidden = n <= 0;
    badge.setAttribute('aria-hidden', n <= 0 ? 'true' : 'false');
  }

  function applyCounts(data) {
    if (!data) return;

    document.querySelectorAll('[data-nav-badge]').forEach((badge) => {
      const key = badge.getAttribute('data-nav-badge');
      if (!key) return;
      setBadgeEl(badge, resolveCount(data, key));
    });

    if (data.messages != null || data.unread_count != null) {
      const messages = resolveCount(data, 'messages');
      if (global.MedConnectUnreadService && typeof global.MedConnectUnreadService.setUnread === 'function') {
        global.MedConnectUnreadService.setUnread(messages, 'portal-nav-badges');
      }
      global.dispatchEvent(new CustomEvent('medconnect:messages-unread', {
        detail: { unread_count: messages, source: 'portal-nav-badges' },
      }));
    }

    if (data.queue != null || data.triage != null) {
      global.dispatchEvent(new CustomEvent('medconnect:provider-nav-counts', {
        detail: {
          queue: resolveCount(data, 'queue'),
          triage: resolveCount(data, 'triage'),
          source: 'portal-nav-badges',
        },
      }));
    }
  }

  let timer = null;
  let inFlight = false;

  async function fetchCounts() {
    const portal = getPortal();
    const path = API_BY_PORTAL[portal];
    if (!path || inFlight) return;
    inFlight = true;
    try {
      const res = await fetch(getAssetBase() + path + '?_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return;
      const json = await res.json();
      if (!json || !json.success) return;
      applyCounts(json.data || json);
    } catch (_) {
      // silent
    } finally {
      inFlight = false;
    }
  }

  function start() {
    if (timer) return;
    fetchCounts();
    timer = global.setInterval(fetchCounts, POLL_MS);
  }

  function stop() {
    if (!timer) return;
    global.clearInterval(timer);
    timer = null;
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stop();
    else start();
  });

  if (!document.hidden && getPortal()) start();

  global.MedConnectPortalNavBadges = {
    refresh: fetchCounts,
    applyCounts,
    start,
    stop,
  };
})(window);
