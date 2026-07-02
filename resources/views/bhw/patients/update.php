<?php
$page_title = 'Update Patient';
$bhw_current_file = 'patients/update.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$pid = (int) ($_GET['patient_id'] ?? 0);
$barangay_label = htmlspecialchars($bhw_barangay_name);

ob_start();
?>
(function () {
  var workspace = document.getElementById('bhwUpdateWorkspace');
  var emptyEl = document.getElementById('bhwUpdateEmpty');
  var heroEl = document.getElementById('bhwUpdateHero');
  var formEl = document.getElementById('bhwUpdateForm');
  var dirtyBadge = document.getElementById('bhwDirtyBadge');
  var pickerApi = null;
  var currentPatient = null;
  var formDirty = false;

  function dash(v) {
    return v && String(v).trim() ? String(v).trim() : '—';
  }

  function fmtDate(v) {
    if (!v) return '—';
    try {
      var d = new Date(v);
      if (isNaN(d.getTime())) return v;
      return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
    } catch (e) { return v; }
  }

  function initials(p) {
    return ((p.first_name || '?').charAt(0) + (p.last_name || '').charAt(0)).toUpperCase();
  }

  function setPair(id, val) {
    var el = document.getElementById(id);
    if (el) el.textContent = dash(val);
  }

  function isActive(p) {
    return p.is_active === 1 || p.is_active === '1' || p.is_active === true;
  }

  function normalizePhone(v) {
    return String(v || '').replace(/\D/g, '');
  }

  function isValidPhMobile(v) {
    return /^09\d{9}$/.test(normalizePhone(v));
  }

  function isValidEmail(v) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(v || '').trim());
  }

  function setDirty(dirty) {
    formDirty = dirty;
    if (dirtyBadge) dirtyBadge.hidden = !dirty;
    var saveBtn = document.getElementById('bhwSaveBtn');
    if (saveBtn) saveBtn.classList.toggle('bhw-btn-teal--pulse', dirty);
  }

  function showWorkspace(show) {
    workspace.style.display = show ? 'block' : 'none';
    emptyEl.style.display = show ? 'none' : 'block';
    if (heroEl) heroEl.style.display = show ? 'block' : 'none';
  }

  function switchTab(tabId) {
    document.querySelectorAll('.bhw-update-tab').forEach(function (btn) {
      var active = btn.dataset.tab === tabId;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    document.querySelectorAll('.bhw-update-tab-panel').forEach(function (panel) {
      var active = panel.id === 'tab_' + tabId;
      panel.classList.toggle('is-active', active);
      panel.hidden = !active;
    });
  }

  document.querySelectorAll('.bhw-update-tab').forEach(function (btn) {
    btn.addEventListener('click', function () { switchTab(btn.dataset.tab); });
  });

  function fillSummary(p) {
    var fullName = dash((p.first_name || '') + ' ' + (p.last_name || ''));
    var active = isActive(p);
    var addr = [p.barangay, p.city_municipality || 'Bago City', p.province].filter(Boolean).join(', ');
    var pid = parseInt(p.id, 10) || 0;
    var q = pid ? '?patient_id=' + pid : '';

    document.getElementById('hero_avatar').textContent = initials(p);
    document.getElementById('hero_name').textContent = fullName;
    document.getElementById('hero_id').textContent = 'Patient ID #' + (p.id || '—');
    var statusEl = document.getElementById('hero_status');
    statusEl.textContent = active ? 'Active' : 'Inactive';
    statusEl.className = 'bhw-update-badge' + (active ? ' bhw-update-badge--active' : ' bhw-update-badge--inactive');

    setPair('hero_age', p.age ? p.age + ' yrs' : '—');
    setPair('hero_gender', p.gender);
    setPair('hero_blood', p.blood_type);

    setPair('info_name', fullName);
    setPair('info_gender', p.gender);
    setPair('info_dob', fmtDate(p.date_of_birth));
    setPair('info_age', p.age);
    setPair('info_address', addr);
    setPair('info_barangay', p.barangay);
    setPair('info_conditions', p.existing_conditions);
    setPair('info_allergies', p.allergies);
    setPair('info_meds', p.current_medications);
    setPair('info_blood', p.blood_type);
    setPair('info_registered', fmtDate(p.created_at || p.registered_at));
    setPair('info_status', active ? 'Active' : 'Inactive');
    setPair('info_id', '#' + p.id);

    setPair('side_contact', p.contact_number);
    setPair('side_email', p.email);
    setPair('side_registered', fmtDate(p.created_at || p.registered_at));

    var links = document.getElementById('hero_quick_links');
    if (links && pid) {
      links.innerHTML =
        '<a class="bhw-update-quick-link" href="../triage/submit.php' + q + '">' +
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>Triage</a>' +
        '<a class="bhw-update-quick-link" href="../records/index.php?patient_id=' + pid + '">' +
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>Records</a>' +
        '<a class="bhw-update-quick-link" href="../consultations/index.php">' +
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Consultations</a>' +
        '<a class="bhw-update-quick-link" href="list.php" data-view="' + pid + '">' +
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>View in List</a>';
    }
  }

  function fillForm(p) {
    document.getElementById('f_patient_id').value = p.id;
    document.getElementById('f_email').value = p.email || '';
    document.getElementById('f_contact').value = p.contact_number || '';
    setDirty(false);
    clearFieldErrors();
  }

  function clearFieldErrors() {
    ['f_email', 'f_contact'].forEach(function (id) {
      var wrap = document.getElementById(id).closest('.bhw-field');
      if (wrap) wrap.classList.remove('is-invalid');
      var err = wrap && wrap.querySelector('.bhw-field-error');
      if (err) err.textContent = '';
    });
  }

  function setFieldError(inputId, msg) {
    var input = document.getElementById(inputId);
    var wrap = input.closest('.bhw-field');
    if (wrap) wrap.classList.add('is-invalid');
    var err = wrap && wrap.querySelector('.bhw-field-error');
    if (err) err.textContent = msg;
  }

  function validateForm() {
    clearFieldErrors();
    var ok = true;
    var email = document.getElementById('f_email').value.trim();
    var contact = document.getElementById('f_contact').value.trim();
    if (!isValidEmail(email)) {
      setFieldError('f_email', 'Enter a valid email address.');
      ok = false;
    }
    if (!isValidPhMobile(contact)) {
      setFieldError('f_contact', 'Use a valid PH mobile number (09XXXXXXXXX).');
      ok = false;
    }
    return ok;
  }

  function loadPatient(pid) {
    if (!pid) {
      currentPatient = null;
      showWorkspace(false);
      return;
    }
    BhwPortal.get('patients.php', { action: 'get', patient_id: pid }).then(function (r) {
      if (!r.success) {
        BhwPortal.toast(r.message, false);
        showWorkspace(false);
        return;
      }
      currentPatient = r.patient;
      fillForm(currentPatient);
      fillSummary(currentPatient);
      showWorkspace(true);
      switchTab('personal');
    });
  }

  ['f_email', 'f_contact'].forEach(function (id) {
    var el = document.getElementById(id);
    if (el) {
      el.addEventListener('input', function () {
        if (!currentPatient) return;
        var changed = el.value !== (id === 'f_email' ? (currentPatient.email || '') : (currentPatient.contact_number || ''));
        setDirty(changed || document.getElementById('f_email').value !== (currentPatient.email || '') ||
          document.getElementById('f_contact').value !== (currentPatient.contact_number || ''));
      });
    }
  });

  pickerApi = BhwPortal.mountPatientPicker(document.getElementById('bhwPatientPickerMount'), {
    preselect: <?= (int) $pid ?>,
    label: 'Search patient in your barangay',
    hideSelectedBar: true,
    onSelect: function (pid) { loadPatient(pid); },
    onClear: function () { loadPatient(0); }
  });

  document.getElementById('bhwResetBtn').addEventListener('click', function () {
    if (currentPatient) fillForm(currentPatient);
  });

  formEl.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!validateForm()) return;
    var fd = new FormData(formEl);
    fd.append('action', 'update');
    var saveBtn = document.getElementById('bhwSaveBtn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<span class="bhw-btn-spinner" aria-hidden="true"></span>Saving…';
    BhwPortal.post('patients.php', fd).then(function (r) {
      saveBtn.disabled = false;
      saveBtn.textContent = 'Save Changes';
      BhwPortal.toast(r.message, r.success, { title: r.success ? 'Contact Updated' : 'Update Failed' });
      if (r.success && currentPatient) {
        var card = document.querySelector('.bhw-update-contact-card');
        if (card) {
          card.classList.add('is-saved');
          setTimeout(function () { card.classList.remove('is-saved'); }, 1200);
        }
        loadPatient(parseInt(document.getElementById('f_patient_id').value, 10));
      }
    });
  });
})();
<?php
$bhw_inline_script = ob_get_clean();
?>
<div class="bhw-update-page">

  <header class="bhw-update-header">
    <div class="bhw-update-header-text">
      <h2 class="text-h2">Update Patient Info</h2>
      <p>Search a patient from <strong>Brgy. <?= $barangay_label ?></strong>. You may update <strong>email and contact number</strong> only — all other details are read-only and managed by the patient or provider.</p>
    </div>
    <a href="list.php" class="bhw-btn-ghost bhw-update-back-link" aria-label="Back to patient list">← Back to Patient List</a>
  </header>

  <div class="bhw-card bhw-update-search-strip">
    <div id="bhwPatientPickerMount" aria-label="Patient search"></div>
  </div>

  <div id="bhwUpdateEmpty" class="bhw-update-empty" role="status">
    <div class="bhw-update-empty-visual" aria-hidden="true">
      <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25">
        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        <path d="M8 11h6M11 8v6" stroke-linecap="round"/>
      </svg>
    </div>
    <h3>Select a patient to begin</h3>
    <p>Search by name, email, or contact number to load a patient profile from your barangay.</p>
    <ul class="bhw-update-empty-tips">
      <li>Only <strong>email</strong> and <strong>mobile number</strong> can be edited here.</li>
      <li>Medical and personal details are updated by the patient or their provider.</li>
      <li>Use quick actions after selecting a patient to triage, view records, or schedule care.</li>
    </ul>
  </div>

  <div id="bhwUpdateHero" class="bhw-update-hero" style="display:none;" aria-live="polite">
    <div class="bhw-update-hero-inner">
      <div class="bhw-update-hero-identity">
        <div class="bhw-update-hero-avatar" id="hero_avatar" aria-hidden="true">—</div>
        <div class="bhw-update-hero-meta">
          <h3 class="bhw-update-hero-name" id="hero_name">—</h3>
          <p class="bhw-update-hero-id" id="hero_id">Patient ID #—</p>
          <div class="bhw-update-hero-badges">
            <span class="bhw-update-badge bhw-update-badge--active" id="hero_status">Active</span>
            <span class="bhw-update-badge bhw-update-badge--muted" id="hero_age">—</span>
            <span class="bhw-update-badge bhw-update-badge--muted" id="hero_gender">—</span>
            <span class="bhw-update-badge bhw-update-badge--muted" id="hero_blood">—</span>
          </div>
        </div>
      </div>
      <nav class="bhw-update-hero-actions" id="hero_quick_links" aria-label="Quick patient actions"></nav>
    </div>
  </div>

  <div id="bhwUpdateWorkspace" class="bhw-update-grid" style="display:none;">

    <div class="bhw-update-main">

      <section class="bhw-card bhw-form-card bhw-update-contact-card" aria-labelledby="update_contact_title">
        <div class="bhw-update-contact-head">
          <div>
            <h3 class="bhw-form-card-title" id="update_contact_title">
              <span class="bhw-card-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
              </span>
              Editable Contact Information
            </h3>
            <p class="bhw-form-card-sub">Update how this patient can be reached for appointments and follow-ups.</p>
          </div>
          <span class="bhw-update-dirty-badge" id="bhwDirtyBadge" hidden>Unsaved changes</span>
        </div>
        <form id="bhwUpdateForm" novalidate>
          <input type="hidden" name="patient_id" id="f_patient_id" value="">
          <div class="bhw-form-grid">
            <div class="bhw-field span-2">
              <label class="form-label" for="f_email">Email address <span class="bhw-req" aria-hidden="true">*</span></label>
              <input type="email" class="form-control" name="email" id="f_email" required autocomplete="email" placeholder="patient@email.com" aria-required="true">
              <span class="bhw-field-error" role="alert"></span>
            </div>
            <div class="bhw-field span-2">
              <label class="form-label" for="f_contact">Mobile number <span class="bhw-req" aria-hidden="true">*</span></label>
              <input type="tel" class="form-control" name="contact_number" id="f_contact" required autocomplete="tel" placeholder="09XXXXXXXXX" inputmode="numeric" aria-required="true">
              <span class="bhw-field-hint">Philippine format: 09XXXXXXXXX</span>
              <span class="bhw-field-error" role="alert"></span>
            </div>
          </div>
          <div class="bhw-form-actions">
            <a href="list.php" class="bhw-btn-ghost">Cancel</a>
            <button type="button" class="bhw-btn-outline" id="bhwResetBtn">Reset</button>
            <button type="submit" class="bhw-btn-teal" id="bhwSaveBtn">Save Changes</button>
          </div>
        </form>
      </section>

      <section class="bhw-card bhw-update-tabs-card" aria-label="Read-only patient profile">
        <div class="bhw-update-tabs" role="tablist" aria-label="Profile sections">
          <button type="button" class="bhw-update-tab is-active" role="tab" data-tab="personal" aria-selected="true" aria-controls="tab_personal" id="tabbtn_personal">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            Personal
          </button>
          <button type="button" class="bhw-update-tab" role="tab" data-tab="medical" aria-selected="false" aria-controls="tab_medical" id="tabbtn_medical">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
            Medical
          </button>
          <button type="button" class="bhw-update-tab" role="tab" data-tab="account" aria-selected="false" aria-controls="tab_account" id="tabbtn_account">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            Account
          </button>
        </div>

        <div class="bhw-update-tab-panels">
          <div class="bhw-update-tab-panel is-active" id="tab_personal" role="tabpanel" aria-labelledby="tabbtn_personal">
            <dl class="bhw-update-dl">
              <div class="bhw-update-dl-row"><dt>Full name</dt><dd id="info_name">—</dd></div>
              <div class="bhw-update-dl-row"><dt>Gender</dt><dd id="info_gender">—</dd></div>
              <div class="bhw-update-dl-row"><dt>Date of birth</dt><dd id="info_dob">—</dd></div>
              <div class="bhw-update-dl-row"><dt>Age</dt><dd id="info_age">—</dd></div>
              <div class="bhw-update-dl-row"><dt>Address</dt><dd id="info_address">—</dd></div>
              <div class="bhw-update-dl-row"><dt>Barangay</dt><dd id="info_barangay">—</dd></div>
            </dl>
          </div>
          <div class="bhw-update-tab-panel" id="tab_medical" role="tabpanel" aria-labelledby="tabbtn_medical" hidden>
            <dl class="bhw-update-dl">
              <div class="bhw-update-dl-row"><dt>Blood type</dt><dd id="info_blood">—</dd></div>
              <div class="bhw-update-dl-row"><dt>Conditions</dt><dd id="info_conditions">—</dd></div>
              <div class="bhw-update-dl-row"><dt>Allergies</dt><dd id="info_allergies">—</dd></div>
              <div class="bhw-update-dl-row"><dt>Medications</dt><dd id="info_meds">—</dd></div>
            </dl>
          </div>
          <div class="bhw-update-tab-panel" id="tab_account" role="tabpanel" aria-labelledby="tabbtn_account" hidden>
            <dl class="bhw-update-dl">
              <div class="bhw-update-dl-row"><dt>Patient ID</dt><dd id="info_id">—</dd></div>
              <div class="bhw-update-dl-row"><dt>Registered</dt><dd id="info_registered">—</dd></div>
              <div class="bhw-update-dl-row"><dt>Status</dt><dd id="info_status">—</dd></div>
            </dl>
            <p class="bhw-update-readonly-note">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              Account credentials and verification are managed by the patient.
            </p>
          </div>
        </div>
      </section>

    </div>

    <aside class="bhw-update-sidebar" aria-label="Update guidance">
      <div class="bhw-card bhw-update-side-card">
        <h4 class="bhw-update-side-title">Current Contact</h4>
        <dl class="bhw-update-side-dl">
          <div><dt>Mobile</dt><dd id="side_contact">—</dd></div>
          <div><dt>Email</dt><dd id="side_email">—</dd></div>
          <div><dt>Registered</dt><dd id="side_registered">—</dd></div>
        </dl>
      </div>

      <div class="bhw-register-guidance bhw-update-guidance">
        <h4>Update Guidance</h4>
        <ul>
          <li>Confirm changes with the patient before saving.</li>
          <li>Changing email affects their login credentials.</li>
          <li>Use a mobile number the patient actively monitors.</li>
          <li>For medical or demographic changes, direct the patient to their provider.</li>
        </ul>
      </div>

      <div class="bhw-update-lock-notice" role="note">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <span>Personal and medical fields are <strong>read-only</strong> for BHW users.</span>
      </div>
    </aside>

  </div>
</div>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
