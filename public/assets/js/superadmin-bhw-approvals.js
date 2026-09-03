(function () {
  'use strict';

  const cfg = window.MC_BHW_APPROVAL || {};
  const api = cfg.api || '';
  const utils = window.MCStaffApplications || {};
  const currentUserId = cfg.currentUserId || 0;
  const hubMode = !!cfg.hubMode;
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

  if (!modal || !api) {
    return;
  }

  function showError(message) {
    if (!errorEl) return;
    errorEl.textContent = message || '';
    errorEl.classList.toggle('is-visible', !!message);
  }

  function redirectWithFlash(flashKey) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', 'pending');
    url.searchParams.delete('approved');
    url.searchParams.delete('rejected');
    url.searchParams.set(flashKey, '1');
    window.location.href = url.pathname + '?' + url.searchParams.toString();
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
  document.getElementById('check_cho')?.addEventListener('change', updateApproveState);

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
      tbody.innerHTML = '<tr><td colspan="8"><div class="staff-apps-empty"><p class="staff-apps-empty__title">Could not load queue</p></div></td></tr>';
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
        const typeLabel = utils.esc(String(d.document_type || '').replace(/_/g, ' '));
        const name = utils.esc(d.original_name || 'Document');
        const mime = utils.esc(d.mime_type || '');
        return '<li><span>' + typeLabel + ': ' + name + '</span>' +
          '<span class="bhw-doc-list__actions">' +
          '<button type="button" class="mc-btn mc-btn--outline bhw-doc-view-btn" style="padding:4px 8px;font-size:11px;"' +
          ' data-doc-id="' + d.id + '"' +
          ' data-doc-name="' + name + '"' +
          ' data-doc-type="' + typeLabel + '"' +
          ' data-doc-mime="' + mime + '">View</button>' +
          '<a class="mc-btn mc-btn--outline" style="padding:4px 8px;font-size:11px;" href="' + api + '?action=download&document_id=' + d.id + '">Download</a>' +
          '</span></li>';
      }).join('') +
      (app.documents && app.documents.length ? '' : '<li class="text-muted">No documents uploaded.</li>') +
      '</ul>';

    reviewContent.querySelectorAll('.bhw-doc-view-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openDocPreview({
          id: btn.getAttribute('data-doc-id'),
          name: btn.getAttribute('data-doc-name') || 'Document',
          type: btn.getAttribute('data-doc-type') || '',
          mime: btn.getAttribute('data-doc-mime') || '',
        });
      });
    });

    const titleEl = document.getElementById('bhwReviewTitle');
    if (titleEl) titleEl.textContent = 'Review: ' + app.display_name;
    updateApproveState();
    modal.style.display = 'flex';
    modal.style.pointerEvents = 'auto';
  }

  function isImageMime(mime, name) {
    const m = String(mime || '').toLowerCase();
    if (m.indexOf('image/') === 0) return true;
    return /\.(jpe?g|png|webp|gif)$/i.test(String(name || ''));
  }

  function isPdfMime(mime, name) {
    const m = String(mime || '').toLowerCase();
    if (m === 'application/pdf' || m.indexOf('pdf') >= 0) return true;
    return /\.pdf$/i.test(String(name || ''));
  }

  function openDocPreview(doc) {
    const preview = document.getElementById('bhwDocPreviewModal');
    const body = document.getElementById('bhwDocPreviewBody');
    const title = document.getElementById('bhwDocPreviewTitle');
    const sub = document.getElementById('bhwDocPreviewSub');
    const download = document.getElementById('bhwDocPreviewDownload');
    if (!preview || !body) return;

    const viewUrl = api + '?action=view&document_id=' + encodeURIComponent(doc.id);
    const downloadUrl = api + '?action=download&document_id=' + encodeURIComponent(doc.id);

    if (title) title.textContent = doc.type ? (doc.type + ' preview') : 'Document preview';
    if (sub) sub.textContent = doc.name || '';
    if (download) download.href = downloadUrl;

    body.innerHTML = '';
    if (isImageMime(doc.mime, doc.name)) {
      const img = document.createElement('img');
      img.src = viewUrl;
      img.alt = doc.name || 'Document image';
      body.appendChild(img);
    } else if (isPdfMime(doc.mime, doc.name)) {
      const frame = document.createElement('iframe');
      frame.src = viewUrl;
      frame.title = doc.name || 'PDF document';
      body.appendChild(frame);
    } else {
      body.innerHTML =
        '<div class="bhw-doc-preview-fallback">' +
        '<p>This file type cannot be previewed in the browser.</p>' +
        '<p><a class="mc-btn mc-btn--outline" href="' + downloadUrl + '">Download to inspect</a></p>' +
        '</div>';
    }

    preview.style.display = 'flex';
    preview.classList.add('is-open');
  }

  function closeDocPreview() {
    const preview = document.getElementById('bhwDocPreviewModal');
    const body = document.getElementById('bhwDocPreviewBody');
    if (body) body.innerHTML = '';
    if (preview) {
      preview.style.display = 'none';
      preview.classList.remove('is-open');
    }
  }

  function closeModal() {
    closeDocPreview();
    modal.style.display = 'none';
    modal.style.pointerEvents = 'none';
  }

  approveBtn?.addEventListener('click', async function () {
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
    if (hubMode) {
      redirectWithFlash('approved');
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
    if (!json.success) {
      showError(json.message || 'Rejection failed.');
      return;
    }
    if (hubMode) {
      redirectWithFlash('rejected');
      return;
    }
    window.location.href = window.location.pathname + '?rejected=1';
  });

  document.getElementById('bhwRequestDocsBtn')?.addEventListener('click', async function () {
    const note = prompt('Specify what the BHW must correct or upload (they will receive an email link):');
    if (!note || !note.trim()) return;
    const fd = new FormData();
    fd.append('application_id', currentAppId);
    fd.append('note', note.trim());
    const res = await fetch(api + '?action=request_documents', { method: 'POST', body: fd, credentials: 'same-origin' });
    const json = await res.json();
    if (json.success) {
      closeModal();
      if (hubMode) {
        window.location.reload();
        return;
      }
      loadList();
      alert(json.message);
    } else {
      showError(json.message || 'Request failed.');
    }
  });

  document.getElementById('bhwReviewClose')?.addEventListener('click', closeModal);
  document.getElementById('bhwDocPreviewClose')?.addEventListener('click', closeDocPreview);
  document.getElementById('bhwDocPreviewModal')?.addEventListener('click', function (e) {
    if (e.target === e.currentTarget) closeDocPreview();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeDocPreview();
  });
  if (searchInput) searchInput.addEventListener('input', applyFilters);
  if (statusFilter) statusFilter.addEventListener('change', applyFilters);
  modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

  window.MCBhwApproval = { openReview: openReview };

  if (tbody) {
    loadList();
  }
})();
