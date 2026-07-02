<?php
$page_title = 'Profile';
$bhw_current_file = 'settings/profile.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
require_once BASE_PATH . '/app/includes/profile_picture.php';
$barangay_label = htmlspecialchars($bhw_barangay_name);
$profile_initials = profile_picture_initials($_SESSION['first_name'] ?? '', $_SESSION['last_name'] ?? '');
$profile_picture_url = profile_picture_public_url($_SESSION['profile_picture'] ?? null);
$profile_display_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Barangay Health Worker';
$profile_role_label = 'Barangay Health Worker';
?>
<div class="bhw-profile-page bhw-update-page">

  <header class="bhw-update-header">
    <h2 class="text-h2">BHW Profile</h2>
    <p>Manage your account details for <strong>Brgy. <?= $barangay_label ?></strong>. Keep your name and contact number up to date so patients and providers can reach you.</p>
  </header>

  <div class="row g-3 bhw-profile-metrics" id="bhwProfileMetrics" aria-label="Sector workload overview">
    <div class="col-sm-6 col-xl-3">
      <div class="bhw-metric-card">
        <div class="bhw-metric-info">
          <div class="bhw-metric-label">Registered Patients</div>
          <div class="bhw-metric-val" id="pf_metric_patients">—</div>
        </div>
        <div class="bhw-metric-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
          </svg>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="bhw-metric-card">
        <div class="bhw-metric-info">
          <div class="bhw-metric-label">Pending Triage</div>
          <div class="bhw-metric-val" id="pf_metric_triage">—</div>
        </div>
        <div class="bhw-metric-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/>
          </svg>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="bhw-metric-card">
        <div class="bhw-metric-info">
          <div class="bhw-metric-label">Today's Consultations</div>
          <div class="bhw-metric-val" id="pf_metric_calls">—</div>
        </div>
        <div class="bhw-metric-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/>
          </svg>
        </div>
      </div>
    </div>
    <div class="col-sm-6 col-xl-3">
      <div class="bhw-metric-card">
        <div class="bhw-metric-info">
          <div class="bhw-metric-label">High-Risk Flags</div>
          <div class="bhw-metric-val" id="pf_metric_risk">—</div>
        </div>
        <div class="bhw-metric-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
          </svg>
        </div>
      </div>
    </div>
  </div>

  <div class="bhw-update-grid">

    <div class="bhw-update-main">

      <div class="bhw-card bhw-form-card bhw-profile-photo-card">
        <h3 class="bhw-form-card-title">
          <span class="bhw-card-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>
            </svg>
          </span>
          Profile Photo
        </h3>
        <p class="bhw-form-card-sub">Upload a photo that appears in the sidebar, header, and your profile summary.</p>
        <?php require VIEWS_PATH . '/partials/profile_upload_card.php'; ?>
      </div>

      <div class="bhw-card bhw-form-card">
        <h3 class="bhw-form-card-title">
          <span class="bhw-card-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
            </svg>
          </span>
          Personal Information
        </h3>
        <p class="bhw-form-card-sub">Update your display name and contact number. Your login email is managed by the system administrator.</p>
        <form id="bhwProfileForm" novalidate>
          <div class="bhw-form-grid">
            <div>
              <label class="form-label" for="pf_fn">First name <span class="bhw-req">*</span></label>
              <input class="form-control" name="first_name" id="pf_fn" required autocomplete="given-name" placeholder="First name">
            </div>
            <div>
              <label class="form-label" for="pf_ln">Last name <span class="bhw-req">*</span></label>
              <input class="form-control" name="last_name" id="pf_ln" required autocomplete="family-name" placeholder="Last name">
            </div>
            <div>
              <label class="form-label" for="pf_phone">Phone number</label>
              <input class="form-control" name="phone" id="pf_phone" type="tel" autocomplete="tel" placeholder="09XXXXXXXXX">
            </div>
            <div>
              <label class="form-label" for="pf_email">Email address</label>
              <input class="form-control bhw-readonly" id="pf_email" type="email" readonly tabindex="-1" aria-readonly="true">
              <span class="bhw-field-hint">Contact your administrator to change your login email.</span>
            </div>
          </div>
          <div class="bhw-form-actions">
            <button type="button" class="bhw-btn-outline" id="pf_reset_btn">Reset</button>
            <button type="submit" class="bhw-btn-teal" id="pf_save_btn">Save Profile</button>
          </div>
        </form>
      </div>

      <div class="bhw-info-cards" aria-label="Account and assignment details">
        <div class="bhw-info-card">
          <h4 class="bhw-info-card-title">Assignment</h4>
          <dl>
            <div class="bhw-info-row"><dt>Role</dt><dd>Barangay Health Worker</dd></div>
            <div class="bhw-info-row"><dt>Assigned sector</dt><dd>Brgy. <?= $barangay_label ?></dd></div>
            <div class="bhw-info-row"><dt>Portal access</dt><dd id="pf_info_access">—</dd></div>
          </dl>
        </div>
        <div class="bhw-info-card">
          <h4 class="bhw-info-card-title">Account</h4>
          <dl>
            <div class="bhw-info-row"><dt>User ID</dt><dd id="pf_info_id">—</dd></div>
            <div class="bhw-info-row"><dt>Member since</dt><dd id="pf_info_since">—</dd></div>
            <div class="bhw-info-row"><dt>Last updated</dt><dd id="pf_info_updated">—</dd></div>
          </dl>
        </div>
        <div class="bhw-info-card">
          <h4 class="bhw-info-card-title">Verification</h4>
          <dl>
            <div class="bhw-info-row"><dt>Email verified</dt><dd id="pf_info_verified">—</dd></div>
            <div class="bhw-info-row"><dt>Account status</dt><dd id="pf_info_status">—</dd></div>
            <div class="bhw-info-row"><dt>Login email</dt><dd id="pf_info_email">—</dd></div>
          </dl>
        </div>
      </div>
    </div>

    <aside class="bhw-update-sidebar" aria-label="Profile summary">
      <div class="bhw-card bhw-summary-card">
        <div class="bhw-summary-hero">
          <div class="bhw-summary-avatar" id="pf_avatar" data-profile-avatar-wrap aria-hidden="true">
            <?= profile_picture_render($profile_initials, $profile_picture_url, '', 'lg') ?>
          </div>
          <h3 class="bhw-summary-name" id="pf_sum_name">Barangay Health Worker</h3>
          <p class="bhw-summary-id" id="pf_sum_id">User ID #—</p>
          <span class="bhw-summary-status" id="pf_sum_status">Active</span>
        </div>
        <div class="bhw-summary-body">
          <div class="bhw-summary-section">
            <div class="bhw-summary-section-title">Contact</div>
            <dl class="bhw-summary-dl">
              <div><dt>Email</dt><dd id="pf_sum_email">—</dd></div>
              <div><dt>Phone</dt><dd id="pf_sum_phone">—</dd></div>
            </dl>
          </div>
          <div class="bhw-summary-section">
            <div class="bhw-summary-section-title">Assignment</div>
            <dl class="bhw-summary-dl">
              <div><dt>Barangay</dt><dd>Brgy. <?= $barangay_label ?></dd></div>
              <div><dt>Role</dt><dd>BHW</dd></div>
            </dl>
          </div>
          <div class="bhw-summary-section">
            <div class="bhw-summary-section-title">Sector workload</div>
            <dl class="bhw-summary-dl">
              <div><dt>Patients</dt><dd id="pf_sum_patients">—</dd></div>
              <div><dt>Pending triage</dt><dd id="pf_sum_triage">—</dd></div>
              <div><dt>Today's calls</dt><dd id="pf_sum_calls">—</dd></div>
            </dl>
          </div>
          <div class="bhw-summary-section">
            <div class="bhw-summary-section-title">Account</div>
            <dl class="bhw-summary-dl">
              <div><dt>Joined</dt><dd id="pf_sum_since">—</dd></div>
              <div><dt>Email verified</dt><dd id="pf_sum_verified">—</dd></div>
            </dl>
          </div>
        </div>
      </div>
    </aside>

  </div>
</div>
<script>
(function () {
  var savedProfile = null;

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
    return ((p.first_name || 'B').charAt(0) + (p.last_name || 'H').charAt(0)).toUpperCase();
  }

  function setText(id, val) {
    var el = document.getElementById(id);
    if (el) el.textContent = dash(val);
  }

  function fillForm(p) {
    document.getElementById('pf_fn').value = p.first_name || '';
    document.getElementById('pf_ln').value = p.last_name || '';
    document.getElementById('pf_phone').value = p.phone || '';
    document.getElementById('pf_email').value = p.email || '';
  }

  function fillProfile(p, metrics) {
    var fullName = dash((p.first_name || '') + ' ' + (p.last_name || ''));
    var active = p.is_active === 1 || p.is_active === '1' || p.is_active === true;
    var verified = p.is_email_verified === 1 || p.is_email_verified === '1' || p.is_email_verified === true;

    var avatarWrap = document.getElementById('pf_avatar');
    if (avatarWrap) {
      var initialsEl = avatarWrap.querySelector('.profile-avatar__initials');
      if (initialsEl) initialsEl.textContent = initials(p);
    }
    setText('pf_sum_name', fullName);
    setText('pf_sum_id', 'User ID #' + (p.id || '—'));
    setText('pf_sum_email', p.email);
    setText('pf_sum_phone', p.phone);
    setText('pf_sum_since', fmtDate(p.created_at));
    setText('pf_sum_verified', verified ? 'Yes' : 'No');

    var statusEl = document.getElementById('pf_sum_status');
    statusEl.textContent = active ? 'Active' : 'Inactive';
    statusEl.className = 'bhw-summary-status' + (active ? '' : ' is-inactive');

    setText('pf_info_id', '#' + (p.id || '—'));
    setText('pf_info_since', fmtDate(p.created_at));
    setText('pf_info_updated', fmtDate(p.updated_at));
    setText('pf_info_verified', verified ? 'Verified' : 'Not verified');
    setText('pf_info_status', active ? 'Active' : 'Inactive');
    setText('pf_info_email', p.email);
    setText('pf_info_access', active ? 'Full BHW portal' : 'Restricted');

    if (metrics) {
      var patients = metrics.total_households ?? 0;
      var triage = metrics.pending_triage ?? 0;
      var calls = metrics.scheduled_calls ?? 0;
      var risk = metrics.high_risk_flags ?? 0;

      setText('pf_metric_patients', patients);
      setText('pf_metric_triage', triage);
      setText('pf_metric_calls', calls);
      setText('pf_metric_risk', risk);
      setText('pf_sum_patients', patients);
      setText('pf_sum_triage', triage);
      setText('pf_sum_calls', calls);
    }
  }

  function loadProfile() {
    BhwPortal.get('profile.php', { action: 'get' }).then(function (r) {
      if (!r.success) return;
      savedProfile = r.profile || {};
      fillForm(savedProfile);
      fillProfile(savedProfile, r.metrics || {});
    });
  }

  document.getElementById('pf_reset_btn').addEventListener('click', function () {
    if (savedProfile) fillForm(savedProfile);
  });

  document.getElementById('bhwProfileForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var saveBtn = document.getElementById('pf_save_btn');
    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';
    BhwPortal.post('profile.php', new FormData(e.target)).then(function (r) {
      saveBtn.disabled = false;
      saveBtn.textContent = 'Save Profile';
      BhwPortal.toast(r.message, r.success);
      if (r.success) loadProfile();
    });
  });

  loadProfile();
})();
</script>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
