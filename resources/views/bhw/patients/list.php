<?php
$page_title = 'Patient List';
$bhw_current_file = 'patients/list.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$barangay_label = htmlspecialchars($bhw_barangay_name);
$registered_hint = isset($_GET['registered']) ? (int) $_GET['registered'] : 0;

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
    purok: document.getElementById('bhwPlPurok'),
    status: document.getElementById('bhwPlStatus'),
    gender: document.getElementById('bhwPlGender'),
    age: document.getElementById('bhwPlAge'),
    sort: document.getElementById('bhwPlSort'),
    reset: document.getElementById('bhwPlReset'),
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

  function isHighRisk(r) {
    var u = (r || '').toLowerCase();
    return u === 'high' || u.indexOf('urgent') >= 0;
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

  function actionLinks(id, stopProp) {
    var q = '?patient_id=' + id;
    return '<div class="bhw-pl-actions"' + (stopProp ? ' onclick="event.stopPropagation()"' : '') + '>' +
      '<a class="bhw-pl-action" href="#" data-view="' + id + '" title="View Profile"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></a>' +
      '<a class="bhw-pl-action" href="update.php' + q + '" title="Update"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a>' +
      '<a class="bhw-pl-action" href="../triage/submit.php' + q + '" title="Triage"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg></a>' +
      '<a class="bhw-pl-action" href="../records/index.php?patient_id=' + id + '" title="Records"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></a>' +
      '<a class="bhw-pl-action" href="../consultations/index.php" title="Consultations"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></a>' +
      '<a class="bhw-pl-action" href="../consultations/index.php?filter=active" title="Assist video"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/></svg></a>' +
      '<a class="bhw-pl-action" href="../referral/create.php' + q + '" title="Refer"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg></a>' +
      '<button type="button" class="bhw-pl-action" data-print="' + id + '" title="Print"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg></button>' +
      '</div>';
  }

  function populatePurokFilter(rows) {
    var puroks = {};
    rows.forEach(function (p) {
      var b = dash(p.barangay);
      if (b !== '—') puroks[b] = true;
    });
    var opts = '<option value="">All Puroks</option>';
    Object.keys(puroks).sort().forEach(function (k) {
      opts += '<option value="' + esc(k) + '">' + esc(k) + '</option>';
    });
    els.purok.innerHTML = opts;
  }

  function applyFilters() {
    var q = (els.search.value || '').toLowerCase().trim();
    var purok = els.purok.value;
    var status = els.status.value;
    var gender = els.gender.value;
    var ageGroup = els.age.value;
    var sort = els.sort.value;

    filtered = allPatients.filter(function (p) {
      var hay = ((p.first_name || '') + ' ' + (p.last_name || '') + ' ' + (p.email || '') + ' ' + (p.contact_number || '') + ' ' + (p.id || '')).toLowerCase();
      if (q && hay.indexOf(q) < 0) return false;
      if (purok && dash(p.barangay) !== purok) return false;
      if (status === 'active' && !p.is_active) return false;
      if (status === 'inactive' && p.is_active) return false;
      if (gender && (p.gender || '').toLowerCase() !== gender) return false;
      var age = parseInt(p.age, 10);
      if (ageGroup === 'child' && (isNaN(age) || age >= 18)) return false;
      if (ageGroup === 'adult' && (isNaN(age) || age < 18 || age >= 60)) return false;
      if (ageGroup === 'senior' && (isNaN(age) || age < 60)) return false;
      return true;
    });

    filtered.sort(function (a, b) {
      if (sort === 'name') return (a.last_name + a.first_name).localeCompare(b.last_name + b.first_name);
      if (sort === 'name-desc') return (b.last_name + b.first_name).localeCompare(a.last_name + a.first_name);
      if (sort === 'registered') return String(b.created_at || '').localeCompare(String(a.created_at || ''));
      if (sort === 'registered-asc') return String(a.created_at || '').localeCompare(String(b.created_at || ''));
      if (sort === 'risk') return (isHighRisk(b.risk_level) ? 1 : 0) - (isHighRisk(a.risk_level) ? 1 : 0);
      return 0;
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
        '<td>#' + esc(p.id) + '</td>' +
        '<td><div class="bhw-pl-name">' + esc(p.last_name + ', ' + p.first_name) + '</div><div class="bhw-pl-email">' + esc(p.email) + '</div></td>' +
        '<td>' + esc(dash(p.age)) + '</td>' +
        '<td>' + esc(dash(p.gender)) + '</td>' +
        '<td>' + esc(dash(p.contact_number)) + '</td>' +
        '<td>' + esc(dash(p.barangay)) + '</td>' +
        '<td>' + esc(fmtDate(p.last_consult)) + '</td>' +
        '<td>' + esc(dash(p.provider_name)) + '</td>' +
        '<td>' + riskBadge(p.risk_level) + '</td>' +
        '<td>' + statusBadge(p.is_active) + '</td>' +
        '<td>' + esc(fmtDate(p.created_at)) + '</td>' +
        '<td>' + actionLinks(p.id, true) + '</td></tr>';
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
        '</dl>' + actionLinks(p.id, true) + '</article>';
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
      els.empty.querySelector('h3').textContent = 'No registered patients found';
      els.empty.querySelector('p').textContent = 'Register your first patient in this barangay to begin managing care.';
      els.pageInfo.textContent = '';
      els.pagination.innerHTML = '';
      return;
    }
    if (!filtered.length) {
      els.empty.style.display = 'none';
      els.tableWrap.style.display = 'block';
      els.tbody.innerHTML = '<tr><td colspan="12" class="text-muted text-center py-4">No patients match your filters. <button type="button" class="btn btn-link p-0" id="bhwPlResetInline">Reset filters</button></td></tr>';
      els.cards.innerHTML = '';
      els.pageInfo.textContent = '0 patients match current filters';
      els.pagination.innerHTML = '';
      var resetInline = document.getElementById('bhwPlResetInline');
      if (resetInline) resetInline.onclick = function () { els.reset.click(); };
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
      tr.onclick = function () { openDrawer(parseInt(tr.dataset.id, 10)); };
    });
    document.querySelectorAll('.bhw-pl-card[data-id]').forEach(function (card) {
      card.onclick = function (e) {
        if (e.target.closest('.bhw-pl-actions')) return;
        openDrawer(parseInt(card.dataset.id, 10));
      };
    });
    document.querySelectorAll('[data-view]').forEach(function (a) {
      a.onclick = function (e) { e.preventDefault(); openDrawer(parseInt(a.dataset.view, 10)); };
    });
    document.querySelectorAll('[data-print]').forEach(function (btn) {
      btn.onclick = function (e) {
        e.stopPropagation();
        openDrawer(parseInt(btn.dataset.print, 10));
        setTimeout(function () { window.print(); }, 400);
      };
    });
  }

  function openDrawer(id) {
    BhwPortal.get('patients.php', { action: 'get', patient_id: id }).then(function (r) {
      if (!r.success) return;
      var p = r.patient;
      els.drawerTitle.textContent = (p.first_name || '') + ' ' + (p.last_name || '');
      els.drawerBody.innerHTML =
        '<div class="bhw-pl-drawer-section"><h4>Profile</h4><dl class="bhw-pl-drawer-dl">' +
        row('Patient ID', '#' + p.id) + row('Email', p.email) + row('Contact', p.contact_number) +
        row('Gender', p.gender) + row('Age', p.age) + row('DOB', fmtDate(p.date_of_birth)) +
        row('Barangay', p.barangay) + row('Blood type', p.blood_type) + '</dl></div>' +
        '<div class="bhw-pl-drawer-section"><h4>Medical Summary</h4><dl class="bhw-pl-drawer-dl">' +
        row('Conditions', p.existing_conditions) + row('Allergies', p.allergies) +
        row('Medications', p.current_medications) + '</dl></div>' +
        '<div class="bhw-pl-drawer-section"><h4>Quick Actions</h4><div class="d-flex flex-wrap gap-2">' +
        '<a class="bhw-pl-btn bhw-pl-btn--outline" href="update.php?patient_id=' + id + '">Update</a>' +
        '<a class="bhw-pl-btn bhw-pl-btn--outline" href="../triage/submit.php?patient_id=' + id + '">Triage</a>' +
        '<a class="bhw-pl-btn bhw-pl-btn--outline" href="../referral/create.php?patient_id=' + id + '">Refer</a>' +
        '</div></div>';
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
    els.tbody.innerHTML = '<tr><td colspan="12" class="text-muted">Loading…</td></tr>';
    BhwPortal.get('patients.php', { action: 'list', q: '' }).then(function (r) {
      if (!r.success) {
        els.tbody.innerHTML = '<tr><td colspan="12">' + esc(r.message || 'Failed to load') + '</td></tr>';
        return;
      }
      allPatients = r.patients || [];
      populatePurokFilter(allPatients);
      applyFilters();
    });
  }

  function exportCsv() {
    var headers = ['ID','Last Name','First Name','Email','Age','Gender','Contact','Barangay','Last Consult','Provider','Risk','Status','Registered'];
    var lines = [headers.join(',')];
    filtered.forEach(function (p) {
      lines.push([
        p.id, p.last_name, p.first_name, p.email, p.age, p.gender, p.contact_number, p.barangay,
        p.last_consult, p.provider_name, p.risk_level, p.is_active ? 'Active' : 'Inactive', p.created_at
      ].map(function (v) { return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"'; }).join(','));
    });
    var blob = new Blob([lines.join('\n')], { type: 'text/csv' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'patients-barangay.csv';
    a.click();
  }

  els.search.addEventListener('input', applyFilters);
  [els.purok, els.status, els.gender, els.age, els.sort].forEach(function (el) {
    el.addEventListener('change', applyFilters);
  });
  els.reset.addEventListener('click', function () {
    els.search.value = '';
    els.purok.value = '';
    els.status.value = '';
    els.gender.value = '';
    els.age.value = '';
    els.sort.value = 'name';
    applyFilters();
  });
  els.refresh.addEventListener('click', loadPatients);
  els.exportCsv.addEventListener('click', exportCsv);
  els.exportPdf.addEventListener('click', function () { window.print(); });
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
  <?php if ($registered_hint): ?>
  setTimeout(function () {
    BhwPortal.toast('Patient registered successfully.', true, { title: 'Registration Complete' });
  }, 300);
  <?php endif; ?>
})();
<?php
$bhw_inline_script = ob_get_clean();
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-patient-list.css">

<div class="bhw-pl-page">

  <header class="bhw-pl-header">
    <div>
      <h2 class="text-h2">Patient Management</h2>
      <p>Manage, search, update, and monitor registered patients within <strong>Brgy. <?= $barangay_label ?></strong>.</p>
    </div>
    <div class="bhw-pl-header-actions">
      <button type="button" class="bhw-pl-btn bhw-pl-btn--ghost" id="bhwPlRefresh" aria-label="Refresh list">Refresh</button>
      <button type="button" class="bhw-pl-btn bhw-pl-btn--outline" id="bhwPlExportCsv">Export CSV</button>
      <button type="button" class="bhw-pl-btn bhw-pl-btn--outline" id="bhwPlExportPdf">Export PDF</button>
      <a href="register.php" class="bhw-pl-btn bhw-pl-btn--primary">+ Register Patient</a>
    </div>
  </header>

  <div class="bhw-pl-toolbar" role="search">
    <div class="bhw-pl-toolbar-search">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <input type="search" id="bhwPlSearch" placeholder="Search patient name, email, contact, ID…" aria-label="Search patients">
    </div>
    <select id="bhwPlPurok" aria-label="Filter by purok"><option value="">All Puroks</option></select>
    <select id="bhwPlStatus" aria-label="Filter by status">
      <option value="">All Status</option><option value="active">Active</option><option value="inactive">Inactive</option>
    </select>
    <select id="bhwPlGender" aria-label="Filter by gender">
      <option value="">All Gender</option><option value="male">Male</option><option value="female">Female</option>
    </select>
    <select id="bhwPlAge" aria-label="Filter by age group">
      <option value="">All Ages</option><option value="child">Children (&lt;18)</option><option value="adult">Adults (18–59)</option><option value="senior">Seniors (60+)</option>
    </select>
    <select id="bhwPlSort" aria-label="Sort by">
      <option value="name">Sort: Name A–Z</option>
      <option value="name-desc">Sort: Name Z–A</option>
      <option value="registered">Sort: Newest</option>
      <option value="registered-asc">Sort: Oldest</option>
      <option value="risk">Sort: High Risk</option>
    </select>
    <button type="button" class="bhw-pl-btn bhw-pl-btn--ghost" id="bhwPlReset">Reset Filters</button>
  </div>

  <div id="bhwPlEmpty" class="bhw-pl-table-wrap bhw-pl-empty" style="display:none;">
    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="17" y1="11" x2="23" y2="11"/></svg>
    <h3>No registered patients found</h3>
    <p>Register your first patient in this barangay to begin managing care.</p>
    <a href="register.php" class="bhw-pl-btn bhw-pl-btn--primary">Register Patient</a>
  </div>

  <div class="bhw-pl-table-wrap" id="bhwPlTableWrap">
    <div class="bhw-pl-table-scroll" id="bhwPlTable">
      <table class="bhw-pl-table">
        <thead>
          <tr>
            <th>ID</th><th>Patient Name</th><th>Age</th><th>Gender</th><th>Contact</th>
            <th>Barangay</th><th>Last Consult</th><th>Provider</th><th>Risk</th>
            <th>Status</th><th>Registered</th><th>Actions</th>
          </tr>
        </thead>
        <tbody><tr><td colspan="12" class="text-muted">Loading…</td></tr></tbody>
      </table>
    </div>
    <div class="bhw-pl-cards" id="bhwPlCards" aria-label="Patient cards"></div>
    <div class="bhw-pl-footer">
      <span id="bhwPlPageInfo"></span>
      <div class="bhw-pl-pagination" id="bhwPlPagination" aria-label="Pagination"></div>
    </div>
  </div>
</div>

<div class="bhw-pl-drawer-overlay" id="bhwPlDrawerOverlay" aria-hidden="true"></div>
<aside class="bhw-pl-drawer" id="bhwPlDrawer" role="dialog" aria-modal="true" aria-labelledby="bhwPlDrawerTitle">
  <div class="bhw-pl-drawer-head">
    <div>
      <div class="text-muted small fw-bold text-uppercase" style="font-size:10px;letter-spacing:.06em;">Patient Profile</div>
      <h3 id="bhwPlDrawerTitle" style="margin:4px 0 0;font-size:18px;font-weight:800;color:#012A4A;">—</h3>
    </div>
    <button type="button" class="bhw-pl-drawer-close" id="bhwPlDrawerClose" aria-label="Close profile">&times;</button>
  </div>
  <div class="bhw-pl-drawer-body" id="bhwPlDrawerBody"></div>
</aside>

<?php require __DIR__ . '/../partials/layout_close.php'; ?>
