<?php
// ── Profile Information — all values from $pt (MySQL row) ──
// Replace fallback strings as DB columns are populated.
$pi_first     = htmlspecialchars($pt['first_name']        ?? '—');
$pi_last      = htmlspecialchars($pt['last_name']         ?? '—');
$pi_full      = trim($pi_first . ' ' . $pi_last) ?: '—';
$pi_dob       = htmlspecialchars($pt['date_of_birth']     ?? '—');
$pi_age       = htmlspecialchars((string)($pt['age']      ?? '—'));
$pi_gender    = htmlspecialchars(ucfirst($pt['gender']    ?? '—'));
$pi_barangay  = htmlspecialchars($pt['barangay']          ?? '—');
$pi_city      = htmlspecialchars($pt['city_municipality'] ?? '—');
$pi_address   = ($pi_barangay !== '—') ? $pi_barangay . ', ' . $pi_city : $pi_city;
$pi_email     = htmlspecialchars($pt['email']             ?? '—');
// Patient number — from DB query (CONCAT('MC-', LPAD(id,6,'0'))); fall back to session-derived
$pi_patient_id = htmlspecialchars($pt['patient_number'] ?? ('MC-' . str_pad((string)($_SESSION['user_id'] ?? 0), 6, '0', STR_PAD_LEFT)));

// Computed: age from DOB if age column is empty
if ($pi_age === '—' && $pi_dob !== '—') {
    try {
        $pi_age = (string)(new DateTime())->diff(new DateTime($pt['date_of_birth']))->y;
    } catch (Exception $e) { /* keep '—' */ }
}

// Gender icon path
$gender_icons = [
    'male'   => 'M12 2a5 5 0 1 0 0 10A5 5 0 0 0 12 2zm0 12c-5.33 0-8 2.67-8 4v2h16v-2c0-1.33-2.67-4-8-4z',
    'female' => 'M12 2a5 5 0 1 0 0 10A5 5 0 0 0 12 2zm0 12c-5.33 0-8 2.67-8 4v2h16v-2c0-1.33-2.67-4-8-4z',
];
?>

<div class="pi-card">

  <!-- ── Card header ── -->
  <div class="pi-card-header">
    <div class="pi-header-left">
      <div class="pi-header-icon">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
          <circle cx="12" cy="7" r="4"/>
        </svg>
      </div>
      <div>
        <h2 class="pi-card-title">Personal Information</h2>
        <p class="pi-card-sub">Basic details &amp; demographics</p>
      </div>
    </div>
    <span class="pi-readonly-badge">
      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
        <circle cx="12" cy="12" r="3"/>
      </svg>
      View Only
    </span>
  </div>

  <!-- ── Field grid ── -->
  <div class="pi-field-grid">

    <!-- Full Name — spans full width -->
    <div class="pi-field pi-field--full">
      <span class="pi-field-label">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
        </svg>
        Full Name
      </span>
      <span class="pi-field-value pi-field-value--name"><?= $pi_full ?></span>
    </div>

    <!-- Patient ID -->
    <div class="pi-field">
      <span class="pi-field-label">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/>
        </svg>
        Patient ID
      </span>
      <span class="pi-field-value">
        <code class="pi-id-code"><?= $pi_patient_id ?></code>
      </span>
    </div>

    <!-- Email Address -->
    <div class="pi-field">
      <span class="pi-field-label">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
          <polyline points="22,6 12,13 2,6"/>
        </svg>
        Email Address
      </span>
      <span class="pi-field-value pi-field-value--email"><?= $pi_email ?></span>
    </div>

    <!-- Date of Birth -->
    <div class="pi-field">
      <span class="pi-field-label">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/>
          <line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
        Date of Birth
      </span>
      <span class="pi-field-value"><?= $pi_dob ?></span>
    </div>

    <!-- Age -->
    <div class="pi-field">
      <span class="pi-field-label">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
        </svg>
        Age
      </span>
      <span class="pi-field-value">
        <?= $pi_age ?>
        <?php if ($pi_age !== '—'): ?>
          <span class="pi-field-unit">years old</span>
        <?php endif; ?>
      </span>
    </div>

    <!-- Gender -->
    <div class="pi-field">
      <span class="pi-field-label">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
        </svg>
        Gender
      </span>
      <span class="pi-field-value">
        <?php
          $g = strtolower($pt['gender'] ?? '');
          $gender_color = $g === 'male' ? 'pi-gender--male' : ($g === 'female' ? 'pi-gender--female' : '');
        ?>
        <span class="pi-gender-pill <?= $gender_color ?>"><?= $pi_gender ?></span>
      </span>
    </div>

    <!-- Address — spans full width -->
    <div class="pi-field pi-field--full">
      <span class="pi-field-label">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M21 10c0 7-9 13-9 13S3 17 3 10a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>
        </svg>
        Address
      </span>
      <span class="pi-field-value pi-field-value--address"><?= $pi_address ?></span>
    </div>

  </div><!-- /pi-field-grid -->

  <!-- ── Footer note ── -->
  <div class="pi-card-footer">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    To update your personal information, please contact the City Health Office of Bago City or use the Edit Profile option.
  </div>

</div>
