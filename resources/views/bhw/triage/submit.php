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

  <div class="bhw-triage-main">
    <header class="bhw-triage-head">
      <div>
        <h2>Triage &amp; Book Consultation</h2>
        <p>
          Preview AI triage, record teleconsult consent, and book a slot (today through <?= htmlspecialchars(date('M j, Y', strtotime($maxDate))) ?>).
          <strong>Emergency</strong> cases skip video and create a hospital referral.
        </p>
      </div>
      <div class="bhw-triage-head__actions">
        <a href="../consultations/index.php" class="bhw-btn-outline">Consultation Center</a>
      </div>
    </header>

    <div id="bhwEmergencyBanner" class="alert alert-danger bhw-triage-emergency" style="display:none;" role="alert">
      <strong>Emergency detected.</strong> Do not book video. Submit to create an immediate hospital referral.
    </div>

    <form id="bhwTriageForm" class="bhw-triage-grid">
      <div class="bhw-triage-field span-2">
        <label for="bhwPatient">Patient</label>
        <select name="patient_id" id="bhwPatient" class="form-select" required></select>
      </div>

      <div class="bhw-triage-field">
        <label for="bhwProvider">Provider</label>
        <select id="bhwProvider" class="form-select" required>
          <option value="">Select provider…</option>
        </select>
      </div>

      <div class="bhw-triage-field">
        <label for="bhwApptDate">Appointment date</label>
        <input type="date" id="bhwApptDate" class="form-control" value="<?= htmlspecialchars($today) ?>" min="<?= htmlspecialchars($today) ?>" max="<?= htmlspecialchars($maxDate) ?>" required>
      </div>

      <div class="bhw-triage-field span-2" id="bhwSlotWrap">
        <label for="bhwSlot">Available slot</label>
        <select name="slot_id" id="bhwSlot" class="form-select" required>
          <option value="">Select provider and date…</option>
        </select>
      </div>

      <div class="bhw-triage-field span-2">
        <label for="bhwComplaint">Chief complaint</label>
        <textarea name="chief_complaint" id="bhwComplaint" class="form-control" rows="2" required placeholder="Brief reason for visit…"></textarea>
      </div>

      <div class="bhw-triage-field span-2">
        <label for="bhwSymptoms">Symptoms (comma-separated)</label>
        <input name="symptoms" id="bhwSymptoms" class="form-control" placeholder="fever, cough, headache">
      </div>

      <div class="bhw-triage-consent" id="bhwConsentWrap">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="teleconsult_consent" id="bhwTeleConsent" value="1">
          <label class="form-check-label" for="bhwTeleConsent">
            Patient was informed of teleconsult limits and <strong>consents to video consultation</strong> (RA 10173).
          </label>
        </div>
        <p class="bhw-triage-consent__note">Required for routine/urgent booking. Skipped for emergency referral.</p>
      </div>

      <div class="bhw-triage-actions">
        <button type="button" class="bhw-btn-outline" id="bhwPreviewBtn">Preview AI Assessment</button>
        <button type="submit" class="bhw-btn-teal" id="bhwSubmitBtn">Submit Triage &amp; Book</button>
      </div>
    </form>
  </div>

  <aside class="bhw-triage-aside" aria-label="AI assessment preview">
    <h3 class="bhw-triage-aside__title">AI Assessment Preview</h3>
    <p class="bhw-triage-aside__placeholder" id="bhwAssessmentPlaceholder">
      Enter complaint and symptoms, then click <strong>Preview AI Assessment</strong> or submit the form.
    </p>
    <pre id="bhwAssessmentOut" class="bhw-triage-aside__out" style="display:none;"></pre>
    <ul class="bhw-triage-aside__tips list-unstyled mb-0">
      <li>• Slots load after you pick provider and date.</li>
      <li>• Emergency triage auto-hides booking fields.</li>
      <li>• After booking, view status in Consultation Center.</li>
    </ul>
  </aside>

</div>

<?php require __DIR__ . '/../partials/layout_close.php'; ?>
