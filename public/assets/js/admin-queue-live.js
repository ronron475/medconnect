/**
 * Admin queue monitoring — update stats and table without reloading the page.
 */
(function (global) {
  'use strict';

  if (global.MedConnectAdminQueueLive) return;

  var root = document.getElementById('adminQueueLive');
  if (!root) return;

  var API_PATH = '/app/api/admin/queue_live.php';
  var POLL_MS = 8000;
  var timer = null;
  var inFlight = false;
  var lastFingerprint = root.getAttribute('data-fingerprint') || '';

  function assetBase() {
    return (document.body && document.body.dataset.assetBase) || (global.APP_BASE || '');
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function setMetric(key, value) {
    var el = root.querySelector('[data-queue-metric="' + key + '"]');
    if (el) el.textContent = String(value);
  }

  function renderRows(rows) {
    var tbody = root.querySelector('[data-queue-body]');
    if (!tbody) return;
    if (!rows || !rows.length) {
      tbody.innerHTML = '<tr><td colspan="4">No consultations for today.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(function (q) {
      return '<tr>' +
        '<td>' + esc(q.time_label || '') + '</td>' +
        '<td>' + esc(q.patient_name || '') + '</td>' +
        '<td>' + esc(q.provider_name || 'Unassigned') + '</td>' +
        '<td><span class="mc-badge">' + esc(q.status || '') + '</span></td>' +
        '</tr>';
    }).join('');
  }

  async function refresh() {
    if (inFlight || document.hidden) return;
    inFlight = true;
    try {
      var res = await fetch(assetBase() + API_PATH + '?_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json', 'X-MC-No-Loader': '1' },
      });
      if (!res.ok) return;
      var json = await res.json();
      if (!json || !json.success) return;
      var fp = json.fingerprint || '';
      if (fp && fp === lastFingerprint) return;
      lastFingerprint = fp;
      setMetric('waiting', json.waiting || 0);
      setMetric('active', json.active || 0);
      setMetric('completed', json.completed || 0);
      renderRows(json.rows || []);
    } catch (_) {
      /* keep last table; retry on next tick */
    } finally {
      inFlight = false;
    }
  }

  function start() {
    if (timer) return;
    refresh();
    timer = global.setInterval(function () {
      if (document.hidden) return;
      if (global.MedConnectLiveSync && Date.now() - (global.MedConnectLiveSync.lastHubAt() || 0) < 4000) return;
      refresh();
    }, POLL_MS);
  }

  function stop() {
    if (!timer) return;
    global.clearInterval(timer);
    timer = null;
  }

  document.addEventListener('visibilitychange', function () {
    if (document.hidden) stop();
    else start();
  });

  document.addEventListener('medconnect:live-sync', function (ev) {
    var changed = (ev.detail && ev.detail.changed) || [];
    if (changed.indexOf('queue') !== -1 || changed.indexOf('appointments') !== -1) {
      refresh();
    }
  });

  start();

  global.MedConnectAdminQueueLive = { refresh: refresh, start: start, stop: stop };
})(window);
