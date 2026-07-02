<?php
/**
 * My Identity — profile, contact, appearance, emergency contact.
 * Expects: $pt, $patient_initials, $patient_picture_url
 */
?>
<h2 class="text-h2 mb-md">Personal Health Identity</h2>

<?php if (!empty($_SESSION['identity_success'])): ?>
  <div class="mc-alert mc-alert--success" style="margin-bottom:16px;padding:12px 16px;border-radius:8px;background:#f0fdf4;border:1px solid #86efac;color:#166534;font-size:13px;display:flex;align-items:center;gap:8px;">
    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
    <?= htmlspecialchars($_SESSION['identity_success']) ?>
  </div>
  <?php unset($_SESSION['identity_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['identity_error'])): ?>
  <div class="mc-alert mc-alert--error" style="margin-bottom:16px;padding:12px 16px;border-radius:8px;background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;font-size:13px;display:flex;align-items:center;gap:8px;">
    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <?= htmlspecialchars($_SESSION['identity_error']) ?>
  </div>
  <?php unset($_SESSION['identity_error']); ?>
<?php endif; ?>

<div class="mc-card mb-md">
  <?php
  $profile_initials = $patient_initials;
  $profile_picture_url = $patient_picture_url;
  $profile_display_name = trim(($pt['first_name'] ?? '') . ' ' . ($pt['last_name'] ?? ''));
  $profile_role_label = 'Patient';
  require VIEWS_PATH . '/partials/profile_upload_card.php';
  ?>
</div>

<div class="profile-grid">
  <div class="mc-card">
    <h3 class="text-h3 mb-md">Personal Information</h3>
    <div class="form-group">
      <label class="form-label">Patient Number</label>
      <input type="text" class="form-control" value="<?= htmlspecialchars($pt['patient_number'] ?? '') ?>" readonly>
    </div>
    <div class="form-group">
      <label class="form-label">Full Name</label>
      <input type="text" class="form-control" value="<?= htmlspecialchars(trim(($pt['first_name'] ?? '') . ' ' . ($pt['last_name'] ?? ''))) ?>" readonly>
    </div>
    <div class="form-grid-2">
      <div class="form-group">
        <label class="form-label">Age</label>
        <input type="text" class="form-control" value="<?= htmlspecialchars($pt['age'] ?? '') ?>" readonly>
      </div>
      <div class="form-group">
        <label class="form-label">Gender</label>
        <input type="text" class="form-control" value="<?= htmlspecialchars(ucfirst($pt['gender'] ?? '')) ?>" readonly>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Date of Birth</label>
      <input type="text" class="form-control" value="<?= !empty($pt['date_of_birth']) ? htmlspecialchars(date('F j, Y', strtotime($pt['date_of_birth']))) : '' ?>" readonly>
    </div>
    <div class="form-group">
      <label class="form-label">Residential Address</label>
      <input type="text" class="form-control" value="<?= htmlspecialchars($pt['full_address'] ?? '') ?>" readonly>
    </div>
  </div>

  <div class="mc-card">
    <h3 class="text-h3 mb-md">Contact Details</h3>
    <form id="contactDetailsForm" method="POST" action="<?= ASSET_BASE ?>/app/api/patient/update_contact.php" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <div class="form-group">
        <label class="form-label">Email Address <span style="color:#ef4444">*</span></label>
        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($pt['email'] ?? '') ?>" required maxlength="180" autocomplete="email">
        <?php if (!empty($_SESSION['contact_errors']['email'])): ?>
          <span style="font-size:11px;color:#dc2626;"><?= htmlspecialchars($_SESSION['contact_errors']['email']) ?></span>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label class="form-label">Phone Number <span style="color:#ef4444">*</span></label>
        <input type="tel" name="contact_number" class="form-control" value="<?= htmlspecialchars($pt['contact_number'] ?? '') ?>" placeholder="e.g. 09171234567" required maxlength="15" autocomplete="tel">
        <?php if (!empty($_SESSION['contact_errors']['contact_number'])): ?>
          <span style="font-size:11px;color:#dc2626;"><?= htmlspecialchars($_SESSION['contact_errors']['contact_number']) ?></span>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label class="form-label">Barangay <span style="color:#ef4444">*</span></label>
        <input type="text" name="barangay" class="form-control" value="<?= htmlspecialchars($pt['barangay'] ?? '') ?>" placeholder="e.g. Barangay 1" required maxlength="120">
        <?php if (!empty($_SESSION['contact_errors']['barangay'])): ?>
          <span style="font-size:11px;color:#dc2626;"><?= htmlspecialchars($_SESSION['contact_errors']['barangay']) ?></span>
        <?php endif; ?>
      </div>
      <?php unset($_SESSION['contact_errors']); ?>
      <button type="submit" class="mc-btn mc-btn--primary" style="width:100%;margin-top:8px;">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:4px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Save Contact Details
      </button>
    </form>
  </div>

  <div class="mc-card mc-appearance-card">
    <h3 class="text-h3 mb-md">Appearance</h3>
    <p class="text-muted" style="font-size:13px;margin-bottom:14px;">Choose how MedConnect looks on your device. System Default follows your OS light or dark setting.</p>
    <div id="patientAppearanceAlert" class="mc-alert" style="display:none;margin-bottom:12px;"></div>
    <form id="patientAppearanceForm" class="patient-appearance-form" novalidate>
      <div class="form-group">
        <label class="form-label" for="patientTheme">Theme Preference</label>
        <select id="patientTheme" name="theme_preference" class="mc-theme-select">
          <?php
          $patient_theme = $_SESSION['user_theme'] ?? 'system';
          foreach (['system' => 'System Default', 'light' => 'Light Mode', 'dark' => 'Dark Mode'] as $val => $label):
          ?>
          <option value="<?= $val ?>" <?= $patient_theme === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="mc-btn mc-btn--primary" style="width:100%;margin-top:8px;">Save Appearance</button>
    </form>
  </div>

  <div class="mc-card">
    <h3 class="text-h3 mb-md">Emergency Contact</h3>
    <form id="emergencyContactForm" method="POST" action="<?= ASSET_BASE ?>/app/api/patient/update_contact.php" novalidate>
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="form_type" value="emergency">
      <div class="form-group">
        <label class="form-label">Contact Name</label>
        <input type="text" name="emergency_contact_name" class="form-control" value="<?= htmlspecialchars($pt['emergency_contact_name'] ?? '') ?>" placeholder="e.g. Maria Santos" maxlength="100">
      </div>
      <div class="form-group">
        <label class="form-label">Relationship</label>
        <input type="text" name="emergency_contact_relation" class="form-control" value="<?= htmlspecialchars($pt['emergency_contact_relation'] ?? '') ?>" placeholder="e.g. Spouse, Parent" maxlength="60">
      </div>
      <div class="form-group">
        <label class="form-label">Emergency Phone</label>
        <input type="tel" name="emergency_contact_phone" class="form-control" value="<?= htmlspecialchars($pt['emergency_contact_phone'] ?? '') ?>" placeholder="09XX XXX XXXX" maxlength="15">
        <?php if (!empty($_SESSION['emergency_errors']['emergency_contact_phone'])): ?>
          <span style="font-size:11px;color:#dc2626;"><?= htmlspecialchars($_SESSION['emergency_errors']['emergency_contact_phone']) ?></span>
          <?php unset($_SESSION['emergency_errors']); ?>
        <?php endif; ?>
      </div>
      <button type="submit" class="mc-btn mc-btn--primary" style="width:100%;margin-top:8px;">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:4px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
        Save Emergency Contact
      </button>
    </form>
  </div>
</div>
