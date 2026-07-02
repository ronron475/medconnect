(function () {
  'use strict';

  const cfg = window.MC_BHW_APP || {};
  const api = cfg.api || '';
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
  const passwordInput = document.getElementById('bhwPassword');
  const passwordConfirm = document.getElementById('bhwPasswordConfirm');
  const emailInput = document.getElementById('bhwEmail');
  const phoneInput = document.getElementById('bhwPhone');
  const searchInput = document.getElementById('bhwAppSearch');
  const statusFilter = document.getElementById('bhwAppStatusFilter');
  const countEl = document.getElementById('bhwAppCount');
  const statsEl = document.getElementById('bhwAppStats');
  let barangays = [];
  let currentApp = null;
  let allRows = [];

  if (formUtils.wrapPasswordInput && passwordInput) {
    formUtils.wrapPasswordInput(passwordInput, { minLength: 12 });
  }
  if (formUtils.initPasswordConfirm && passwordInput && passwordConfirm) {
    formUtils.initPasswordConfirm(passwordInput, passwordConfirm);
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
    const pw = passwordInput ? passwordInput.value : '';
    if (pw && formUtils.validatePasswordStrength && !formUtils.validatePasswordStrength(pw, 12)) {
      formUtils.setFieldError(passwordInput, 'Password does not meet all strength requirements.');
      ok = false;
    } else if (passwordInput && pw) {
      formUtils.setFieldError(passwordInput, '');
    }
    if (passwordInput && passwordConfirm && formUtils.passwordsMatch && !formUtils.passwordsMatch(passwordInput, passwordConfirm)) {
      formUtils.setFieldError(passwordConfirm, 'Passwords do not match.');
      ok = false;
    } else if (passwordConfirm && passwordConfirm.value) {
      formUtils.setFieldError(passwordConfirm, '');
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

  function renderTable(rows) {
    if (!tbody) return;

    if (!allRows.length) {
      tbody.innerHTML = utils.renderEmptyState(7, {
        title: 'No BHW applications yet',
        text: 'Create your first Barangay Health Worker application to begin the Maker-Checker approval workflow.',
        ctaId: 'bhwEmptyCreateBtn',
        ctaLabel: 'Create BHW Application',
      });
      document.getElementById('bhwEmptyCreateBtn')?.addEventListener('click', function () { openModal(0); });
      return;
    }

    if (!rows.length) {
      tbody.innerHTML = utils.renderNoResultsRow(7);
      return;
    }

    tbody.innerHTML = rows.map(function (r) {
      const editable = utils.canEdit(r.status);
      return (
        '<tr>' +
        '<td class="staff-apps-td--applicant" data-label="">' + utils.renderApplicantCell(r) + '</td>' +
        '<td data-label="Barangay"><span class="staff-apps-meta">' + utils.esc(r.barangay_name || '—') + '</span></td>' +
        '<td data-label="Appointment"><span class="staff-apps-meta staff-apps-meta--muted">' + utils.esc(utils.formatDate(r.appointment_date)) + '</span></td>' +
        '<td data-label="Documents">' + utils.renderDocBadge(r.document_count, 2) + '</td>' +
        '<td data-label="Status">' + utils.renderStatusBadge(r) + '</td>' +
        '<td data-label="Submitted"><span class="staff-apps-meta staff-apps-meta--muted">' + utils.esc(utils.formatDate(r.submitted_at)) + '</span></td>' +
        '<td class="staff-apps-td--actions" data-label="Actions">' + utils.renderEditBtn(r.id, editable, 'bhw-edit-btn') + '</td>' +
        '</tr>'
      );
    }).join('');

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
    document.getElementById('bhwModalTitle').textContent = id ? 'Edit BHW Application' : 'Create BHW Application';
    formUtils.showFormAlert(rejectionNote, '', 'warn');
    formUtils.showFormAlert(docsRequestNote, '', 'warn');
    formUtils.showFormAlert(errorEl, '', 'error');
    docList.innerHTML = '';
    currentApp = null;

    if (id) {
      fetch(api + '?action=get&id=' + id, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (!json.success) return;
          currentApp = json.data;
          populateForm(json.data);
        });
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
      formUtils.showFormAlert(docsRequestNote, 'Additional documents requested: ' + app.additional_docs_note, 'warn');
    }

    docList.innerHTML = (app.documents || []).map(function (d) {
      return '<li><span>' + utils.esc(d.document_type.replace(/_/g, ' ')) + ': ' + utils.esc(d.original_name) + '</span></li>';
    }).join('');

    const editable = utils.canEdit(app.status);
    if (submitBtn) submitBtn.disabled = !editable;
    if (saveDraftBtn) saveDraftBtn.disabled = !editable;
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
      const fd = new FormData(form);
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
      ['bhwDocGovId', 'government_id'],
      ['bhwDocCho', 'cho_endorsement'],
    ];
    for (let i = 0; i < uploads.length; i++) {
      const input = document.getElementById(uploads[i][0]);
      if (!input || !input.files || !input.files[0]) continue;
      const fd = new FormData();
      fd.append('application_id', appId);
      fd.append('document_type', uploads[i][1]);
      fd.append('document', input.files[0]);
      await fetch(api + '?action=upload_document', { method: 'POST', body: fd, credentials: 'same-origin' });
    }
  }

  if (form) {
    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      formUtils.showFormAlert(errorEl, '', 'error');
      if (!validateClient()) return;

      formUtils.setFormLoading(form, true, submitBtn, 'Submitting application...');
      try {
        let appId = document.getElementById('bhwApplicationId').value;
        if (!appId) {
          appId = await saveDraft(false, true);
          if (!appId) return;
        } else {
          const fd = new FormData(form);
          const saveRes = await fetch(api + '?action=save_draft', { method: 'POST', body: fd, credentials: 'same-origin' });
          const saveJson = await saveRes.json();
          if (!saveJson.success) {
            formUtils.showFormAlert(errorEl, saveJson.message || 'Could not save application.', 'error');
            return;
          }
          await uploadPendingDocs(appId);
        }

        const submitFd = new FormData();
        submitFd.append('application_id', appId);
        const res = await fetch(api + '?action=submit', { method: 'POST', body: submitFd, credentials: 'same-origin' });
        const json = await res.json();
        if (!json.success) {
          formUtils.showFormAlert(errorEl, json.message || 'Submission failed.', 'error');
          return;
        }
        window.location.href = cfg.assetBase + '/views/admin/bhw_applications.php?submitted=1';
      } finally {
        formUtils.setFormLoading(form, false, submitBtn);
      }
    });
  }

  document.getElementById('bhwOpenCreateBtn')?.addEventListener('click', function () { openModal(0); });
  document.getElementById('bhwModalClose')?.addEventListener('click', closeModal);
  document.getElementById('bhwModalCancel')?.addEventListener('click', closeModal);
  document.getElementById('bhwSaveDraftBtn')?.addEventListener('click', function () { saveDraft(true); });
  if (searchInput) searchInput.addEventListener('input', applyFilters);
  if (statusFilter) statusFilter.addEventListener('change', applyFilters);
  modal?.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

  loadList();
})();
