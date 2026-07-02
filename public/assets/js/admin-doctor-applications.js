(function () {
  'use strict';

  var cfg = window.MC_DOCTOR_APP || {};
  var api = cfg.api || '';
  var utils = window.MCStaffApplications || {};
  var tbody = document.getElementById('doctorAppsBody');
  var openBtn = document.getElementById('doctorOpenCreateBtn');
  var searchInput = document.getElementById('doctorAppSearch');
  var statusFilter = document.getElementById('doctorAppStatusFilter');
  var countEl = document.getElementById('doctorAppCount');
  var statsEl = document.getElementById('doctorAppStats');
  var allRows = [];

  function renderTable(rows) {
    if (!tbody) return;

    if (!allRows.length) {
      tbody.innerHTML = utils.renderEmptyState(8, {
        title: 'No Doctor applications yet',
        text: 'Create your first Doctor account application to begin the Maker-Checker approval workflow.',
        ctaId: 'doctorEmptyCreateBtn',
        ctaLabel: 'Create Doctor Application',
      });
      document.getElementById('doctorEmptyCreateBtn')?.addEventListener('click', openCreate);
      return;
    }

    if (!rows.length) {
      tbody.innerHTML = utils.renderNoResultsRow(8);
      return;
    }

    tbody.innerHTML = rows.map(function (r) {
      var editable = utils.canEdit(r.status);
      return (
        '<tr>' +
        '<td class="staff-apps-td--applicant" data-label="">' + utils.renderApplicantCell(r) + '</td>' +
        '<td data-label="PRC License"><span class="staff-apps-meta">' + utils.esc(r.prc_license_number || '—') + '</span></td>' +
        '<td data-label="Specialization"><span class="staff-apps-meta">' + utils.esc(r.specialization || '—') + '</span></td>' +
        '<td data-label="Hospital / Clinic"><span class="staff-apps-meta">' + utils.esc(r.facility || '—') + '</span></td>' +
        '<td data-label="Documents">' + utils.renderDocBadge(r.document_count, 2) + '</td>' +
        '<td data-label="Status">' + utils.renderStatusBadge(r) + '</td>' +
        '<td data-label="Submitted"><span class="staff-apps-meta staff-apps-meta--muted">' + utils.esc(utils.formatDate(r.submitted_at)) + '</span></td>' +
        '<td class="staff-apps-td--actions" data-label="Actions">' + utils.renderEditBtn(r.id, editable, 'doctor-edit-btn') + '</td>' +
        '</tr>'
      );
    }).join('');

    tbody.querySelectorAll('.doctor-edit-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openEditModal(parseInt(btn.dataset.id, 10));
      });
    });
  }

  function applyFilters() {
    var filtered = utils.filterRows(allRows, {
      search: searchInput ? searchInput.value : '',
      status: statusFilter ? statusFilter.value : 'all',
    });
    if (countEl) {
      var total = allRows.length;
      var shown = filtered.length;
      countEl.textContent = shown === total
        ? shown + ' application' + (shown === 1 ? '' : 's')
        : 'Showing ' + shown + ' of ' + total;
    }
    renderTable(filtered);
  }

  async function loadList() {
    try {
      var res = await fetch(api + '?action=list', { credentials: 'same-origin' });
      var json = await res.json();
      if (!json.success) {
        if (tbody) tbody.innerHTML = '<tr><td colspan="8"><div class="staff-apps-empty"><p class="staff-apps-empty__title">Could not load applications</p></div></td></tr>';
        return;
      }
      allRows = json.data.applications || [];
      utils.updateStats(statsEl, utils.computeStats(allRows));
      applyFilters();
    } catch (e) {
      if (tbody) tbody.innerHTML = '<tr><td colspan="8"><div class="staff-apps-empty"><p class="staff-apps-empty__title">Could not load applications</p></div></td></tr>';
    }
  }

  function openEditModal(id) {
    fetch(api + '?action=get&id=' + id, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (json) {
        if (!json.success || !json.data) return;
        var app = json.data;
        if (typeof window.openCreateDoctorModal === 'function') {
          window.openCreateDoctorModal();
        }
        var formId = 'doctorAppCreateForm';
        var form = document.getElementById(formId);
        if (!form) return;
        form.elements['first_name'].value = app.first_name || '';
        form.elements['middle_name'].value = app.middle_name || '';
        form.elements['last_name'].value = app.last_name || '';
        form.elements['birthdate'].value = app.birthdate || '';
        form.elements['email'].value = app.email || '';
        form.elements['phone'].value = app.phone || '';
        form.elements['prc_license_number'].value = app.prc_license_number || '';
        form.elements['specialization'].value = app.specialization || '';
        form.elements['facility'].value = app.facility || '';
        document.getElementById(formId + 'ApplicationId').value = app.id;
        var prcConfirm = document.getElementById(formId + 'PrcConfirm');
        if (prcConfirm) prcConfirm.checked = !!parseInt(app.prc_verification_confirmed, 10);
        var docList = document.getElementById(formId + 'DocList');
        if (docList) {
          docList.innerHTML = (app.documents || []).map(function (d) {
            return '<li>' + utils.esc(d.document_type.replace(/_/g, ' ')) + ': ' + utils.esc(d.original_name) + '</li>';
          }).join('');
        }
        var title = document.querySelector('#doctorAppCreateModal .admin-modal-title');
        if (title) title.textContent = 'Edit Doctor Application';
      });
  }

  function openCreate() {
    if (typeof window.openCreateDoctorModal === 'function') {
      window.openCreateDoctorModal();
      var title = document.querySelector('#doctorAppCreateModal .admin-modal-title');
      if (title) title.textContent = 'Create Doctor Application';
    }
  }

  if (openBtn) openBtn.addEventListener('click', openCreate);
  if (searchInput) searchInput.addEventListener('input', applyFilters);
  if (statusFilter) statusFilter.addEventListener('change', applyFilters);

  loadList();
})();
