<?php
$page_title = 'Upload Documents';
$bhw_current_file = 'records/upload.php';
require __DIR__ . '/../partials/bhw_bootstrap.php';
require __DIR__ . '/../partials/layout_open.php';
$preselect = (int) ($_GET['patient_id'] ?? 0);
$barangay_label = htmlspecialchars($bhw_barangay_name);
$records_css_ver = (int) @filemtime(ASSETS_PATH . '/css/bhw-records.css');
$records_js_ver = (int) @filemtime(ASSETS_PATH . '/js/bhw-records.js');
?>
<link rel="stylesheet" href="<?= ASSET_BASE ?>/assets/css/bhw-records.css?v=<?= $records_css_ver ?>">
<script src="<?= ASSET_BASE ?>/assets/js/bhw-records.js?v=<?= $records_js_ver ?>" defer></script>

<div class="bhw-records-page bhw-records-upload-page" id="bhwRecordsUpload" data-preselect="<?= $preselect ?>">

  <header class="bhw-records-header">
    <div>
      <h2 class="text-h2">Upload Documents</h2>
      <p class="bhw-records-sub">Attach residency certificates, lab results, referral letters, and other files to a patient in <strong>Brgy. <?= $barangay_label ?></strong>. Fill in all fields below, then submit.</p>
    </div>
  </header>

  <nav class="bhw-records-nav" aria-label="Records sections">
    <a href="index.php<?= $preselect ? '?patient_id=' . $preselect : '' ?>" class="bhw-records-nav__item">View records</a>
    <a href="upload.php<?= $preselect ? '?patient_id=' . $preselect : '' ?>" class="bhw-records-nav__item is-active" aria-current="page">Upload document</a>
  </nav>

  <div class="bhw-records-upload-layout">
    <form id="bhwUploadForm" class="bhw-records-main bhw-records-form" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="patient_id" id="bhwUploadPatientId" value="<?= $preselect > 0 ? $preselect : '' ?>">

      <section class="bhw-records-section" aria-labelledby="bhwUploadSec1">
        <h3 class="bhw-records-section__title" id="bhwUploadSec1">1. Select patient</h3>
        <p class="bhw-records-section__sub">Search by name, email, or contact number.</p>
        <div id="bhwUploadPicker" class="bhw-records-picker-mount"></div>
        <div id="bhwUploadPatientCard" class="bhw-records-patient-summary" hidden aria-live="polite">
          <div class="bhw-records-patient-summary__avatar" id="bhwUploadPatientAvatar" aria-hidden="true"></div>
          <div class="bhw-records-patient-summary__body">
            <strong id="bhwUploadPatientName"></strong>
            <span id="bhwUploadPatientMeta"></span>
          </div>
          <button type="button" class="bhw-records-patient-summary__change" id="bhwUploadPatientChange">Change</button>
        </div>
        <p class="bhw-records-field-error" id="bhwUploadPatientError" role="alert" hidden>Please select a patient.</p>
      </section>

      <section class="bhw-records-section" aria-labelledby="bhwUploadSec2">
        <h3 class="bhw-records-section__title" id="bhwUploadSec2">2. Document details</h3>
        <p class="bhw-records-section__sub">Describe what you are uploading.</p>

        <div class="bhw-records-field">
          <label class="form-label" for="bhwUploadDocType">Document type <span class="bhw-records-req">*</span></label>
          <select class="form-select" name="document_type" id="bhwUploadDocType" required>
            <option value="">Select type…</option>
            <option value="Laboratory Result">Laboratory Result</option>
            <option value="Medical Certificate">Medical Certificate</option>
            <option value="Referral Letter">Referral Letter</option>
            <option value="Prescription">Prescription</option>
            <option value="Imaging Result">Imaging Result</option>
            <option value="Barangay Residency">Barangay Residency</option>
            <option value="Vaccination Record">Vaccination Record</option>
            <option value="Other">Other</option>
          </select>
          <p class="bhw-records-field-error" id="bhwUploadTypeError" role="alert" hidden>Please select a document type.</p>
        </div>

        <div class="bhw-records-field">
          <label class="form-label" for="bhwUploadDocTitle">Document title <span class="bhw-records-req">*</span></label>
          <input type="text" class="form-control" name="document_title" id="bhwUploadDocTitle" required maxlength="255" placeholder="e.g. CBC Laboratory Result" autocomplete="off">
          <p class="bhw-records-field-error" id="bhwUploadTitleError" role="alert" hidden>Please enter a document title.</p>
        </div>

        <div class="bhw-records-field">
          <label class="form-label" for="bhwUploadDescription">Description <span class="bhw-records-opt">(optional)</span></label>
          <textarea class="form-control" name="description" id="bhwUploadDescription" rows="2" maxlength="1000" placeholder="Short note about this document"></textarea>
        </div>
      </section>

      <section class="bhw-records-section" aria-labelledby="bhwUploadSec3">
        <h3 class="bhw-records-section__title" id="bhwUploadSec3">3. Attach file</h3>
        <p class="bhw-records-section__sub">One file per submission. PDF, JPG, or PNG — maximum 10 MB.</p>

        <div class="bhw-records-file-zone" id="bhwUploadZone">
          <div class="bhw-records-file-zone__inner">
            <p class="bhw-records-file-zone__lead">Select a file from your device</p>
            <label class="bhw-records-file-btn" for="bhwUploadFile">Browse file</label>
            <input type="file" name="document" id="bhwUploadFile" class="bhw-records-file-input" required accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
            <div class="bhw-records-selected-file" id="bhwUploadFileName" aria-live="polite"></div>
            <div class="bhw-records-upload-progress" id="bhwUploadProgressWrap" hidden>
              <div class="bhw-records-upload-progress__bar" id="bhwUploadProgressBar" style="width:0%"></div>
            </div>
            <p class="bhw-records-upload-progress__label" id="bhwUploadProgressLabel" hidden></p>
          </div>
        </div>
        <p class="bhw-records-field-error" id="bhwUploadFileError" role="alert" hidden>Please choose a valid file (PDF, JPG, or PNG, max 10 MB).</p>
      </section>

      <footer class="bhw-records-form-actions">
        <a href="index.php<?= $preselect ? '?patient_id=' . $preselect : '' ?>" class="bhw-records-btn bhw-records-btn--secondary">Cancel</a>
        <button type="submit" class="bhw-records-btn bhw-records-btn--primary" id="bhwUploadSubmit" disabled>
          <span id="bhwUploadBtnLabel">Upload document</span>
          <span class="bhw-btn-spinner" id="bhwUploadSpinner" hidden aria-hidden="true"></span>
        </button>
      </footer>

      <div class="bhw-records-upload-success" id="bhwUploadSuccess" hidden role="status">
        <strong>Document uploaded.</strong> Redirecting to patient records…
      </div>
    </form>

    <aside class="bhw-records-aside" aria-label="Upload help">
      <div class="bhw-records-aside__head">
        <h3>Before you upload</h3>
      </div>
      <div class="bhw-records-aside__body">
        <ol class="bhw-records-upload-notes">
          <li>Select the correct patient.</li>
          <li>Choose the document type and enter a clear title.</li>
          <li>Attach a clear, complete scan or photo.</li>
          <li>Submit — the file will be marked <strong>pending</strong> until reviewed.</li>
        </ol>
        <dl class="bhw-records-upload-stats" id="bhwUploadStats">
          <div><dt>Pending review</dt><dd id="bhwStatPending">—</dd></div>
          <div><dt>Verified</dt><dd id="bhwStatVerified">—</dd></div>
          <div><dt>Rejected</dt><dd id="bhwStatRejected">—</dd></div>
          <div><dt>Today&rsquo;s uploads</dt><dd id="bhwStatToday">—</dd></div>
        </dl>
      </div>
    </aside>
  </div>

</div>
<?php require __DIR__ . '/../partials/layout_close.php'; ?>
