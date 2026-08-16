(function () {
  'use strict';

  var root = document.getElementById('caseReportsRoot');
  if (!root) return;

  var api = root.dataset.api || '';
  var isSuperadmin = root.dataset.superadmin === '1';
  var deepLink = Number(root.dataset.deepLink || 0);
  var reports = [];

  function csrfToken() {
    return (document.body && document.body.dataset.csrf) || '';
  }

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function statusBadge(status, type) {
    var cls = 'cr-badge cr-badge--' + String(status || 'pending').replace(/\s+/g, '_');
    var label = String(status || '').replace(/_/g, ' ');
    return '<span class="' + cls + '">' + esc(label) + '</span>';
  }

  function formatDate(value) {
    if (!value) return '—';
    var d = new Date(value.replace(' ', 'T'));
    if (isNaN(d.getTime())) return esc(value);
    return d.toLocaleString();
  }

  function renderTable() {
    var body = document.getElementById('crReportsBody');
    if (!body) return;
    if (!reports.length) {
      body.innerHTML = '<tr><td colspan="8" class="staff-apps-empty">No violation reports found.</td></tr>';
      return;
    }
    body.innerHTML = reports.map(function (r) {
      var source = r.source_label || r.source_type || 'Case';
      var statusLabel = r.status || 'pending';
      var consultRef = r.consultation_id ? ('#' + r.consultation_id) : (r.triage_id ? ('Case #' + r.triage_id) : '—');
      var entityStatus = r.source_type === 'video_consultation'
        ? (r.consultation_status_display || r.consultation_status || '—')
        : (r.case_terminated ? 'terminated' : (r.triage_status || 'active'));
      return '<tr>'
        + '<td data-label="Patient">' + esc(r.patient_name) + '<br><small>ID ' + esc(r.patient_id) + '</small></td>'
        + '<td data-label="Provider">' + esc(r.reporter_name) + '</td>'
        + '<td data-label="Source">' + esc(source) + '</td>'
        + '<td data-label="Consultation">' + esc(consultRef) + '</td>'
        + '<td data-label="Reason">' + esc(r.reason_label || r.reason) + '</td>'
        + '<td data-label="Date">' + formatDate(r.created_at) + '</td>'
        + '<td data-label="Status">' + statusBadge(statusLabel, 'report') + '<br><small>' + esc(entityStatus) + '</small></td>'
        + '<td data-label="Actions"><button type="button" class="mc-btn mc-btn--outline mc-btn--sm" data-cr-view="' + esc(r.id) + '">View Report</button></td>'
        + '</tr>';
    }).join('');
  }

  async function apiGet(params) {
    var url = api + '?' + new URLSearchParams(params).toString();
    var res = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    return res.json();
  }

  async function apiPost(body) {
    var res = await fetch(api, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      body: new URLSearchParams(body),
    });
    return res.json();
  }

  async function loadReports() {
    var filter = document.getElementById('crStatusFilter');
    var status = filter ? filter.value : 'all';
    var data = await apiGet({ action: 'list', status: status });
    if (!data || !data.success) return;
    reports = data.reports || [];
    renderTable();
  }

  function renderDetail(report) {
    var body = document.getElementById('crDetailBody');
    var footer = document.getElementById('crDetailFooter');
    if (!body || !footer) return;

    body.innerHTML = ''
      + '<section class="cr-section"><h3>Patient</h3>'
      + '<p class="cr-kv"><strong>' + esc(report.patient_name) + '</strong> · ID ' + esc(report.patient_id) + '</p>'
      + '<p class="cr-kv">Account: ' + statusBadge(report.patient_account_status, 'account') + '</p></section>'
      + '<section class="cr-section"><h3>Report</h3>'
      + '<p class="cr-kv"><strong>Source:</strong> ' + esc(report.source_label || report.source_type || 'Case') + '</p>'
      + '<p class="cr-kv"><strong>Reporting provider:</strong> ' + esc(report.reporter_name) + '</p>'
      + '<p class="cr-kv"><strong>Possible violation:</strong> ' + esc(report.reason_label || report.reason) + '</p>'
      + '<p class="cr-kv"><strong>Provider notes:</strong> ' + esc(report.notes || '—') + '</p>'
      + '<p class="cr-kv"><strong>Reported:</strong> ' + formatDate(report.created_at) + '</p>'
      + '<p class="cr-kv"><strong>Report status:</strong> ' + statusBadge(report.status, 'report') + '</p></section>'
      + (report.source_type === 'video_consultation'
        ? '<section class="cr-section"><h3>Video consultation</h3>'
          + '<p class="cr-kv"><strong>Consultation ID:</strong> ' + esc(report.consultation_id || '—') + '</p>'
          + '<p class="cr-kv"><strong>Status at report:</strong> ' + esc(report.consultation_status_at_report || report.consultation_status || '—') + '</p>'
          + (report.appointment_id ? '<p class="cr-kv"><strong>Appointment ID:</strong> ' + esc(report.appointment_id) + '</p>' : '')
          + '</section>'
        : '<section class="cr-section"><h3>Case</h3>'
          + '<p class="cr-kv"><strong>Case ID:</strong> ' + esc(report.triage_id || '—') + '</p>'
          + '<p class="cr-kv"><strong>Chief complaint:</strong> ' + esc(report.chief_complaint || '—') + '</p>'
          + '<p class="cr-kv"><strong>AI classification:</strong> ' + esc(report.triage_level || report.triage_classification || '—') + '</p>'
          + '<p class="cr-kv"><strong>Submitted:</strong> ' + formatDate(report.assessed_at) + '</p>'
          + '<p class="cr-kv"><strong>Case status:</strong> ' + statusBadge(report.case_terminated ? 'terminated' : report.triage_status, 'case') + '</p>'
          + '</section>');

    var canReview = ['pending', 'under_review', 'escalated'].indexOf(String(report.status)) >= 0;
    footer.innerHTML = '';
    if (canReview) {
      footer.innerHTML += '<button type="button" class="mc-btn mc-btn--ghost" data-cr-action="dismiss" data-id="' + esc(report.id) + '">Dismiss Report</button>';
      footer.innerHTML += '<button type="button" class="mc-btn mc-btn--primary" data-cr-action="confirm" data-id="' + esc(report.id) + '">Confirm Violation</button>';
      if (isSuperadmin) {
        footer.innerHTML += '<button type="button" class="mc-btn mc-btn--outline" data-cr-action="escalate" data-id="' + esc(report.id) + '">Escalate</button>';
      }
    }
    if (String(report.status) === 'confirmed' || String(report.status) === 'escalated') {
      footer.innerHTML += '<button type="button" class="mc-btn mc-btn--outline" data-cr-restrict="' + esc(report.patient_id) + '" data-report="' + esc(report.id) + '">Restrict Patient</button>';
      if (isSuperadmin) {
        footer.innerHTML += '<button type="button" class="mc-btn mc-btn--danger-outline" data-cr-suspend="' + esc(report.patient_id) + '" data-report="' + esc(report.id) + '">Suspend Patient</button>';
        footer.innerHTML += '<button type="button" class="mc-btn mc-btn--outline" data-cr-restore="' + esc(report.patient_id) + '" data-report="' + esc(report.id) + '">Restore Patient</button>';
      }
    }
    footer.innerHTML += '<button type="button" class="mc-btn mc-btn--ghost" data-cr-close>Close</button>';
  }

  async function openDetail(reportId) {
    var data = await apiGet({ action: 'detail', id: String(reportId) });
    if (!data || !data.success || !data.report) {
      alert((data && data.message) || 'Could not load report.');
      return;
    }
    renderDetail(data.report);
    var modal = document.getElementById('crDetailModal');
    if (modal) {
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
    }
  }

  function closeDetail() {
    var modal = document.getElementById('crDetailModal');
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
  }

  async function reviewAction(action, reportId) {
    var note = '';
    if (action === 'dismiss' || action === 'confirm') {
      note = window.prompt('Optional administrator note (not shown to patient):', '') || '';
    }
    var data = await apiPost({
      action: action,
      report_id: String(reportId),
      admin_note: note,
      csrf_token: csrfToken(),
    });
    if (!data || !data.success) {
      alert((data && data.message) || 'Action failed.');
      return;
    }
    alert(data.message || 'Updated.');
    closeDetail();
    loadReports();
  }

  async function accountAction(action, patientId, reportId) {
    var reason = window.prompt('Reason for this account action (required):', '');
    if (!reason || reason.trim().length < 5) {
      alert('A reason of at least 5 characters is required.');
      return;
    }
    var data = await apiPost({
      action: action,
      patient_id: String(patientId),
      report_id: String(reportId),
      reason: reason.trim(),
      csrf_token: csrfToken(),
    });
    if (!data || !data.success) {
      alert((data && data.message) || 'Account action failed.');
      return;
    }
    alert(data.message || 'Account updated.');
    openDetail(reportId);
    loadReports();
  }

  document.getElementById('crStatusFilter')?.addEventListener('change', loadReports);

  document.getElementById('crReportsBody')?.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-cr-view]');
    if (!btn) return;
    openDetail(btn.getAttribute('data-cr-view'));
  });

  document.getElementById('crDetailModal')?.addEventListener('click', function (e) {
    if (e.target.id === 'crDetailModal' || e.target.closest('[data-cr-close]')) {
      closeDetail();
    }
    var actionBtn = e.target.closest('[data-cr-action]');
    if (actionBtn) {
      reviewAction(actionBtn.getAttribute('data-cr-action'), actionBtn.getAttribute('data-id'));
      return;
    }
    var restrictBtn = e.target.closest('[data-cr-restrict]');
    if (restrictBtn) {
      accountAction('restrict_patient', restrictBtn.getAttribute('data-cr-restrict'), restrictBtn.getAttribute('data-report'));
      return;
    }
    var suspendBtn = e.target.closest('[data-cr-suspend]');
    if (suspendBtn) {
      accountAction('suspend_patient', suspendBtn.getAttribute('data-cr-suspend'), suspendBtn.getAttribute('data-report'));
      return;
    }
    var restoreBtn = e.target.closest('[data-cr-restore]');
    if (restoreBtn) {
      accountAction('restore_patient', restoreBtn.getAttribute('data-cr-restore'), restoreBtn.getAttribute('data-report'));
    }
  });

  loadReports().then(function () {
    if (deepLink > 0) openDetail(deepLink);
  });
})();
