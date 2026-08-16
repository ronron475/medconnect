/**
 * medConnect live sync hub — cheap fingerprints, then existing page refreshers.
 * Does not reload the page. Pauses while the tab is hidden.
 */
(function (global) {
  'use strict';

  if (global.MedConnectLiveSync) return;

  var API_PATH = '/app/api/live/sync.php';
  var POLL_MS = 6000;
  var MAX_BACKOFF_MS = 30000;
  var timer = null;
  var inFlight = false;
  var active = false;
  var lastFingerprints = null;
  var backoffMs = POLL_MS;
  var lastHubAt = 0;

  function isVideoRoomPage() {
    return /video_room\.php/i.test(global.location.pathname || '');
  }

  function assetBase() {
    var body = document.body;
    if (body && body.dataset.assetBase) return body.dataset.assetBase;
    var root = document.getElementById('medconnectThemeRoot');
    if (root && root.dataset.assetBase) return root.dataset.assetBase;
    if (typeof global.APP_BASE === 'string' && global.APP_BASE) return global.APP_BASE;
    return '';
  }

  function changedKeys(prev, next) {
    var keys = {};
    var k;
    if (next) {
      for (k in next) {
        if (Object.prototype.hasOwnProperty.call(next, k)) keys[k] = true;
      }
    }
    if (prev) {
      for (k in prev) {
        if (Object.prototype.hasOwnProperty.call(prev, k)) keys[k] = true;
      }
    }
    var out = [];
    for (k in keys) {
      if ((prev && prev[k]) !== (next && next[k])) out.push(k);
    }
    return out;
  }

  function invokeExisting(changed, counts) {
    var i;
    var has = function (key) {
      return changed.indexOf(key) !== -1;
    };

    if (has('notifications')) {
      if (counts && counts.notifications != null) {
        global.dispatchEvent(new CustomEvent('medconnect:notifications-unread', {
          detail: { unread_count: counts.notifications, source: 'live-sync' },
        }));
      }
      if (global.MedConnectNotifications) {
        if (typeof global.MedConnectNotifications.refreshCount === 'function') {
          global.MedConnectNotifications.refreshCount();
        }
        if (typeof global.MedConnectNotifications.poll === 'function') {
          global.MedConnectNotifications.poll();
        }
      }
    }

    if (has('messages')) {
      if (global.MedConnectUnreadService && typeof global.MedConnectUnreadService.refresh === 'function') {
        global.MedConnectUnreadService.refresh();
      } else if (counts && counts.messages != null) {
        global.dispatchEvent(new CustomEvent('medconnect:messages-unread', {
          detail: { unread_count: counts.messages, source: 'live-sync' },
        }));
      }
    }

    if (has('slots') || has('schedule') || has('booking_state')) {
      if (typeof global.refreshBookingPicker === 'function') {
        global.refreshBookingPicker(true);
      }
      if (global.MedConnectProviderScheduleLive && typeof global.MedConnectProviderScheduleLive.refresh === 'function') {
        global.MedConnectProviderScheduleLive.refresh(false);
      }
      if (global.MedConnectPatientSlotWait && typeof global.MedConnectPatientSlotWait.refresh === 'function') {
        global.MedConnectPatientSlotWait.refresh();
      }
    }

    if (has('appointments') || has('queue') || has('booking_state')) {
      if (typeof global.refreshConsultationStatus === 'function') {
        global.refreshConsultationStatus();
      }
      if (global.MedConnectProviderQueueLive && typeof global.MedConnectProviderQueueLive.refresh === 'function') {
        global.MedConnectProviderQueueLive.refresh();
      }
      if (global.MedConnectAdminQueueLive && typeof global.MedConnectAdminQueueLive.refresh === 'function') {
        global.MedConnectAdminQueueLive.refresh();
      }
      if (global.MedConnectProviderDashboardLive && typeof global.MedConnectProviderDashboardLive.refresh === 'function') {
        global.MedConnectProviderDashboardLive.refresh();
      }
    }

    if (has('triage') || has('queue') || has('booking_state')) {
      if (global.MedConnectTriageLive && typeof global.MedConnectTriageLive.refresh === 'function') {
        global.MedConnectTriageLive.refresh(true);
      }
      if (global.MedConnectPatientSlotWait && typeof global.MedConnectPatientSlotWait.refresh === 'function') {
        global.MedConnectPatientSlotWait.refresh();
      }
    }

    if (has('dashboard') || has('queue') || has('triage') || has('appointments')) {
      if (global.MedConnectAdminDashboardLive && typeof global.MedConnectAdminDashboardLive.refresh === 'function') {
        global.MedConnectAdminDashboardLive.refresh();
      }
      if (typeof global.refreshBhwDashboard === 'function') {
        global.refreshBhwDashboard();
      }
      if (typeof global.refreshBhwConsultations === 'function') {
        global.refreshBhwConsultations();
      }
    }

    if (global.MedConnectNavBadgesRefresh) {
      global.MedConnectNavBadgesRefresh();
    } else {
      global.dispatchEvent(new CustomEvent('medconnect:nav-badges-refresh'));
    }

    lastHubAt = Date.now();
  }

  async function tick() {
    if (inFlight || document.hidden || isVideoRoomPage()) return;
    inFlight = true;
    try {
      var res = await fetch(assetBase() + API_PATH + '?_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json', 'X-MC-No-Loader': '1' },
      });
      if (res.status === 401 || res.status === 403) {
        stop();
        return;
      }
      if (!res.ok) throw new Error('sync');
      var json = await res.json();
      if (!json || !json.success) throw new Error('sync');

      var fps = json.fingerprints || {};
      var counts = json.counts || {};
      backoffMs = POLL_MS;

      if (!lastFingerprints) {
        lastFingerprints = fps;
        return;
      }

      var changed = changedKeys(lastFingerprints, fps);
      lastFingerprints = fps;
      if (!changed.length) return;

      global.dispatchEvent(new CustomEvent('medconnect:live-sync', {
        detail: { changed: changed, fingerprints: fps, counts: counts, source: 'live-sync' },
      }));
      invokeExisting(changed, counts);
    } catch (_) {
      backoffMs = Math.min(MAX_BACKOFF_MS, Math.max(POLL_MS, backoffMs * 2));
    } finally {
      inFlight = false;
      if (active && !document.hidden && !isVideoRoomPage()) {
        schedule();
      }
    }
  }

  function schedule() {
    if (timer) {
      global.clearTimeout(timer);
      timer = null;
    }
    if (!active || document.hidden || isVideoRoomPage()) return;
    timer = global.setTimeout(tick, backoffMs);
  }

  function start() {
    if (isVideoRoomPage()) return;
    active = true;
    backoffMs = POLL_MS;
    if (timer) {
      global.clearTimeout(timer);
      timer = null;
    }
    tick();
  }

  function stop() {
    active = false;
    if (timer) {
      global.clearTimeout(timer);
      timer = null;
    }
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      stop();
      active = false;
      return;
    }
    start();
  });

  global.addEventListener('focus', function () {
    if (!document.hidden) start();
  });

  function boot() {
    if (isVideoRoomPage()) return;
    if (!document.hidden) start();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  global.MedConnectLiveSync = {
    refresh: tick,
    start: start,
    stop: stop,
    isActive: function () { return active; },
    lastHubAt: function () { return lastHubAt; },
  };
})(window);
