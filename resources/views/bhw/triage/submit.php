<?php
$page_title = 'Triage & Book Consultation';
$bhw_current_file = 'triage/submit.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';

$preselect = (int) ($_GET['patient_id'] ?? 0);
$showPreview = !empty($_GET['preview']);
$today = date('Y-m-d');
$maxDate = date('Y-m-d', strtotime('+28 days'));

$triageCssVer = (int) @filemtime(ASSETS_PATH . '/css/bhw-triage-submit.css');
$triageJsVer = (int) @filemtime(ASSETS_PATH . '/js/bhw-triage-submit.js');
$bhw_extra_js = [
    ASSET_BASE . '/assets/js/bhw-triage-submit.js?v=' . $triageJsVer,
];
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-triage-submit.css?v=<?= $triageCssVer ?>">

<script>
window.bhwTriageConfig = <?= json_encode([
    'preselect' => $preselect,
    'showPreview' => $showPreview,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
</script>

<div class="bhw-triage-page">

  <header class="bhw-triage-page-header">
    <div class="bhw-triage-page-header__text">
      <h2 class="text-h2">Triage &amp; Book Consultation</h2>
      <p>
        Record the patient&apos;s chief complaint, run AI triage for priority classification, then schedule or refer as indicated.
        BHW staff do not diagnose or override AI urgency results.
      </p>
    </div>
    <a href="../consultations/index.php" class="bhw-triage-link-btn bhw-triage-link-btn--header">Consultation Center</a>
  </header>

  <div class="bhw-triage-facilitator-notice" role="note">
    <strong>BHW facilitator role:</strong> Type exactly what the patient says. Do not interpret, summarize, diagnose, prescribe, or change AI urgency results.
  </div>

  <div id="bhwEmergencyOverlay" class="bhw-triage-emergency-overlay" hidden aria-hidden="true" role="alertdialog" aria-labelledby="bhwEmergencyTitle">
    <div class="bhw-triage-emergency-overlay__panel">
      <span class="bhw-triage-emergency-overlay__icon" aria-hidden="true">🔴</span>
      <h3 id="bhwEmergencyTitle">Emergency Classification</h3>
      <p>The AI Triage Engine classified this case as <strong>EMERGENCY</strong>. Online consultation booking is locked.</p>
      <ul>
        <li>Explain that immediate in-person care may be required.</li>
        <li>Generate the electronic hospital referral.</li>
        <li>Assist the patient in proceeding to the appropriate facility.</li>
        <li>Do not downgrade or dismiss this emergency classification.</li>
      </ul>
      <button type="button" class="bhw-triage-btn bhw-triage-btn--emergency" id="bhwEmergencyAckBtn">I Understand — Proceed with Referral</button>
    </div>
  </div>

  <div class="bhw-triage-layout">

    <div class="bhw-triage-main">
      <div id="bhwEmergencyBanner" class="bhw-triage-emergency" style="display:none;" role="alert">
        <strong>Emergency detected.</strong> Do not book video. Submit to create an immediate hospital referral.
      </div>

      <form id="bhwTriageForm" class="bhw-triage-form" novalidate>
        <input type="hidden" name="assessment_token" id="bhwAssessmentToken" value="">

        <section class="bhw-triage-section" aria-labelledby="bhwTriageSec1">
          <h3 class="bhw-triage-section__title" id="bhwTriageSec1">Step 1 — Select patient</h3>
          <div class="bhw-triage-grid">
            <div class="bhw-triage-field span-2">
              <label for="bhwPatient">Patient</label>
              <select name="patient_id" id="bhwPatient" class="form-select" required>
                <option value="">Select patient…</option>
              </select>
            </div>
          </div>
        </section>

        <section class="bhw-triage-section" aria-labelledby="bhwTriageSec2">
          <h3 class="bhw-triage-section__title" id="bhwTriageSec2">Step 2 — Chief complaint (patient&apos;s own words)</h3>
          <div class="bhw-triage-grid">
            <div class="bhw-triage-field span-2">
              <label for="bhwNlpInput">Chief Complaint (Patient&apos;s Own Words) <span class="bhw-triage-required" aria-hidden="true">*</span></label>
              <div class="bhw-triage-nlp-input-wrap">
                <textarea
                  name="chief_complaint"
                  id="bhwNlpInput"
                  class="form-control bhw-triage-nlp-input"
                  rows="4"
                  required
                  maxlength="500"
                  placeholder="Describe your main health concern in your own words. For example: 'I have had a fever for three days and a sore throat.'"
                  aria-describedby="bhwNlpHint bhwNlpCharCount bhwNlpError"
                  aria-required="true"
                  aria-invalid="false"
                ></textarea>
                <span class="bhw-triage-nlp-char-count" id="bhwNlpCharCount" aria-live="polite">0 / 500</span>
              </div>
              <p class="bhw-triage-field-error" id="bhwNlpError" role="alert" hidden></p>
              <p class="bhw-triage-hint" id="bhwNlpHint">
                Record the patient&apos;s concern verbatim, then click <strong>Run</strong> to classify priority before scheduling.
              </p>

              <div class="bhw-triage-nlp-actions">
                <button type="button" class="bhw-triage-btn bhw-triage-btn--verify" id="bhwVerifyBtn">
                  <span id="bhwVerifyBtnLabel">Run</span>
                </button>
                <span class="bhw-triage-nlp-actions__note" id="bhwVerifyStatus" aria-live="polite"></span>
              </div>
              <span id="bhwSchedulingNote" hidden aria-hidden="true"></span>
            </div>
          </div>
        </section>

        <section class="bhw-triage-section" id="bhwAssessmentSection" aria-labelledby="bhwTriageSec3" hidden>
          <h3 class="bhw-triage-section__title" id="bhwTriageSec3">Step 3 — AI classification (read-only)</h3>
          <p class="bhw-triage-hint bhw-triage-hint--step3">AI urgency results are shown for facilitator reference only. BHW staff cannot edit or override these results.</p>
          <div id="bhwAssessmentPanel" class="bhw-triage-assessment" aria-live="polite"></div>
        </section>

        <section class="bhw-triage-section" id="bhwBookingSection" aria-labelledby="bhwTriageSecBook" hidden>
          <h3 class="bhw-triage-section__title" id="bhwTriageSecBook">Step 4 — Schedule or refer (AI-controlled)</h3>
          <div class="bhw-triage-grid">
            <div class="bhw-triage-field span-2">
              <label for="bhwProvider">Provider</label>
              <select id="bhwProvider" class="form-select" required>
                <option value="">Select provider…</option>
              </select>
              <p class="bhw-triage-hint" id="bhwProviderSchedule" hidden></p>
            </div>
            <div class="bhw-triage-field span-2" id="bhwSlotWrap">
              <label for="bhwSlot">Available slot</label>
              <select name="slot_id" id="bhwSlot" class="form-select" required>
                <option value="">Run triage first, then select provider…</option>
              </select>
              <p class="bhw-triage-hint" id="bhwSlotHint" hidden></p>
            </div>
          </div>
        </section>

        <section class="bhw-triage-section" id="bhwConsentSection" aria-labelledby="bhwTriageSecConsent" hidden>
          <h3 class="bhw-triage-section__title" id="bhwTriageSecConsent">Teleconsult consent</h3>
          <div class="bhw-triage-consent" id="bhwConsentWrap">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="teleconsult_consent" id="bhwTeleConsent" value="1">
              <label class="form-check-label" for="bhwTeleConsent">
                Patient was informed of teleconsult limits and <strong>consents to video consultation</strong> under the Data Privacy Act (RA 10173).
              </label>
            </div>
            <p class="bhw-triage-consent__note">Required for routine and urgent bookings. Not required for emergency referral.</p>
          </div>
        </section>

        <footer class="bhw-triage-actions">
          <button type="submit" class="bhw-triage-btn bhw-triage-btn--primary" id="bhwSubmitBtn">Submit Triage &amp; Book</button>
        </footer>
      </form>
    </div>

  </div>
</div>

<?php require __DIR__ . '/../partials/layout_close.php'; ?>
