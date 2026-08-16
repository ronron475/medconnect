/**
 * Portal sidebar badge sync — live polling + instant event updates.
 */
(function (global) {
  'use strict';

  if (global.MedConnectPortalNavBadges) return;

  const POLL_MS = 5000;
  const CHANNEL = 'mc_portal_nav_badges_v1';

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
    referrals: ['referrals'],
    followups: ['followups'],
    bhw_triage: ['bhw_triage'],
    bhw_consultations: ['bhw_consultations'],
    bhw_referrals: ['bhw_referrals'],
    bhw_records: ['bhw_records'],
    bhw_followups: ['bhw_followups'],
    bhw_patients_pending: ['bhw_patients_pending'],
    pending_doctor_apps: ['pending_doctor_apps'],
    pending_bhw_apps: ['pending_bhw_apps'],
    active_consultations: ['active_consultations'],
    queue_pending: ['queue_pending'],
    notifications: ['notifications'],
    ai_review_pending: ['ai_review_pending'],
    announcement_drafts: ['announcement_drafts'],
    pending_referrals: ['pending_referrals'],
    patient_triage: ['patient_triage'],
  };

  let timer = null;
  let inFlight = false;
  let booted = false;
  let lastPayload = null;

  const bc = (typeof global.BroadcastChannel !== 'undefined')
    ? new BroadcastChannel(CHANNEL)
    : null;

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

  function payloadKey(data) {
    if (!data) return '';
    return Object.keys(KEY_ALIASES).map((key) => key + ':' + resolveCount(data, key)).join('|');
  }

  function setBadgeEl(badge, count) {
    const n = clamp(count);
    const text = formatBadge(n);
    badge.textContent = text;
    badge.hidden = n <= 0;
    badge.setAttribute('aria-hidden', n <= 0 ? 'true' : 'false');
  }

  function setBadgeByKey(key, count) {
    if (!key) return;
    document.querySelectorAll('[data-nav-badge="' + key + '"]').forEach((badge) => {
      setBadgeEl(badge, count);
    });
    if (key === 'messages') {
      document.querySelectorAll('[data-nav-messages-badge]').forEach((badge) => {
        setBadgeEl(badge, count);
      });
    } else if (key === 'queue') {
      document.querySelectorAll('[data-nav-queue-badge]').forEach((badge) => {
        setBadgeEl(badge, count);
      });
    } else if (key === 'triage') {
      document.querySelectorAll('[data-nav-triage-badge]').forEach((badge) => {
        setBadgeEl(badge, count);
      });
    }
  }

  function applyCounts(data, options) {
    if (!data) return;
    const opts = options || {};
    const nextKey = payloadKey(data);
    if (!opts.force && lastPayload && payloadKey(lastPayload) === nextKey) return;
    lastPayload = Object.assign({}, data);

    document.querySelectorAll('[data-nav-badge]').forEach((badge) => {
      const key = badge.getAttribute('data-nav-badge');
      if (!key) return;
      setBadgeEl(badge, resolveCount(data, key));
    });

    if (!opts.skipBroadcast && bc) {
      bc.postMessage({ type: 'counts', data: lastPayload, at: Date.now() });
    }
  }

  async function fetchCounts(options) {
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
      applyCounts(json.data || json, options || {});
    } catch (_) {
      // silent
    } finally {
      inFlight = false;
    }
  }

  function start() {
    if (timer || !getPortal()) return;
    fetchCounts({ force: true });
    timer = global.setInterval(function () {
      if (document.hidden) return;
      if (global.MedConnectLiveSync && Date.now() - (global.MedConnectLiveSync.lastHubAt() || 0) < 4000) return;
      fetchCounts();
    }, POLL_MS);
  }

  function stop() {
    if (!timer) return;
    global.clearInterval(timer);
    timer = null;
  }

  function boot() {
    if (booted) return;
    if (!getPortal()) return;
    if (!document.querySelector('[data-nav-badge]')) return;
    booted = true;
    start();
  }

  if (bc) {
    bc.onmessage = function (ev) {
      const data = ev && ev.data ? ev.data : null;
      if (!data || data.type !== 'counts' || !data.data) return;
      applyCounts(data.data, { force: true, skipBroadcast: true });
    };
  }

  global.addEventListener('medconnect:messages-unread', function (ev) {
    const detail = ev && ev.detail ? ev.detail : null;
    if (!detail || detail.unread_count == null) return;
    const count = clamp(detail.unread_count);
    setBadgeByKey('messages', count);
    if (lastPayload) lastPayload.messages = count;
  });

  global.addEventListener('medconnect:provider-nav-counts', function (ev) {
    const detail = ev && ev.detail ? ev.detail : null;
    if (!detail) return;
    if (detail.queue != null) setBadgeByKey('queue', detail.queue);
    if (detail.triage != null) setBadgeByKey('triage', detail.triage);
    if (lastPayload) {
      if (detail.queue != null) lastPayload.queue = clamp(detail.queue);
      if (detail.triage != null) lastPayload.triage = clamp(detail.triage);
    }
  });

  global.addEventListener('medconnect:notifications-unread', function (ev) {
    const detail = ev && ev.detail ? ev.detail : null;
    if (!detail || detail.unread_count == null) return;
    const count = clamp(detail.unread_count);
    setBadgeByKey('notifications', count);
    if (lastPayload) lastPayload.notifications = count;
  });

  global.addEventListener('medconnect:nav-badges-refresh', function () {
    fetchCounts({ force: true });
  });

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      stop();
    } else {
      boot();
      fetchCounts({ force: true });
    }
  });

  global.addEventListener('focus', function () {
    if (!document.hidden && booted) fetchCounts({ force: true });
  });

  global.addEventListener('pageshow', function (ev) {
    if (ev && ev.persisted && booted) {
      fetchCounts({ force: true });
    }
  });

  function init() {
    boot();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  global.MedConnectPortalNavBadges = {
    refresh: function () { return fetchCounts({ force: true }); },
    applyCounts: function (data) { applyCounts(data, { force: true }); },
    start: boot,
    stop: stop,
  };
})(window);
