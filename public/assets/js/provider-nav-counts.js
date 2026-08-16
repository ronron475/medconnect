/**
 * MedConnect — Provider sidebar live counts
 * Polls /app/api/provider/nav_counts.php and updates Queue / Triage / Messages badges.
 */
(function (global) {
  'use strict';

  if (global.MedConnectProviderNavCounts) return;

  const API_PATH = '/app/api/provider/nav_counts.php';
  const POLL_MS = 5000;
  const CHANNEL = 'mc_provider_nav_counts_v1';

  function getAssetBase() {
    const body = document.body;
    if (body && body.dataset.assetBase) return body.dataset.assetBase;
    const themeRoot = document.getElementById('medconnectThemeRoot');
    if (themeRoot && themeRoot.dataset.assetBase) return themeRoot.dataset.assetBase;
    if (typeof global.APP_BASE === 'string' && global.APP_BASE) return global.APP_BASE;
    return '';
  }

  function clamp(v) {
    return Math.max(0, parseInt(v, 10) || 0);
  }

  function formatBadge(n) {
    if (n <= 0) return '';
    return n > 99 ? '99+' : String(n);
  }

  function setBadge(selector, count, labelBase) {
    const n = clamp(count);
    const text = formatBadge(n);
    document.querySelectorAll(selector).forEach((badge) => {
      badge.textContent = text;
      badge.hidden = n <= 0;
      badge.setAttribute('aria-hidden', n <= 0 ? 'true' : 'false');
    });
    const linkSel = selector.replace('-badge', '');
    document.querySelectorAll(linkSel).forEach((link) => {
      if (!labelBase) return;
      link.setAttribute('aria-label', n > 0 ? `${labelBase} (${n})` : labelBase);
    });
  }

  function applyCounts(data) {
    if (!data) return;
    if (data.queue != null) {
      setBadge('[data-nav-queue-badge]', data.queue, 'Live Queue');
    }
    if (data.triage != null) {
      setBadge('[data-nav-triage-badge]', data.triage, 'Active Triage Review');
    }
    if (data.referrals != null) {
      setBadge('[data-nav-badge="referrals"]', data.referrals, 'Referrals');
    }
    if (data.followups != null) {
      setBadge('[data-nav-badge="followups"]', data.followups, 'Follow-Up Management');
    }
    if (data.messages != null || data.unread_count != null) {
      const messages = clamp(data.messages != null ? data.messages : data.unread_count);
      setBadge('[data-nav-messages-badge]', messages, 'Messages');
      document.querySelectorAll('[data-nav-messages]').forEach((link) => {
        link.setAttribute('aria-label', messages > 0 ? `Messages (${messages} unread)` : 'Messages');
      });
      if (global.MedConnectUnreadService && typeof global.MedConnectUnreadService.setUnread === 'function') {
        global.MedConnectUnreadService.setUnread(messages, 'provider-nav-counts');
      } else {
        global.dispatchEvent(new CustomEvent('medconnect:messages-unread', {
          detail: { unread_count: messages, source: 'provider-nav-counts' },
        }));
      }
    }

    global.dispatchEvent(new CustomEvent('medconnect:provider-nav-counts', {
      detail: {
        queue: data.queue != null ? clamp(data.queue) : lastCounts.queue,
        triage: data.triage != null ? clamp(data.triage) : lastCounts.triage,
        triage_urgent: data.triage_urgent != null ? clamp(data.triage_urgent) : lastCounts.triage_urgent,
        messages: data.messages != null ? clamp(data.messages) : lastCounts.messages,
        source: 'service',
      },
    }));
  }

  let lastCounts = { queue: null, triage: null, triage_urgent: null, messages: null, referrals: null, followups: null };
  let timer = null;
  let inFlight = false;
  let booted = false;

  function countsKey(c) {
    return `${c.queue}|${c.triage}|${c.messages}|${c.referrals}|${c.followups}`;
  }

  const bc = (typeof global.BroadcastChannel !== 'undefined')
    ? new BroadcastChannel(CHANNEL)
    : null;

  if (bc) {
    bc.onmessage = (ev) => {
      const data = ev && ev.data ? ev.data : null;
      if (!data || data.type !== 'counts') return;
      const payload = {
        queue: clamp(data.queue),
        triage: clamp(data.triage),
        triage_urgent: clamp(data.triage_urgent),
        messages: clamp(data.messages),
        referrals: clamp(data.referrals),
        followups: clamp(data.followups),
      };
      if (countsKey(payload) === countsKey(lastCounts) && lastCounts.queue !== null) return;
      lastCounts = payload;
      applyCounts(payload);
    };
  }

  async function fetchCounts() {
    if (inFlight) return;
    inFlight = true;
    try {
      const res = await fetch(getAssetBase() + API_PATH + '?_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return;
      const json = await res.json();
      if (!json || !json.success) return;

      const payload = {
        queue: clamp(json.queue),
        triage: clamp(json.triage),
        triage_urgent: clamp(json.triage_urgent),
        messages: clamp(json.messages != null ? json.messages : json.unread_count),
        referrals: clamp(json.referrals),
        followups: clamp(json.followups),
      };
      if (countsKey(payload) !== countsKey(lastCounts) || lastCounts.queue === null) {
        lastCounts = payload;
        applyCounts(payload);
        if (bc) {
          bc.postMessage({ type: 'counts', ...payload, at: Date.now() });
        }
      }
    } catch (_) {
      // silent
    } finally {
      inFlight = false;
    }
  }

  function start() {
    if (timer) return;
    fetchCounts();
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
    if (!document.body || !document.body.classList.contains('provider-body')) return;
    booted = true;
    start();
  }

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stop();
    else {
      boot();
      fetchCounts();
    }
  });

  global.addEventListener('focus', () => {
    if (!document.hidden && booted) fetchCounts();
  });

  global.addEventListener('medconnect:messages-unread', (ev) => {
    const detail = ev && ev.detail ? ev.detail : null;
    if (!detail || detail.unread_count == null) return;
    setCounts({ messages: detail.unread_count });
  });

  global.addEventListener('medconnect:nav-badges-refresh', () => {
    fetchCounts();
  });

  // When triage live refresh finishes, bump sidebar from its stats if provided.
  function setCounts(partial) {
    if (!partial || typeof partial !== 'object') return;
    const cur = { ...lastCounts };
    if (partial.queue != null) cur.queue = clamp(partial.queue);
    if (partial.triage != null) cur.triage = clamp(partial.triage);
    if (partial.triage_urgent != null) cur.triage_urgent = clamp(partial.triage_urgent);
    if (partial.messages != null) cur.messages = clamp(partial.messages);
    if (partial.referrals != null) cur.referrals = clamp(partial.referrals);
    if (partial.followups != null) cur.followups = clamp(partial.followups);
    if (countsKey(cur) === countsKey(lastCounts) && lastCounts.triage !== null) return;
    lastCounts = cur;
    applyCounts(partial);
    if (bc && cur.queue !== null && cur.triage !== null && cur.messages !== null) {
      bc.postMessage({ type: 'counts', ...cur, at: Date.now() });
    }
  }

  /** Allow triage/queue pages to push fresher counts immediately. */
  global.addEventListener('medconnect:triage-live', (ev) => {
    const d = ev && ev.detail ? ev.detail : null;
    if (!d) return;
    if (typeof d.triage === 'number' || typeof d.total === 'number') {
      setCounts({
        triage: d.triage != null ? d.triage : d.total,
        triage_urgent: d.urgent != null ? d.urgent : d.triage_urgent,
      }, 'triage-live');
    } else {
      fetchCounts();
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  global.MedConnectProviderNavCounts = {
    refresh: fetchCounts,
    setCounts,
    start,
    stop,
  };
})(window);
