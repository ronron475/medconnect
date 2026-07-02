<?php
// ── Medical Profile — values from $pt (MySQL row) ──
// blood_type and philhealth_status already fetched.
// existing_conditions, allergies, current_medications need columns added to patient_registrations.
$mp_blood       = htmlspecialchars($pt['blood_type']           ?? '');
$mp_philhealth  = htmlspecialchars($pt['philhealth_status']    ?? '');
$mp_conditions  = htmlspecialchars($pt['existing_conditions']  ?? '');
$mp_allergies   = htmlspecialchars($pt['allergies']            ?? '');
$mp_medications = htmlspecialchars($pt['current_medications']  ?? '');

// Flash feedback from medical_profile.controller.php
$mp_success = $_SESSION['medical_success'] ?? null;
$mp_errors  = $_SESSION['medical_errors']  ?? [];
unset($_SESSION['medical_success'], $_SESSION['medical_errors']);

$mfe = fn(string $k) => $mp_errors[$k] ?? null;

// Blood type options
$blood_types = ['A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown'];

// PhilHealth status options
$philhealth_statuses = ['Active','Inactive','Pending','Exempt'];
?>

<div class="mpf-card">

  <!-- ── Header ── -->
  <div class="mpf-header">
    <div class="mpf-header-left">
      <div class="mpf-header-icon">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
        </svg>
      </div>
      <div>
        <h2 class="mpf-title">Medical Information</h2>
        <p class="mpf-sub">Health profile &amp; clinical details</p>
      </div>
    </div>
    <span class="mpf-badge">
      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
      </svg>
      Health Record
    </span>
  </div>

  <!-- ── Flash messages ── -->
  <?php if ($mp_success): ?>
  <div class="mpf-alert mpf-alert--success" role="alert">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
      <polyline points="22 4 12 14.01 9 11.01"/>
    </svg>
    <?= htmlspecialchars($mp_success) ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($mp_errors['general'])): ?>
  <div class="mpf-alert mpf-alert--error" role="alert">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <circle cx="12" cy="12" r="10"/>
      <line x1="12" y1="8" x2="12" y2="12"/>
      <line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    <?= htmlspecialchars($mp_errors['general']) ?>
  </div>
  <?php endif; ?>

  <!-- ── Form ── -->
  <form
    class="mpf-form"
    method="POST"
        action="<?= ASSET_BASE ?>/app/controllers/patient/medical_profile.controller.php"
    novalidate
  >
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>"/>

    <!-- ── Row 1: Blood Type + PhilHealth ── -->
    <div class="mpf-row">

      <!-- Blood Type -->
      <div class="mpf-field <?= $mfe('blood_type') ? 'mpf-field--error' : '' ?>">
        <label class="mpf-label" for="blood_type">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 2C6 8 4 12.5 4 15a8 8 0 0 0 16 0c0-2.5-2-7-8-13z"/>
          </svg>
          Blood Type
          <span class="mpf-required" aria-label="required">*</span>
        </label>
        <div class="mpf-select-wrap">
          <select id="blood_type" name="blood_type" class="mpf-select" required>
            <option value="" disabled <?= $mp_blood === '' ? 'selected' : '' ?>>Select blood type</option>
            <?php foreach ($blood_types as $bt): ?>
            <option value="<?= $bt ?>" <?= $mp_blood === $bt ? 'selected' : '' ?>><?= $bt ?></option>
            <?php endforeach; ?>
          </select>
          <span class="mpf-select-arrow" aria-hidden="true">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </span>
        </div>
        <?php if ($mfe('blood_type')): ?>
          <span class="mpf-field-error"><?= htmlspecialchars($mfe('blood_type')) ?></span>
        <?php endif; ?>
      </div>

      <!-- PhilHealth Status -->
      <div class="mpf-field <?= $mfe('philhealth_status') ? 'mpf-field--error' : '' ?>">
        <label class="mpf-label" for="philhealth_status">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
          PhilHealth Status
          <span class="mpf-required" aria-label="required">*</span>
        </label>
        <div class="mpf-select-wrap">
          <select id="philhealth_status" name="philhealth_status" class="mpf-select" required>
            <option value="" disabled <?= $mp_philhealth === '' ? 'selected' : '' ?>>Select status</option>
            <?php foreach ($philhealth_statuses as $ps): ?>
            <option value="<?= $ps ?>" <?= $mp_philhealth === $ps ? 'selected' : '' ?>><?= $ps ?></option>
            <?php endforeach; ?>
          </select>
          <span class="mpf-select-arrow" aria-hidden="true">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <polyline points="6 9 12 15 18 9"/>
            </svg>
          </span>
        </div>
        <?php if ($mfe('philhealth_status')): ?>
          <span class="mpf-field-error"><?= htmlspecialchars($mfe('philhealth_status')) ?></span>
        <?php endif; ?>
      </div>

    </div><!-- /mpf-row -->

    <!-- ── Divider ── -->
    <div class="mpf-divider">
      <span>Clinical Details</span>
    </div>

    <!-- ── Existing Conditions ── -->
    <div class="mpf-field mpf-field--full <?= $mfe('existing_conditions') ? 'mpf-field--error' : '' ?>">
      <label class="mpf-label" for="existing_conditions">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="10"/>
          <line x1="12" y1="8" x2="12" y2="12"/>
          <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        Existing Conditions
      </label>
      <textarea
        id="existing_conditions"
        name="existing_conditions"
        class="mpf-textarea"
        rows="3"
        maxlength="500"
        placeholder="e.g. Hypertension, Type 2 Diabetes, Asthma — or leave blank if none"
      ><?= $mp_conditions ?></textarea>
      <span class="mpf-field-hint">List any diagnosed medical conditions, separated by commas.</span>
      <?php if ($mfe('existing_conditions')): ?>
        <span class="mpf-field-error"><?= htmlspecialchars($mfe('existing_conditions')) ?></span>
      <?php endif; ?>
    </div>

    <!-- ── Allergies ── -->
    <div class="mpf-field mpf-field--full <?= $mfe('allergies') ? 'mpf-field--error' : '' ?>">
      <label class="mpf-label" for="allergies">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
          <line x1="12" y1="9" x2="12" y2="13"/>
          <line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
        Allergies
      </label>
      <textarea
        id="allergies"
        name="allergies"
        class="mpf-textarea mpf-textarea--amber"
        rows="3"
        maxlength="500"
        placeholder="e.g. Penicillin, Shellfish, Pollen — or leave blank if none"
      ><?= $mp_allergies ?></textarea>
      <span class="mpf-field-hint">List known drug, food, or environmental allergies.</span>
      <?php if ($mfe('allergies')): ?>
        <span class="mpf-field-error"><?= htmlspecialchars($mfe('allergies')) ?></span>
      <?php endif; ?>
    </div>

    <!-- ── Current Medications ── -->
    <div class="mpf-field mpf-field--full <?= $mfe('current_medications') ? 'mpf-field--error' : '' ?>">
      <label class="mpf-label" for="current_medications">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>
        </svg>
        Current Medications
      </label>
      <textarea
        id="current_medications"
        name="current_medications"
        class="mpf-textarea mpf-textarea--blue"
        rows="3"
        maxlength="500"
        placeholder="e.g. Metformin 500mg, Amlodipine 5mg — or leave blank if none"
      ><?= $mp_medications ?></textarea>
      <span class="mpf-field-hint">List medications currently being taken, including dosage if known.</span>
      <?php if ($mfe('current_medications')): ?>
        <span class="mpf-field-error"><?= htmlspecialchars($mfe('current_medications')) ?></span>
      <?php endif; ?>
    </div>

    <!-- ── Actions ── -->
    <div class="mpf-actions">
      <button type="submit" class="mpf-btn-submit">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/>
          <polyline points="17 21 17 13 7 13 7 21"/>
          <polyline points="7 3 7 8 15 8"/>
        </svg>
        Save Medical Profile
      </button>
      <button type="reset" class="mpf-btn-reset">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="1 4 1 10 7 10"/>
          <path d="M3.51 15a9 9 0 1 0 .49-3.5"/>
        </svg>
        Reset
      </button>
    </div>

  </form>

  <!-- ── Footer note ── -->
  <div class="mpf-footer">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <circle cx="12" cy="12" r="10"/>
      <line x1="12" y1="8" x2="12" y2="12"/>
      <line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>
    Medical information is confidential and used only for your healthcare consultations at the City Health Office of Bago City.
  </div>

</div>
