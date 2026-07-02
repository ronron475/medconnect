<?php
$page_title = 'Patient Records';
$bhw_current_file = 'records/index.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$preselect = (int) ($_GET['patient_id'] ?? 0);
$records_css_ver = (int) @filemtime(ASSETS_PATH . '/css/bhw-records.css');
$records_js_ver = (int) @filemtime(ASSETS_PATH . '/js/bhw-records.js');
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-records.css?v=<?= $records_css_ver ?>">
<script src="<?= ASSET_BASE ?>/assets/js/bhw-records.js?v=<?= $records_js_ver ?>" defer></script>

<div class="bhw-records-page" id="bhwRecordsView" data-preselect="<?= $preselect ?>">

  <header class="bhw-records-header">
    <div>
      <h2 class="text-h2">View Patient Records</h2>
      <p class="bhw-records-sub">Browse uploaded documents and prescriptions for residents in <strong>Brgy. <?= htmlspecialchars($bhw_barangay_name) ?></strong>. Select a patient below to see their file history.</p>
    </div>
    <div class="bhw-records-actions">
      <a href="upload.php" class="bhw-btn-teal" id="bhwRecordsUploadLink" style="display:none;">Upload document</a>
    </div>
  </header>

  <div class="bhw-records-quick">
    <a href="index.php" class="bhw-records-quick-card is-active">
      <div class="bhw-records-quick-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
      </div>
      <div class="bhw-records-quick-body">
        <strong>View records</strong>
        <span>See documents, verification status, and provider prescriptions.</span>
      </div>
    </a>
    <a href="upload.php" class="bhw-records-quick-card">
      <div class="bhw-records-quick-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      </div>
      <div class="bhw-records-quick-body">
        <strong>Upload documents</strong>
        <span>Add residency proof, lab results, or referral letters (PDF/JPG/PNG).</span>
      </div>
    </a>
  </div>

  <div class="bhw-card bhw-records-panel">
    <div class="bhw-records-panel-head">
      <div>
        <h3>Find patient</h3>
        <p>Search by name, email, or contact number — only patients in your barangay are listed.</p>
      </div>
    </div>
    <div id="bhwRecordsPicker"></div>
  </div>

  <div class="bhw-card bhw-records-panel">
    <div class="bhw-records-panel-head">
      <div>
        <h3>Uploaded documents</h3>
        <p>Residency and supporting files submitted for this patient.</p>
      </div>
    </div>
    <div id="bhwRecordsDocs">
      <div class="bhw-records-empty">
        <strong>Select a patient</strong>
        <span>Choose a resident above to view their document list.</span>
      </div>
    </div>
  </div>

  <div class="bhw-card bhw-records-panel">
    <div class="bhw-records-panel-head">
      <div>
        <h3>Prescriptions</h3>
        <p>Medications prescribed during consultations with providers.</p>
      </div>
    </div>
    <div id="bhwRecordsRx"></div>
  </div>

</div>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
