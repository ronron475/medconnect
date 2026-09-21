(function () {
  'use strict';

  const cfg = window.MC_DOCTOR_APPROVAL || {};
  const api = cfg.api || '';
  const utils = window.MCStaffApplications || {};
  const currentUserId = cfg.currentUserId || 0;
  const tbody = document.getElementById('doctorApprovalBody');
  const modal = document.getElementById('doctorReviewModal');
  const reviewContent = document.getElementById('doctorReviewContent');
  const approveBtn = document.getElementById('doctorApproveBtn');
  const errorEl = document.getElementById('doctorReviewError');
  const searchInput = document.getElementById('doctorApprovalSearch');
  const statusFilter = document.getElementById('doctorApprovalStatusFilter');
  const countEl = document.getElementById('doctorApprovalCount');
  const statsEl = document.getElementById('doctorApprovalStats');
  const pendingBadge = document.getElementById('doctorPendingBadge');
  const checklistIds = [
    'check_prc_verified', 'check_prc_id', 'check_gov_id', 'check_license_active',
    'check_license_expiry', 'check_profession', 'check_facility', 'check_email',
    'check_no_dup_prc', 'check_no_dup_doctor',
  ];
  let allRows = [];
  let currentAppId = 0;
  let currentSubmittedBy = 0;

  if (!modal || !api) {
    return;
  }

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
    if (!approveBtn) return;
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

  async function loadList() {
    if (!tbody) return;
    try {
      const res = await fetch(api + '?action=list', { credentials: 'same-origin' });
      const json = await res.json();
      if (!json.success) return;
      allRows = json.data.applications || [];
      updateStatsDisplay(computeApprovalStats(allRows), json.data.pending_count || 0);
      applyFilters();
    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="10"><div class="staff-apps-empty"><p class="staff-apps-empty__title">Could not load queue</p></div></td></tr>';
    }
  }

  function applyFilters() {
    if (!tbody) return;
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
      tbody.innerHTML = utils.renderEmptyState(10, {
        title: 'No Doctor applications in queue',
        text: 'Pending applications submitted by administrators will appear here for your review.',
      });
      return;
    }
    if (!rows.length) {
      tbody.innerHTML = utils.renderNoResultsRow(10);
      return;
    }
    tbody.innerHTML = rows.map(function (r) {
      return (
        '<tr>' +
        '<td class="staff-apps-td--applicant" data-label="">' + utils.renderApplicantCell(r) + '</td>' +
        '<td data-label="PRC License"><span class="staff-apps-meta">' + utils.esc(r.prc_license_number || '—') + '</span></td>' +
        '<td data-label="Specialization"><span class="staff-apps-meta">' + utils.esc(r.specialization || '—') + '</span></td>' +
        '<td data-label="Hospital / Clinic"><span class="staff-apps-meta">' + utils.esc(r.facility || '—') + '</span></td>' +
        '<td data-label="Submitted By"><span class="staff-apps-meta staff-apps-meta--muted">' + utils.esc(r.submitted_by_name || '—') + '</span></td>' +
        '<td data-label="Submitted"><span class="staff-apps-meta staff-apps-meta--muted">' + utils.esc(utils.formatDate(r.submitted_at)) + '</span></td>' +
        '<td data-label="Verification"><span class="staff-apps-meta">' + utils.esc(r.verification_status || '—') + '</span></td>' +
        '<td data-label="Documents">' + utils.renderDocBadge(r.document_count, 2) + '</td>' +
        '<td data-label="Status">' + utils.renderStatusBadge(r) + '</td>' +
        '<td class="staff-apps-td--actions" data-label="Actions">' + utils.renderReviewBtn(r.id, 'doctor-review-btn', 'Review') + '</td>' +
        '</tr>'
      );
    }).join('');
    tbody.querySelectorAll('.doctor-review-btn').forEach(function (btn) {
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
    showError('');

    const res = await fetch(api + '?action=get&id=' + id, { credentials: 'same-origin' });
    const json = await res.json();
    if (!json.success) return;
    const app = json.data;
    currentSubmittedBy = parseInt(app.submitted_by || 0, 10);

    reviewContent.innerHTML =
      '<dl class="bhw-review-meta">' +
      '<dt>Doctor Name</dt><dd>' + utils.esc(app.display_name) + '</dd>' +
      '<dt>Email</dt><dd>' + utils.esc(app.email) + '</dd>' +
      '<dt>Mobile</dt><dd>' + utils.esc(app.phone) + '</dd>' +
      '<dt>Birthdate</dt><dd>' + utils.esc(app.birthdate || '—') + '</dd>' +
      '<dt>PRC License</dt><dd>' + utils.esc(app.prc_license_number) + '</dd>' +
      '<dt>Specialization</dt><dd>' + utils.esc(app.specialization) + '</dd>' +
      '<dt>Hospital / Clinic</dt><dd>' + utils.esc(app.facility || '—') + '</dd>' +
      '<dt>Submitted By</dt><dd>' + utils.esc(app.submitted_by_name || '—') + '</dd>' +
      '<dt>Verification Status</dt><dd>' + utils.esc(app.verification_status) + '</dd>' +
      '<dt>Account Status</dt><dd>' + utils.esc(app.status_label) + '</dd>' +
      '</dl>' +
      '<h4 class="admin-form-section-title">Uploaded Documents</h4>' +
      '<ul class="bhw-doc-list">' +
      (app.documents || []).map(function (d) {
        return '<li><span>' + utils.esc(d.document_type.replace(/_/g, ' ')) + ': ' + utils.esc(d.original_name) + '</span>' +
          '<a class="mc-btn mc-btn--outline mc-btn--info" style="padding:4px 8px;font-size:11px;" target="_blank" rel="noopener" href="' + api + '?action=download&document_id=' + d.id + '">View</a></li>';
      }).join('') +
      (app.documents && app.documents.length ? '' : '<li class="text-muted">No documents uploaded.</li>') +
      '</ul>';

    const titleEl = document.getElementById('doctorReviewTitle');
    if (titleEl) titleEl.textContent = 'Review: ' + app.display_name;
    updateApproveState();
    modal.style.display = 'flex';
    modal.style.pointerEvents = 'auto';
  }

  function closeModal() {
    modal.style.display = 'none';
    modal.style.pointerEvents = 'none';
  }

  function notifyLiveRefresh() {
    try {
      window.dispatchEvent(new CustomEvent('medconnect:nav-badges-refresh'));
    } catch (e) { /* ignore */ }
    if (window.MCDoctorApplications && typeof window.MCDoctorApplications.refresh === 'function') {
      window.MCDoctorApplications.refresh();
    }
    if (tbody) {
      loadList();
    }
  }

  function showInlineFlash(kind, title, text) {
    const esc = typeof utils.esc === 'function' ? utils.esc : function (s) {
      return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };
    const page = document.querySelector('.staff-apps-page--doctor');
    if (!page) return;
    let flash = document.getElementById('doctorLiveFlash');
    if (!flash) {
      flash = document.createElement('div');
      flash.id = 'doctorLiveFlash';
      flash.setAttribute('role', 'status');
      flash.className = 'staff-apps-flash';
      const hero = page.querySelector('.staff-apps-hero');
      if (hero && hero.parentNode) {
        hero.parentNode.insertBefore(flash, hero);
      } else {
        page.insertBefore(flash, page.firstChild);
      }
    }
    flash.className = 'staff-apps-flash staff-apps-flash--' + (kind === 'warn' ? 'warn' : 'success');
    flash.innerHTML =
      '<div class="staff-apps-flash__icon" aria-hidden="true">' +
      (kind === 'warn'
        ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
        : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg>') +
      '</div><div><p class="staff-apps-flash__title">' + esc(title) + '</p>' +
      '<p class="staff-apps-flash__text">' + esc(text) + '</p></div>';
  }

  approveBtn?.addEventListener('click', async function () {
    const fd = new FormData();
    fd.append('application_id', currentAppId);
    fd.append('check_prc_verified', document.getElementById('check_prc_verified')?.checked ? '1' : '');
    fd.append('check_prc_id', document.getElementById('check_prc_id')?.checked ? '1' : '');
    fd.append('check_gov_id', document.getElementById('check_gov_id')?.checked ? '1' : '');
    fd.append('check_license_active', document.getElementById('check_license_active')?.checked ? '1' : '');
    fd.append('check_license_expiry', document.getElementById('check_license_expiry')?.checked ? '1' : '');
    fd.append('check_profession', document.getElementById('check_profession')?.checked ? '1' : '');
    fd.append('check_facility', document.getElementById('check_facility')?.checked ? '1' : '');
    fd.append('check_email', document.getElementById('check_email')?.checked ? '1' : '');
    fd.append('check_no_dup_prc', document.getElementById('check_no_dup_prc')?.checked ? '1' : '');
    fd.append('check_no_dup_doctor', document.getElementById('check_no_dup_doctor')?.checked ? '1' : '');

    const res = await fetch(api + '?action=approve', { method: 'POST', body: fd, credentials: 'same-origin' });
    const json = await res.json();
    if (!json.success) {
      showError(json.message || 'Approval failed.');
      return;
    }
    closeModal();
    showInlineFlash('success', 'Doctor account approved', 'The account is now active and the doctor may log in.');
    notifyLiveRefresh();
  });

  document.getElementById('doctorRejectBtn')?.addEventListener('click', async function () {
    const reason = prompt('Enter rejection reason:');
    if (!reason || !reason.trim()) return;
    const fd = new FormData();
    fd.append('application_id', currentAppId);
    fd.append('reason', reason.trim());
    const res = await fetch(api + '?action=reject', { method: 'POST', body: fd, credentials: 'same-origin' });
    const json = await res.json();
    if (!json.success) {
      showError(json.message || 'Rejection failed.');
      return;
    }
    closeModal();
    showInlineFlash('warn', 'Application rejected', 'The submitting administrator has been notified.');
    notifyLiveRefresh();
  });

  document.getElementById('doctorRequestDocsBtn')?.addEventListener('click', async function () {
    const note = prompt('Specify additional documents required:');
    if (!note || !note.trim()) return;
    const fd = new FormData();
    fd.append('application_id', currentAppId);
    fd.append('note', note.trim());
    const res = await fetch(api + '?action=request_documents', { method: 'POST', body: fd, credentials: 'same-origin' });
    const json = await res.json();
    if (json.success) {
      closeModal();
      showInlineFlash('success', 'Documents requested', json.message || 'The applicant was notified.');
      notifyLiveRefresh();
    } else {
      showError(json.message || 'Request failed.');
    }
  });

  document.getElementById('doctorReviewClose')?.addEventListener('click', closeModal);
  if (searchInput) searchInput.addEventListener('input', applyFilters);
  if (statusFilter) statusFilter.addEventListener('change', applyFilters);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

  document.addEventListener('medconnect:live-sync', function (ev) {
    const changed = (ev.detail && ev.detail.changed) || [];
    if (changed.indexOf('staff_applications') !== -1 || changed.indexOf('dashboard') !== -1) {
      if (tbody) loadList();
    }
  });

  window.MCDoctorApproval = { openReview: openReview, refresh: loadList };

  if (tbody) {
    loadList();
  }
})();
