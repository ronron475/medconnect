/**
 * MedConnect — Unread Messages Sync Service
 *
 * Single source of truth: DB via /app/api/messages/unread_count.php
 * - Polls in ONE place per tab (deduped)
 * - Broadcasts to other tabs via BroadcastChannel (no refresh needed)
 * - Emits window event: `medconnect:messages-unread` with { unread_count }
 *
 * Consumers (FAB, sidebar badge, quick panel, messages pages) should listen to
 * `medconnect:messages-unread` and render only.
 */
(function (global) {
  'use strict';

  if (global.MedConnectUnreadService) return; // singleton

  const API_PATH = '/app/api/messages/unread_count.php';
  const POLL_MS = 5000;
  const CHANNEL = 'mc_messages_unread_v1';

  function getAssetBase() {
    const body = document.body;
    if (body && body.dataset.assetBase) return body.dataset.assetBase;
    const root = document.getElementById('medconnectThemeRoot');
    if (root && root.dataset.assetBase) return root.dataset.assetBase;
    return '';
  }

  function clampCount(v) {
    return Math.max(0, parseInt(v, 10) || 0);
  }

  function emit(count, source = 'service') {
    const n = clampCount(count);
    global.dispatchEvent(new CustomEvent('medconnect:messages-unread', { detail: { unread_count: n, source } }));
  }

  let lastCount = null;
  let lastEmitAt = 0;
  let timer = null;
  let inFlight = false;

  const bc = (typeof global.BroadcastChannel !== 'undefined') ? new BroadcastChannel(CHANNEL) : null;
  if (bc) {
    bc.onmessage = (ev) => {
      const data = ev && ev.data ? ev.data : null;
      if (!data || data.type !== 'unread') return;
      const n = clampCount(data.unread_count);
      // Avoid loops + redundant re-renders
      if (lastCount === n) return;
      lastCount = n;
      emit(n, 'broadcast');
    };
  }

  async function fetchUnread() {
    if (inFlight) return;
    inFlight = true;
    try {
      const base = getAssetBase();
      const res = await fetch(base + API_PATH + '?_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return;
      const json = await res.json();
      if (!json || !json.success) return;

      const n = clampCount(json.unread_count);
      if (lastCount !== n) {
        lastCount = n;
        lastEmitAt = Date.now();
        emit(n, 'poll');
        if (bc) bc.postMessage({ type: 'unread', unread_count: n, at: lastEmitAt });
      }
    } catch (_) {
      // silent
    } finally {
      inFlight = false;
    }
  }

  function start() {
    if (timer) return;
    fetchUnread();
    timer = global.setInterval(fetchUnread, POLL_MS);
  }

  function stop() {
    if (!timer) return;
    global.clearInterval(timer);
    timer = null;
  }

  function setUnread(count, source = 'local') {
    const n = clampCount(count);
    if (lastCount === n) return;
    lastCount = n;
    lastEmitAt = Date.now();
    emit(n, source);
    if (bc) bc.postMessage({ type: 'unread', unread_count: n, at: lastEmitAt });
  }

  // If other parts of the app emit unread updates, rebroadcast cross-tab.
  global.addEventListener('medconnect:messages-unread', (ev) => {
    const d = ev && ev.detail ? ev.detail : null;
    if (!d || typeof d.unread_count === 'undefined') return;
    const n = clampCount(d.unread_count);
    // Prevent tight rebroadcast loops when we were the source
    const since = Date.now() - lastEmitAt;
    if (lastCount === n && since < 250) return;
    setUnread(n, d.source || 'event');
  });

  document.addEventListener('visibilitychange', () => {
    if (document.hidden) stop();
    else start();
  });

  if (!document.hidden) start();

  global.MedConnectUnreadService = {
    refresh: fetchUnread,
    setUnread,
    start,
    stop,
    getLast() { return lastCount; },
  };
})(window);

