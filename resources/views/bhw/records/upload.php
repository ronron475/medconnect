<?php
$page_title = 'Upload Documents';
$bhw_current_file = 'records/upload.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$preselect = (int) ($_GET['patient_id'] ?? 0);
$records_css_ver = (int) @filemtime(ASSETS_PATH . '/css/bhw-records.css');
$records_js_ver = (int) @filemtime(ASSETS_PATH . '/js/bhw-records.js');
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-records.css?v=<?= $records_css_ver ?>">
<script src="<?= ASSET_BASE ?>/assets/js/bhw-records.js?v=<?= $records_js_ver ?>" defer></script>

<div class="bhw-records-page bhw-records-upload-page" id="bhwRecordsUpload" data-preselect="<?= $preselect ?>">

  <a href="index.php<?= $preselect ? '?patient_id=' . $preselect : '' ?>" class="bhw-records-back" aria-label="Back to patient records">
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
    Back to records
  </a>

  <header class="bhw-records-header">
    <div>
      <h2 class="text-h2">Upload Documents</h2>
      <p class="bhw-records-sub">Attach supporting files to a patient's record — for example barangay residency proof, lab results, or referral letters. Files are stored securely and marked <em>pending</em> until reviewed.</p>
    </div>
  </header>

  <div class="bhw-records-quick">
    <a href="index.php" class="bhw-records-quick-card">
      <div class="bhw-records-quick-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
      </div>
      <div class="bhw-records-quick-body">
        <strong>View records</strong>
        <span>Browse existing documents and prescriptions.</span>
      </div>
    </a>
    <a href="upload.php" class="bhw-records-quick-card is-active">
      <div class="bhw-records-quick-icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      </div>
      <div class="bhw-records-quick-body">
        <strong>Upload documents</strong>
        <span>Add a new file for a patient in your barangay.</span>
      </div>
    </a>
  </div>

  <div class="bhw-card bhw-records-panel">
  <form id="bhwUploadForm" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="patient_id" id="bhwUploadPatientId" value="<?= $preselect > 0 ? $preselect : '' ?>">

    <div class="mb-4" id="bhwUploadPicker"></div>

    <div class="mb-4">
      <label class="form-label" for="bhwUploadFile">Document file</label>
      <div class="bhw-records-file-zone" id="bhwUploadZone">
        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.8" aria-hidden="true">
          <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>
        </svg>
        <p class="mb-1" style="font-weight:600;color:var(--bhw-navy);">Drop a file here or choose below</p>
        <input type="file" name="document" id="bhwUploadFile" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
        <p class="bhw-records-file-hint">Maximum one file per upload. Use clear scans or photos.</p>
        <div class="bhw-records-file-types" aria-hidden="true">
          <span>PDF</span><span>JPG</span><span>PNG</span>
        </div>
        <div class="bhw-records-selected-file" id="bhwUploadFileName"></div>
      </div>
    </div>

    <button type="submit" class="bhw-btn-teal">Upload document</button>
  </form>
  </div>

</div>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
