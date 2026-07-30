<?php
if (!defined('BASE_PATH')) {
    $d = __DIR__;
    while ($d !== dirname($d)) {
        if (is_file($d . '/mc_load.php')) {
            require_once $d . '/mc_load.php';
            break;
        }
        $d = dirname($d);
    }
}
require_once BASE_PATH . '/app/includes/auth_guard.php';
require_once __DIR__ . '/_portal_access.php';
require_once BASE_PATH . '/app/includes/triage_assessment_schema.php';

$page_title = 'AI Review Assignments';
$apiUrl = ASSET_BASE . '/app/api/admin/triage_review_assignments.php';

require_once __DIR__ . '/partials/layout_open.php';
?>

<div class="header-row" style="margin-bottom:24px;">
  <h2 class="text-h2">AI Review Assignments</h2>
  <p class="text-muted" style="margin:8px 0 0;">
    Reassign reviewing doctors for non-urgent self-care cases. Patients stay locked to the assigned provider when booking follow-up consultations.
  </p>
</div>

<div class="mc-card" style="margin-bottom:16px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
  <label class="text-sm" for="aiReviewFilter">Show</label>
  <select id="aiReviewFilter" class="form-control" style="max-width:220px;">
    <option value="active">Pending + approved (30 days)</option>
    <option value="pending">Pending review only</option>
    <option value="approved">Approved only</option>
  </select>
  <button type="button" class="mc-btn mc-btn--outline" id="aiReviewRefresh">Refresh</button>
  <span class="text-xs text-muted" id="aiReviewStatus"></span>
</div>

<div class="mc-card" style="padding:0;overflow:hidden;">
  <table class="mc-table" id="aiReviewTable">
    <thead>
      <tr>
        <th>Patient</th>
        <th>Concern</th>
        <th>Status</th>
        <th>Assigned reviewer</th>
        <th>Submitted</th>
        <th>Reassign</th>
      </tr>
    </thead>
    <tbody id="aiReviewTbody">
      <tr><td colspan="6" class="text-muted" style="padding:20px;">Loading…</td></tr>
    </tbody>
  </table>
</div>

<script>
(function () {
  var apiUrl = <?= json_encode($apiUrl) ?>;
  var csrf = document.body.dataset.csrf || '';
  var providers = [];
  var filterEl = document.getElementById('aiReviewFilter');
  var tbody = document.getElementById('aiReviewTbody');
  var statusEl = document.getElementById('aiReviewStatus');

  function esc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  }

  function formatDate(raw) {
    if (!raw) return '—';
    try {
      return new Date(raw.replace(' ', 'T')).toLocaleString();
    } catch (e) {
      return raw;
    }
  }

  function providerOptions(selectedId) {
    var html = '';
    providers.forEach(function (p) {
      var sel = Number(p.id) === Number(selectedId) ? ' selected' : '';
      html += '<option value="' + esc(p.id) + '"' + sel + '>' + esc(p.name) + '</option>';
    });
    return html;
  }

  function renderRows(rows) {
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-muted" style="padding:20px;">No AI review cases in this filter.</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(function (row) {
      var status = row.recommendation_status || '';
      var badge = status === 'pending_approval'
        ? '<span class="mc-badge mc-badge--pending">Pending</span>'
        : '<span class="mc-badge mc-badge--approved">Approved</span>';
      var assignId = row.assigned_provider_id || '';
      return '<tr data-triage-id="' + esc(row.id) + '">' +
        '<td>' + esc(row.patient_name) + '</td>' +
        '<td style="max-width:240px;">' + esc((row.chief_complaint || '').slice(0, 120)) + '</td>' +
        '<td>' + badge + '</td>' +
        '<td>' + esc(row.reviewer_name || 'Unassigned') + '</td>' +
        '<td style="white-space:nowrap;font-size:12px;">' + esc(formatDate(row.assessed_at)) + '</td>' +
        '<td style="min-width:200px;">' +
          '<select class="form-control ai-review-provider-select" data-triage="' + esc(row.id) + '" style="display:inline-block;max-width:160px;margin-right:6px;">' +
            providerOptions(assignId) +
          '</select>' +
          '<button type="button" class="mc-btn mc-btn--primary mc-btn--sm ai-review-save" data-triage="' + esc(row.id) + '">Save</button>' +
        '</td>' +
      '</tr>';
    }).join('');
  }

  async function load() {
    statusEl.textContent = 'Loading…';
    var status = filterEl ? filterEl.value : 'active';
    try {
      var res = await fetch(apiUrl + '?status=' + encodeURIComponent(status), { credentials: 'same-origin' });
      var data = await res.json();
      if (!data.success) {
        statusEl.textContent = data.message || 'Could not load.';
        return;
      }
      providers = data.providers || [];
      renderRows(data.rows || []);
      statusEl.textContent = 'Updated ' + new Date().toLocaleTimeString();
    } catch (e) {
      statusEl.textContent = 'Load failed.';
    }
  }

  tbody.addEventListener('click', async function (ev) {
    var btn = ev.target.closest('.ai-review-save');
    if (!btn) return;
    var triageId = btn.getAttribute('data-triage');
    var row = btn.closest('tr');
    var sel = row ? row.querySelector('.ai-review-provider-select') : null;
    if (!triageId || !sel || !sel.value) return;
    btn.disabled = true;
    try {
      var fd = new FormData();
      fd.append('action', 'reassign');
      fd.append('triage_id', triageId);
      fd.append('provider_id', sel.value);
      fd.append('csrf_token', csrf);
      var res = await fetch(apiUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
      var data = await res.json();
      alert(data.message || (data.success ? 'Saved.' : 'Could not reassign.'));
      if (data.success) load();
    } catch (e) {
      alert('Could not reassign.');
    } finally {
      btn.disabled = false;
    }
  });

  document.getElementById('aiReviewRefresh').addEventListener('click', load);
  if (filterEl) filterEl.addEventListener('change', load);
  load();
})();
</script>

<?php require_once __DIR__ . '/partials/layout_close.php'; ?>
