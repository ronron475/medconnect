/**
 * MedConnect — Operational Reports live refresh (Admin / Super Admin)
 * Polls /app/api/admin/operational_reports_live.php and swaps in fresh report cards.
 */
(function (global) {
  'use strict';

  if (global.MedConnectOperationalReportsLive) return;

  var POLL_MS = (global.McChartTheme && global.McChartTheme.REFRESH_MS) || 15000;
  var root = null;
  var timer = null;
  var inFlight = false;
  var lastHtml = {};

  function touchSync(updatedAt) {
    var el = root && root.querySelector('[data-op-live-sync]');
    if (!el) return;
    var t = updatedAt ? new Date(updatedAt) : new Date();
    if (Number.isNaN(t.getTime())) t = new Date();
    el.textContent = 'Live · Updated ' + t.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
  }

  function requestUrl() {
    var params = new URLSearchParams(global.location.search);
    params.set('base', global.location.pathname);
    params.set('_', String(Date.now()));
    return root.getAttribute('data-op-reports-api') + '?' + params.toString();
  }

  function swapSection(type, html) {
    if (lastHtml[type] === html) return;
    var current = document.getElementById('report-' + type);
    if (!current) return;
    // Leave a card alone while the user is using its controls; the next poll catches up.
    if (current.contains(document.activeElement) && document.activeElement !== document.body) return;
    var tpl = document.createElement('template');
    tpl.innerHTML = html.trim();
    var next = tpl.content.querySelector('#report-' + type);
    if (!next) return;
    current.replaceWith(next);
    lastHtml[type] = html;
  }

  async function refresh() {
    if (inFlight || document.hidden || !root) return;
    inFlight = true;
    try {
      var res = await fetch(requestUrl(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return;
      var payload = await res.json();
      if (!payload || !payload.success || !payload.sections) return;
      Object.keys(payload.sections).forEach(function (type) {
        swapSection(type, String(payload.sections[type] || ''));
      });
      touchSync(payload.updated_at);
    } catch (e) {
      // quiet — keep the last rendered data on screen
    } finally {
      inFlight = false;
    }
  }

  function start() {
    if (timer) return;
    timer = global.setInterval(refresh, POLL_MS);
  }

  function stop() {
    if (!timer) return;
    global.clearInterval(timer);
    timer = null;
  }

  function boot() {
    root = document.querySelector('[data-op-reports-live]');
    if (!root) return;
    touchSync();
    start();
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        stop();
      } else {
        refresh();
        start();
      }
    });
    global.addEventListener('medconnect:live-sync', refresh);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();

  global.MedConnectOperationalReportsLive = { refresh: refresh, start: start, stop: stop };
})(window);
