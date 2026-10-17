<?php
$page_title = 'Profile';
$bhw_current_file = 'settings/profile.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require_once BASE_PATH . '/app/includes/profile_picture.php';
require_once BASE_PATH . '/app/includes/language_preferences.php';
$ui_language = language_preferences_get($pdo, (int) $_SESSION['user_id'], 'bhw');
require __DIR__ . '/../partials/layout_open.php';
$barangay_label = htmlspecialchars($bhw_barangay_name);
$profile_initials = profile_picture_initials($_SESSION['first_name'] ?? '', $_SESSION['last_name'] ?? '');
$profile_picture_url = profile_picture_public_url($_SESSION['profile_picture'] ?? null);
$profile_display_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Barangay Health Worker';
$profile_role_label = 'Barangay Health Worker';
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/portal-settings-shell.css?v=<?= (int) @filemtime(ASSETS_PATH . '/css/portal-settings-shell.css') ?>">
<div class="bhw-profile-page bhw-update-page">
<div class="mcs" data-mc-settings data-initial-tab="profile">
  <aside class="mcs-menu">
    <h2 class="mcs-title">Settings</h2>
    <nav aria-label="Settings sections">
      <div class="mcs-group">
        <p class="mcs-group-label">Account</p>
        <button type="button" class="mcs-item is-active" data-mc-tab="profile" data-search="profile photo name phone" aria-selected="true">Profile</button>
        <button type="button" class="mcs-item" data-mc-tab="account" data-search="account assignment verification barangay" aria-selected="false">Account details</button>
      </div>
      <div class="mcs-group">
        <p class="mcs-group-label">Preferences</p>
        <button type="button" class="mcs-item" data-mc-tab="language" data-search="language english filipino hiligaynon" aria-selected="false">Language</button>
      </div>
    </nav>
  </aside>
  <div class="mcs-stage">
    <div class="mcs-stage-bar">
      <label class="mcs-search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="search" placeholder="Search" autocomplete="off" aria-label="Search settings">
      </label>
    </div>
    <p class="mcs-empty" hidden>No matching settings.</p>

    <section class="mcs-panel is-active" data-mc-panel="profile">
      <header class="mcs-panel-head">
        <h2>Profile</h2>
        <p>Manage your account details for <strong>Brgy. <?= $barangay_label ?></strong>. Keep your name and contact number up to date.</p>
      </header>

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
              <input class="form-control" name="first_name" id="pf_fn" required maxlength="80" autocomplete="given-name" placeholder="First name">
            </div>
            <div>
              <label class="form-label" for="pf_ln">Last name <span class="bhw-req">*</span></label>
              <input class="form-control" name="last_name" id="pf_ln" required maxlength="80" autocomplete="family-name" placeholder="Last name">
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

    </section>

    <section class="mcs-panel" data-mc-panel="language">
      <header class="mcs-panel-head">
        <h2>Language</h2>
        <p>Choose English, Filipino, or Hiligaynon. Your choice is saved to this account.</p>
      </header>
      <div class="bhw-card bhw-form-card">
        <h3 class="bhw-form-card-title">Language</h3>
        <p class="bhw-form-card-sub">Choose English, Filipino, or Hiligaynon for this account.</p>
        <form id="bhwLanguageForm">
          <div class="bhw-form-grid">
            <div>
              <label class="form-label" for="bhw_language">Language</label>
              <select class="form-control" name="language" id="bhw_language">
                <?php foreach (['en' => 'English', 'fil' => 'Filipino', 'hil' => 'Hiligaynon'] as $code => $label): ?>
                <option value="<?= $code ?>" <?= $ui_language === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <p id="bhwLanguageAlert" class="bhw-field-hint" role="status" hidden></p>
          <div class="bhw-form-actions">
            <button type="submit" class="bhw-btn-teal" id="bhw_language_btn">Save Language</button>
          </div>
        </form>
      </div>
    </section>

    <section class="mcs-panel" data-mc-panel="account">
      <header class="mcs-panel-head">
        <h2>Account details</h2>
        <p>Your assignment, verification, and sector workload for Brgy. <?= $barangay_label ?>.</p>
      </header>
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
      <div class="bhw-card bhw-summary-card">
        <div class="bhw-summary-hero">
          <div class="bhw-summary-avatar" id="pf_avatar" data-profile-avatar-wrap aria-hidden="true">
            <?= profile_picture_render($profile_initials, $profile_picture_url, '', 'lg') ?>
          </div>
          <h3 class="bhw-summary-name" id="pf_sum_name"><?= htmlspecialchars($profile_display_name) ?></h3>
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
    </section>
  </div>
</div>
</div>
<script src="<?= ASSET_BASE ?>/assets/js/portal-settings-shell.js?v=<?= (int) @filemtime(ASSETS_PATH . '/js/portal-settings-shell.js') ?>"></script>
<?php
ob_start();
?>
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

  function updateShellName(p) {
    var fullName = ((p.first_name || '') + ' ' + (p.last_name || '')).trim();
    document.querySelectorAll('.adm-profile-name').forEach(function (el) {
      if (fullName) el.textContent = fullName;
    });
    document.querySelectorAll('.adm-profile [data-profile-avatar-wrap] .profile-avatar__initials').forEach(function (el) {
      el.textContent = initials(p);
    });
  }

  var saving = false;

  document.getElementById('bhwProfileForm').addEventListener('submit', function (e) {
    e.preventDefault();
    if (saving) return;

    var fnEl = document.getElementById('pf_fn');
    var lnEl = document.getElementById('pf_ln');
    fnEl.value = String(fnEl.value || '').trim();
    lnEl.value = String(lnEl.value || '').trim();
    if (!fnEl.value) {
      BhwPortal.toast('First name is required.', false);
      return;
    }
    if (!lnEl.value) {
      BhwPortal.toast('Last name is required.', false);
      return;
    }

    var phoneEl = document.getElementById('pf_phone');
    phoneEl.value = String(phoneEl.value || '').trim();
    if (phoneEl.value) {
      if (window.MCContactValidation && !window.MCContactValidation.isValidPhone(phoneEl.value)) {
        BhwPortal.toast(window.MCContactValidation.validatePhone(phoneEl.value) || 'Please enter a valid Philippine mobile number.', false);
        return;
      }
      if (window.MCContactValidation) {
        phoneEl.value = window.MCContactValidation.canonicalPhone(phoneEl.value);
      }
    }

    var saveBtn = document.getElementById('pf_save_btn');
    var resetBtn = document.getElementById('pf_reset_btn');
    saving = true;
    saveBtn.disabled = true;
    resetBtn.disabled = true;
    saveBtn.textContent = 'Saving…';

    var fd = new FormData(e.target);
    fd.set('action', 'save');
    BhwPortal.post('profile.php', fd).then(function (r) {
      var ok = !!(r && r.success === true);
      BhwPortal.toast(ok ? 'Profile updated successfully.' : ((r && r.message) || 'Could not save your profile. Please try again.'), ok);
      if (ok) {
        if (r.profile) updateShellName(r.profile);
        loadProfile();
      }
    }).catch(function () {
      BhwPortal.toast('Could not save your profile. Please check your connection and try again.', false);
    }).then(function () {
      saving = false;
      saveBtn.disabled = false;
      resetBtn.disabled = false;
      saveBtn.textContent = 'Save Profile';
    });
  });

  loadProfile();

  var languageForm = document.getElementById('bhwLanguageForm');
  if (languageForm) {
    languageForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var alertEl = document.getElementById('bhwLanguageAlert');
      var btn = document.getElementById('bhw_language_btn');
      var fd = new FormData(languageForm);
      fd.append('csrf_token', document.body.dataset.csrf || '');
      if (btn) btn.disabled = true;
      fetch((document.body.dataset.assetBase || '') + '/app/api/language/save.php', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
      }).then(function (res) { return res.json(); }).then(function (data) {
        var ok = !!(data && data.success);
        if (alertEl) {
          alertEl.hidden = false;
          alertEl.textContent = (data && data.message) || (ok ? 'Language saved.' : 'Could not save language.');
        }
        if (ok) {
          document.documentElement.lang = (data.data && data.data.language) || fd.get('language') || 'en';
        }
      }).catch(function () {
        if (alertEl) {
          alertEl.hidden = false;
          alertEl.textContent = 'Could not save language.';
        }
      }).then(function () {
        if (btn) btn.disabled = false;
      });
    });
  }
})();
<?php
$bhw_inline_script = ob_get_clean();
require __DIR__ . '/../partials/layout_close.php';
?>
