<?php
$page_title = 'Patient Registration';
$bhw_current_file = 'patients/register.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
$reg_js_ver = (int) @filemtime(ASSETS_PATH . '/js/bhw-patient-register.js');
$bhw_head_css = '';
require __DIR__ . '/../partials/layout_open.php';
$barangay_label = htmlspecialchars($bhw_barangay_name);
?>
<div class="bhw-register-page" id="bhwRegPage">
  <header class="bhw-register-header">
    <div class="bhw-register-header-text">
      <h2>Patient Registration</h2>
      <p>Register a patient in <strong>Brgy. <?= $barangay_label ?></strong>. After you save their information, they verify their Gmail and create their own password on their phone. You never set or see that password.</p>
    </div>
  </header>

  <form id="bhwRegForm" class="bhw-register-form" novalidate>
    <section class="bhw-card bhw-form-card" aria-labelledby="bhwRegInfoTitle">
      <h3 class="bhw-form-card-title" id="bhwRegInfoTitle">
        <span class="bhw-card-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </span>
        Step 1 — Patient Information
      </h3>
      <p class="bhw-form-card-sub">Required details are sent with a Gmail verification link. Medical fields can be left blank.</p>
      <div class="bhw-form-grid">
        <div class="bhw-field span-2">
          <label class="form-label" for="bhwRegName">Full Name <span class="bhw-req" aria-hidden="true">*</span></label>
          <input class="form-control" id="bhwRegName" name="full_name" required maxlength="200" autocomplete="name" placeholder="First and last name">
        </div>
        <div class="bhw-field">
          <label class="form-label" for="bhwRegContact">Contact Number <span class="bhw-req" aria-hidden="true">*</span></label>
          <input class="form-control" id="bhwRegContact" name="contact_number" required inputmode="numeric" autocomplete="tel" placeholder="09XXXXXXXXX" maxlength="20">
        </div>
        <div class="bhw-field">
          <label class="form-label" for="bhwRegEmail">Gmail <span class="bhw-req" aria-hidden="true">*</span></label>
          <input class="form-control" id="bhwRegEmail" name="email" type="email" required maxlength="180" autocomplete="email" placeholder="name@gmail.com">
        </div>
        <div class="bhw-field span-2">
          <label class="form-label" for="bhwRegAllergies">Allergies</label>
          <textarea class="form-control" id="bhwRegAllergies" name="allergies" rows="2" maxlength="2000" placeholder="None, or list known allergies"></textarea>
        </div>
        <div class="bhw-field span-2">
          <label class="form-label" for="bhwRegConditions">Medical Conditions</label>
          <textarea class="form-control" id="bhwRegConditions" name="existing_conditions" rows="2" maxlength="2000" placeholder="Hypertension, diabetes, asthma…"></textarea>
        </div>
        <div class="bhw-field span-2">
          <label class="form-label" for="bhwRegMeds">Current Medications</label>
          <textarea class="form-control" id="bhwRegMeds" name="current_medications" rows="2" maxlength="2000" placeholder="Medicines the patient takes now"></textarea>
        </div>
        <div class="bhw-field">
          <label class="form-label" for="bhwRegBlood">Blood Type</label>
          <select class="form-control" id="bhwRegBlood" name="blood_type">
            <option value="Unknown">Unknown</option>
            <option value="A+">A+</option>
            <option value="A-">A-</option>
            <option value="B+">B+</option>
            <option value="B-">B-</option>
            <option value="AB+">AB+</option>
            <option value="AB-">AB-</option>
            <option value="O+">O+</option>
            <option value="O-">O-</option>
          </select>
        </div>
      </div>
      <p id="bhwRegError" class="bhw-field-error" role="alert" hidden></p>
      <div class="bhw-form-actions">
        <button type="submit" class="bhw-btn-teal" id="bhwRegSubmit">Continue to Gmail verification</button>
      </div>
    </section>
  </form>

  <section class="bhw-card bhw-form-card bhw-reg-status" id="bhwRegStatus" hidden aria-live="polite">
    <h3 class="bhw-form-card-title">Step 2 — Gmail Verification</h3>
    <p class="bhw-form-card-sub" id="bhwRegStatusWho"></p>
    <p class="bhw-reg-status__label" id="bhwRegStatusLabel">Waiting for Gmail verification</p>
    <p class="bhw-reg-status__detail" id="bhwRegStatusDetail" hidden></p>
    <p class="bhw-field-hint">This page updates on its own. The patient opens the Gmail link on their own device and creates the password there.</p>
    <div class="bhw-form-actions">
      <button type="button" class="bhw-btn-ghost" id="bhwRegAnother">Register another patient</button>
    </div>
  </section>
</div>
<script src="<?= ASSET_BASE ?>/assets/js/bhw-patient-register.js?v=<?= $reg_js_ver ?>" defer></script>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
