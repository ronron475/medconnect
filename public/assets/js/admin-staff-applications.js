/**
 * MedConnect Admin — shared Doctor & BHW applications list utilities
 */
(function (global) {
  'use strict';

  var STATUS_CLASS = {
    draft: 'staff-app-status--draft',
    pending_approval: 'staff-app-status--pending',
    active: 'staff-app-status--active',
    approved: 'staff-app-status--active',
    rejected: 'staff-app-status--rejected',
    requires_documents: 'staff-app-status--docs',
  };

  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function statusClass(status) {
    return STATUS_CLASS[status] || 'staff-app-status--draft';
  }

  function canEdit(status) {
    return ['draft', 'rejected', 'requires_documents'].indexOf(status) >= 0;
  }

  function initials(name) {
    var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }

  function formatDate(value) {
    if (!value) return '—';
    var d = String(value).split(' ')[0];
    if (!d || d === '0000-00-00') return '—';
    try {
      var dt = new Date(d + 'T00:00:00');
      if (isNaN(dt.getTime())) return d;
      return dt.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) {
      return d;
    }
  }

  function computeStats(rows) {
    var stats = { total: rows.length, draft: 0, pending: 0, active: 0 };
    rows.forEach(function (r) {
      var s = r.status;
      if (s === 'draft') stats.draft++;
      else if (s === 'pending_approval') stats.pending++;
      else if (s === 'active' || s === 'approved') stats.active++;
    });
    return stats;
  }

  function filterRows(rows, opts) {
    var search = String((opts && opts.search) || '').trim().toLowerCase();
    var status = String((opts && opts.status) || 'all');
    return rows.filter(function (r) {
      if (status !== 'all' && r.status !== status) return false;
      if (!search) return true;
      var hay = [
        r.display_name,
        r.email,
        r.prc_license_number,
        r.specialization,
        r.facility,
        r.barangay_name,
      ].join(' ').toLowerCase();
      return hay.indexOf(search) >= 0;
    });
  }

  function renderApplicantCell(r) {
    return (
      '<div class="staff-apps-applicant">' +
      '<span class="staff-apps-avatar" aria-hidden="true">' + esc(initials(r.display_name)) + '</span>' +
      '<div><div class="staff-apps-applicant__name">' + esc(r.display_name) + '</div>' +
      '<div class="staff-apps-applicant__email">' + esc(r.email) + '</div></div></div>'
    );
  }

  function renderStatusBadge(r) {
    return (
      '<span class="staff-app-status ' + statusClass(r.status) + '">' +
      esc(r.status_label || r.status) +
      '</span>'
    );
  }

  function renderDocBadge(count, minRequired) {
    var n = parseInt(count, 10) || 0;
    var min = minRequired || 2;
    var complete = n >= min;
    var cls = 'staff-apps-docs' + (complete ? ' staff-apps-docs--complete' : '');
    return (
      '<span class="' + cls + '">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>' +
      '<polyline points="14 2 14 8 20 8"/></svg>' +
      esc(String(n)) + ' file' + (n === 1 ? '' : 's') +
      '</span>'
    );
  }

  function renderEditBtn(id, editable, btnClass) {
    if (!editable) {
      return '<span class="staff-apps-action staff-apps-action--muted" aria-label="No actions available">—</span>';
    }
    return (
      '<button type="button" class="staff-apps-action ' + esc(btnClass) + '" data-id="' + esc(String(id)) + '">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
      '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>' +
      '<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>' +
      'Edit</button>'
    );
  }

  function renderReviewBtn(id, btnClass, label) {
    return (
      '<button type="button" class="staff-apps-action staff-apps-action--primary ' + esc(btnClass) + '" data-id="' + esc(String(id)) + '">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">' +
      '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>' +
      esc(label || 'Review') +
      '</button>'
    );
  }

  function renderLoadingRow(colspan) {
    var L = window.MedConnectGlobalLoader || window.MedConnectLoader;
    if (L && typeof L.inlineRow === 'function') {
      return L.inlineRow(colspan, 'Loading applications…');
    }
    return '<tr><td colspan="' + colspan + '"><div class="mc-inline-loading staff-apps-loading" role="status"><span>Loading applications…</span></div></td></tr>';
  }

  function renderEmptyState(colspan, config) {
    var cfg = config || {};
    var cta = cfg.ctaId
      ? '<button type="button" class="mc-btn mc-btn--primary" id="' + esc(cfg.ctaId) + '">' + esc(cfg.ctaLabel || 'Create Application') + '</button>'
      : '';
    return (
      '<tr><td colspan="' + colspan + '">' +
      '<div class="staff-apps-empty">' +
      '<div class="staff-apps-empty__icon" aria-hidden="true">' +
      '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">' +
      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>' +
      '<polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>' +
      '</div>' +
      '<p class="staff-apps-empty__title">' + esc(cfg.title || 'No applications yet') + '</p>' +
      '<p class="staff-apps-empty__text">' + esc(cfg.text || 'Get started by creating your first application.') + '</p>' +
      cta +
      '</div></td></tr>'
    );
  }

  function renderNoResultsRow(colspan) {
    return (
      '<tr><td colspan="' + colspan + '">' +
      '<div class="staff-apps-empty">' +
      '<div class="staff-apps-empty__icon" aria-hidden="true">' +
      '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">' +
      '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' +
      '</div>' +
      '<p class="staff-apps-empty__title">No matching applications</p>' +
      '<p class="staff-apps-empty__text">Try adjusting your search or filter criteria.</p>' +
      '</div></td></tr>'
    );
  }

  function updateStats(container, stats) {
    if (!container) return;
    var map = {
      statTotal: stats.total,
      statDraft: stats.draft,
      statPending: stats.pending,
      statActive: stats.active,
    };
    Object.keys(map).forEach(function (id) {
      var el = container.querySelector('#' + id);
      if (el) el.textContent = String(map[id]);
    });
  }

  global.MCStaffApplications = {
    esc: esc,
    statusClass: statusClass,
    canEdit: canEdit,
    initials: initials,
    formatDate: formatDate,
    computeStats: computeStats,
    filterRows: filterRows,
    renderApplicantCell: renderApplicantCell,
    renderStatusBadge: renderStatusBadge,
    renderDocBadge: renderDocBadge,
    renderEditBtn: renderEditBtn,
    renderReviewBtn: renderReviewBtn,
    renderLoadingRow: renderLoadingRow,
    renderEmptyState: renderEmptyState,
    renderNoResultsRow: renderNoResultsRow,
    updateStats: updateStats,
  };
})(window);
