<?php
$page_title = 'Appointment History';
$bhw_current_file = 'followup/track.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require_once BASE_PATH . '/app/includes/bhw_workflows.php';

$bhwCtx = [
    'barangay_id' => (int) $bhw_barangay_id,
    'barangay_name' => $bhw_barangay_name,
    'allowed' => empty($bhw_no_sector),
];
// Server-render for this BHW's barangay so every account sees live data immediately.
$initialQueue = [];
try {
    $initialQueue = BhwWorkflows::listAppointmentFollowupQueue($pdo, $bhwCtx);
} catch (Throwable $e) {
    $initialQueue = [];
}

require __DIR__ . '/../partials/layout_open.php';
?>
<div class="bhw-followup-page bhw-afu-queue" id="bhwAfuQueueRoot"
     data-barangay-id="<?= (int) $bhw_barangay_id ?>">
  <header class="bhw-followup-header bhw-page-intro">
    <p class="bhw-page-intro__desc">Live queue of appointments and follow-ups for residents in your assigned barangay.</p>
  </header>

  <div class="bhw-card bhw-followup-card">
    <div class="bhw-followup-toolbar bhw-afu-toolbar">
      <div class="bhw-afu-tabs" role="tablist" aria-label="Appointment history">
        <button type="button" class="bhw-afu-tab is-active" data-afu-tab="all" role="tab" aria-selected="true">All</button>
        <button type="button" class="bhw-afu-tab" data-afu-tab="appointments" role="tab" aria-selected="false">Appointments</button>
        <button type="button" class="bhw-afu-tab" data-afu-tab="followups" role="tab" aria-selected="false">Follow-Ups</button>
      </div>
      <div class="bhw-dash-search bhw-afu-search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" id="bhwAfuSearch" placeholder="Search patient..." aria-label="Search patient">
      </div>
      <span class="bhw-afu-live" id="bhwAfuLive" aria-live="polite">Live</span>
    </div>
    <div id="bhwAfuAppointments" class="table-responsive bhw-followup-table-wrap">
      <table class="table bhw-table bhw-followup-table bhw-afu-table mb-0">
        <thead>
          <tr>
            <th scope="col">Patient ID</th>
            <th scope="col">Patient Name</th>
            <th scope="col">Appointment Date</th>
            <th scope="col">Appointment Time</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody id="bhwAfuBody">
          <tr><td colspan="5" class="bhw-followup-empty">Loading appointments…</td></tr>
        </tbody>
      </table>
    </div>
    <div id="bhwAfuFollowups" class="bhw-afu-followups" hidden></div>
  </div>
</div>

<div id="bhwAfuDetail" class="bhw-afu-modal" hidden>
  <div class="bhw-afu-modal__backdrop" data-afu-close></div>
  <div class="bhw-afu-modal__panel" role="dialog" aria-modal="true" aria-labelledby="bhwAfuDetailTitle">
    <div class="bhw-afu-modal__head">
      <h2 id="bhwAfuDetailTitle">Follow-up details</h2>
      <button type="button" class="bhw-afu-modal__close" data-afu-close aria-label="Close">Close</button>
    </div>
    <div id="bhwAfuDetailBody" class="bhw-afu-modal__body"></div>
  </div>
</div>
<script type="application/json" id="bhwAfuInitialQueue"><?= json_encode($initialQueue, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>

<?php
ob_start();
?>
(function () {
  var tbody = document.getElementById('bhwAfuBody');
  var followupsEl = document.getElementById('bhwAfuFollowups');
  var appointmentsEl = document.getElementById('bhwAfuAppointments');
  var searchEl = document.getElementById('bhwAfuSearch');
  var liveEl = document.getElementById('bhwAfuLive');
  var initialEl = document.getElementById('bhwAfuInitialQueue');
  var detailEl = document.getElementById('bhwAfuDetail');
  var detailBody = document.getElementById('bhwAfuDetailBody');
  var allRows = [];
  var pollTimer = null;
  var loading = false;
  var lastFp = '';
  var activeTab = 'all';
  var sending = {};

  try {
    allRows = initialEl ? (JSON.parse(initialEl.textContent || '[]') || []) : [];
  } catch (e) {
    allRows = [];
  }
  lastFp = stableFp(allRows);

  function stableFp(value) {
    try { return JSON.stringify(value || null); } catch (e) { return ''; }
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function statusBadge(row) {
    var key = String(row.status_key || 'unknown').toLowerCase().replace(/\s+/g, '_');
    var label = row.status || '—';
    return '<span class="bhw-fu-status bhw-fu-status--' + esc(key) + '">' + esc(label) + '</span>';
  }

  function matchesSearch(row, q) {
    if (!q) return true;
    var name = String(row.patient_name || '').toLowerCase();
    var id = String(row.patient_id || '');
    return name.indexOf(q) !== -1 || id.indexOf(q) !== -1;
  }

  function setLive(state) {
    if (!liveEl) return;
    liveEl.textContent = state === 'error' ? 'Offline' : (state === 'updating' ? 'Updating…' : 'Live');
    liveEl.className = 'bhw-afu-live' + (state === 'error' ? ' is-offline' : '');
  }

  function applyTab() {
    var showAppointments = activeTab === 'all' || activeTab === 'appointments';
    var showFollowups = activeTab === 'all' || activeTab === 'followups';
    if (appointmentsEl) appointmentsEl.hidden = !showAppointments;
    if (followupsEl) followupsEl.hidden = !showFollowups;
    document.querySelectorAll('[data-afu-tab]').forEach(function (btn) {
      var on = btn.getAttribute('data-afu-tab') === activeTab;
      btn.classList.toggle('is-active', on);
      btn.setAttribute('aria-selected', on ? 'true' : 'false');
    });
  }

  function followupCard(row) {
    var id = parseInt(row.followup_id, 10) || 0;
    var doctor = row.provider_name && row.provider_name !== '—' ? row.provider_name : '';
    var doctorLine = doctor ? ('Dr. ' + doctor.replace(/^dr\.?\s+/i, '')) : '—';
    var reason = String(row.instructions || '').trim();
    var canRemind = row.followup_group === 'upcoming' && id > 0;
    var remindBtn = canRemind
      ? '<button type="button" class="bhw-btn-teal bhw-afu-remind" data-followup-id="' + id + '"' +
        (sending[id] ? ' disabled' : '') + '>' + (sending[id] ? 'Sending…' : 'Send Reminder') + '</button>'
      : '';
    return '<article class="bhw-afu-card" data-followup-id="' + id + '">' +
      '<div class="bhw-afu-card__main">' +
        '<div class="bhw-afu-card__who">' +
          '<h3 class="bhw-afu-card__name">' + esc(row.patient_name || '—') + '</h3>' +
          '<p class="bhw-afu-card__kind">Follow-up #' + esc(id || '—') + '</p>' +
        '</div>' +
        '<p class="bhw-afu-card__meta"><span>When</span>' + esc(row.when_label || row.appointment_date || 'Date TBD') + '</p>' +
        '<p class="bhw-afu-card__meta"><span>Doctor</span>' + esc(doctorLine) + '</p>' +
        '<p class="bhw-afu-card__meta bhw-afu-card__meta--status"><span>Status</span>' + statusBadge(row) + '</p>' +
      '</div>' +
      (reason ? '<p class="bhw-afu-card__reason">' + esc(reason) + '</p>' : '') +
      '<div class="bhw-afu-card__actions">' +
        '<button type="button" class="bhw-btn-outline bhw-afu-view" data-followup-id="' + id + '">View Details</button>' +
        remindBtn +
      '</div>' +
    '</article>';
  }

  function renderFollowups(rows) {
    if (!followupsEl) return;
    var groups = [
      { key: 'upcoming', title: 'Upcoming' },
      { key: 'pending', title: 'Pending' },
      { key: 'past', title: 'Past / Missed' },
      { key: 'completed', title: 'Completed' }
    ];
    var html = '';
    groups.forEach(function (group) {
      var items = rows.filter(function (row) {
        return row.source === 'followup' && (row.followup_group || 'upcoming') === group.key;
      }).sort(function (a, b) {
        return String(a.sort_key || '').localeCompare(String(b.sort_key || ''));
      });
      if (!items.length) return;
      html += '<section class="bhw-afu-group"><h3 class="bhw-afu-group__title">' + esc(group.title) + '</h3>' +
        items.map(followupCard).join('') + '</section>';
    });
    followupsEl.innerHTML = html || '<p class="bhw-followup-empty">No follow-ups in your barangay yet.</p>';
  }

  function render() {
    var q = (searchEl && searchEl.value ? searchEl.value : '').trim().toLowerCase();
    var rows = allRows.filter(function (r) { return matchesSearch(r, q); });
    var appointments = rows.filter(function (r) { return r.source !== 'followup'; });
    applyTab();
    if (!appointments.length) {
      tbody.innerHTML = '<tr><td colspan="5" class="bhw-followup-empty">' +
        (rows.length ? 'No matching appointments.' : 'No appointments in your barangay yet.') +
        '</td></tr>';
    } else {
      tbody.innerHTML = appointments.map(function (r) {
        return '<tr>' +
          '<td data-label="Patient ID">#' + esc(r.patient_id || '—') + '</td>' +
          '<td data-label="Patient Name"><span class="bhw-fu-patient-name">' + esc(r.patient_name || '—') + '</span></td>' +
          '<td data-label="Appointment Date">' + esc(r.appointment_date || '—') + '</td>' +
          '<td data-label="Appointment Time">' + esc(r.appointment_time || '—') + '</td>' +
          '<td data-label="Status">' + statusBadge(r) + '</td>' +
        '</tr>';
      }).join('');
    }
    renderFollowups(rows);
  }

  function closeDetail() {
    if (!detailEl) return;
    detailEl.hidden = true;
  }

  function detailItem(label, value, wide, valueClass) {
    return '<div class="bhw-afu-detail__item' + (wide ? ' bhw-afu-detail__item--wide' : '') + '">' +
      '<span class="bhw-afu-detail__label">' + esc(label) + '</span>' +
      '<span class="bhw-afu-detail__value' + (valueClass ? ' ' + valueClass : '') + '">' + esc(value || '—') + '</span>' +
    '</div>';
  }

  function openDetail(followupId) {
    if (!followupId || typeof BhwPortal === 'undefined') return;
    if (detailEl) detailEl.hidden = false;
    if (detailBody) detailBody.innerHTML = '<p class="bhw-followup-empty">Loading follow-up #' + esc(followupId) + '…</p>';
    BhwPortal.get('followups.php', { action: 'get', followup_id: followupId }).then(function (r) {
      if (!r.success || !r.followup || parseInt(r.followup.id, 10) !== followupId) {
        if (detailBody) {
          detailBody.innerHTML = '<p class="bhw-followup-empty bhw-followup-empty--error">' +
            esc((r && r.message) || 'Could not open this follow-up.') + '</p>';
        }
        return;
      }
      var f = r.followup;
      var storedStatus = String(f.status || '').toLowerCase();
      var statusLabel = f.display_status || f.status || '—';
      if (storedStatus === 'scheduled' && String(statusLabel).toLowerCase() === 'upcoming') {
        statusLabel = 'Scheduled';
      }
      var when = f.followup_datetime_label || f.followup_date || 'Date TBD';
      var doctor = f.provider_name ? ('Dr. ' + String(f.provider_name).replace(/^dr\.?\s+/i, '')) : '—';
      var reason = String(f.message || f.notes || '').trim();
      var visits = Array.isArray(r.visits) ? r.visits : [];
      var visitHtml = visits.length
        ? '<ul class="bhw-afu-visits">' + visits.map(function (v) {
            return '<li>' + esc(v.visit_date || '—') + ' · ' + esc(v.visit_type || 'visit') +
              (v.patient_status ? ' · ' + esc(v.patient_status) : '') + '</li>';
          }).join('') + '</ul>'
        : '<p class="bhw-afu-detail__line">No home visits yet</p>';
      if (!detailBody) return;
      var statusKey = String(f.display_status || statusLabel || 'unknown').toLowerCase().replace(/\s+/g, '_');
      detailBody.innerHTML =
        '<div class="bhw-afu-detail__hero">' +
          '<div>' +
            '<h3 class="bhw-afu-detail__name">' + esc(f.patient_name || '—') + '</h3>' +
            '<p class="bhw-afu-detail__chip">Follow-up #' + esc(f.id || followupId) + '</p>' +
          '</div>' +
          '<span class="bhw-fu-status bhw-fu-status--' + esc(statusKey) + '">' + esc(statusLabel) + '</span>' +
        '</div>' +
        '<div class="bhw-afu-detail__grid">' +
          detailItem('Patient', f.patient_name) +
          detailItem('Doctor', doctor) +
          detailItem('When', when) +
          detailItem('Status', statusLabel, false, statusKey === 'missed' ? 'is-missed' : '') +
          detailItem('Consultation #', f.consultation_id ? ('#' + f.consultation_id) : '—') +
          detailItem('Ref #', '#' + (f.id || followupId)) +
          detailItem('Reason/Instructions', reason || '—', true) +
        '</div>' +
        '<h3 class="bhw-afu-detail__visits">Home visits</h3>' + visitHtml;
    }).catch(function () {
      if (detailBody) {
        detailBody.innerHTML = '<p class="bhw-followup-empty bhw-followup-empty--error">Could not open this follow-up.</p>';
      }
    });
  }

  function sendReminder(followupId, btn) {
    if (!followupId || sending[followupId] || typeof BhwPortal === 'undefined') return;
    sending[followupId] = true;
    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Sending…';
    }
    BhwPortal.post('followups.php', { action: 'remind', followup_id: followupId }).then(function (res) {
      sending[followupId] = false;
      if (window.BhwPortal && BhwPortal.toast) BhwPortal.toast(res.message, res.success);
      if (btn) {
        btn.textContent = res.success ? 'Sent' : 'Send Reminder';
        btn.disabled = !!res.success;
      }
    }).catch(function () {
      sending[followupId] = false;
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Send Reminder';
      }
      if (window.BhwPortal && BhwPortal.toast) BhwPortal.toast('Could not send reminder. Please try again.', false);
    });
  }

  function loadQueue(silent) {
    if (typeof BhwPortal === 'undefined' || !BhwPortal.get) {
      setLive('error');
      return;
    }
    if (loading) return;
    loading = true;
    if (!silent) setLive('updating');
    BhwPortal.get('followups.php', { action: 'queue' }).then(function (r) {
      loading = false;
      if (!r.success) {
        setLive('error');
        if (!silent && !allRows.length) {
          tbody.innerHTML = '<tr><td colspan="5" class="bhw-followup-empty bhw-followup-empty--error">' +
            esc(r.message || 'Could not load queue.') + '</td></tr>';
        }
        return;
      }
      var next = r.queue || [];
      var fp = stableFp(next);
      if (fp !== lastFp) {
        lastFp = fp;
        allRows = next;
        render();
      }
      setLive('live');
      if (window.MedConnectNavBadgesRefresh) window.MedConnectNavBadgesRefresh();
    }).catch(function () {
      loading = false;
      setLive('error');
      if (!silent && !allRows.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="bhw-followup-empty bhw-followup-empty--error">Could not load queue.</td></tr>';
      }
    });
  }

  if (searchEl) {
    searchEl.addEventListener('input', render);
  }

  document.querySelectorAll('[data-afu-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      activeTab = btn.getAttribute('data-afu-tab') || 'all';
      applyTab();
    });
  });

  document.addEventListener('click', function (ev) {
    var viewBtn = ev.target.closest ? ev.target.closest('.bhw-afu-view') : null;
    var remindBtn = ev.target.closest ? ev.target.closest('.bhw-afu-remind') : null;
    var closeBtn = ev.target.closest ? ev.target.closest('[data-afu-close]') : null;
    if (viewBtn) {
      openDetail(parseInt(viewBtn.getAttribute('data-followup-id'), 10) || 0);
    } else if (remindBtn) {
      sendReminder(parseInt(remindBtn.getAttribute('data-followup-id'), 10) || 0, remindBtn);
    } else if (closeBtn) {
      closeDetail();
    }
  });

  render();

  // Keep every BHW barangay queue live: poll + hub + tab focus.
  loadQueue(true);
  pollTimer = window.setInterval(function () {
    if (document.hidden) return;
    if (window.MedConnectLiveSync && Date.now() - (window.MedConnectLiveSync.lastHubAt() || 0) < 3000) return;
    loadQueue(true);
  }, 8000);

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) loadQueue(true);
  });

  document.addEventListener('medconnect:live-sync', function (ev) {
    var changed = (ev.detail && ev.detail.changed) || [];
    if (
      changed.indexOf('appointments') !== -1 ||
      changed.indexOf('queue') !== -1 ||
      changed.indexOf('consultations') !== -1 ||
      changed.indexOf('followups') !== -1 ||
      changed.indexOf('triage') !== -1
    ) {
      loadQueue(true);
    }
  });

  window.addEventListener('beforeunload', function () {
    if (pollTimer) window.clearInterval(pollTimer);
  });
})();
<?php
$bhw_inline_script = ob_get_clean();
require __DIR__ . '/../partials/layout_close.php';
?>
