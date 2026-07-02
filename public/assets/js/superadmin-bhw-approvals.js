(function () {
  'use strict';

  const cfg = window.MC_BHW_APPROVAL || {};
  const api = cfg.api || '';
  const utils = window.MCStaffApplications || {};
  const currentUserId = cfg.currentUserId || 0;
  const tbody = document.getElementById('bhwApprovalBody');
  const modal = document.getElementById('bhwReviewModal');
  const reviewContent = document.getElementById('bhwReviewContent');
  const approveBtn = document.getElementById('bhwApproveBtn');
  const errorEl = document.getElementById('bhwReviewError');
  const searchInput = document.getElementById('bhwApprovalSearch');
  const statusFilter = document.getElementById('bhwApprovalStatusFilter');
  const countEl = document.getElementById('bhwApprovalCount');
  const statsEl = document.getElementById('bhwApprovalStats');
  const pendingBadge = document.getElementById('bhwPendingBadge');
  const checklistIds = ['check_identity', 'check_barangay', 'check_appointment', 'check_government_id', 'check_no_duplicate'];
  let allRows = [];
  let currentAppId = 0;
  let currentSubmittedBy = 0;

  function showError(message) {
    if (!errorEl) return;
    errorEl.textContent = message || '';
    errorEl.classList.toggle('is-visible', !!message);
  }

  function computeApprovalStats(rows) {
    let pending = 0;
    let docs = 0;
    let ready = 0;
    rows.forEach(function (r) {
      if (r.status === 'pending_approval') {
        pending++;
        if ((parseInt(r.document_count, 10) || 0) >= 2) ready++;
      }
      if (r.status === 'requires_documents') docs++;
    });
    return { total: rows.length, pending: pending, docs: docs, active: ready };
  }

  function updateStatsDisplay(stats, pendingCount) {
    if (statsEl) {
      const map = { statTotal: stats.total, statPending: stats.pending, statDocs: stats.docs, statActive: stats.active };
      Object.keys(map).forEach(function (id) {
        const el = statsEl.querySelector('#' + id);
        if (el) el.textContent = String(map[id]);
      });
    }
    if (pendingBadge) {
      pendingBadge.innerHTML = '<span class="staff-apps-hero__badge-dot" aria-hidden="true"></span>' + (pendingCount || stats.pending) + ' pending';
    }
  }

  function updateApproveState() {
    const allRequired = checklistIds.every(function (id) {
      return document.getElementById(id)?.checked;
    });
    const notOwnSubmission = currentSubmittedBy !== currentUserId;
    approveBtn.disabled = !(allRequired && notOwnSubmission);
    if (currentSubmittedBy === currentUserId) {
      showError('You cannot approve an application you submitted (Maker-Checker separation).');
    } else if (errorEl && errorEl.textContent.indexOf('Maker-Checker') >= 0) {
      showError('');
    }
  }

  checklistIds.forEach(function (id) {
    document.getElementById(id)?.addEventListener('change', updateApproveState);
  });
  document.getElementById('check_cho')?.addEventListener('change', updateApproveState);

  async function loadList() {
    try {
      const res = await fetch(api + '?action=list', { credentials: 'same-origin' });
      const json = await res.json();
      if (!json.success) return;
      allRows = json.data.applications || [];
      updateStatsDisplay(computeApprovalStats(allRows), json.data.pending_count || 0);
      applyFilters();
    } catch (e) {
      if (tbody) tbody.innerHTML = '<tr><td colspan="8"><div class="staff-apps-empty"><p class="staff-apps-empty__title">Could not load queue</p></div></td></tr>';
    }
  }

  function applyFilters() {
    const filtered = utils.filterRows(allRows, {
      search: searchInput ? searchInput.value : '',
      status: statusFilter ? statusFilter.value : 'all',
    });
    if (countEl) {
      const total = allRows.length;
      const shown = filtered.length;
      countEl.textContent = shown === total
        ? shown + ' in queue'
        : 'Showing ' + shown + ' of ' + total;
    }
    renderTable(filtered);
  }

  function renderTable(rows) {
    if (!tbody) return;
    if (!allRows.length) {
      tbody.innerHTML = utils.renderEmptyState(8, {
        title: 'No BHW applications in queue',
        text: 'Pending applications submitted by administrators will appear here for your review.',
      });
      return;
    }
    if (!rows.length) {
      tbody.innerHTML = utils.renderNoResultsRow(8);
      return;
    }
    tbody.innerHTML = rows.map(function (r) {
      return (
        '<tr>' +
        '<td class="staff-apps-td--applicant" data-label="">' + utils.renderApplicantCell(r) + '</td>' +
        '<td data-label="Barangay"><span class="staff-apps-meta">' + utils.esc(r.barangay_name || '—') + '</span></td>' +
        '<td data-label="Appointment"><span class="staff-apps-meta staff-apps-meta--muted">' + utils.esc(utils.formatDate(r.appointment_date)) + '</span></td>' +
        '<td data-label="Documents">' + utils.renderDocBadge(r.document_count, 2) + '</td>' +
        '<td data-label="Submitted By"><span class="staff-apps-meta staff-apps-meta--muted">' + utils.esc(r.submitted_by_name || '—') + '</span></td>' +
        '<td data-label="Submitted"><span class="staff-apps-meta staff-apps-meta--muted">' + utils.esc(utils.formatDate(r.submitted_at)) + '</span></td>' +
        '<td data-label="Status">' + utils.renderStatusBadge(r) + '</td>' +
        '<td class="staff-apps-td--actions" data-label="Actions">' + utils.renderReviewBtn(r.id, 'bhw-review-btn', 'Review') + '</td>' +
        '</tr>'
      );
    }).join('');
    tbody.querySelectorAll('.bhw-review-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openReview(parseInt(btn.dataset.id, 10));
      });
    });
  }

  async function openReview(id) {
    currentAppId = id;
    checklistIds.forEach(function (cid) {
      const el = document.getElementById(cid);
      if (el) el.checked = false;
    });
    const cho = document.getElementById('check_cho');
    if (cho) cho.checked = false;
    showError('');

    const res = await fetch(api + '?action=get&id=' + id, { credentials: 'same-origin' });
    const json = await res.json();
    if (!json.success) return;
    const app = json.data;
    currentSubmittedBy = parseInt(app.submitted_by || 0, 10);

    reviewContent.innerHTML =
      '<dl class="bhw-review-meta">' +
      '<dt>Applicant</dt><dd>' + utils.esc(app.display_name) + '</dd>' +
      '<dt>Email</dt><dd>' + utils.esc(app.email) + '</dd>' +
      '<dt>Mobile</dt><dd>' + utils.esc(app.phone) + '</dd>' +
      '<dt>Barangay</dt><dd>' + utils.esc(app.barangay_name) + '</dd>' +
      '<dt>Appointment Date</dt><dd>' + utils.esc(app.appointment_date) + '</dd>' +
      '<dt>Submitted By</dt><dd>' + utils.esc(app.submitted_by_name || '—') + '</dd>' +
      '</dl>' +
      '<h4 class="admin-form-section-title">Uploaded Documents</h4>' +
      '<ul class="bhw-doc-list">' +
      (app.documents || []).map(function (d) {
        return '<li><span>' + utils.esc(d.document_type.replace(/_/g, ' ')) + ': ' + utils.esc(d.original_name) + '</span>' +
          '<a class="mc-btn mc-btn--outline" style="padding:4px 8px;font-size:11px;" target="_blank" rel="noopener" href="' + api + '?action=download&document_id=' + d.id + '">View</a></li>';
      }).join('') +
      (app.documents && app.documents.length ? '' : '<li class="text-muted">No documents uploaded.</li>') +
      '</ul>';

    document.getElementById('bhwReviewTitle').textContent = 'Review: ' + app.display_name;
    updateApproveState();
    modal.style.display = 'flex';
    modal.style.pointerEvents = 'auto';
  }

  function closeModal() {
    modal.style.display = 'none';
    modal.style.pointerEvents = 'none';
  }

  approveBtn.addEventListener('click', async function () {
    const fd = new FormData();
    fd.append('application_id', currentAppId);
    fd.append('check_identity', document.getElementById('check_identity')?.checked ? '1' : '');
    fd.append('check_barangay', document.getElementById('check_barangay')?.checked ? '1' : '');
    fd.append('check_appointment', document.getElementById('check_appointment')?.checked ? '1' : '');
    fd.append('check_government_id', document.getElementById('check_government_id')?.checked ? '1' : '');
    fd.append('check_cho', document.getElementById('check_cho')?.checked ? '1' : '');
    fd.append('check_no_duplicate', document.getElementById('check_no_duplicate')?.checked ? '1' : '');

    const res = await fetch(api + '?action=approve', { method: 'POST', body: fd, credentials: 'same-origin' });
    const json = await res.json();
    if (!json.success) {
      showError(json.message || 'Approval failed.');
      return;
    }
    window.location.href = window.location.pathname + '?approved=1';
  });

  document.getElementById('bhwRejectBtn')?.addEventListener('click', async function () {
    const reason = prompt('Enter rejection reason:');
    if (!reason || !reason.trim()) return;
    const fd = new FormData();
    fd.append('application_id', currentAppId);
    fd.append('reason', reason.trim());
    const res = await fetch(api + '?action=reject', { method: 'POST', body: fd, credentials: 'same-origin' });
    const json = await res.json();
    if (json.success) window.location.href = window.location.pathname + '?rejected=1';
    else showError(json.message || 'Rejection failed.');
  });

  document.getElementById('bhwRequestDocsBtn')?.addEventListener('click', async function () {
    const note = prompt('Specify additional documents required:');
    if (!note || !note.trim()) return;
    const fd = new FormData();
    fd.append('application_id', currentAppId);
    fd.append('note', note.trim());
    const res = await fetch(api + '?action=request_documents', { method: 'POST', body: fd, credentials: 'same-origin' });
    const json = await res.json();
    if (json.success) {
      closeModal();
      loadList();
      alert(json.message);
    } else {
      showError(json.message || 'Request failed.');
    }
  });

  document.getElementById('bhwReviewClose')?.addEventListener('click', closeModal);
  if (searchInput) searchInput.addEventListener('input', applyFilters);
  if (statusFilter) statusFilter.addEventListener('change', applyFilters);
  modal?.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

  loadList();
})();
