<?php
if (!isset($page_title)) {
    $page_title = 'Patient List';
}
if (!isset($bhw_current_file)) {
    $bhw_current_file = 'patients/list.php';
}
require __DIR__ . '/../partials/bhw_bootstrap.php';
$pl_css_path = ASSETS_PATH . '/css/bhw-patient-list.css';
$bhw_head_css = ASSET_BASE . '/assets/css/bhw-patient-list.css?v=' . (file_exists($pl_css_path) ? (int) filemtime($pl_css_path) : time());
require __DIR__ . '/../partials/layout_open.php';
$barangay_label = htmlspecialchars($bhw_barangay_name);
$pl_heading = 'Patient List';
$pl_intro = 'Search and assist patients already registered in <strong>Brgy. ' . $barangay_label . '</strong>.';
$pl_print_title = 'Patient List';

ob_start();
?>
(function () {
  var base = document.body.dataset.assetBase || '';
  var PAGE = 15;
  var allPatients = [];
  var filtered = [];
  var page = 1;

  var els = {
    search: document.getElementById('bhwPlSearch'),
    refresh: document.getElementById('bhwPlRefresh'),
    exportCsv: document.getElementById('bhwPlExportCsv'),
    exportPdf: document.getElementById('bhwPlExportPdf'),
    tbody: document.querySelector('#bhwPlTable tbody'),
    cards: document.getElementById('bhwPlCards'),
    tableWrap: document.getElementById('bhwPlTableWrap'),
    empty: document.getElementById('bhwPlEmpty'),
    pageInfo: document.getElementById('bhwPlPageInfo'),
    pagination: document.getElementById('bhwPlPagination'),
    drawer: document.getElementById('bhwPlDrawer'),
    drawerOverlay: document.getElementById('bhwPlDrawerOverlay'),
    drawerBody: document.getElementById('bhwPlDrawerBody'),
    drawerTitle: document.getElementById('bhwPlDrawerTitle')
  };

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function dash(v) { return v && String(v).trim() ? String(v).trim() : '—'; }

  function fmtDate(v) {
    if (!v) return '—';
    try {
      var d = new Date(v);
      if (isNaN(d.getTime())) return v;
      return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) { return v; }
  }

  function riskBadge(r) {
    if (!r) return '<span class="bhw-pl-badge bhw-pl-badge--none">None</span>';
    var u = r.toLowerCase();
    var cls = 'bhw-pl-badge--none';
    if (u === 'high' || u.indexOf('urgent') >= 0) cls = 'bhw-pl-badge--high';
    else if (u === 'moderate') cls = 'bhw-pl-badge--moderate';
    else if (u === 'low') cls = 'bhw-pl-badge--low';
    return '<span class="bhw-pl-badge ' + cls + '">' + esc(r) + '</span>';
  }

  function statusBadge(active) {
    return active
      ? '<span class="bhw-pl-badge bhw-pl-badge--active">Active</span>'
      : '<span class="bhw-pl-badge bhw-pl-badge--inactive">Inactive</span>';
  }

  var WORKFLOW_LABELS = {
    registered: 'Registered',
    awaiting_complaint: 'Awaiting complaint',
    ai_processing: 'AI processing',
    emergency: 'Emergency',
    urgent: 'Urgent',
    non_urgent: 'Non-urgent',
    appointment_scheduled: 'Appointment scheduled',
    referral_generated: 'Referral generated',
    consultation_completed: 'Consultation done',
    follow_up_monitoring: 'Follow-up'
  };

  function workflowBadge(status) {
    var key = (status || 'registered').toLowerCase();
    var label = WORKFLOW_LABELS[key] || key.replace(/_/g, ' ');
    var cls = 'bhw-pl-badge--none';
    if (key === 'awaiting_complaint' || key === 'ai_processing') cls = 'bhw-pl-badge--moderate';
    else if (key === 'emergency' || key === 'urgent') cls = 'bhw-pl-badge--high';
    else if (key === 'appointment_scheduled' || key === 'referral_generated') cls = 'bhw-pl-badge--low';
    else if (key === 'consultation_completed' || key === 'follow_up_monitoring') cls = 'bhw-pl-badge--active';
    return '<span class="bhw-pl-badge ' + cls + '" title="' + esc(label) + '">' + esc(label) + '</span>';
  }

  function fmtGender(g) {
    var v = dash(g);
    if (v === '—') return v;
    return v.charAt(0).toUpperCase() + v.slice(1).toLowerCase();
  }

  function applyFilters() {
    filtered = allPatients.slice();
    filtered.sort(function (a, b) {
      return (a.last_name + a.first_name).localeCompare(b.last_name + b.first_name);
    });

    page = 1;
    render();
  }

  function pageSlice() {
    var start = (page - 1) * PAGE;
    return filtered.slice(start, start + PAGE);
  }

  function renderTable(rows) {
    els.tbody.innerHTML = rows.map(function (p) {
      return '<tr data-id="' + p.id + '">' +
        '<td class="bhw-pl-col-id">#' + esc(p.id) + '</td>' +
        '<td class="bhw-pl-col-patient"><div class="bhw-pl-name">' + esc(p.last_name + ', ' + p.first_name) + '</div><div class="bhw-pl-email">' + esc(p.email) + '</div></td>' +
        '<td>' + esc(dash(p.age)) + '</td>' +
        '<td>' + esc(fmtGender(p.gender)) + '</td>' +
        '<td class="bhw-pl-col-contact">' + esc(dash(p.contact_number)) + '</td>' +
        '<td class="bhw-pl-col-status">' + riskBadge(p.risk_level) + '</td>' +
        '<td class="bhw-pl-col-status bhw-pl-col-workflow">' + workflowBadge(p.workflow_status) + '</td>' +
        '<td class="bhw-pl-col-status">' + statusBadge(p.is_active) + '</td>' +
        '<td class="bhw-pl-col-date">' + esc(fmtDate(p.created_at)) + '</td></tr>';
    }).join('');
  }

  function renderCards(rows) {
    els.cards.innerHTML = rows.map(function (p) {
      return '<article class="bhw-pl-card" data-id="' + p.id + '">' +
        '<div class="bhw-pl-card-head"><div><div class="bhw-pl-name">' + esc(p.last_name + ', ' + p.first_name) + '</div>' +
        '<div class="bhw-pl-email">#' + esc(p.id) + ' · ' + esc(p.email) + '</div></div>' +
        statusBadge(p.is_active) + '</div>' +
        '<dl class="bhw-pl-card-meta">' +
        '<div><dt>Age</dt><dd>' + esc(dash(p.age)) + '</dd></div>' +
        '<div><dt>Gender</dt><dd>' + esc(dash(p.gender)) + '</dd></div>' +
        '<div><dt>Contact</dt><dd>' + esc(dash(p.contact_number)) + '</dd></div>' +
        '<div><dt>Risk</dt><dd>' + esc(dash(p.risk_level)) + '</dd></div>' +
        '<div><dt>Workflow</dt><dd>' + esc(WORKFLOW_LABELS[(p.workflow_status || 'registered').toLowerCase()] || dash(p.workflow_status)) + '</dd></div>' +
        '</dl></article>';
    }).join('');
  }

  function renderPagination() {
    var pages = Math.max(1, Math.ceil(filtered.length / PAGE));
    if (page > pages) page = pages;
    var start = filtered.length ? (page - 1) * PAGE + 1 : 0;
    var end = Math.min(page * PAGE, filtered.length);
    els.pageInfo.textContent = filtered.length
      ? 'Showing ' + start + '–' + end + ' of ' + filtered.length + ' patients'
      : 'No patients to display';

    var html = '';
    html += '<button type="button" data-page="prev"' + (page <= 1 ? ' disabled' : '') + '>&lsaquo;</button>';
    for (var i = 1; i <= pages && i <= 7; i++) {
      html += '<button type="button" data-page="' + i + '" class="' + (i === page ? 'is-active' : '') + '">' + i + '</button>';
    }
    html += '<button type="button" data-page="next"' + (page >= pages ? ' disabled' : '') + '>&rsaquo;</button>';
    els.pagination.innerHTML = html;
  }

  function render() {
    if (!allPatients.length) {
      els.tableWrap.style.display = 'none';
      els.empty.style.display = 'block';
      var emptyTitle = els.empty.querySelector('h3');
      var emptyCopy = els.empty.querySelector('p');
      var q = (els.search.value || '').trim();
      if (emptyTitle) emptyTitle.textContent = q ? 'No matching patients' : 'No registered patients found';
      if (emptyCopy) {
        emptyCopy.textContent = q
          ? 'No patients in your assigned barangay match this search.'
          : 'Patients from this barangay appear here automatically after they complete the main registration.';
      }
      els.pageInfo.textContent = '';
      els.pagination.innerHTML = '';
      return;
    }
    if (!filtered.length) {
      els.empty.style.display = 'none';
      els.tableWrap.style.display = 'block';
      els.tbody.innerHTML = '<tr><td colspan="8" class="text-muted text-center py-4">No patients match your search.</td></tr>';
      els.cards.innerHTML = '';
      els.pageInfo.textContent = '0 patients match this search';
      els.pagination.innerHTML = '';
      return;
    }
    els.empty.style.display = 'none';
    els.tableWrap.style.display = 'block';
    var slice = pageSlice();
    renderTable(slice);
    renderCards(slice);
    renderPagination();
    bindRowEvents();
  }

  function bindRowEvents() {
    document.querySelectorAll('#bhwPlTable tbody tr[data-id]').forEach(function (tr) {
      tr.onclick = function () {
        openDrawer(parseInt(tr.dataset.id, 10));
      };
    });
    document.querySelectorAll('.bhw-pl-card[data-id]').forEach(function (card) {
      card.onclick = function () {
        openDrawer(parseInt(card.dataset.id, 10));
      };
    });
  }

  function profilePrintRow(label, val) {
    return '<tr><th scope="row">' + esc(label) + '</th><td>' + esc(dash(val)) + '</td></tr>';
  }

  function buildProfilePrintSheet(p) {
    var name = ((p.first_name || '') + ' ' + (p.last_name || '')).trim() || 'Patient';
    var now = new Date().toLocaleString('en-US', {
      year: 'numeric', month: 'short', day: 'numeric',
      hour: 'numeric', minute: '2-digit', hour12: true
    });
    return '<div class="bhw-pl-print-header">' +
      '<div class="bhw-pl-print-brand"><strong>medConnect</strong><span>City Health Office — Bago City</span></div>' +
      '<h1 class="bhw-pl-print-title">Patient Profile</h1>' +
      '<p class="bhw-pl-print-sub">Brgy. <?= $barangay_label ?> · ' + esc(name) + ' · #' + esc(p.id) + ' · ' + now + '</p>' +
      '</div>' +
      '<div class="bhw-pl-profile-report">' +
      '<div class="bhw-pl-profile-report__banner">' +
      '<div class="bhw-pl-profile-report__identity">' +
      '<h2>' + esc(name) + '</h2>' +
      '<p>Patient ID #' + esc(p.id) + ' · ' + esc(fmtGender(p.gender)) + ' · Age ' + esc(dash(p.age)) + '</p>' +
      '</div></div>' +
      '<section class="bhw-pl-profile-report__section">' +
      '<h3>Profile Information</h3>' +
      '<table class="bhw-pl-profile-table"><tbody>' +
      profilePrintRow('Email', p.email) +
      profilePrintRow('Contact Number', p.contact_number) +
      profilePrintRow('Date of Birth', fmtDate(p.date_of_birth)) +
      profilePrintRow('Barangay', p.barangay) +
      profilePrintRow('Purok', p.purok) +
      profilePrintRow('Blood Type', p.blood_type) +
      '</tbody></table></section>' +
      '<section class="bhw-pl-profile-report__section">' +
      '<h3>Medical Summary</h3>' +
      '<table class="bhw-pl-profile-table"><tbody>' +
      profilePrintRow('Existing Conditions', p.existing_conditions) +
      profilePrintRow('Allergies', p.allergies) +
      profilePrintRow('Current Medications', p.current_medications) +
      '</tbody></table></section>' +
      '<p class="bhw-pl-print-footer">Confidential health record · Generated via medConnect BHW Portal · Brgy. <?= $barangay_label ?></p>' +
      '</div>';
  }

  var savedPrintTitle = document.title;

  function openDrawer(id, options) {
    options = options || {};
    BhwPortal.get('patients.php', { action: 'get', patient_id: id }).then(function (r) {
      if (!r.success) return;
      var p = r.patient;
      var name = ((p.first_name || '') + ' ' + (p.last_name || '')).trim() || 'Patient';

      if (options.printAfter) {
        var printEl = document.getElementById('bhwPlPrintProfile');
        if (printEl) {
          printEl.innerHTML = buildProfilePrintSheet(p);
          printEl.hidden = false;
        }
        savedPrintTitle = document.title;
        document.title = 'Patient Profile — ' + name;
        document.body.classList.add('bhw-pl-print-profile');
        requestAnimationFrame(function () { window.print(); });
        return;
      }

      els.drawerTitle.textContent = name;
      els.drawerBody.innerHTML =
        '<div class="bhw-pl-drawer-section"><h4>Profile</h4><dl class="bhw-pl-drawer-dl">' +
        row('Patient ID', '#' + p.id) + row('Email', p.email) + row('Contact', p.contact_number) +
        row('Gender', p.gender) + row('Age', p.age) + row('DOB', fmtDate(p.date_of_birth)) +
        row('Barangay', p.barangay) + row('Purok', p.purok) + row('Blood type', p.blood_type) +
        row('Workflow', WORKFLOW_LABELS[(p.workflow_status || 'registered').toLowerCase()] || p.workflow_status) +
        '</dl></div>' +
        '<div class="bhw-pl-drawer-section"><h4>Medical Summary</h4><dl class="bhw-pl-drawer-dl">' +
        row('Conditions', p.existing_conditions) + row('Allergies', p.allergies) +
        row('Medications', p.current_medications) + '</dl></div>';
      els.drawer.classList.add('is-open');
      els.drawerOverlay.classList.add('is-open');
    });
  }

  function row(label, val) {
    return '<div><dt>' + esc(label) + '</dt><dd>' + esc(dash(val)) + '</dd></div>';
  }

  function closeDrawer() {
    els.drawer.classList.remove('is-open');
    els.drawerOverlay.classList.remove('is-open');
  }

  function loadPatients() {
    var q = (els.search.value || '').trim();
    BhwPortal.get('patients.php', { action: 'list', q: q }).then(function (r) {
      if (!r.success) {
        els.tbody.innerHTML = '<tr><td colspan="8">' + esc(r.message || 'Failed to load') + '</td></tr>';
        return;
      }
      allPatients = r.patients || [];
      applyFilters();
    });
  }

  function updatePrintMeta() {
    var meta = document.getElementById('bhwPlPrintMeta');
    if (!meta) return;
    var now = new Date().toLocaleString('en-US', {
      year: 'numeric', month: 'short', day: 'numeric',
      hour: 'numeric', minute: '2-digit', hour12: true
    });
    var count = filtered.length;
    meta.textContent = 'Brgy. <?= $barangay_label ?> · ' + count + ' patient' + (count === 1 ? '' : 's') + ' · ' + now;
  }

  function prepareListPrint() {
    updatePrintMeta();
    renderTable(filtered);
    els.cards.innerHTML = '';
  }

  function restoreListView() {
    document.body.classList.remove('bhw-pl-print-profile');
    document.title = savedPrintTitle || document.title;
    var printEl = document.getElementById('bhwPlPrintProfile');
    if (printEl) {
      printEl.innerHTML = '';
      printEl.hidden = true;
    }
    closeDrawer();
    render();
  }

  window.addEventListener('beforeprint', function () {
    document.body.classList.add('bhw-print-mode');
    if (document.body.classList.contains('bhw-pl-print-profile')) return;
    savedPrintTitle = document.title;
    document.title = 'Patient List — medConnect';
    prepareListPrint();
  });

  window.addEventListener('afterprint', function () {
    document.body.classList.remove('bhw-print-mode');
    restoreListView();
  });

  var BARANGAY_NAME = <?= json_encode((string) $bhw_barangay_name, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

  // Apostrophe-prefix text starting with = + - @ so spreadsheets never run it as a formula.
  function csvCell(v) {
    var s = v == null ? '' : String(v);
    if (/^[=+\-@\t\r]/.test(s) && !/^-?\d+(\.\d+)?$/.test(s)) s = "'" + s;
    return '"' + s.replace(/"/g, '""') + '"';
  }

  // Keep phone numbers as text so Excel does not drop the leading 0 or show 6.39E+11.
  function phoneText(v) {
    var s = dash(v);
    return /^0\d{6,}$/.test(s.replace(/\s+/g, '')) ? "'" + s : s;
  }

  function exportCsv() {
    var now = new Date();
    var generated = now.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) +
      ' at ' + now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    var rows = [
      ['medConnect Patient List'],
      ['Barangay', BARANGAY_NAME],
      ['Generated', generated],
      ['Total Patients', filtered.length],
      [],
      ['Patient ID', 'Last Name', 'First Name', 'Email', 'Age', 'Sex', 'Purok', 'Barangay', 'Contact', 'Risk', 'Workflow', 'Account Status', 'Registered']
    ];
    filtered.forEach(function (p) {
      rows.push([
        p.id, dash(p.last_name), dash(p.first_name), dash(p.email), dash(p.age), fmtGender(p.gender),
        dash(p.purok), dash(p.barangay || BARANGAY_NAME), phoneText(p.contact_number), p.risk_level || 'None',
        WORKFLOW_LABELS[(p.workflow_status || 'registered').toLowerCase()] || dash(p.workflow_status),
        p.is_active ? 'Active' : 'Inactive', fmtDate(p.created_at)
      ]);
    });
    var csv = rows.map(function (r) { return r.map(csvCell).join(','); }).join('\r\n');
    var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' });
    var slug = String(BARANGAY_NAME || 'Barangay').replace(/[^A-Za-z0-9]+/g, '_').replace(/^_|_$/g, '');
    var stamp = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'Patient_List_' + slug + '_' + stamp + '.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
  }

  var searchTimer = null;
  els.search.addEventListener('input', function () {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadPatients, 250);
  });
  els.refresh.addEventListener('click', loadPatients);
  els.exportCsv.addEventListener('click', exportCsv);
  els.exportPdf.addEventListener('click', function () {
    window.print();
  });
  els.pagination.addEventListener('click', function (e) {
    var btn = e.target.closest('button[data-page]');
    if (!btn) return;
    var pages = Math.ceil(filtered.length / PAGE);
    if (btn.dataset.page === 'prev') page = Math.max(1, page - 1);
    else if (btn.dataset.page === 'next') page = Math.min(pages, page + 1);
    else page = parseInt(btn.dataset.page, 10);
    render();
  });
  document.getElementById('bhwPlDrawerClose').addEventListener('click', closeDrawer);
  els.drawerOverlay.addEventListener('click', closeDrawer);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeDrawer();
  });

  loadPatients();
})();
<?php
$bhw_inline_script = ob_get_clean();
?>

<div class="bhw-pl-page">

  <div class="bhw-pl-print-header" aria-hidden="true">
    <div class="bhw-pl-print-brand">
      <strong>medConnect</strong>
      <span>City Health Office — Bago City</span>
    </div>
    <h1 class="bhw-pl-print-title"><?= htmlspecialchars($pl_print_title) ?></h1>
    <p class="bhw-pl-print-sub" id="bhwPlPrintMeta">Brgy. <?= $barangay_label ?> · Generated <?= date('M j, Y') . ' • ' . date('g:i A') ?></p>
  </div>

  <header class="bhw-pl-header bhw-page-intro">
    <div>
      <p class="bhw-page-intro__desc"><?= $pl_intro ?></p>
    </div>
    <div class="bhw-pl-header-actions no-print">
      <button type="button" class="bhw-pl-btn bhw-pl-btn--ghost" id="bhwPlRefresh" aria-label="Refresh list">Refresh</button>
      <button type="button" class="bhw-pl-btn bhw-pl-btn--outline" id="bhwPlExportCsv">Export CSV</button>
      <button type="button" class="bhw-pl-btn bhw-pl-btn--outline" id="bhwPlExportPdf">Export PDF</button>
    </div>
  </header>

  <div class="bhw-pl-toolbar no-print" role="search">
    <div class="bhw-pl-toolbar-search">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="search" id="bhwPlSearch" placeholder="Search patient name, email, contact, ID…" aria-label="Search patients">
    </div>
  </div>

  <div id="bhwPlEmpty" class="bhw-pl-table-wrap bhw-pl-empty" style="display:none;">
    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="11" x2="23" y2="11"/></svg>
    <h3>No registered patients found</h3>
    <p>Patients from this barangay appear here automatically after they complete the main registration.</p>
    <a href="update.php" class="bhw-pl-btn bhw-pl-btn--primary">Assist Patient</a>
  </div>

  <div class="bhw-pl-table-wrap" id="bhwPlTableWrap">
    <div class="bhw-pl-table-scroll" id="bhwPlTable">
      <table class="bhw-pl-table">
        <thead>
          <tr>
            <th scope="col">ID</th>
            <th scope="col">Patient</th>
            <th scope="col">Age</th>
            <th scope="col">Gender</th>
            <th scope="col">Contact</th>
            <th scope="col">Risk</th>
            <th scope="col" class="bhw-pl-col-workflow">Workflow</th>
            <th scope="col">Account</th>
            <th scope="col">Registered</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>
    <div class="bhw-pl-cards" id="bhwPlCards" aria-label="Patient cards"></div>
    <div class="bhw-pl-footer no-print">
      <span id="bhwPlPageInfo"></span>
      <div class="bhw-pl-pagination" id="bhwPlPagination" aria-label="Pagination"></div>
    </div>
    <p class="bhw-pl-print-footer bhw-pl-print-footer--list" aria-hidden="true">Confidential health record · Generated via medConnect BHW Portal · Brgy. <?= $barangay_label ?></p>
  </div>
</div>

<div class="bhw-pl-print-profile-sheet" id="bhwPlPrintProfile" hidden aria-hidden="true"></div>

<div class="bhw-pl-drawer-overlay" id="bhwPlDrawerOverlay" aria-hidden="true"></div>
<aside class="bhw-pl-drawer" id="bhwPlDrawer" role="dialog" aria-modal="true" aria-labelledby="bhwPlDrawerTitle">
  <div class="bhw-pl-drawer-head">
    <div>
      <div class="bhw-pl-drawer-eyebrow">Patient Profile</div>
      <h3 id="bhwPlDrawerTitle">—</h3>
    </div>
    <button type="button" class="bhw-pl-drawer-close" id="bhwPlDrawerClose" aria-label="Close profile">&times;</button>
  </div>
  <div class="bhw-pl-drawer-body" id="bhwPlDrawerBody"></div>
</aside>

<?php require __DIR__ . '/../partials/layout_close.php'; ?>
