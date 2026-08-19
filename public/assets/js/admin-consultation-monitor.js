/**
 * Admin Consultation Monitoring — tab switch + live consultation table.
 */
(function () {
  'use strict';

  var root = document.getElementById('cmMonitor');
  var livePanel = document.getElementById('liveConsultPanel');
  var liveBody = document.getElementById('liveConsultBody');
  var liveStamp = document.getElementById('liveConsultUpdated');
  var liveTimer = null;
  var COLSPAN = 7;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function showTab(name, updateUrl) {
    if (!root) return;
    var tabs = root.querySelectorAll('[data-cm-tab]');
    var panels = root.querySelectorAll('[data-cm-panel]');
    tabs.forEach(function (tab) {
      var on = tab.getAttribute('data-cm-tab') === name;
      tab.classList.toggle('is-active', on);
      if (on) tab.setAttribute('aria-current', 'page');
      else tab.removeAttribute('aria-current');
    });
    panels.forEach(function (panel) {
      panel.hidden = panel.getAttribute('data-cm-panel') !== name;
    });
    if (updateUrl && typeof history.replaceState === 'function') {
      try {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', name);
        history.replaceState({}, '', url);
      } catch (_) { /* ignore */ }
    }
    if (name === 'live') loadLive();
  }

  function loadLive() {
    if (!livePanel || !liveBody) return;
    var api = livePanel.getAttribute('data-api');
    if (!api) return;
    fetch(api, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-MC-No-Loader': '1' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.success || !j.rows || !j.rows.length) {
          liveBody.innerHTML = '<tr><td colspan="' + COLSPAN + '"><div class="mc-table-empty"><p>No active consultations right now.</p></div></td></tr>';
        } else {
          liveBody.innerHTML = j.rows.map(function (row) {
            return '<tr>' +
              '<td data-label="Patient"><strong>' + esc(row.patient_name) + '</strong></td>' +
              '<td data-label="Provider">' + esc(row.provider_name) + '</td>' +
              '<td data-label="Consultation status"><span class="mc-badge">' + esc(row.status) + '</span></td>' +
              '<td data-label="Start time">' + esc(row.started_label) + '</td>' +
              '<td data-label="Duration">' + esc(row.duration_label || '—') + '</td>' +
              '<td data-label="Connection">' + esc(row.connection || '—') + '</td>' +
              '<td data-label="Urgency">' + esc(row.urgency_label || '—') + '</td>' +
              '</tr>';
          }).join('');
        }
        if (liveStamp) {
          liveStamp.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) + ' · ' + (j.count || 0) + ' active';
        }
      })
      .catch(function () {
        if (liveStamp) liveStamp.textContent = 'Could not refresh live consultations.';
      });
  }

  if (root) {
    root.querySelectorAll('[data-cm-tab]').forEach(function (tab) {
      tab.addEventListener('click', function (e) {
        var name = tab.getAttribute('data-cm-tab');
        if (!name) return;
        e.preventDefault();
        showTab(name, true);
      });
    });
  }

  loadLive();
  liveTimer = window.setInterval(function () {
    if (document.hidden) return;
    loadLive();
  }, 30000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) loadLive();
  });
})();
