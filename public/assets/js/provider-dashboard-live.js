/**
 * MedConnect — Provider dashboard live refresh
 * Polls /app/api/provider/dashboard_live.php and updates chart, metrics, queue, status, activity.
 */
(function (global) {
  'use strict';

  if (global.MedConnectProviderDashboardLive) return;

  var API_PATH = '/app/api/provider/dashboard_live.php';
  var POLL_MS = (global.McChartTheme && global.McChartTheme.REFRESH_MS) || 15000;
  var lastFingerprint = '';
  var timer = null;
  var inFlight = false;

  function assetBase() {
    return (document.body && document.body.dataset.assetBase) || '';
  }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function setText(sel, value) {
    document.querySelectorAll(sel).forEach(function (el) {
      el.textContent = String(value);
    });
  }

  function formatDate(iso) {
    if (!iso) return 'Today';
    var d = new Date(iso + (iso.length <= 10 ? 'T00:00:00' : ''));
    if (Number.isNaN(d.getTime())) return iso;
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function formatTime(t) {
    if (!t) return '';
    var parts = String(t).split(':');
    if (parts.length < 2) return t;
    var h = parseInt(parts[0], 10);
    var m = parts[1];
    var ampm = h >= 12 ? 'PM' : 'AM';
    h = h % 12;
    if (h === 0) h = 12;
    return h + ':' + m + ' ' + ampm;
  }

  function updateMetrics(stats) {
    if (!stats) return;
    setText('[data-live-stat="appointments"]', stats.appointments || 0);
    setText('[data-live-stat="pending"]', stats.pending || 0);
    setText('[data-live-stat="ongoing"]', stats.ongoing || 0);
    setText('[data-live-stat="completed"]', stats.completed || 0);
    setText('[data-live-status="waiting"]', stats.pending || 0);
    setText('[data-live-status="ongoing"]', stats.ongoing || 0);
    setText('[data-live-status="completed"]', stats.completed || 0);

    var urgentWrap = document.querySelector('[data-live-urgent-wrap]');
    var urgentVal = document.querySelector('[data-live-status="urgent"]');
    var urgent = Number(stats.urgent || 0);
    if (urgentWrap) {
      urgentWrap.hidden = urgent <= 0;
    }
    if (urgentVal) urgentVal.textContent = String(urgent);
  }

  function updateChart(weekChart, weekTotal) {
    var badge = document.querySelector('[data-live-week-total]');
    if (badge) badge.textContent = (weekTotal || 0) + ' this week';

    var dataEl = document.getElementById('provWeekChartData');
    if (dataEl) dataEl.textContent = JSON.stringify(weekChart || []);

    var legend = document.querySelector('[data-live-week-today]');
    if (legend && weekChart && weekChart.length) {
      legend.textContent = (weekChart[weekChart.length - 1].date || 'Today') + ' = today';
    }

    var canvas = document.querySelector('canvas[data-mc-weekly-bar="provWeekChartData"]');
    if (!canvas || !global.McChartTheme) return;
    if (typeof global.McChartTheme.updateWeeklyBarChart === 'function') {
      global.McChartTheme.updateWeeklyBarChart(canvas, weekChart || []);
    }
  }

  function queueRowHtml(item) {
    var urg = String(item.urgency || '').toLowerCase();
    var isUrgent = urg.indexOf('urgent') !== -1 || urg.indexOf('1') !== -1 || urg.indexOf('emergency') !== -1;
    var urgBg = isUrgent ? '#fee2e2' : '#e0f2fe';
    var urgColor = isUrgent ? '#ef4444' : '#0369a1';
    var schedDate = item.date ? formatDate(item.date) : 'Today';
    var schedTime = item.time ? formatTime(item.time) : '';
    var id = Number(item.id || 0);
    var action;
    if (item.session_allowed) {
      action = '<a href="' + esc(assetBase()) + '/views/provider/consultation_session.php?id=' + id +
        '" class="mc-btn mc-btn--primary" style="padding:4px 12px;font-size:10px;white-space:nowrap;">Start Session</a>';
    } else {
      action = '<button type="button" class="mc-btn mc-btn--outline queue-open-session-blocked" style="padding:4px 12px;font-size:10px;white-space:nowrap;opacity:.65;" data-reason="' +
        esc(item.session_reason || '') + '" title="' + esc(item.session_reason || '') + '">Start Session</button>';
    }

    return '<tr>' +
      '<td style="font-weight:700;">' + esc(item.patient_name || 'Patient') + '</td>' +
      '<td class="text-muted">' + esc(item.complaint || 'General Consultation') + '</td>' +
      '<td><span style="background:' + urgBg + ';color:' + urgColor + ';padding:4px 8px;border-radius:6px;font-size:10px;font-weight:700;text-transform:uppercase;">' +
        esc(item.urgency || 'Routine') + '</span></td>' +
      '<td style="font-size:12px;white-space:nowrap;"><div style="font-weight:700;">' + esc(schedDate) + '</div>' +
        (schedTime ? '<div class="text-muted" style="font-size:11px;">' + esc(schedTime) + '</div>' : '') +
      '</td>' +
      '<td>' + action + '</td>' +
      '</tr>';
  }

  function updateQueue(queue) {
    var badge = document.querySelector('[data-live-queue-count]');
    var host = document.querySelector('[data-live-queue]');
    if (!host) return;
    var list = Array.isArray(queue) ? queue : [];
    if (badge) badge.textContent = list.length + ' pending';

    if (!list.length) {
      host.innerHTML =
        '<div class="mc-table-empty">' +
        '<p>No pending consultations in your queue.</p>' +
        '<a href="' + esc(assetBase()) + '/views/provider/queue.php" class="mc-btn mc-btn--outline prov-dash-empty-cta">Open Live Queue</a>' +
        '</div>';
      return;
    }

    host.innerHTML =
      '<div class="table-responsive"><table class="mc-table"><thead><tr>' +
      '<th>Patient</th><th>Complaint</th><th>Priority</th><th>Schedule</th><th></th>' +
      '</tr></thead><tbody>' +
      list.map(queueRowHtml).join('') +
      '</tbody></table></div>';
  }

  function activityIcon() {
    return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>';
  }

  function updateActivity(activity) {
    var host = document.querySelector('[data-live-activity]');
    if (!host) return;
    var list = Array.isArray(activity) ? activity : [];
    if (!list.length) {
      host.innerHTML = '<p class="text-xs text-muted" style="text-align:center;padding:12px 0;margin:0;">No recent activity yet.</p>';
      return;
    }
    host.innerHTML =
      '<div class="prov-activity-list">' +
      list.map(function (act) {
        return '<div class="prov-activity-item">' +
          '<span class="prov-activity-item__icon" aria-hidden="true">' + activityIcon() + '</span>' +
          '<div class="prov-activity-item__body">' +
          '<div class="prov-activity-item__msg">' + esc(act.msg || '') + '</div>' +
          '<div class="prov-activity-item__time">' + esc(act.time || '') + '</div>' +
          '</div></div>';
      }).join('') +
      '</div>';
  }

  function touchSync(updatedAt) {
    var el = document.querySelector('[data-live-sync]');
    if (!el) return;
    var t = updatedAt ? new Date(updatedAt) : new Date();
    if (Number.isNaN(t.getTime())) t = new Date();
    el.textContent = 'Updated ' + t.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
  }

  function fingerprint(payload) {
    try {
      return JSON.stringify({
        s: payload.stats,
        w: payload.week_chart,
        q: (payload.queue || []).map(function (x) { return [x.id, x.raw_status, x.session_allowed]; }),
        a: (payload.activity || []).map(function (x) { return [x.msg, x.time]; }),
      });
    } catch (e) {
      return String(Date.now());
    }
  }

  function apply(payload) {
    if (!payload) return;
    var fp = fingerprint(payload);
    if (fp === lastFingerprint) {
      touchSync(payload.updated_at);
      return;
    }
    lastFingerprint = fp;
    updateMetrics(payload.stats || {});
    updateChart(payload.week_chart || [], payload.week_total || 0);
    updateQueue(payload.queue || []);
    updateActivity(payload.activity || []);
    touchSync(payload.updated_at);

    // Keep sidebar badges in sync when dashboard refreshes.
    if (global.MedConnectProviderNavCounts && typeof global.MedConnectProviderNavCounts.setCounts === 'function') {
      global.MedConnectProviderNavCounts.setCounts({
        queue: (payload.queue || []).length,
        triage: (payload.stats && payload.stats.pending) || 0,
      });
    }

    global.dispatchEvent(new CustomEvent('medconnect:provider-dashboard-live', { detail: payload }));
  }

  async function refresh() {
    if (inFlight) return;
    inFlight = true;
    try {
      var res = await fetch(assetBase() + API_PATH + '?_=' + Date.now(), {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return;
      var json = await res.json();
      if (!json || !json.success) return;
      apply(json);
    } catch (e) {
      // quiet
    } finally {
      inFlight = false;
    }
  }

  function start() {
    if (timer) return;
    refresh();
    timer = global.setInterval(refresh, POLL_MS);
  }

  function stop() {
    if (!timer) return;
    global.clearInterval(timer);
    timer = null;
  }

  function boot() {
    if (!document.querySelector('.prov-dash[data-live-dashboard]')) return;
    start();
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) stop();
      else start();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  global.MedConnectProviderDashboardLive = {
    refresh: refresh,
    start: start,
    stop: stop,
  };
})(window);
