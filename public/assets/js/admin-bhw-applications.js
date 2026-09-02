(function () {
  'use strict';

  const cfg = window.MC_BHW_APP || {};
  const api = cfg.api || '';
  const checkerMode = !!cfg.checkerMode;
  const utils = window.MCStaffApplications || {};
  const formUtils = window.MCStaffForm || {};
  const tbody = document.getElementById('bhwAppsBody');
  const modal = document.getElementById('bhwAppModal');
  const form = document.getElementById('bhwAppForm');
  const barangaySelect = document.getElementById('bhwBarangaySelect');
  const docList = document.getElementById('bhwDocList');
  const errorEl = document.getElementById('bhwFormError');
  const rejectionNote = document.getElementById('bhwRejectionNote');
  const docsRequestNote = document.getElementById('bhwDocsRequestNote');
  const submitBtn = document.getElementById('bhwSubmitBtn');
  const saveDraftBtn = document.getElementById('bhwSaveDraftBtn');
  const resendBtn = document.getElementById('bhwResendInviteBtn');
  const emailInput = document.getElementById('bhwEmail');
  const phoneInput = document.getElementById('bhwPhone');
  const searchInput = document.getElementById('bhwAppSearch');
  const statusFilter = document.getElementById('bhwAppStatusFilter');
  const countEl = document.getElementById('bhwAppCount');
  const statsEl = document.getElementById('bhwAppStats');
  let barangays = [];
  let currentApp = null;
  let allRows = [];

  function buildBhwApplicationPayload() {
    function trimmedVal(name) {
      const el = form?.elements?.[name];
      const raw = el && typeof el.value === 'string' ? el.value : (el?.value ?? '');
      return String(raw).trim();
    }

    const appId = String(document.getElementById('bhwApplicationId')?.value || '');
    const fd = new FormData();
    fd.append('application_id', appId);
    fd.append('first_name', trimmedVal('first_name'));
    fd.append('middle_name', trimmedVal('middle_name'));
    fd.append('last_name', trimmedVal('last_name'));
    fd.append('email', trimmedVal('email'));
    fd.append('phone', trimmedVal('phone'));
    fd.append('barangay_id', String(trimmedVal('barangay_id')));
    fd.append('appointment_date', trimmedVal('appointment_date'));
    return fd;
  }

  if (statusFilter && cfg.initialStatus) {
    statusFilter.value = cfg.initialStatus;
  }

  if (formUtils.enhanceFileInputsIn && form) {
    formUtils.enhanceFileInputsIn(form);
  }

  function validateClient() {
    let ok = true;
    if (emailInput && formUtils.validateEmail && !formUtils.validateEmail(emailInput.value)) {
      formUtils.setFieldError(emailInput, 'Enter a valid email address.');
      ok = false;
    } else if (emailInput) {
      formUtils.setFieldError(emailInput, '');
    }
    if (phoneInput && formUtils.validatePhone && !formUtils.validatePhone(phoneInput.value)) {
      formUtils.setFieldError(phoneInput, 'Use format 09XXXXXXXXX or +639XXXXXXXXX.');
      ok = false;
    } else if (phoneInput) {
      formUtils.setFieldError(phoneInput, '');
    }
    return ok;
  }

  async function loadList() {
    try {
      const res = await fetch(api + '?action=list', { credentials: 'same-origin' });
      const json = await res.json();
      if (!json.success) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="7"><div class="staff-apps-empty"><p class="staff-apps-empty__title">Could not load applications</p></div></td></tr>';
        return;
      }
      barangays = json.data.barangays || [];
      fillBarangays();
      allRows = json.data.applications || [];
      utils.updateStats(statsEl, utils.computeStats(allRows));
      applyFilters();
    } catch (e) {
      if (tbody) tbody.innerHTML = '<tr><td colspan="7"><div class="staff-apps-empty"><p class="staff-apps-empty__title">Could not load applications</p></div></td></tr>';
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
        ? shown + ' application' + (shown === 1 ? '' : 's')
        : 'Showing ' + shown + ' of ' + total;
    }
    renderTable(filtered);
  }

  function fillBarangays() {
    if (!barangaySelect) return;
    const cur = barangaySelect.value;
    barangaySelect.innerHTML = '<option value="">Select barangay…</option>';
    barangays.forEach(function (b) {
      const opt = document.createElement('option');
      opt.value = b.id;
      opt.textContent = b.name;
      barangaySelect.appendChild(opt);
    });
    if (cur) barangaySelect.value = cur;
  }

  function canReview(status) {
    return status === 'pending_approval' || status === 'requires_documents';
  }

  function canAdminEditStatus(status) {
    return status === 'draft' || status === 'rejected';
  }

  function canResend(status) {
    return status === 'invited' || status === 'onboarding';
  }

  function renderTable(rows) {
    if (!tbody) return;

    if (!allRows.length) {
      tbody.innerHTML = utils.renderEmptyState(7, checkerMode ? {
        title: 'No BHW applications yet',
        text: 'Applications submitted by invited BHWs will appear here for review.',
      } : {
        title: 'No BHW invites yet',
        text: 'Invite a Barangay Health Worker to begin activation and onboarding.',
        ctaId: 'bhwEmptyCreateBtn',
        ctaLabel: 'Create BHW Invite',
      });
      if (!checkerMode) {
        document.getElementById('bhwEmptyCreateBtn')?.addEventListener('click', function () { openModal(0); });
      }
      return;
    }

    if (!rows.length) {
      tbody.innerHTML = utils.renderNoResultsRow(7);
      return;
    }

    tbody.innerHTML = rows.map(function (r) {
      const editable = canAdminEditStatus(r.status) || canResend(r.status);
      let actionCell;
      if (checkerMode && canReview(r.status)) {
        actionCell = utils.renderReviewBtn(r.id, 'bhw-review-btn', 'Review');
      } else if (!checkerMode) {
        actionCell = utils.renderEditBtn(r.id, editable, 'bhw-edit-btn');
      } else {
        actionCell = '<span class="staff-apps-meta staff-apps-meta--muted">—</span>';
      }
      return (
        '<tr>' +
        '<td class="staff-apps-td--applicant" data-label="">' + utils.renderApplicantCell(r) + '</td>' +
        '<td data-label="Barangay"><span class="staff-apps-meta">' + utils.esc(r.barangay_name || '—') + '</span></td>' +
        '<td data-label="Appointment"><span class="staff-apps-meta staff-apps-meta--muted">' + utils.esc(utils.formatDate(r.appointment_date)) + '</span></td>' +
        '<td data-label="Documents">' + utils.renderDocBadge(r.document_count, 2) + '</td>' +
        '<td data-label="Status">' + utils.renderStatusBadge(r) + '</td>' +
        '<td data-label="Submitted"><span class="staff-apps-meta staff-apps-meta--muted">' + utils.esc(utils.formatDate(r.bhw_submitted_at || r.submitted_at || r.invited_at)) + '</span></td>' +
        '<td class="staff-apps-td--actions" data-label="Actions">' + actionCell + '</td>' +
        '</tr>'
      );
    }).join('');

    if (checkerMode) {
      tbody.querySelectorAll('.bhw-review-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const reviewId = parseInt(btn.dataset.id, 10);
          if (window.MCBhwApproval && typeof window.MCBhwApproval.openReview === 'function') {
            window.MCBhwApproval.openReview(reviewId);
          }
        });
      });
      return;
    }

    tbody.querySelectorAll('.bhw-edit-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openModal(parseInt(btn.dataset.id, 10));
      });
    });
  }

  async function ensureBarangays() {
    if (barangays.length) {
      fillBarangays();
      return;
    }
    try {
      const res = await fetch(api + '?action=list', { credentials: 'same-origin' });
      const json = await res.json();
      if (json.success) {
        barangays = json.data.barangays || [];
        fillBarangays();
      }
    } catch (e) {
      /* dropdown stays on placeholder */
    }
  }

  function openModal(id) {
    if (!modal) return;
    form.reset();
    ensureBarangays();
    document.getElementById('bhwApplicationId').value = id ? String(id) : '';
    document.getElementById('bhwModalTitle').textContent = id ? 'BHW Invite' : 'Invite Barangay Health Worker';
    formUtils.showFormAlert(rejectionNote, '', 'warn');
    formUtils.showFormAlert(docsRequestNote, '', 'warn');
    formUtils.showFormAlert(errorEl, '', 'error');
    docList.innerHTML = '';
    currentApp = null;
    if (resendBtn) resendBtn.style.display = 'none';

    if (id) {
      fetch(api + '?action=get&id=' + id, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (!json.success) return;
          currentApp = json.data;
          populateForm(json.data);
        });
    } else {
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Send Invite';
      }
      if (saveDraftBtn) saveDraftBtn.disabled = false;
    }

    modal.style.display = 'flex';
    modal.style.pointerEvents = 'auto';
  }

  function populateForm(app) {
    form.elements['first_name'].value = app.first_name || '';
    form.elements['middle_name'].value = app.middle_name || '';
    form.elements['last_name'].value = app.last_name || '';
    form.elements['email'].value = app.email || '';
    form.elements['phone'].value = app.phone || '';
    form.elements['appointment_date'].value = app.appointment_date || '';
    form.elements['barangay_id'].value = app.barangay_id || '';
    document.getElementById('bhwApplicationId').value = app.id;

    if (app.rejection_reason) {
      formUtils.showFormAlert(rejectionNote, 'Rejection reason: ' + app.rejection_reason, 'warn');
    }
    if (app.additional_docs_note) {
      formUtils.showFormAlert(docsRequestNote, 'Documents requested from BHW: ' + app.additional_docs_note, 'warn');
    }

    docList.innerHTML = (app.documents || []).map(function (d) {
      return '<li><span>' + utils.esc(d.document_type.replace(/_/g, ' ')) + ': ' + utils.esc(d.original_name) + '</span></li>';
    }).join('');

    const editable = canAdminEditStatus(app.status);
    const resendable = canResend(app.status);
    if (submitBtn) {
      submitBtn.disabled = !(editable || app.status === 'invited');
      submitBtn.textContent = app.status === 'invited' ? 'Resend Invite' : 'Send Invite';
    }
    if (saveDraftBtn) saveDraftBtn.disabled = !editable;
    if (resendBtn) {
      resendBtn.style.display = resendable ? '' : 'none';
      resendBtn.disabled = !resendable;
    }

    ['first_name', 'middle_name', 'last_name', 'email', 'phone', 'barangay_id', 'appointment_date'].forEach(function (name) {
      if (form.elements[name]) form.elements[name].disabled = !editable && !resendable;
    });
    if (!editable) {
      ['bhwDocAppointment', 'bhwDocCho'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) el.disabled = true;
      });
    } else {
      ['bhwDocAppointment', 'bhwDocCho'].forEach(function (id) {
        const el = document.getElementById(id);
        if (el) el.disabled = false;
      });
    }
  }

  function closeModal() {
    if (modal) {
      modal.style.display = 'none';
      modal.style.pointerEvents = 'none';
    }
  }

  async function saveDraft(redirect, quiet) {
    formUtils.showFormAlert(errorEl, '', 'error');
    if (!validateClient() && !quiet) return null;

    if (!quiet) formUtils.setFormLoading(form, true, saveDraftBtn, 'Saving draft...');
    try {
      const fd = buildBhwApplicationPayload();
      const res = await fetch(api + '?action=save_draft', { method: 'POST', body: fd, credentials: 'same-origin' });
      const json = await res.json();
      if (!json.success) {
        formUtils.showFormAlert(errorEl, json.message || 'Could not save draft.', 'error');
        return null;
      }
      document.getElementById('bhwApplicationId').value = json.application_id;
      await uploadPendingDocs(json.application_id);
      if (redirect) {
        window.location.href = cfg.assetBase + '/views/admin/bhw_applications.php?saved=1';
      }
      return json.application_id;
    } finally {
      if (!quiet) formUtils.setFormLoading(form, false, saveDraftBtn);
    }
  }

  async function uploadPendingDocs(appId) {
    const uploads = [
      ['bhwDocAppointment', 'appointment_letter'],
      ['bhwDocCho', 'cho_endorsement'],
    ];
    for (let i = 0; i < uploads.length; i++) {
      const input = document.getElementById(uploads[i][0]);
      if (!input || input.disabled || !input.files || !input.files[0]) continue;
      const fd = new FormData();
      fd.append('application_id', appId);
      fd.append('document_type', uploads[i][1]);
      fd.append('document', input.files[0]);
      await fetch(api + '?action=upload_document', { method: 'POST', body: fd, credentials: 'same-origin' });
    }
  }

  async function sendOrResendInvite(action) {
    formUtils.showFormAlert(errorEl, '', 'error');
    if (!validateClient()) return;

    formUtils.setFormLoading(form, true, submitBtn, action === 'resend_invite' ? 'Resending…' : 'Sending invite…');
    try {
      let appId = document.getElementById('bhwApplicationId').value;
      if (action === 'send_invite') {
        if (!appId) {
          appId = await saveDraft(false, true);
          if (!appId) return;
        } else if (canAdminEditStatus(currentApp?.status || 'draft')) {
          const fd = buildBhwApplicationPayload();
          const saveRes = await fetch(api + '?action=save_draft', { method: 'POST', body: fd, credentials: 'same-origin' });
          const saveJson = await saveRes.json();
          if (!saveJson.success) {
            formUtils.showFormAlert(errorEl, saveJson.message || 'Could not save application.', 'error');
            return;
          }
          await uploadPendingDocs(appId);
        }
      }

      const submitFd = new FormData();
      submitFd.append('application_id', appId);
      const res = await fetch(api + '?action=' + action, { method: 'POST', body: submitFd, credentials: 'same-origin' });
      const json = await res.json();
      if (!json.success) {
        formUtils.showFormAlert(errorEl, json.message || 'Invite failed.', 'error');
        return;
      }
      window.location.href = cfg.assetBase + '/views/admin/bhw_applications.php?submitted=1';
    } finally {
      formUtils.setFormLoading(form, false, submitBtn);
    }
  }

  if (form) {
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      const status = currentApp?.status || 'draft';
      const action = (status === 'invited' || status === 'onboarding') ? 'resend_invite' : 'send_invite';
      await sendOrResendInvite(action);
    });
  }

  document.getElementById('bhwOpenCreateBtn')?.addEventListener('click', function () { openModal(0); });
  document.getElementById('bhwModalClose')?.addEventListener('click', closeModal);
  document.getElementById('bhwModalCancel')?.addEventListener('click', closeModal);
  document.getElementById('bhwSaveDraftBtn')?.addEventListener('click', function () { saveDraft(true); });
  resendBtn?.addEventListener('click', function () { sendOrResendInvite('resend_invite'); });
  if (searchInput) searchInput.addEventListener('input', applyFilters);
  if (statusFilter) statusFilter.addEventListener('change', applyFilters);
  modal?.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

  if (cfg.showApplications !== false) {
    loadList();
  }
})();
