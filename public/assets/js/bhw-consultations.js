/**
 * BHW Consultation Center — live schedule, status, and video assist.
 */
(function () {
  'use strict';

  var root = document.getElementById('bhwConsRoot');
  if (!root || typeof window.BhwPortal === 'undefined') return;

  var base = document.body.dataset.assetBase || '';
  var dateEl = document.getElementById('bhwConsDate');
  var statusEl = document.getElementById('bhwConsStatus');
  var searchEl = document.getElementById('bhwConsSearch');
  var tbody = document.getElementById('bhwConsBody');
  var refreshBtn = document.getElementById('bhwConsRefresh');
  var lastSyncEl = document.getElementById('bhwConsLastSync');
  var pollTimer = null;
  var allRows = [];

  var metricEls = {
    total: document.getElementById('bhwConsMetricTotal'),
    active: document.getElementById('bhwConsMetricActive'),
    live: document.getElementById('bhwConsMetricLive'),
    done: document.getElementById('bhwConsMetricDone'),
  };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatTime(t) {
    if (!t) return '—';
    var parts = String(t).split(':');
    if (parts.length < 2) return t;
    var h = parseInt(parts[0], 10);
    var m = parts[1];
    if (Number.isNaN(h)) return t;
    var ampm = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12;
    if (h12 === 0) h12 = 12;
    return h12 + ':' + m + ' ' + ampm;
  }

  function statusBadge(status) {
    var s = (status || '').toLowerCase();
    var cls = 'bhw-badge-scheduled';
    var label = status || '—';
    if (s === 'in_consultation') {
      cls = 'bhw-badge-ready';
      label = 'In call';
    } else if (s === 'completed') {
      cls = 'bhw-badge-low';
      label = 'Completed';
    } else if (s === 'cancelled') {
      cls = 'bhw-badge-high';
      label = 'Cancelled';
    } else if (s === 'scheduled') {
      label = 'Scheduled';
    }
    return '<span class="bhw-badge ' + cls + '">' + esc(label) + '</span>';
  }

  function consentBadge(c) {
    if (c.teleconsult_consent === '1' || c.teleconsult_consent === 1 || c.teleconsult_consent === true) {
      return '<span class="bhw-badge bhw-badge-low" title="Consent recorded">Yes</span>';
    }
    return '<span class="bhw-cons-action-muted">No</span>';
  }

  function canAssist(c) {
    return !!(c.room_token && (c.status === 'in_consultation' || c.status === 'scheduled'));
  }

  function actionCell(c) {
    if (canAssist(c)) {
      var link = base + '/views/consultation/video_room.php?token=' + encodeURIComponent(c.room_token);
      return '<a class="bhw-btn-teal" href="' + link + '" target="_blank" rel="noopener">Assist Video</a>';
    }
    if (c.status === 'scheduled') {
      return '<span class="bhw-cons-action-muted">Waiting for provider</span>';
    }
    if (c.status === 'completed') {
      return '<span class="bhw-cons-action-muted">Finished</span>';
    }
    return '<span class="bhw-cons-action-muted">—</span>';
  }

  function filterRows(rows) {
    var q = (searchEl && searchEl.value ? searchEl.value : '').toLowerCase().trim();
    if (!q) return rows;
    return rows.filter(function (c) {
      var hay = ((c.patient_name || '') + ' ' + (c.provider_name || '')).toLowerCase();
      return hay.indexOf(q) >= 0;
    });
  }

  function setLoading() {
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="bhw-cons-loading"><span class="bhw-cons-spin" aria-hidden="true"></span>Loading consultations…</td></tr>';
  }

  function setError(message) {
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="bhw-cons-error"><strong>Could not load consultations</strong>' + esc(message || 'Please try again.') + '</td></tr>';
  }

  function updateMetrics(summary) {
    if (!summary) return;
    if (metricEls.total) metricEls.total.textContent = summary.total != null ? summary.total : '0';
    if (metricEls.active) metricEls.active.textContent = summary.active != null ? summary.active : '0';
    if (metricEls.live) metricEls.live.textContent = summary.in_consultation != null ? summary.in_consultation : '0';
    if (metricEls.done) metricEls.done.textContent = summary.completed != null ? summary.completed : '0';
  }

  function renderTable(rows) {
    var visible = filterRows(rows);
    if (!visible.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="bhw-cons-empty"><strong>No consultations found</strong>Try another date, status filter, or search term. You can also book via Triage &amp; Book.</td></tr>';
      return;
    }

    tbody.innerHTML = visible.map(function (c) {
      var live = c.status === 'in_consultation' ? ' bhw-cons-row--live' : '';
      return '<tr class="' + live + '">' +
        '<td><strong>' + formatTime(c.consult_time) + '</strong></td>' +
        '<td><strong>' + esc(c.patient_name || '—') + '</strong></td>' +
        '<td>' + esc(c.provider_name || '—') + '</td>' +
        '<td>' + statusBadge(c.status) + '</td>' +
        '<td>' + consentBadge(c) + '</td>' +
        '<td>' + actionCell(c) + '</td>' +
        '</tr>';
    }).join('');
  }

  function touchSync() {
    if (lastSyncEl) {
      lastSyncEl.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
    }
  }

  function loadConsultations() {
    if (!dateEl || !statusEl) return;
    setLoading();
    if (refreshBtn) refreshBtn.disabled = true;

    var params = {
      date: dateEl.value,
      status: statusEl.value || '',
    };

    BhwPortal.get('consultations.php', params).then(function (r) {
      if (refreshBtn) refreshBtn.disabled = false;
      if (!r || !r.success) {
        setError((r && r.message) || 'Request failed.');
        return;
      }
      allRows = r.consultations || [];
      updateMetrics(r.summary || {});
      renderTable(allRows);
      touchSync();
    }).catch(function () {
      if (refreshBtn) refreshBtn.disabled = false;
      setError('Network error. Check your connection and refresh.');
    });
  }

  if (dateEl) dateEl.addEventListener('change', loadConsultations);
  if (statusEl) statusEl.addEventListener('change', loadConsultations);
  if (searchEl) searchEl.addEventListener('input', function () { renderTable(allRows); });
  if (refreshBtn) refreshBtn.addEventListener('click', loadConsultations);

  BhwPortal.post('activity.php', { action: 'log', event: 'consultations_viewed' }).catch(function () {});

  loadConsultations();
  pollTimer = window.setInterval(loadConsultations, 30000);

  window.addEventListener('beforeunload', function () {
    if (pollTimer) window.clearInterval(pollTimer);
  });
})();
