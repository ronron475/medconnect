/**
 * MedConnect Admin / SuperAdmin — AI Review Assignments (inbox / monitoring)
 */
(function () {
  'use strict';

  var root = document.getElementById('airReviewRoot');
  if (!root) return;

  var apiUrl = root.dataset.api || '';
  var csrf = document.body.dataset.csrf || '';
  var allRows = [];
  var unreadCount = 0;

  var filterEl = document.getElementById('aiReviewFilter');
  var searchEl = document.getElementById('aiReviewSearch');
  var tbody = document.getElementById('aiReviewTbody');
  var statusEl = document.getElementById('aiReviewStatus');
  var countEl = document.getElementById('aiReviewCount');

  function esc(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/"/g, '&quot;');
  }

  function initials(name) {
    var parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }

  function formatSubmitted(raw) {
    if (!raw) return '—';
    try {
      var d = new Date(String(raw).replace(' ', 'T'));
      return d.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
      });
    } catch (e) {
      return raw;
    }
  }

  function providerDisplay(row) {
    var name = String(row.assigned_provider_name || row.reviewer_name || '').trim();
    if (!name || !row.assigned_provider_id) {
      return '<span class="staff-apps-meta staff-apps-meta--muted air-review-reviewer is-unassigned">No assigned doctor</span>';
    }
    return '<span class="staff-apps-meta">' + esc(name) + '</span>';
  }

  function refreshNavBadge(unread) {
    var n = Math.max(0, parseInt(unread, 10) || 0);
    unreadCount = n;
    var text = n <= 0 ? '' : (n > 9 ? '9+' : String(n));
    document.querySelectorAll('[data-nav-badge="ai_review_pending"]').forEach(function (badge) {
      badge.textContent = text;
      badge.hidden = n <= 0;
      badge.setAttribute('aria-hidden', n <= 0 ? 'true' : 'false');
    });
  }

  function updateStats(rows) {
    var pending = 0;
    var approved = 0;
    var unread = 0;
    rows.forEach(function (row) {
      if (row.recommendation_status === 'pending_approval') pending += 1;
      if (row.recommendation_status === 'approved') approved += 1;
      if (row.is_unread) unread += 1;
    });
    var totalEl = document.getElementById('airStatTotal');
    var pendingEl = document.getElementById('airStatPending');
    var approvedEl = document.getElementById('airStatApproved');
    var unreadEl = document.getElementById('airStatUnread');
    if (totalEl) totalEl.textContent = String(rows.length);
    if (pendingEl) pendingEl.textContent = String(pending);
    if (approvedEl) approvedEl.textContent = String(approved);
    if (unreadEl) unreadEl.textContent = String(typeof unreadCount === 'number' ? unreadCount : unread);
  }

  function filteredRows() {
    var q = searchEl ? String(searchEl.value || '').trim().toLowerCase() : '';
    if (!q) return allRows.slice();
    return allRows.filter(function (row) {
      var hay = [
        row.patient_name,
        row.chief_complaint,
        row.assigned_provider_name || row.reviewer_name,
        row.recommendation_status,
      ].join(' ').toLowerCase();
      return hay.indexOf(q) !== -1;
    });
  }

  function emptyStateHtml() {
    return (
      '<tr><td colspan="6">' +
        '<div class="staff-apps-empty">' +
          '<div class="staff-apps-empty__icon" aria-hidden="true">' +
            '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>' +
          '</div>' +
          '<p class="staff-apps-empty__title">No AI review cases found</p>' +
          '<p class="staff-apps-empty__text">Try another filter or search term.</p>' +
        '</div>' +
      '</td></tr>'
    );
  }

  function renderRows(rows) {
    updateStats(allRows);
    if (countEl) {
      countEl.textContent = rows.length + ' case' + (rows.length === 1 ? '' : 's');
    }

    if (!rows.length) {
      tbody.innerHTML = emptyStateHtml();
      return;
    }

    tbody.innerHTML = rows.map(function (row) {
      var status = row.recommendation_status || '';
      var badge = status === 'pending_approval'
        ? '<span class="staff-app-status staff-app-status--pending">Pending</span>'
        : '<span class="staff-app-status staff-app-status--active">Approved</span>';
      var unread = !!row.is_unread;
      var actionLabel = unread ? 'Mark as Read' : 'Mark as Unread';
      var actionClass = unread ? 'mc-btn--primary' : 'mc-btn--outline';
      var unreadBadge = unread
        ? ' <span class="air-review-unread-pill">Unread</span>'
        : '';

      return '<tr data-triage-id="' + esc(row.id) + '"' + (unread ? ' class="is-unread"' : '') + '>' +
        '<td class="staff-apps-td--applicant" data-label="">' +
          '<div class="staff-apps-applicant">' +
            '<div class="staff-apps-avatar" aria-hidden="true">' + esc(initials(row.patient_name)) + '</div>' +
            '<div class="staff-apps-applicant__name">' + esc(row.patient_name) + unreadBadge + '</div>' +
          '</div>' +
        '</td>' +
        '<td data-label="Concern">' +
          '<div class="air-review-concern" title="' + esc(row.chief_complaint || '') + '">' + esc(row.chief_complaint || '—') + '</div>' +
        '</td>' +
        '<td data-label="Status">' + badge + '</td>' +
        '<td data-label="Assigned Provider">' + providerDisplay(row) + '</td>' +
        '<td data-label="Submitted"><span class="staff-apps-meta staff-apps-meta--muted">' + esc(formatSubmitted(row.assessed_at)) + '</span></td>' +
        '<td class="staff-apps-td--actions" data-label="Actions">' +
          '<div class="air-review-actions">' +
            '<button type="button" class="mc-btn ' + actionClass + ' mc-btn--sm air-review-actions__toggle ai-review-read-toggle" data-triage="' + esc(row.id) + '" data-unread="' + (unread ? '1' : '0') + '">' +
              actionLabel +
            '</button>' +
          '</div>' +
        '</td>' +
      '</tr>';
    }).join('');
  }

  function setStatus(msg, isError) {
    if (!statusEl) return;
    statusEl.textContent = msg || '';
    statusEl.classList.toggle('is-error', !!isError);
  }

  function notify(message, success) {
    if (window.McModal && typeof window.McModal.alert === 'function') {
      window.McModal.alert({
        title: success ? 'Updated' : 'Could not update',
        message: message,
        variant: success ? 'success' : 'error',
        icon: success ? 'success' : 'error',
      });
      return;
    }
    alert(message);
  }

  async function load() {
    setStatus('Loading…', false);
    tbody.innerHTML =
      '<tr><td colspan="6"><div class="staff-apps-loading">' +
      '<div class="staff-apps-loading__spinner" aria-hidden="true"></div>Loading AI review cases…</div></td></tr>';

    var status = filterEl ? filterEl.value : 'active';
    try {
      var res = await fetch(apiUrl + '?status=' + encodeURIComponent(status), { credentials: 'same-origin' });
      var data = await res.json();
      if (!data.success) {
        setStatus(data.message || 'Could not load.', true);
        tbody.innerHTML = emptyStateHtml();
        return;
      }
      allRows = data.rows || [];
      if (typeof data.unread_count === 'number') {
        refreshNavBadge(data.unread_count);
      }
      renderRows(filteredRows());
      setStatus('Updated ' + new Date().toLocaleTimeString(), false);
    } catch (e) {
      setStatus('Load failed.', true);
      tbody.innerHTML = emptyStateHtml();
    }
  }

  tbody.addEventListener('click', async function (ev) {
    var btn = ev.target.closest('.ai-review-read-toggle');
    if (!btn) return;
    var triageId = btn.getAttribute('data-triage');
    if (!triageId) return;

    var currentlyUnread = btn.getAttribute('data-unread') === '1';
    var action = currentlyUnread ? 'mark_read' : 'mark_unread';

    btn.disabled = true;
    btn.classList.add('is-saving');
    var prevLabel = btn.textContent;
    btn.textContent = currentlyUnread ? 'Marking…' : 'Updating…';
    try {
      var fd = new FormData();
      fd.append('action', action);
      fd.append('triage_id', triageId);
      fd.append('csrf_token', csrf);
      var res = await fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
      var data = await res.json();
      if (!data.success) {
        notify(data.message || 'Could not update read state.', false);
        return;
      }
      if (typeof data.unread_count === 'number') {
        refreshNavBadge(data.unread_count);
      }
      var row = allRows.find(function (r) { return String(r.id) === String(triageId); });
      if (row) {
        row.is_unread = !!data.is_unread;
      }
      renderRows(filteredRows());
    } catch (e) {
      notify('Could not update read state. Please try again.', false);
      btn.textContent = prevLabel;
    } finally {
      btn.disabled = false;
      btn.classList.remove('is-saving');
    }
  });

  var refreshBtn = document.getElementById('aiReviewRefresh');
  if (refreshBtn) refreshBtn.addEventListener('click', load);
  if (filterEl) filterEl.addEventListener('change', load);
  if (searchEl) {
    var debounce;
    searchEl.addEventListener('input', function () {
      clearTimeout(debounce);
      debounce = setTimeout(function () {
        renderRows(filteredRows());
      }, 200);
    });
  }

  load();
})();
